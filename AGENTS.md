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

# Run all tests
docker compose exec php85 vendor/bin/phpunit

# Run a single test file
docker compose exec php85 vendor/bin/phpunit tests/Factory/ClickhouseClientFactoryTest.php

# Run a single test method
docker compose exec php85 vendor/bin/phpunit --filter test_method_name
```

## Architecture

### Namespace: `Cog\Laravel\Clickhouse\` → `src/`

**Entry point:** `ClickhouseServiceProvider` registers three singletons:
- `ClickHouseDB\Client` — configured ClickHouse client (via `ClickhouseClientFactory`)
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

Published to `config/clickhouse.php`. Connection settings read from env vars: `CLICKHOUSE_HOST`, `CLICKHOUSE_PORT`, `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, `CLICKHOUSE_DATABASE`, `CLICKHOUSE_MIGRATION_TABLE`.

## Testing

Tests use Orchestra Testbench. Test connection config for ClickHouse is in `phpunit.xml.dist`. The `clickhouse` Docker service must be running for integration tests.

- `Cog\Tests\Laravel\Clickhouse\` → `tests/`
- Tests extend `AbstractTestCase` (which extends Orchestra Testbench's `TestCase`)

## CI Matrix

GitHub Actions tests against: PHP 8.2–8.5 × Laravel 11/12/13 × prefer-lowest/prefer-stable.

## Code Conventions

- All PHP files use `declare(strict_types=1)`
- All files include the copyright header block
- PSR-4 autoloading: `src/` → `Cog\Laravel\Clickhouse\`, `tests/` → `Cog\Tests\Laravel\Clickhouse\`
