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
    /**
     * Container key of the client migrations run on.
     *
     * A migration file returns an anonymous class it constructs itself, so nothing can
     * hand it a client. Resolving this key rather than `ClickHouseDB\Client` is what
     * keeps a migration off the application-facing query timeout — see
     * `clickhouse.migrations.timeout`.
     */
    public const CLIENT = 'clickhouse.migration-client';

    protected Client $clickhouseClient;
    protected string $databaseName;

    public function __construct(
        ?Client $clickhouseClient = null,
        ?string $databaseName = null,
    ) {
        $this->clickhouseClient = $clickhouseClient ?? app(self::CLIENT);
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

    /**
     * The whole clause, not just the name — interpolated into the SQL rather than
     * bound, so that it collapses to nothing on a single-node deployment:
     *
     *     "CREATE TABLE events {$this->onCluster()} (id UInt32) ENGINE = MergeTree"
     *
     * A migration written against a `{cluster}` binding would emit a bare
     * `ON CLUSTER` wherever no cluster is configured.
     */
    public function onCluster(): string
    {
        $cluster = config('clickhouse.migrations.cluster');

        return Identifier::onClusterClause(
            $cluster === null ? null : (string) $cluster,
        );
    }
}
