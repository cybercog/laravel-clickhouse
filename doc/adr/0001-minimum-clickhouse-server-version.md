# 1. Minimum supported ClickHouse server version

- Status: Accepted
- Date: 2026-07-27

## Context

The package has never declared which ClickHouse server versions it supports. Neither `README.md`,
`composer.json` nor CI said anything, and CI runs no ClickHouse service at all. The only version
signal in the repository was the image pinned in `compose.yml` — `clickhouse/clickhouse-server:21.9`
— which is a local development convenience, not a contract. It was nevertheless read as one.

That pin was also self-reinforcing by accident: `.docker/clickhouse/clickhouse-user-config.xml`
referenced a `test` profile that does not exist, so every image newer than the pin refused to start
(`Profile test was not found` → `CANNOT_LOAD_CONFIG`). The one version anybody could run locally
was the one already pinned.

The question stopped being academic with in-progress work on cluster-aware migrations, which makes
the migration registry readable and writable from any node of a replicated or sharded cluster. That
design leans on four server capabilities that became available at different times:

1. Typed query parameters in reads — `FROM {t:Identifier}`, `WHERE migration = {m:String}`.
2. Typed query parameters in `CREATE TABLE`, `INSERT` and `EXISTS TABLE`.
3. `INSERT … SETTINGS …` — so `insert_quorum` rides on the statement instead of a shared client.
4. `insert_quorum = 'auto'` — the server computes the majority, not the operator.

Capabilities 2–4 decide whether the registry write path can be fully parameterised or has to fall
back to hand-rolled escaping, which is exactly the injection surface the design removes.

## Decision

**The minimum supported ClickHouse server version is 22.8 LTS.**

This decision requires, and is not complete without:

- the supported range stated in `README.md`;
- a CI integration job pinned to `22.8-alpine`, so the floor is proven by a test rather than
  asserted in prose — the failure mode this ADR exists to prevent;
- `compose.yml` tracking a current release for development convenience, with a comment saying
  explicitly that the pin is *not* the contract.

The last item ships with this ADR; the first two are still outstanding.

## Evidence

The range was bisected rather than assumed. Every cell was measured against a running server —
21.9.6.24, 22.3.20.29, 22.8.21.38, 23.3.22.3, 23.8.16.16, 24.3.18.7, 24.5.8.10, 24.8.14.39,
25.8.28.1 — with columns collapsed where consecutive versions agree.

| Capability | 21.9 | 22.3 | **22.8 LTS** | 23.3 → 24.5 | 24.8 → 25.8 |
|---|---|---|---|---|---|
| Typed parameters in reads | ✓ | ✓ | ✓ | ✓ | ✓ |
| `CREATE TABLE {t:Identifier} (…)` | ✗ | ✓ | ✓ | ✓ | ✓ |
| `INSERT INTO {t:Identifier} …` | ✗ | ✓ | ✓ | ✓ | ✓ |
| `EXISTS TABLE {t:Identifier}` | ✗ | ✓ | ✓ | ✓ | ✓ |
| `INSERT INTO t (…) SETTINGS … VALUES (…)` | ✗ | ✗ | **✓** | ✓ | ✓ |
| `insert_quorum = 'auto'` | ✗ | ✗ | **✓** | ✓ | ✓ |
| `ENGINE = ReplicatedReplacingMergeTree('/path', '{replica}')` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `SELECT … FINAL` on `ReplacingMergeTree` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `SETTINGS select_sequential_consistency = 1` | ✓ | ✓ | ✓ | ✓ | ✓ |

There are exactly two cut points:

- **22.3** adds typed parameters to `CREATE` / `INSERT` / `EXISTS`.
- **22.8 LTS** adds `INSERT … SETTINGS` and `insert_quorum = 'auto'`.

Above 22.8 and through 25.8, every statement this package emits behaves identically. Nothing
between 22.8 and 24.8 buys anything.

Reproducing a single capability, for any tag:

```shell
docker run --rm -d --name chprobe clickhouse/clickhouse-server:22.8
until docker exec chprobe clickhouse-client -q "SELECT 1" >/dev/null 2>&1; do sleep 2; done

docker exec chprobe clickhouse-client -q \
    "CREATE TABLE t (m String, b UInt32) ENGINE = ReplacingMergeTree ORDER BY m"
docker exec chprobe clickhouse-client --param_t=t --param_m=x -q \
    "INSERT INTO {t:Identifier} (m, b) SETTINGS insert_quorum = 'auto' VALUES ({m:String}, 1)"

docker rm -f chprobe
```

Silence means success; an unsupported version answers with `SYNTAX_ERROR`.

## Consequences

**Positive**

- The registry write path is fully parameterised: table names and values reach the server as
  `param_*`, never as SQL text. No hand-rolled escaping on `INSERT`.
- `insert_quorum` defaults to `'auto'`. An operator cannot get the majority arithmetic wrong, and
  `select_sequential_consistency` cannot silently degrade to a no-op (which is what
  `insert_quorum = 0` does).
- No dedicated `ClickHouseDB\Client` instance for registry writes — settings ride on the statement,
  so they cannot leak into user migrations.
- One code path. No version detection, no branching grammar, no branch that only a live server can
  validate.

**Negative**

- 21.9 through 22.3 are excluded. Neither was ever declared or tested as supported, but somebody may
  be running one.
- The floor is only checked by CI. A contributor developing against a current release will not
  notice using a newer feature until the integration job fails.

**Neutral**

- 22.8 LTS reached end of life long ago; the floor documents a compatibility boundary, not a
  recommendation. Running a supported ClickHouse release remains the operator's business.
- `smi2/phpclickhouse` declares no server minimum of its own — `composer.json` requires only
  `php: ^8.0` — so the driver imposes no constraint here.

## Alternatives considered

**21.9 — keep the status quo.** Rejected. It was never a contract, only a pin. Supporting it means
losing typed parameters on the entire write path (`CREATE`, `INSERT`, `EXISTS` all reject them),
which reintroduces string escaping where the design specifically removes it, *and* moving
`insert_quorum` back onto a dedicated client with the majority computed by hand.

**22.3.** Rejected. Buys back parameterised writes but still lacks `INSERT … SETTINGS` and
`insert_quorum = 'auto'`, so the quorum machinery stays awkward for one non-LTS release.

**24.3 LTS or 24.8 LTS.** Rejected. Measurement shows nothing between 22.8 and 24.8 affects any
statement this package emits, so either would exclude users for no benefit. 24.8 was the initial
proposal precisely because only the two ends of the range had been measured; bisecting corrected it.

**Latest stable only.** Rejected. The package targets Laravel applications with long-lived
analytics infrastructure, where the database is upgraded on a slower cycle than the framework.

## Revisit when

A future feature requires a capability introduced after 22.8. At that point raise the floor to the
LTS that first provides it — and bisect the range before writing the number down.
