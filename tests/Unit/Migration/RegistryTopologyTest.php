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
        self::assertSame('', $topology->getOnClusterClause());
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
        self::assertSame('', $topology->getOnClusterClause());
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

        self::assertSame('ON CLUSTER `main`', $topology->getOnClusterClause());
        self::assertSame('ReplicatedReplacingMergeTree', $topology->getEngine());
    }

    public function testEngineDefinitionOnASingleNode(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
        );

        self::assertSame('ReplacingMergeTree', $topology->getEngineDefinition());
    }

    /**
     * The path is the prefix plus the database and the table, so nothing in it is left
     * for ClickHouse to expand.
     */
    public function testEngineDefinitionBuildsThePathFromThePrefix(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaPathPrefix: '/clickhouse/staging/tables',
        );

        self::assertSame(
            "ReplicatedReplacingMergeTree('/clickhouse/staging/tables/analytics/migrations', '{replica}')",
            $topology->getEngineDefinition(),
        );
    }

    public function testEngineDefinitionToleratesATrailingSlashInThePrefix(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaPathPrefix: '/clickhouse/tables/',
        );

        self::assertStringContainsString(
            "'/clickhouse/tables/analytics/migrations'",
            $topology->getEngineDefinition(),
        );
    }

    public function testEngineDefinitionKeepsTheReplicaNameUnexpanded(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaName: '{shard}-{replica}',
        );

        self::assertStringEndsWith("'{shard}-{replica}')", $topology->getEngineDefinition());
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
     * D1: a macro that resolves differently per host yields one registry per group of
     * hosts — the original bug. `{shard}` is only the obvious spelling of it, which is
     * why the rule rejects every macro rather than naming any.
     */
    #[DataProvider('provideMacroPrefixes')]
    public function testMacroInReplicaPathPrefixIsRejected(
        string $prefix,
    ): void {
        $this->expectException(ClickhouseConfigException::class);
        $this->expectExceptionMessageMatches('/macro/i');

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: 'main',
            isReplicated: true,
            replicaPathPrefix: $prefix,
        );
    }

    /**
     * Off a cluster the damage is the same — a replicated registry still has to be one
     * replication group — so the rule does not depend on `cluster` being set.
     */
    #[DataProvider('provideMacroPrefixes')]
    public function testMacroInReplicaPathPrefixIsRejectedWithoutCluster(
        string $prefix,
    ): void {
        $this->expectException(ClickhouseConfigException::class);

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaPathPrefix: $prefix,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMacroPrefixes(): array
    {
        return [
            'shard' => ['/clickhouse/tables/{shard}'],
            'layer' => ['/clickhouse/{layer}/tables'],
            'custom' => ['/clickhouse/{shard_group}/tables'],
            'replica' => ['/clickhouse/tables/{replica}'],
            'opening brace alone' => ['/clickhouse/tables/{'],
        ];
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
    public function testUnsafeReplicaPathPrefixIsRejected(
        string $literal,
    ): void {
        $this->expectException(ClickhouseConfigException::class);

        new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
            replicaPathPrefix: $literal,
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
        self::assertSame('', $topology->getOnClusterClause());
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
                'replica_path_prefix' => '/clickhouse/staging/tables',
                'replica_name' => '{shard}-{replica}',
            ],
            'analytics',
        );

        self::assertSame('ON CLUSTER `main`', $topology->getOnClusterClause());
        self::assertTrue($topology->isReplicated());
        self::assertSame(
            "ReplicatedReplacingMergeTree('/clickhouse/staging/tables/analytics/migrations', '{shard}-{replica}')",
            $topology->getEngineDefinition(),
        );
    }

    /**
     * `env('CLICKHOUSE_MIGRATION_CLUSTER')` on an empty `.env` entry yields `''`,
     * which must not be treated as a cluster named "".
     */
    #[DataProvider('provideBlankClusterNames')]
    public function testFromConfigTreatsABlankClusterAsAbsent(
        string $cluster,
    ): void {
        $topology = RegistryTopology::fromConfig(
            [
                'table' => 'migrations',
                'cluster' => $cluster,
            ],
            'analytics',
        );

        self::assertSame('', $topology->getOnClusterClause());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideBlankClusterNames(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
        ];
    }

    /**
     * Padding is insignificant wherever a blank name is, so a quoted `.env` value names
     * the cluster it looks like instead of failing identifier validation on whitespace.
     */
    public function testFromConfigTrimsThePaddedClusterName(): void
    {
        $topology = RegistryTopology::fromConfig(
            [
                'table' => 'migrations',
                'cluster' => ' main ',
                'replicated' => true,
            ],
            'analytics',
        );

        self::assertSame('ON CLUSTER `main`', $topology->getOnClusterClause());
    }
}
