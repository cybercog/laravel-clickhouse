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
3. **Migrations only ever touch one node.** There was no way for a migration to say
   `ON CLUSTER`, and no way for it to learn the cluster's name.

The practical failure is unglamorous and total: a Laravel app behind a load balancer runs
`clickhouse:migrate`, the connection lands on node A, the registry on node A records the migration,
the next deploy lands on node B, and every migration runs again. Against `CREATE TABLE` that is
survivable; against `ALTER TABLE … ADD COLUMN` or an `INSERT`-shaped data migration it is not.

[ADR 0001](0001-minimum-clickhouse-server-version.md) records the minimum server version this design
depends on, and the measured capability matrix behind it.

## Decision

**The registry becomes topology-aware: it is described by explicit configuration, and every
statement it emits is derived from that description rather than hardcoded.**

Configuration under `clickhouse.migrations` gains `cluster`, `replicated`, `replica_path`,
`replica_name`, `insert_quorum` and `timeout`. The defaults reproduce today's single-node behaviour
exactly, so an existing installation sees no change.

The decision has nine parts. Each is a constraint that a smaller change would have violated.

### D1 — The registry is replicated, never sharded

Its ZooKeeper path deliberately carries no `{shard}` macro, so every node of the cluster joins one
replication group and sees the same rows. This is the opposite of the advice for data tables, and it
is the point: the registry is coordination state, not data. Sharding it would give each shard a
partial history — which is the bug being fixed, reintroduced with more machinery.

### D2 — `cluster` without `replicated` is a configuration error

`ON CLUSTER` over non-replicated tables creates N independent registries and swallows the
inconsistency. It is rejected at wiring time rather than at the first divergent read.

### D3 — SQL construction moves into a grammar object

`RegistryTopology` describes the deployment and validates it; `RegistryGrammar` turns it into
statements. Reads, the `INSERT` and the `EXISTS` probe are fully parameterised — identifiers and
values reach the server as `param_*` and are never interpolated.

Three values are exceptions, and only because the server forces them to be: `ON CLUSTER` accepts no
query parameter, and query parameters do **not** expand macros — sending `param_replica={replica}`
inserts seven literal characters, which on a real cluster produced `REPLICA_ALREADY_EXISTS` with two
nodes claiming one replica. The cluster name is validated by `RegistryTopology` as an identifier
(`/^[A-Za-z_][A-Za-z0-9_]*$/`) and backtick-quoted; the ZooKeeper path and replica name are rejected
if they contain a quote, a backslash or a newline, so neither can close the string literal it sits
in.

The DDL therefore carries **no bindings at all**: the driver's `Bindings::process()` runs a raw
`str_replace` over `{name}`, which would eat the `{replica}` macro before the server ever saw it.

### D4 — The create is unconditional and idempotent

`CREATE TABLE IF NOT EXISTS … ON CLUSTER` replaces the old `exists()`-then-create pair. `EXISTS
TABLE` only ever reflects the connected node, so gating a cluster-wide write on it is a race by
construction. It survives for reporting.

### D5 — Consistent reads take three mechanisms, not two

In replicated mode the `INSERT` carries `SETTINGS insert_quorum = 'auto'` and reads append
`SETTINGS select_sequential_consistency = 1`. Every registry read also uses `FINAL`.

That is necessary and **not sufficient**. `select_sequential_consistency` caps a read at the last
quorum-committed part; it does not wait for the connected replica to reach it. A replica that has
not fetched the part yet returns *fewer* rows, with no error — precisely the "second run re-applies
migrations" failure the setting looks like it prevents. So each `MigrationRepository` issues
`SYSTEM SYNC REPLICA` once, before its first read of the registry. `exists()` and `getEngine()` are
exempt: both answer questions about the connected node alone and must work before the registry
exists.

`insert_quorum` defaults to `'auto'` (majority) rather than `0`, because `0` turns
`select_sequential_consistency` into a silent no-op — a setting that looks like a guarantee and
provides none.

### D6 — Migration authors get `{on_cluster}`, not `{cluster}`

`AbstractClickhouseMigration::getBindings()` exposes `on_cluster`, which expands to either an empty
string or ``ON CLUSTER `name` ``. A raw cluster name would force every migration to write
`ON CLUSTER {cluster}` and break on single-node deployments, where the correct output is nothing at
all. `getClusterName()` and `onCluster()` remain available for authors who need the parts.

