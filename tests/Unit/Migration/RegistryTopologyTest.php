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

use Cog\Laravel\Clickhouse\Exception\ClickhouseConfigException;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RegistryTopologyTest extends AbstractTestCase
{
    public function testDefaultsReproduceSingleNodeBehaviour(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
        );

        self::assertSame('migrations', $topology->getTable());
        self::assertSame('analytics', $topology->getDatabase());
        self::assertNull($topology->getCluster());
        self::assertFalse($topology->isClustered());
        self::assertFalse($topology->isReplicated());
        self::assertSame('ReplacingMergeTree', $topology->getEngine());
    }

    public function testReplicatedTopologyUsesReplicatedEngine(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
        );

        self::assertTrue($topology->isReplicated());
        self::assertFalse($topology->isClustered());
        self::assertSame('ReplicatedReplacingMergeTree', $topology->getEngine());
    }

    public function testClusteredTopologyIsReplicated(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: 'main',
            isReplicated: true,
        );

        self::assertTrue($topology->isClustered());
        self::assertSame('main', $topology->getCluster());
        self::assertSame('ReplicatedReplacingMergeTree', $topology->getEngine());
    }

    /**
     * D3: the grammar substitutes `{database}` and `{table}` itself and leaves every
     * other brace token for ClickHouse to expand.
     */
    public function testReplicaPathSubstitutesDatabaseAndTableOnly(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaPath: '/clickhouse/tables/{database}/{table}',
        );

        self::assertSame(
            '/clickhouse/tables/analytics/migrations',
            $topology->getReplicaPath(),
        );
    }

    public function testReplicaPathKeepsUnknownMacrosIntact(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaPath: '/clickhouse/{layer}/tables/{database}/{table}',
        );

        self::assertSame(
            '/clickhouse/{layer}/tables/analytics/migrations',
            $topology->getReplicaPath(),
        );
    }

    public function testReplicaNameIsNotExpanded(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaName: '{shard}-{replica}',
        );

        self::assertSame('{shard}-{replica}', $topology->getReplicaName());
    }

    /**
     * D2: `ON CLUSTER` over a non-replicated engine creates S diverging tables.
     */
    public function testClusterWithoutReplicatedIsRejected(): void
    {
        $this->expectException(ClickhouseConfigException::class);
        $this->expectExceptionMessageMatches('/replicated/i');

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: 'main',
            isReplicated: false,
        );
    }

    /**
     * D1: a `{shard}` in the path yields one registry per shard — the original bug.
     */
    public function testShardMacroInReplicaPathIsRejectedForClusteredTopology(): void
    {
        $this->expectException(ClickhouseConfigException::class);
        $this->expectExceptionMessageMatches('/\{shard\}/');

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: 'main',
            isReplicated: true,
            replicaPath: '/clickhouse/tables/{shard}/{database}/{table}',
        );
    }

    public function testShardMacroInReplicaPathIsAllowedWithoutCluster(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaPath: '/clickhouse/tables/{shard}/{database}/{table}',
        );

        self::assertSame(
            '/clickhouse/tables/{shard}/analytics/migrations',
            $topology->getReplicaPath(),
        );
    }

    #[DataProvider('provideInvalidIdentifiers')]
    public function testInvalidTableIdentifierIsRejected(
        string $identifier,
    ): void {
        $this->expectException(ClickhouseConfigException::class);

        new RegistryTopology(
            table: $identifier,
            database: 'analytics',
        );
    }

    #[DataProvider('provideInvalidIdentifiers')]
    public function testInvalidDatabaseIdentifierIsRejected(
        string $identifier,
    ): void {
        $this->expectException(ClickhouseConfigException::class);

        new RegistryTopology(
            table: 'migrations',
            database: $identifier,
        );
    }

    #[DataProvider('provideInvalidIdentifiers')]
    public function testInvalidClusterIdentifierIsRejected(
        string $identifier,
    ): void {
        $this->expectException(ClickhouseConfigException::class);

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: $identifier,
            isReplicated: true,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidIdentifiers(): array
    {
        return [
            'empty' => [''],
            'backtick' => ['migr`ations'],
            'space' => ['migration table'],
            'semicolon' => ['migrations; DROP TABLE users'],
            'quote' => ["migrations'"],
            'backslash' => ['migrations\\'],
            'leading digit' => ['1migrations'],
            'dash' => ['migrations-registry'],
            'dot qualified' => ['analytics.migrations'],
            'brace' => ['migrations{shard}'],
        ];
    }

    /**
     * The replica path and name are interpolated into string literals: verified on
     * 24.8, ClickHouse does not expand macros passed through query parameters.
     */
    #[DataProvider('provideUnsafeLiterals')]
    public function testUnsafeReplicaPathIsRejected(
        string $literal,
    ): void {
        $this->expectException(ClickhouseConfigException::class);

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaPath: $literal,
        );
    }

    #[DataProvider('provideUnsafeLiterals')]
    public function testUnsafeReplicaNameIsRejected(
        string $literal,
    ): void {
        $this->expectException(ClickhouseConfigException::class);

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaName: $literal,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsafeLiterals(): array
    {
        return [
            'empty' => [''],
            'single quote' => ["/clickhouse/tables/x', 'evil"],
            'backslash' => ['/clickhouse/tables/x\\'],
            'newline' => ["/clickhouse/tables/x\n"],
        ];
    }

    public function testInsertQuorumDefaultsToAuto(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
        );

        self::assertSame('auto', $topology->getInsertQuorum());
    }

    public function testInsertQuorumAcceptsIntegers(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            insertQuorum: 2,
        );

        self::assertSame(2, $topology->getInsertQuorum());
    }

    #[DataProvider('provideInvalidInsertQuorums')]
    public function testInvalidInsertQuorumIsRejected(
        int|string $insertQuorum,
    ): void {
        $this->expectException(ClickhouseConfigException::class);

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            insertQuorum: $insertQuorum,
        );
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function provideInvalidInsertQuorums(): array
    {
        return [
            'negative' => [-1],
            'unknown keyword' => ['majority'],
            'empty' => [''],
            'injection' => ["1 SETTINGS allow_experimental = 1"],
        ];
    }

    public function testFromConfigWithoutNewKeysReproducesCurrentBehaviour(): void
    {
        $topology = RegistryTopology::fromConfig(
            [
                'table' => 'migrations',
                'path' => '/app/database/clickhouse-migrations',
            ],
            'analytics',
        );

        self::assertSame('migrations', $topology->getTable());
        self::assertSame('analytics', $topology->getDatabase());
        self::assertNull($topology->getCluster());
        self::assertFalse($topology->isReplicated());
        self::assertSame('ReplacingMergeTree', $topology->getEngine());
    }

    public function testFromConfigReadsFullTopology(): void
    {
        $topology = RegistryTopology::fromConfig(
            [
                'table' => 'migrations',
                'cluster' => 'main',
                'replicated' => true,
                'replica_path' => '/clickhouse/tables/{database}/{table}',
                'replica_name' => '{shard}-{replica}',
                'insert_quorum' => 2,
            ],
            'analytics',
        );

        self::assertSame('main', $topology->getCluster());
        self::assertTrue($topology->isReplicated());
        self::assertSame('/clickhouse/tables/analytics/migrations', $topology->getReplicaPath());
        self::assertSame('{shard}-{replica}', $topology->getReplicaName());
        self::assertSame(2, $topology->getInsertQuorum());
    }

    /**
     * `env('CLICKHOUSE_MIGRATION_REPLICATED', false)` only casts the literals
     * `true` / `false`, so string values must survive the trip.
     */
    public function testFromConfigCastsStringFlags(): void
    {
        $topology = RegistryTopology::fromConfig(
            [
                'table' => 'migrations',
                'replicated' => '1',
                'insert_quorum' => '3',
            ],
            'analytics',
        );

        self::assertTrue($topology->isReplicated());
        self::assertSame(3, $topology->getInsertQuorum());
    }

    /**
     * `env('CLICKHOUSE_MIGRATION_CLUSTER')` on an empty `.env` entry yields `''`,
     * which must not be treated as a cluster named "".
     */
    public function testFromConfigTreatsEmptyClusterAsAbsent(): void
    {
        $topology = RegistryTopology::fromConfig(
            [
                'table' => 'migrations',
                'cluster' => '',
            ],
            'analytics',
        );

        self::assertNull($topology->getCluster());
        self::assertFalse($topology->isClustered());
    }
}
