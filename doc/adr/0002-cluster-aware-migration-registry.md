# 2. Cluster-aware migration registry

- Status: Accepted
- Date: 2026-07-27

## Context

The migration registry — the table that records which migrations have been applied — was written
for exactly one deployment: a single ClickHouse node. Three assumptions were baked into it, none of
them stated anywhere:

1. **The registry is local.** `CREATE TABLE … ENGINE = ReplacingMergeTree` creates a table on the
   node the connection happens to reach. On a cluster, every node grows its own private registry and
   each one records a different subset of the history.
2. **Reading it back is trivial.** Registry `SELECT`s carried no `FINAL`, so `ReplacingMergeTree`
   returned pre-merge duplicates. `total()` over-counted, and the same migration applied twice
   showed up twice — a bug on a single node, before clustering enters the picture.
3. **Migrations only ever touch one node.** There was no way for a migration to say `ON CLUSTER`.

The practical failure is unglamorous and total: a Laravel app behind a load balancer runs
`clickhouse:migrate`, the connection lands on node A, the registry on node A records the migration,
the next deploy lands on node B, and every migration runs again. Against `CREATE TABLE` that is
survivable; against `ALTER TABLE … ADD COLUMN` or an `INSERT`-shaped data migration it is not.

[ADR 0001](0001-minimum-clickhouse-server-version.md) records the minimum server version this design
depends on, and the measured capability matrix behind it.

## Decision

**The registry is described by explicit configuration, and every statement it emits is derived from
that description rather than hardcoded.**

`clickhouse.migrations` gains `cluster`, `replicated`, `replica_path_prefix`, `replica_name` and
`timeout`.
The defaults reproduce today's single-node behaviour exactly, so an existing installation sees no
change. `RegistryTopology` holds and validates that description; `MigrationRepository` turns it into
statements and runs them.

### D1 — The registry is replicated, never sharded

Its ZooKeeper path carries no macro at all, so every node joins one replication group and sees the
same rows. This is the opposite of the advice for data tables, and it is the point: the registry is
coordination state, not data. Sharding it would give each shard a partial history — the bug being
fixed, reintroduced with more machinery.

The path is not a template but `replica_path_prefix` — a literal — with the database and the table
appended by this package. Rejecting `{shard}` by name would be a blocklist, and macro names belong
to the operator: `{layer}`, or any custom name that resolves per shard, splits the registry exactly
as `{shard}` does while sailing past a check that names one macro. An operator with a shared Keeper
still gets what the setting exists for by spelling the value out — `/clickhouse/staging/tables`.

For the same reason `cluster` without `replicated` is rejected at wiring time: `ON CLUSTER` over
non-replicated tables creates N independent registries and swallows the inconsistency.

### D2 — Consistent reads take `FINAL`, a quorum write and a replica catch-up

Every registry read uses `FINAL`. In replicated mode the `INSERT` carries
`SETTINGS insert_quorum = 'auto'`, and every read of the registry is preceded by
`SYSTEM SYNC REPLICA`.

`select_sequential_consistency = 1` is the setting that looks like it belongs here, and it is not
used. Two reasons, either one sufficient. ClickHouse documents it as inoperative while
`insert_quorum_parallel` is enabled — which it is, by default, on every node of the test cluster.
And even where it does apply it caps a read at the last quorum-committed part without waiting for
the connected replica to reach it: a replica that has not fetched the part yet returns *fewer* rows,
with no error, which is precisely the "second run re-applies migrations" failure the setting appears
to prevent. Draining the replication queue is what actually makes the read see the write. An earlier
revision of this ADR carried the setting alongside the sync; it was doing nothing, and it is gone.

`exists()` and the engine probe are exempt from the sync: both answer questions about table
existence rather than table contents, and have to work before the registry exists.

Syncing per read rather than once per repository is deliberate. A flag makes correctness depend on
how long the object lives — under Octane or a queue worker a second migrate run would reuse a
repository that believes it has already synced — and it buys one saved round trip on a run that
reads the registry twice, where the second call returns immediately against a queue the first one
drained. `Migrator` is therefore an ordinary `singleton`, with no state to make that binding a lie.

Twice is the whole cost, regardless of how many files the directory holds: `Migrator` lists the
applied migrations once and filters the directory against that list in memory, then takes the batch
number. Checking each file against the registry individually would multiply both the sync and the
read by the number of pending migrations.

The quorum is not configurable. The only value an operator would reach for is `0`, which turns a
quorum write into an ordinary one and leaves the sync draining a queue that may not carry the row
yet.

### D3 — Three values are interpolated; everything else is a query parameter

