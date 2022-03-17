# Laravel ClickHouse Migrations

<p align="center">
<a href="https://discord.gg/YcZDjNTzSa"><img src="https://img.shields.io/static/v1?logo=discord&label=&message=Discord&color=36393f&style=flat-square" alt="Discord"></a>
<a href="https://github.com/cybercog/laravel-clickhouse-migrations/releases"><img src="https://img.shields.io/github/release/cybercog/laravel-clickhouse-migrations.svg?style=flat-square" alt="Releases"></a>
<a href="https://github.com/cybercog/laravel-clickhouse-migrations/blob/master/LICENSE"><img src="https://img.shields.io/github/license/cybercog/laravel-clickhouse-migrations.svg?style=flat-square" alt="License"></a>
</p>

## Introduction

Laravel ClickHouse Migrations is database helper package.
It adds generation and execution of ClickHouse database migrations to the Laravel application.

## Installation

Pull in the package through [Composer](https://getcomposer.org/).

```shell
composer require cybercog/laravel-clickhouse-migrations
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
php artisan vendor:publish --provider="Cog\Laravel\ClickhouseMigrations\ClickhouseMigrationsServiceProvider" --tag=config
```

Edit `config/clickhouse.php` file.

## Usage

### Create migration

```shell
php artisan make:clickhouse-migration create_example_table
```

> New migration will be created in `database/clickhouse-migrations` directory.

### Run migrations

```shell
php artisan clickhouse-migrations:migrate
```

To remove the interactive question during production migrations, you can use `--force` option.

```shell
php artisan clickhouse-migrations:migrate --force
```

#### Step

You can specify how many files need to be applied:

```shell
php artisan clickhouse-migrations:migrate --step=1
```

> Value `0` is default — all files

### Rollback migrations

> Rolling back migrations is intentionally unavailable. Migrations should go only forward.

## Other

You can use a singleton object [smi2/phpClickHouse](https://github.com/smi2/phpClickHouse#start) to query ClickHouse (used in migrations):

```php
app('clickhouse')->select(
    /* Query */
);

app('clickhouse')->write(
    /* Query */
);
```

## Changelog

Detailed changes for each release are documented in the [CHANGELOG.md](https://github.com/cybercog/laravel-clickhouse-migrations/blob/master/CHANGELOG.md).

## License

- `Laravel ClickHouse Migrations` package is open-sourced software licensed under the [MIT license](LICENSE) by [Anton Komarev].

## 🌟 Stargazers over time

[![Stargazers over time](https://chart.yhype.me/github/repository-star/v1/R_kgDOHA8mbQ.svg)](https://yhype.me?utm_source=github&utm_medium=cybercog-laravel-clickhouse-migrations&utm_content=chart-repository-star-cumulative)

## About CyberCog

[CyberCog] is a Social Unity of enthusiasts. Research the best solutions in product & software development is our passion.

- [Follow us on Twitter](https://twitter.com/cybercog)

<a href="https://cybercog.su"><img src="https://cloud.githubusercontent.com/assets/1849174/18418932/e9edb390-7860-11e6-8a43-aa3fad524664.png" alt="CyberCog"></a>

[Anton Komarev]: https://komarev.com
[CyberCog]: https://cybercog.su
