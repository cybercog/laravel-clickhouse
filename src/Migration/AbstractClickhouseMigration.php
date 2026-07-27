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
        $this->clusterName = self::normaliseClusterName(
            $clusterName ?? config('clickhouse.migrations.cluster'),
        );
    }

    public function getClickhouseClient(): Client
    {
        return $this->clickhouseClient;
    }

    /**
     * Migrations run on the migrator's own client, which is not capped by the
     * application-facing query timeout.
     */
    public function setClickhouseClient(
        Client $clickhouseClient,
    ): void {
        $this->clickhouseClient = $clickhouseClient;
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
     * The whole clause, not just the name: a migration written against `{cluster}`
     * emits a literal `ON CLUSTER {cluster}` wherever no cluster is configured,
     * because the driver skips `null` bindings and leaves the placeholder in place.
     */
    public function onCluster(): string
    {
        if ($this->clusterName === null) {
            return '';
        }

        return 'ON CLUSTER ' . Identifier::quote($this->clusterName);
    }

    /**
     * @param array<string, mixed> $extraBindings
     * @return array<string, mixed>
     */
    public function getBindings(
        array $extraBindings = [],
    ): array {
        return array_merge(
            [
                'database' => $this->databaseName,
                'cluster' => $this->clusterName ?? '',
                'on_cluster' => $this->onCluster(),
            ],
            $extraBindings,
        );
    }

    private static function normaliseClusterName(
        ?string $clusterName,
    ): ?string {
        if ($clusterName === null || trim($clusterName) === '') {
            return null;
        }

        return Identifier::ensureValid($clusterName, 'cluster name');
    }
}
