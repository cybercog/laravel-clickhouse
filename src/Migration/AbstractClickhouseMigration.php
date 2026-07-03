<?php

/*
 * This file is part of Laravel ClickHouse.
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
    protected Client $clickhouseClient;
    protected string $databaseName;
    protected ?string $clusterName;

    public function __construct(
        ?Client $clickhouseClient = null,
        ?string $databaseName = null,
        ?string $clusterName = null,
    ) {
        $this->clickhouseClient = $clickhouseClient ?? app(Client::class);
        $this->databaseName = $databaseName ?? config('clickhouse.connection.options.database');
        $this->clusterName = $clusterName ?? config('clickhouse.connection.cluster_name');
    }

    public function getClickhouseClient(): Client
    {
        return $this->clickhouseClient;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getClusterName(): ?string
    {
        return $this->clusterName;
    }

    /**
     * Get the bindings for the SQL query.
     *
     * @return array<string, mixed>
     */
    public function getBindings(array $extra = []): array
    {
        return array_merge([
            'cluster' => $this->clusterName,
            'database' => $this->databaseName,
        ], $extra);
    }
}
