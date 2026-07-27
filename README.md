# Laravel ClickHouse

![laravel-clickhouse](https://user-images.githubusercontent.com/1849174/158847081-af69213c-7f66-40e8-be0b-f127f128c653.png)

<p align="center">
<a href="https://discord.gg/YcZDjNTzSa"><img src="https://img.shields.io/static/v1?logo=discord&label=&message=Discord&color=36393f&style=flat-square" alt="Discord"></a>
<a href="https://github.com/cybercog/laravel-clickhouse/releases"><img src="https://img.shields.io/github/release/cybercog/laravel-clickhouse.svg?style=flat-square" alt="Releases"></a>
<a href="https://github.com/cybercog/laravel-clickhouse/blob/master/LICENSE"><img src="https://img.shields.io/github/license/cybercog/laravel-clickhouse.svg?style=flat-square" alt="License"></a>
</p>

## Introduction

Laravel ClickHouse database integration.
This package includes generation and execution of the ClickHouse database migrations in the Laravel application.

## Features

- [smi2/phpClickHouse] client integration
- Migration creation
- Migration execution

## Requirements

| Requirement       | Version         |
|-------------------|-----------------|
| PHP               | 8.2+            |
| Laravel           | 11, 12, 13      |
| ClickHouse server | 22.8 LTS and up |

Only [ClickHouse LTS releases] are supported. Non-LTS releases are not tested against and may work
by coincidence, but nothing in this package is designed or verified around them.

The reasoning behind the minimum server version is recorded in
[ADR 0001](doc/adr/0001-minimum-clickhouse-server-version.md).

## Installation

Pull in the package through [Composer](https://getcomposer.org/).

```shell
composer require cybercog/laravel-clickhouse
```

## Setup

Add environment variables in `.env` file.

```dotenv
CLICKHOUSE_HOST=localhost
CLICKHOUSE_PORT=8123
CLICKHOUSE_USER=default
CLICKHOUSE_PASSWORD=
CLICKHOUSE_DATABASE=default
```

### Configuration customization

Publish ClickHouse configuration.

```shell
php artisan vendor:publish --provider="Cog\Laravel\Clickhouse\ClickhouseServiceProvider" --tag=config
```

Edit `config/clickhouse.php` file.

## Usage

### ClickHouse client

You can use a singleton object [smi2/phpClickHouse] to query ClickHouse:

```php
app(\ClickHouseDB\Client::class)->select(
    /* Query */
);

app(\ClickHouseDB\Client::class)->write(
    /* Query */
);
```

### ClickHouse database migration

#### Create migration

```shell
php artisan make:clickhouse-migration create_example_table
```

> New migration will be created in `database/clickhouse-migrations` directory.

#### Run migrations

```shell
php artisan clickhouse:migrate
```

To remove the interactive question during production migrations, you can use `--force` option.

```shell
php artisan clickhouse:migrate --force
```

##### Step

You can specify how many files need to be applied:

```shell
php artisan clickhouse:migrate --step=1
```

> Value `0` is default — all files

#### Rollback migrations

> Rolling back migrations is intentionally unavailable. Migrations should go only forward.

#### Running on a cluster

By default the migration registry is a local `ReplacingMergeTree` — correct for a single node, and
wrong behind a load balancer, where each node would keep its own view of which migrations have been
applied.

Cluster mode requires ClickHouse Keeper (or ZooKeeper) and a `remote_servers` entry naming the
cluster. Enable it with:

```dotenv
CLICKHOUSE_MIGRATION_CLUSTER=main
CLICKHOUSE_MIGRATION_REPLICATED=true
```

The registry then becomes a `ReplicatedReplacingMergeTree` created `ON CLUSTER`, and
`clickhouse:migrate` is safe to run from any node.

Two rules the configuration cannot infer for you:

- **`CLICKHOUSE_MIGRATION_REPLICA_NAME` must be unique cluster-wide.** It defaults to the
  `{replica}` macro. If `{replica}` repeats across shards in your `macros.xml`, set it to
  `{shard}-{replica}` — two nodes claiming one replica fail with `REPLICA_ALREADY_EXISTS`.
- **`CLICKHOUSE_MIGRATION_REPLICA_PATH` must not contain `{shard}`.** The registry is replicated,
  never sharded: a `{shard}` in the path gives each shard its own history. This one is rejected for
  you.

Migrations themselves opt into `ON CLUSTER` with `onCluster()`, which returns the whole clause and
collapses to nothing where no cluster is configured, so one file serves both deployments:

```php
$this->clickhouseClient->write(
    "CREATE TABLE events {$this->onCluster()} (id UInt32) ENGINE = MergeTree ORDER BY id",
);
```

Converting a registry created before cluster mode was enabled is a manual step — the recipe is in
[UPGRADE.md](UPGRADE.md). The design is recorded in
[ADR 0002](doc/adr/0002-cluster-aware-migration-registry.md).

## Changelog

Detailed changes for each release are documented in the [CHANGELOG.md](https://github.com/cybercog/laravel-clickhouse/blob/master/CHANGELOG.md).

## Upgrading

Breaking changes and the steps each one requires are documented in the [UPGRADE.md](https://github.com/cybercog/laravel-clickhouse/blob/master/UPGRADE.md).

## License

- `Laravel ClickHouse` package is open-sourced software licensed under the [MIT license](LICENSE) by [Anton Komarev].

## 🌟 Stargazers over time

[![Stargazers over time](https://chart.yhype.me/github/repository-star/v1/470754925.svg)](https://yhype.me?utm_source=github&utm_medium=cybercog-laravel-clickhouse&utm_content=chart-repository-star-cumulative)
## About CyberCog

[CyberCog] is a Social Unity of enthusiasts. Research the best solutions in product & software development is our passion.

- [Follow us on Twitter]

<a href="https://cybercog.su"><img src="https://cloud.githubusercontent.com/assets/1849174/18418932/e9edb390-7860-11e6-8a43-aa3fad524664.png" alt="CyberCog"></a>

[Anton Komarev]: https://komarev.com
[ClickHouse LTS releases]: https://clickhouse.com/docs/faq/operations/production
[CyberCog]: https://cybercog.su
[Follow us on Twitter]: https://twitter.com/cybercog
[smi2/phpClickHouse]: https://github.com/smi2/phpClickHouse#start