### D7 — A registry from a different topology is reported, not adopted

A registry created before cluster mode was enabled stays a plain `ReplacingMergeTree` on the node
that owns it, while `CREATE TABLE IF NOT EXISTS … ON CLUSTER` cheerfully creates *empty replicated*
tables everywhere else — the migration history survives on one node and is invisible from the rest.
`ensureEngineMatchesTopology()` compares `system.tables.engine` against the configured topology and
throws `ClickhouseRegistryEngineMismatchException` naming both engines and the conversion recipe.

### D8 — The migrator gets its own client and its own timeout

`connection.options.timeout` reaches the server as `max_execution_time` on *every* request, so an
application tuned to a 1-second cap cannot run an `ON CLUSTER` statement at all — that DDL waits on
`distributed_ddl_task_timeout`, 180 seconds by default. The migrator builds its own client from
`migrations.timeout` (default 180) and leaves the application-facing client exactly as configured.

### D9 — Unit tests are hermetic

`RegistryGrammar` is a pure function of `RegistryTopology`, so the whole topology matrix is asserted
without a server. Integration and cluster suites cover what only a server can answer.

### Outstanding

D1–D9 ship with this ADR. Three follow-ups do not, and the decision is not fully delivered until
they do: CI jobs running the integration and cluster suites against both 22.8 and the current LTS
(without them the D5 finding below is caught by hand, once); README coverage of the Keeper and macro
prerequisites, the unique-replica-name rule and the manual registry-conversion recipe from D7; and a
migration stub that demonstrates `{on_cluster}`.

## Evidence

Three suites, run against both ends of the supported range:

| Suite | ClickHouse 22.8.21.38 | ClickHouse 26.3.17.56 |
|---|---|---|
| `Unit` (no server) | 171 tests, 313 assertions | 171 tests, 313 assertions |
| `Integration` (single node) | 14 tests, 29 assertions | 14 tests, 29 assertions |
| `Cluster` (2 shards × 2 replicas + Keeper) | 9 tests, 41 assertions | 9 tests, 41 assertions |

`compose.cluster.yml` provides the cluster environment; the built-in `test_shard_localhost` cluster
is absent on current versions, so `ON CLUSTER` cannot be tested without a real Keeper.

Two findings came out of running it, and neither would have surfaced on a single node or on a
current release alone:

- **Query parameters do not expand macros.** `param_replica={replica}` produced
  `REPLICA_ALREADY_EXISTS` — two nodes claiming one replica (D3).
- **`select_sequential_consistency` does not wait.** On 22.8 a quorum `INSERT` on `s1r1` followed
  immediately by a sequential-consistency read on `s2r2` returns nothing, then returns the row a
  moment later. 26.3 usually wins the race, which hides the defect rather than fixing it (D5). This
  is why the cluster suite has to run on the declared floor and not only on the version a developer
  happens to have running.

## Consequences

**Positive**

- `clickhouse:migrate` is safe to run from any node of a cluster; a second run from a different node
  sees no pending migrations.
- `total()` and duplicate detection are correct on single nodes too — `FINAL` fixes a pre-existing
  bug unrelated to clustering.
- The registry write path is fully parameterised. No hand-rolled escaping on the `INSERT`.
- Migrations can be written once and deployed to both single-node and cluster installations, because
  `{on_cluster}` collapses to nothing where there is no cluster.
- A topology change is caught with a message that names the fix, instead of splitting the history in
  half silently.

**Negative**

- More configuration surface: five new keys where there were none. Every one defaults to today's
  behaviour, but they are five more things an operator can get wrong.
- Cluster mode couples a migrate run to the health of a majority of replicas. That is the intended
  trade-off — it is also a new way for a deploy to block. An operator who wants the old behaviour
  sets `insert_quorum = 0` and owns the consequence.
- Every registry read path now pays one `SYSTEM SYNC REPLICA` per run in replicated mode.
- Cluster coverage requires a five-container environment, which is slower than the rest of CI.

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
split-brain in D7. Explicit configuration makes the operator's intent inspectable.

**`select_sequential_consistency` alone, without `SYSTEM SYNC REPLICA`.** Rejected by measurement —
see Evidence. It was the original design, and it is wrong.

## Revisit when

ClickHouse gains a first-class way for a replica to block until it has caught up as part of a read,
which would make the explicit `SYSTEM SYNC REPLICA` redundant — or when a use case appears for a
sharded registry, which would reopen D1.
