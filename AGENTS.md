# AGENTS.md

This file provides guidance to LLM Agents when working with code in this repository.

## Project Overview

Laravel ClickHouse (`cybercog/laravel-clickhouse`) — a Laravel package providing ClickHouse database migrations and client integration via `smi2/phpclickhouse`. Supports Laravel 11/12/13 on PHP 8.2+.

## Commands

All commands run through Docker. Services: `php82`, `php83`, `php84`, `php85`.

```bash
# Build and start containers
docker compose up -d --build

# Install dependencies
docker compose exec php85 composer install

# Run the Unit suite (the default — no server needed)
docker compose exec php85 vendor/bin/phpunit

# Run the Integration suite (needs the `clickhouse` service)
docker compose exec php85 vendor/bin/phpunit --testsuite Integration

# Run the Cluster suite (needs the compose.cluster.yml stack)
docker compose -f compose.yml -f compose.cluster.yml up -d
docker compose -f compose.yml -f compose.cluster.yml exec php85 vendor/bin/phpunit --testsuite Cluster

# Run a single test file
docker compose exec php85 vendor/bin/phpunit tests/Unit/Factory/ClickhouseClientFactoryTest.php

# Run a single test method
docker compose exec php85 vendor/bin/phpunit --filter test_method_name

# Pin the server version (22.8 LTS is the supported floor)
CLICKHOUSE_IMAGE=clickhouse/clickhouse-server:22.8-alpine docker compose up -d --renew-anon-volumes
```

## Architecture

### Namespace: `Cog\Laravel\Clickhouse\` → `src/`

**Entry point:** `ClickhouseServiceProvider` registers four singletons:
- `ClickHouseDB\Client` — configured ClickHouse client (via `ClickhouseClientFactory`)
- `AbstractClickhouseMigration::CLIENT` — the same client on the migration timeout
- `Migrator` — executes migrations, tracks state via `MigrationRepository`
- `MigrationCreator` — generates migration files from stub

**Migration system (forward-only, no rollback by design):**
- Migrations live in `database/clickhouse-migrations/` in the consuming app
- Migration files are anonymous classes extending `AbstractClickhouseMigration` with an `up()` method
- Tracking table uses ClickHouse's `ReplacingMergeTree` engine
- `MigrationRepository` manages the tracking table (migration name, batch, applied_at)
- `Migrator` loads unapplied files, executes them, records completion

**Artisan commands:**
- `make:clickhouse-migration {name}` — create a new migration file
- `clickhouse:migrate` — run pending migrations (`--force` for production, `--step=N` to limit)

### Configuration

Published to `config/clickhouse.php`. Connection settings read from env vars: `CLICKHOUSE_HOST`, `CLICKHOUSE_PORT`, `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, `CLICKHOUSE_DATABASE`, `CLICKHOUSE_QUERY_TIMEOUT`, `CLICKHOUSE_MIGRATION_TABLE`.

Two execution timeouts, because `options.timeout` is sent as `max_execution_time` on every request: `CLICKHOUSE_QUERY_TIMEOUT` (1s) for the application client bound to `ClickHouseDB\Client`, and `CLICKHOUSE_MIGRATION_TIMEOUT` (180s) for the client bound to `AbstractClickhouseMigration::CLIENT`, which migrations resolve for themselves.

## Testing

Tests use Orchestra Testbench. Connection defaults are in the `<php>` block of `phpunit.xml.dist`; they are `<env>` entries, so an exported variable wins over them.

Three suites, split by what infrastructure they need:

- `Unit` (`tests/Unit`) — no server. The default suite.
- `Integration` (`tests/Integration`) — the single `clickhouse` service.
- `Cluster` (`tests/Cluster`) — the `compose.cluster.yml` stack (1 Keeper, 2 shards × 2 replicas).

Both server suites skip themselves when nothing answers, so a bare run without Docker is green but proves nothing — CI passes `--fail-on-skipped` to catch that.

`CLICKHOUSE_CLUSTER_NODES` takes `host` or `host:port` entries. Inside the compose network the hostnames suffice; from the host, name the published ports (8131–8134).

- `Cog\Tests\Laravel\Clickhouse\` → `tests/`
- Tests extend `AbstractTestCase` (which extends Orchestra Testbench's `TestCase`)

## CI Matrix

`.github/workflows/tests.yaml` has three jobs:

- `unit` — PHP 8.2–8.5 × Laravel 11/12/13 × prefer-lowest/prefer-stable, `--testsuite Unit`.
- `integration` — ClickHouse 22.8-alpine and 26.3-alpine, `--testsuite Integration`.
- `cluster` — the same two ClickHouse versions, `--testsuite Cluster`.

The server jobs start the repo's own compose services and drive them from the runner over published ports, so CI and local runs share one environment definition. Both ends of the supported range are covered because ADR 0002 records a consistency defect that only reproduces on 22.8.

## Code Conventions

- All PHP files use `declare(strict_types=1)`
- All files include the copyright header block
- PSR-4 autoloading: `src/` → `Cog\Laravel\Clickhouse\`, `tests/` → `Cog\Tests\Laravel\Clickhouse\`
