# Upgrade Guide

## From 0.2 to 0.3

The migration registry became cluster-aware. Its configuration defaults reproduce the previous
single-node behaviour exactly, so **no configuration change is required** — but the release is not
fully backwards compatible. Read the two required actions below before upgrading.

### Required: ClickHouse 22.8 LTS or newer

The registry now uses typed query parameters (`{table:Identifier}`) in its reads, its `INSERT` and
its `EXISTS TABLE` probe, which the server rejects with `SYNTAX_ERROR` before 22.3, and
`INSERT … SETTINGS`, which arrived in 22.8. Earlier releases substituted identifiers client-side and
worked on 21.9, the version `compose.yml` used to pin.

The `CREATE TABLE` is the exception, because `ON CLUSTER` accepts no query parameter. Interpolating
that clause puts the engine definition in the query text as well, macros and all, and the driver
rewrites `{name}` in the text from the bindings — so the statement carries no bindings at all. The
cluster name, the ZooKeeper path and the replica name are validated and quoted instead.

Only LTS releases are supported. The reasoning, and the measured capability matrix behind the
number, are in [ADR 0001](doc/adr/0001-minimum-clickhouse-server-version.md).

### Required if you published the config: re-publish or add the new keys

`ServiceProvider::mergeConfigFrom()` merges only the top level of the `clickhouse` array, so a
published `config/clickhouse.php` replaces the package's `migrations` section wholesale. New keys
will silently not exist for you.

Nothing breaks — every new key falls back to a default that reproduces the old behaviour — but none
of the options below will have any effect until you add them:

```shell
php artisan vendor:publish --provider="Cog\Laravel\Clickhouse\ClickhouseServiceProvider" --tag=config --force
```

Or add them by hand to `config/clickhouse.php` under `migrations`:

```php
'timeout' => (int) env('CLICKHOUSE_MIGRATION_TIMEOUT', 180),
'cluster' => env('CLICKHOUSE_MIGRATION_CLUSTER'),
'replicated' => (bool) env('CLICKHOUSE_MIGRATION_REPLICATED', false),
'replica_path_prefix' => env('CLICKHOUSE_MIGRATION_REPLICA_PATH_PREFIX', '/clickhouse/tables'),
'replica_name' => env('CLICKHOUSE_MIGRATION_REPLICA_NAME', '{replica}'),
```

The application query timeout became an env var too, under `connection.options`, so that it can be
raised without publishing the config:

```php
'timeout' => (int) env('CLICKHOUSE_QUERY_TIMEOUT', 1),
```

### Behaviour changes

**Migrations run on their own client.** `connection.options.timeout` reaches ClickHouse as
`max_execution_time` on every request, so an application tuned to a short timeout could not run
`ON CLUSTER` DDL at all. The two timeouts are now separate settings: `CLICKHOUSE_QUERY_TIMEOUT`
(1 second, as before) for the application client, and `migrations.timeout` /
`CLICKHOUSE_MIGRATION_TIMEOUT` (180 seconds, matching `distributed_ddl_task_timeout`) for a second
client the container registers under `AbstractClickhouseMigration::CLIENT`. A migration resolves
that key when nothing hands it a client, so it gets the migration timeout whether the migrator
built it or not.

The practical consequence: inside a migration, `getClickhouseClient()` returns that client and not
the `ClickHouseDB\Client` singleton. If you mutate the singleton at runtime — for example
`app(Client::class)->settings()->set(…)` from a service provider — those settings no longer reach
migrations. Set them on the statement instead.

**Registry reads use `FINAL`.** `ReplacingMergeTree` returns pre-merge duplicates, so `total()`
could over-count and the same migration could appear twice in `all()`. Both are now correct. If you
read those numbers from anywhere, expect them to change on installations that had duplicates.

**A registry built for a different topology is rejected.** `ensureTableExists()` compares
`system.tables.engine` against the configured topology and throws
`ClickhouseRegistryEngineMismatchException` on a mismatch, instead of letting
`CREATE TABLE IF NOT EXISTS … ON CLUSTER` create empty replicated tables on every other node and
strand the migration history on one of them.