Reads, the `INSERT` and the `EXISTS` probe are fully parameterised: identifiers and values reach the
server as `param_*`. The `CREATE TABLE` is the exception, and one clause forces it: `ON CLUSTER`
accepts no query parameter. `ON CLUSTER {cluster:Identifier}` is a `SYNTAX_ERROR` — *Expected one of:
identifier, string literal* — so the cluster name has to be in the query text.

That decides the rest of the statement. Once the text carries the engine definition, it carries the
replica name's macros with it, and the driver rewrites every `{name}` it finds in the text from the
bindings before sending: `Bindings::process()` runs `preg_replace_callback('#{([\w+]+)}#')` twice
over the SQL on every query. Typed `{x:Type}` parameters survive the pass — the colon keeps them out
of the pattern — but a bare `{replica}` does not, so a single binding by that name would consume the
macro the server was supposed to expand. The DDL therefore carries no bindings at all, which is a
property of the statement rather than of each value in it.

The server itself is not the obstacle here, and an earlier draft of this ADR said it was. Measured on
22.8 and 26.3, through curl and through the driver: `ENGINE = ReplicatedReplacingMergeTree({path:String},
{replica:String})` with `param_replica={replica}` creates the table with `replica_name = 01-01`, the
node's macro, correctly expanded. `{table:Identifier}` in `CREATE TABLE` works on 22.8 too. Neither
changes the decision, because `ON CLUSTER` still cannot be bound.

The cluster name is validated as an identifier (`/^[A-Za-z_][A-Za-z0-9_]*$/`) and backtick-quoted;
the ZooKeeper path prefix and the replica name are rejected if they contain a quote, a backslash or
a newline, so neither can close the string literal it sits in. The database and the table appended
to the prefix are identifiers, already validated as such.

### D4 — The create is unconditional and idempotent

`CREATE TABLE IF NOT EXISTS … ON CLUSTER` replaces the old `exists()`-then-create pair. `EXISTS
TABLE` only ever reflects the connected node, so gating a cluster-wide write on it is a race by
construction. It survives for reporting.

### D5 — A registry from a different topology is reported, not adopted

A registry created before cluster mode was enabled stays a plain `ReplacingMergeTree` on the node
that owns it, while `CREATE TABLE IF NOT EXISTS … ON CLUSTER` cheerfully creates *empty replicated*
tables everywhere else — the migration history survives on one node and is invisible from the rest.
`ensureEngineMatchesTopology()` compares `system.tables.engine` against the configured topology and
throws `ClickhouseRegistryEngineMismatchException` naming both engines and the conversion recipe.

`system.tables` is local to a node, so in cluster mode the probe reads
`clusterAllReplicas(cluster, system.tables)` instead. A node-local probe would only catch the stray
registry when the migrate run happened to land on the node that owns it — which is the same coin
flip the whole ADR exists to remove, and it would clear the check on the next run from anywhere
else.

### D6 — Migration authors get one method, interpolated

`AbstractClickhouseMigration::onCluster()` returns the whole clause — either an empty string or
``ON CLUSTER `name` `` — and is interpolated into the SQL:

```php
$this->clickhouseClient->write(
    "CREATE TABLE events {$this->onCluster()} (id UInt32) ENGINE = MergeTree ORDER BY id",
);
```

Exposing the raw cluster name instead would force every migration to write `ON CLUSTER {cluster}`
and break on single-node deployments, where the correct output is nothing at all. Exposing it as a
*binding* would be worse: the driver rewrites `{name}` in the query text from the bindings, so a
`{database}` binding silently eats the ClickHouse `{database}` macro in a replica path (D3).

### D7 — The migrator gets its own client and its own timeout

`connection.options.timeout` reaches the server as `max_execution_time` on *every* request, so an
application tuned to a 1-second cap cannot run an `ON CLUSTER` statement at all — that DDL waits on
`distributed_ddl_task_timeout`, 180 seconds by default. The driver offers no per-request settings,
so the two caps become two settings — `CLICKHOUSE_QUERY_TIMEOUT` and `CLICKHOUSE_MIGRATION_TIMEOUT`
— and two clients. The application-facing client stays exactly as configured.

The second client is registered under `AbstractClickhouseMigration::CLIENT`, and the migration base
class resolves that key when its constructor is handed nothing. That is the only place it can be
resolved: a migration file returns an anonymous class it constructs itself, so the migrator has no
opportunity to inject anything. Reaching for the right client in the constructor also means a
migration built outside the migrator is not silently capped at the application timeout, which a
setter on the migrator's side could not guarantee.

## Evidence

Three suites — `Unit` (no server), `Integration` (single node) and `Cluster` (2 shards × 2 replicas
plus Keeper) — run against both ends of the supported range, 22.8 and 26.3.

