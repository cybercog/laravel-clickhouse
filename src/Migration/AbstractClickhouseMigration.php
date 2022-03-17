<?php

/*
 * This file is part of Laravel ClickHouse Migrations.
 *
 * (c) Anton Komarev <anton@komarev.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Cog\Laravel\Clickhouse\Migration;

use ClickHouseDB\Client;

use function config;

abstract class AbstractClickhouseMigration
{
    protected ?Client $clickhouseClient;

    protected string $databaseName;

    public function __construct(
        ?Client $clickhouseClient = null,
        ?string $databaseName = null
    ) {
        $this->clickhouseClient = $clickhouseClient ?? app('clickhouse');
        $this->databaseName = $databaseName ?? config('clickhouse.connection.options.database');
    }

    public function getClickhouseClient(): Client
    {
        return $this->clickhouseClient;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }
}
