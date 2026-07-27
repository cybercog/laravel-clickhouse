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

namespace Cog\Tests\Laravel\Clickhouse\Unit;

use ClickHouseDB\Client as ClickhouseClient;
use Cog\Laravel\Clickhouse\Exception\ClickhouseConfigException;
use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;
use Cog\Laravel\Clickhouse\Migration\Migrator;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;

final class ClickhouseServiceProviderTest extends AbstractTestCase
{
    /**
     * Zero behaviour change when no new config is set (§3).
     */
    public function testShippedConfigDefaultsToSingleNodeTopology(): void
    {
        self::assertSame('migrations', config('clickhouse.migrations.table'));
        self::assertNull(config('clickhouse.migrations.cluster'));
        self::assertFalse(config('clickhouse.migrations.replicated'));
        self::assertSame(
            '/clickhouse/tables',
            config('clickhouse.migrations.replica_path_prefix'),
        );
        self::assertSame('{replica}', config('clickhouse.migrations.replica_name'));
    }

    public function testShippedConfigDefaultsBothTimeouts(): void
    {
        self::assertSame(1, config('clickhouse.connection.options.timeout'));
        self::assertSame(180, config('clickhouse.migrations.timeout'));
    }

    /**
     * `connection.options.timeout` is `max_execution_time` on every request. At the
     * shipped value of 1 second an `ON CLUSTER` DDL — which waits for every host up
     * to `distributed_ddl_task_timeout` (180s) — cannot complete, so migrations get
     * a client of their own.
     */
    public function testMigrationClientCarriesTheMigrationTimeout(): void
    {
        $applicationClient = $this->app->get(ClickhouseClient::class);
        $migrationClient = $this->app->get(AbstractClickhouseMigration::CLIENT);

        self::assertNotSame($applicationClient, $migrationClient);
        self::assertSame(1, $applicationClient->getTimeout());
        self::assertSame(180, $migrationClient->getTimeout());
    }

    /**
     * A migration file returns an anonymous class it constructs itself, so nothing
     * can hand it a client — it has to reach for the right one on its own, whether
     * the migrator built it or not.
     */
    public function testMigrationBuildsItselfWithTheMigrationClient(): void
    {
        $migration = new class extends AbstractClickhouseMigration {
            public function up(): void {}
        };

        self::assertSame(
            $this->app->get(AbstractClickhouseMigration::CLIENT),
            $migration->getClickhouseClient(),
        );
    }

    public function testMigratorResolvesWithDefaultConfig(): void
    {
        self::assertInstanceOf(Migrator::class, $this->app->get(Migrator::class));
    }

    /**
     * D2: fail loudly at wiring time, not with diverging tables at runtime.
     */
    public function testClusterWithoutReplicatedFailsWhenResolvingMigrator(): void
    {
        config(
            [
                'clickhouse.migrations.cluster' => 'main',
                'clickhouse.migrations.replicated' => false,
            ],
        );

        $this->expectException(ClickhouseConfigException::class);

        $this->app->get(Migrator::class);
    }

    public function testInvalidMigrationTableNameFailsWhenResolvingMigrator(): void
    {
        config(['clickhouse.migrations.table' => 'migrations; DROP TABLE users']);

        $this->expectException(ClickhouseConfigException::class);

        $this->app->get(Migrator::class);
    }
}
