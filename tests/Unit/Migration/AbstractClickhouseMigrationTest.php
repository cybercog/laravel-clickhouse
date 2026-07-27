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

namespace Cog\Tests\Laravel\Clickhouse\Unit\Migration;

use ClickHouseDB\Client;
use Cog\Laravel\Clickhouse\Exception\ClickhouseConfigException;
use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;

final class AbstractClickhouseMigrationTest extends AbstractTestCase
{
    public function testDatabaseNameFallsBackToConfig(): void
    {
        config(['clickhouse.connection.options.database' => 'analytics']);

        self::assertSame('analytics', $this->migration()->getDatabaseName());
    }

    public function testOnClusterIsEmptyWithoutCluster(): void
    {
        config(['clickhouse.migrations.cluster' => null]);

        self::assertSame('', $this->migration()->onCluster());
    }

    /**
     * `env('CLICKHOUSE_MIGRATION_CLUSTER')` on an empty `.env` entry yields `''`,
     * which must not be treated as a cluster named "".
     */
    public function testOnClusterTreatsEmptyStringAsAbsent(): void
    {
        config(['clickhouse.migrations.cluster' => '']);

        self::assertSame('', $this->migration()->onCluster());
    }

    public function testOnClusterIsQuotedWithCluster(): void
    {
        config(['clickhouse.migrations.cluster' => 'main']);

        self::assertSame('ON CLUSTER `main`', $this->migration()->onCluster());
    }

    /**
     * The clause is interpolated into the SQL, so the name is the only thing standing
     * between a config value and an injected statement.
     */
    public function testInvalidClusterNameIsRejected(): void
    {
        config(['clickhouse.migrations.cluster' => 'main`; DROP TABLE users']);

        $this->expectException(ClickhouseConfigException::class);

        $this->migration()->onCluster();
    }

    /**
     * What a migration author writes must be valid SQL on both topologies.
     */
    public function testAuthoredSqlIsValidOnBothTopologies(): void
    {
        config(['clickhouse.migrations.cluster' => 'main']);

        self::assertSame(
            'CREATE TABLE events ON CLUSTER `main` (id UInt32)',
            $this->authoredSql(),
        );

        config(['clickhouse.migrations.cluster' => null]);

        self::assertSame(
            'CREATE TABLE events  (id UInt32)',
            $this->authoredSql(),
        );
    }

    private function authoredSql(): string
    {
        $migration = $this->migration();

        return "CREATE TABLE events {$migration->onCluster()} (id UInt32)";
    }

    private function migration(): AbstractClickhouseMigration
    {
        return new class ($this->createMock(Client::class)) extends AbstractClickhouseMigration {
            public function up(): void {}
        };
    }
}