A registry created by 0.2 reports `ReplacingMergeTree`, which is what the default topology expects,
so a standard upgrade passes this check. It only fires if you created the registry by hand on a
different engine, or if you enable `replicated` on an installation that already has a plain one —
see *Enabling cluster mode* below.

### API changes

`MigrationRepository::__construct()` takes a `RegistryTopology` instead of the registry table name:

```php
// 0.2
new MigrationRepository($client, config('clickhouse.migrations.table'));

// 0.3
new MigrationRepository(
    $client,
    RegistryTopology::fromConfig(
        config('clickhouse.migrations'),
        config('clickhouse.connection.options.database'),
    ),
);
```

Neither class is bound in the container — the `Migrator` builds both — so this only affects code
that constructed the repository directly.

`MigrationRepository::latest()` is deprecated and will be removed in the next major release. It
returns the applied migrations ordered by batch and name descending — the shape Laravel's own
repository uses to feed a rollback, which this package does not have by design — and nothing in the
package has ever called it. Switch to `all()`, which returns the same set.

`AbstractClickhouseMigration` gained one method, `onCluster()`. Nothing was removed.

### Optional: enabling cluster mode

Only relevant if you run ClickHouse as a cluster. It requires ClickHouse Keeper (or ZooKeeper) and
a `remote_servers` entry.

```dotenv
CLICKHOUSE_MIGRATION_CLUSTER=main
CLICKHOUSE_MIGRATION_REPLICATED=true
```

`replicated` is mandatory whenever `cluster` is set — an `ON CLUSTER` registry on a non-replicated
engine is one independent table per shard, and they diverge from the first migration onwards. The
package rejects that combination at wiring time rather than at the first divergent read.

One rule the configuration cannot infer for you: **`replica_name` must be unique cluster-wide.** If
`{replica}` repeats across shards in your macros, use `{shard}-{replica}`. Two nodes claiming one
replica fail with `REPLICA_ALREADY_EXISTS`.

The replica path is `replica_path_prefix` followed by your database and table name. Change the
prefix only to keep several installations apart in a shared Keeper, and spell the value out — a
macro in the prefix *is* rejected, because one that resolves differently per host gives each group
of hosts its own view of which migrations have been applied.

**Writing migrations that work on both topologies.** `onCluster()` returns the whole clause, so it
collapses to nothing where no cluster is configured. Interpolate it — do not pass it as a binding:

```php
$this->clickhouseClient->write(
    "CREATE TABLE events {$this->onCluster()} (id UInt32) ENGINE = MergeTree ORDER BY id",
);
```

**Converting an existing registry.** A registry created before cluster mode was enabled is a plain
`ReplacingMergeTree` and must be converted by hand, on the node that owns it, before enabling the
new settings:

```sql
CREATE TABLE migrations_replicated (
    migration String,
    batch UInt32,
    applied_at DateTime DEFAULT now()
)
ENGINE = ReplicatedReplacingMergeTree('/clickhouse/tables/analytics/migrations', '{replica}')
ORDER BY migration;

INSERT INTO migrations_replicated SELECT * FROM migrations FINAL;

RENAME TABLE migrations TO migrations_local_backup, migrations_replicated TO migrations;
```

Write the ZooKeeper path out in full, with your database and table name in place of
`analytics`/`migrations`: the package appends both to `replica_path_prefix` itself, so the path here
has to match what it produces. Leave `{replica}` alone — that one is a macro the server expands per
node.

Then set `CLICKHOUSE_MIGRATION_REPLICATED=true` and `CLICKHOUSE_MIGRATION_CLUSTER`, and run
`php artisan clickhouse:migrate` — the `CREATE TABLE IF NOT EXISTS … ON CLUSTER` will create the
replicas on the remaining nodes, which then fetch the history from this one. Drop
`migrations_local_backup` once you have verified `SELECT count() FROM migrations FINAL` on every
node.

The design is recorded in [ADR 0002](doc/adr/0002-cluster-aware-migration-registry.md).
