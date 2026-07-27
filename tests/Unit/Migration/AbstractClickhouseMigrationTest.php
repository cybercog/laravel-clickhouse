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
use ClickHouseDB\Query\Degeneration\Bindings;
use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;

final class AbstractClickhouseMigrationTest extends AbstractTestCase
{
    public function testDatabaseNameFallsBackToConfig(): void
    {
        config(['clickhouse.connection.options.database' => 'analytics']);

        self::assertSame('analytics', $this->migration()->getDatabaseName());
    }

    public function testClusterNameFallsBackToConfig(): void
    {
        config(['clickhouse.migrations.cluster' => 'main']);

        self::assertSame('main', $this->migration()->getClusterName());
    }

    public function testClusterNameIsNullWithoutConfig(): void
    {
        config(['clickhouse.migrations.cluster' => null]);

        self::assertNull($this->migration()->getClusterName());
    }

    public function testClusterNameTreatsEmptyStringAsAbsent(): void
    {
        config(['clickhouse.migrations.cluster' => '']);

        self::assertNull($this->migration()->getClusterName());
    }

    /**
     * D6: `{on_cluster}` is the portable primitive — the whole clause, not the name.
     */
    public function testOnClauseIsEmptyWithoutCluster(): void
    {
        config(['clickhouse.migrations.cluster' => null]);

        self::assertSame('', $this->migration()->onCluster());
    }

    public function testOnClauseIsQuotedWithCluster(): void
    {
        config(['clickhouse.migrations.cluster' => 'main']);

        self::assertSame('ON CLUSTER `main`', $this->migration()->onCluster());
    }

    public function testBindingsWithoutCluster(): void
    {
        config(
            [
                'clickhouse.connection.options.database' => 'analytics',
                'clickhouse.migrations.cluster' => null,
            ],
        );

        self::assertSame(
            [
                'database' => 'analytics',
                'cluster' => '',
                'on_cluster' => '',
            ],
            $this->migration()->getBindings(),
        );
    }

    public function testBindingsWithCluster(): void
    {
        config(
            [
                'clickhouse.connection.options.database' => 'analytics',
                'clickhouse.migrations.cluster' => 'main',
            ],
        );

        self::assertSame(
            [
                'database' => 'analytics',
                'cluster' => 'main',
                'on_cluster' => 'ON CLUSTER `main`',
            ],
            $this->migration()->getBindings(),
        );
    }

    /**
     * D6: a `null` binding is skipped by the driver, which emits the placeholder
     * verbatim into the SQL. No binding may ever be `null`.
     */
    public function testBindingsAreNeverNull(): void
    {
        config(['clickhouse.migrations.cluster' => null]);

        foreach ($this->migration()->getBindings() as $binding => $value) {
            self::assertNotNull($value, "Binding {$binding} must not be null");
        }
    }

    public function testExtraBindingsAreMerged(): void
    {
        config(['clickhouse.migrations.cluster' => 'main']);

        $bindings = $this->migration()->getBindings(['table' => 'events']);

        self::assertSame('events', $bindings['table']);
        self::assertSame('ON CLUSTER `main`', $bindings['on_cluster']);
    }

    public function testExtraBindingsOverrideDefaults(): void
    {
        config(['clickhouse.migrations.cluster' => 'main']);

        $bindings = $this->migration()->getBindings(['on_cluster' => '']);

        self::assertSame('', $bindings['on_cluster']);
    }

    /**
     * End to end through the driver's own substitution: what a migration author
     * writes must become valid SQL on both topologies.
     */
    public function testAuthoredSqlResolvesWithCluster(): void
    {
        config(
            [
                'clickhouse.connection.options.database' => 'analytics',
                'clickhouse.migrations.cluster' => 'main',
            ],
        );

        self::assertSame(
            'CREATE TABLE analytics.events ON CLUSTER `main` (id UInt32) ENGINE = MergeTree ORDER BY id',
            $this->resolve(
                'CREATE TABLE {database}.events {on_cluster} (id UInt32) ENGINE = MergeTree ORDER BY id',
            ),
        );
    }

    public function testAuthoredSqlResolvesWithoutCluster(): void
    {
        config(
            [
                'clickhouse.connection.options.database' => 'analytics',
                'clickhouse.migrations.cluster' => null,
            ],
        );

        self::assertSame(
            'CREATE TABLE analytics.events  (id UInt32) ENGINE = MergeTree ORDER BY id',
            $this->resolve(
                'CREATE TABLE {database}.events {on_cluster} (id UInt32) ENGINE = MergeTree ORDER BY id',
            ),
        );
    }

    /**
     * The reason `{cluster}` binds to `''` rather than `null`.
     */
    public function testClusterPlaceholderNeverLeaksIntoSql(): void
    {
        config(['clickhouse.migrations.cluster' => null]);

        self::assertStringNotContainsString(
            '{cluster}',
            $this->resolve('CREATE TABLE events ON CLUSTER {cluster} (id UInt32)'),
        );
    }

    private function resolve(
        string $sql,
    ): string {
        $bindings = new Bindings();
        $bindings->bindParams($this->migration()->getBindings());

        return $bindings->process($sql);
    }

    private function migration(): AbstractClickhouseMigration
    {
        return new class ($this->createMock(Client::class)) extends AbstractClickhouseMigration {
            public function up(): void {}
        };
    }
}