`compose.cluster.yml` provides the cluster environment; the built-in `test_shard_localhost` cluster
is absent on current versions, so `ON CLUSTER` cannot be tested without a real Keeper.

CI runs all three. The `Unit` suite runs on every PHP × Laravel matrix job; `Integration` and
`Cluster` each run twice, once per end of the supported range, off the same compose files a
developer uses — the runner starts only the server containers and drives them over their published
ports, so `CLICKHOUSE_CLUSTER_NODES` carries a port per node. Both server jobs pass
`--fail-on-skipped`: the suites skip themselves when nothing answers, which would otherwise report
a green build that tested nothing.

Two findings came out of running it, and neither would have surfaced on a single node or on a
current release alone:

- **A replica name that repeats across nodes fails loudly.** `replica_name = {shard}` is shared by
  both replicas of a shard, and the second one to arrive gets `REPLICA_ALREADY_EXISTS`. That is the
  one rule the configuration cannot check for the operator, so the suite pins the failure instead
  (D3). An earlier revision of this ADR blamed the same error on query parameters not expanding
  macros; that explanation was wrong — see D3 for what was actually measured.
- **`select_sequential_consistency` does not wait.** On 22.8 a quorum `INSERT` on `s1r1` followed
  immediately by a sequential-consistency read on `s2r2` returns nothing, then returns the row a
  moment later. 26.3 usually wins the race, which hides the defect rather than fixing it (D2). This
  is why the cluster suite has to run on the declared floor and not only on the version a developer
  happens to have running. The setting is not in the shipped code at all — it is documented as
  inoperative under the default `insert_quorum_parallel = 1`, so it never had a chance to help.

## Consequences

**Positive**

- `clickhouse:migrate` is safe to run from any node of a cluster; a second run from a different node
  sees no pending migrations.
- `total()` and duplicate detection are correct on single nodes too — `FINAL` fixes a pre-existing
  bug unrelated to clustering.
- The registry write path is fully parameterised. No hand-rolled escaping on the `INSERT`.
- Migrations can be written once and deployed to both single-node and cluster installations, because
  `onCluster()` collapses to nothing where there is no cluster.
- A topology change is caught with a message that names the fix, instead of splitting the history in
  half silently.

**Negative**

- More configuration surface: five new keys where there were none. Every one defaults to today's
  behaviour, but they are five more things an operator can get wrong.
- Cluster mode couples a migrate run to the health of a majority of replicas. That is the intended
  trade-off — it is also a new way for a deploy to block.
- Every registry read pays a `SYSTEM SYNC REPLICA` in replicated mode — twice per migrate run.
- The engine probe queries every replica in cluster mode, so a node that is down fails the check
  rather than being skipped. For a statement about cluster-wide consistency that is the right way
  round, but it does mean a degraded cluster cannot be migrated without attention.
- Cluster coverage requires a six-container environment, which is slower than the rest of CI.

**Neutral**

- Migrations remain forward-only; nothing here introduces rollback.
- Converting an existing single-node registry to a replicated one stays a manual operation
  (create the replicated table beside the old one, `INSERT SELECT`, `RENAME`). Automating it means
  guessing at data the package cannot see.

## Alternatives considered

**Keep the registry local and document "run migrations from one node".** Rejected. It is not
enforceable behind a load balancer, and the failure it permits is silent re-application of
migrations, not an error.

**Use a `Distributed` table over per-node registries.** Rejected. `Distributed` gives fan-out reads
but no write coordination and no deduplication; two nodes recording the same migration still produce
two rows, and `FINAL` does not apply across shards.

**Store the registry in the application's SQL database instead.** Rejected. It makes ClickHouse
migration state depend on a second datastore's availability and transactional semantics, and it
breaks the case this package exists for — ClickHouse used without a Laravel-managed RDBMS.

**Detect the topology from `system.clusters` at runtime.** Rejected. The package cannot tell which
of several configured clusters the registry belongs to, and guessing wrong is exactly the
split-brain in D5. Explicit configuration makes the operator's intent inspectable.

**`select_sequential_consistency` alone, without `SYSTEM SYNC REPLICA`.** Rejected by measurement —
see Evidence. It was the original design, and it is wrong. Carrying it *alongside* the sync was
rejected too: a setting that ClickHouse documents as inoperative under the running configuration
reads as a guarantee to whoever maintains this next.

**A node-local engine probe.** Rejected. It only sees the stray registry from the node that owns it,
so whether a topology mismatch is reported depends on which node the connection lands on — the exact
failure mode D1 exists to remove (D5).

## Revisit when

ClickHouse gains a first-class way for a replica to block until it has caught up as part of a read,
which would make the explicit `SYSTEM SYNC REPLICA` redundant — or when a use case appears for a
sharded registry, which would reopen D1.
