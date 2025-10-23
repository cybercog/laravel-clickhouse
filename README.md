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

### ClickHouse settings

You can configure ClickHouse session settings in the configuration file. These settings will be applied to all queries made through the client.

For example, to enable nested JSON storage using the experimental Object type:

```php
'connection' => [
    // ... other connection settings
    'settings' => [
        'allow_experimental_object_type' => 1,
    ],
],
```

Common settings you might want to configure:
- `allow_experimental_object_type` - Enable JSON/Object type for nested data structures
- `max_execution_time` - Maximum query execution time in seconds
- `max_memory_usage` - Maximum memory usage for query execution

See [ClickHouse settings documentation](https://clickhouse.com/docs/en/operations/settings/settings) for all available settings.

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

## Changelog

Detailed changes for each release are documented in the [CHANGELOG.md](https://github.com/cybercog/laravel-clickhouse/blob/master/CHANGELOG.md).

## License

- `Laravel ClickHouse` package is open-sourced software licensed under the [MIT license](LICENSE) by [Anton Komarev].

## 🌟 Stargazers over time

[![Stargazers over time](https://chart.yhype.me/github/repository-star/v1/470754925.svg)](https://yhype.me?utm_source=github&utm_medium=cybercog-laravel-clickhouse&utm_content=chart-repository-star-cumulative)
## About CyberCog

[CyberCog] is a Social Unity of enthusiasts. Research the best solutions in product & software development is our passion.

- [Follow us on Twitter]

<a href="https://cybercog.su"><img src="https://cloud.githubusercontent.com/assets/1849174/18418932/e9edb390-7860-11e6-8a43-aa3fad524664.png" alt="CyberCog"></a>

[Anton Komarev]: https://komarev.com
[CyberCog]: https://cybercog.su
[Follow us on Twitter]: https://twitter.com/cybercog
[smi2/phpClickHouse]: https://github.com/smi2/phpClickHouse#start
