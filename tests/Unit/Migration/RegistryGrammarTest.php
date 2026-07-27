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

use ClickHouseDB\Query\Degeneration\Bindings;
use Cog\Laravel\Clickhouse\Migration\RegistryGrammar;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RegistryGrammarTest extends AbstractTestCase
{
    private const MIGRATION_NAME = '2026_07_26_120000_create_events_table';

    // ------------------------------------------------------------------
    // CREATE TABLE
    // ------------------------------------------------------------------

    public function testCreateTableOnSingleNode(): void
    {
        $statement = $this->grammar($this->singleNodeTopology())->createTable();

        self::assertSame(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS `migrations` (
                    migration String,
                    batch UInt32,
                    applied_at DateTime DEFAULT now()
                )
                ENGINE = ReplacingMergeTree
                ORDER BY migration
                SQL,
            $statement->sql,
        );
    }

    public function testCreateTableWhenReplicatedWithoutCluster(): void
    {
        $statement = $this->grammar($this->replicatedTopology())->createTable();

        self::assertSame(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS `migrations` (
                    migration String,
                    batch UInt32,
                    applied_at DateTime DEFAULT now()
                )
                ENGINE = ReplicatedReplacingMergeTree('/clickhouse/tables/analytics/migrations', '{replica}')
                ORDER BY migration
                SQL,
            $statement->sql,
        );
    }

    /**
     * D1: no `{shard}` in the path, so every host of every shard joins one
     * replication group. Verified against a 2x2 cluster on 24.8.
     */
    public function testCreateTableWhenClustered(): void
    {
        $statement = $this->grammar($this->clusteredTopology())->createTable();

        self::assertSame(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS `migrations` ON CLUSTER `main` (
                    migration String,
                    batch UInt32,
                    applied_at DateTime DEFAULT now()
                )
                ENGINE = ReplicatedReplacingMergeTree('/clickhouse/tables/analytics/migrations', '{replica}')
                ORDER BY migration
                SQL,
            $statement->sql,
        );
    }

    public function testCreateTableRespectsCustomReplicaName(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: 'main',
            isReplicated: true,
            replicaName: '{shard}-{replica}',
        );

        self::assertStringContainsString(
            "ReplicatedReplacingMergeTree('/clickhouse/tables/analytics/migrations', '{shard}-{replica}')",
            $this->grammar($topology)->createTable()->sql,
        );
    }

    /**
     * D3: DDL carries no bindings at all — the driver's `{name}` substitution is a
     * raw str_replace and would eat the `{replica}` macro.
     */
    #[DataProvider('provideTopologies')]
    public function testCreateTablePassesNoBindings(
        string $topologyName,
    ): void {
        $statement = $this->grammar($this->topology($topologyName))->createTable();

        self::assertSame([], $statement->bindings);
    }

    // ------------------------------------------------------------------
    // INSERT
    // ------------------------------------------------------------------

    public function testInsertMigrationOnSingleNode(): void
    {
        $statement = $this->grammar($this->singleNodeTopology())
            ->insertMigration(self::MIGRATION_NAME, 3);

        self::assertSame(
            <<<'SQL'
                INSERT INTO {table:Identifier} (migration, batch)
                VALUES ({migration:String}, {batch:UInt32})
                SQL,
            $statement->sql,
        );
        self::assertSame(
            [
                'table' => 'migrations',
                'migration' => self::MIGRATION_NAME,
                'batch' => 3,
            ],
            $statement->bindings,
        );
    }

    /**
     * D5: quorum comes from the statement, not from a dedicated `Client`.
     * `INSERT ... SETTINGS` parses from 24.8 onwards (rejected on 21.9).
     */
    public function testInsertMigrationWhenReplicatedCarriesQuorum(): void
    {
        $statement = $this->grammar($this->clusteredTopology())
            ->insertMigration(self::MIGRATION_NAME, 3);

        self::assertSame(
            <<<'SQL'
                INSERT INTO {table:Identifier} (migration, batch)
                SETTINGS insert_quorum = 'auto'
                VALUES ({migration:String}, {batch:UInt32})
                SQL,
            $statement->sql,
        );
    }

    public function testInsertMigrationWithNumericQuorum(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: 'main',
            isReplicated: true,
            insertQuorum: 2,
        );

        self::assertStringContainsString(
            'SETTINGS insert_quorum = 2',
            $this->grammar($topology)->insertMigration(self::MIGRATION_NAME, 3)->sql,
        );
    }

    public function testInsertMigrationOmitsDisabledQuorum(): void
    {
        $topology = new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: 'main',
            isReplicated: true,
            insertQuorum: 0,
        );

        self::assertStringNotContainsString(
            'insert_quorum',
            $this->grammar($topology)->insertMigration(self::MIGRATION_NAME, 3)->sql,
        );
    }

    public function testInsertMigrationNeverInterpolatesTheMigrationName(): void
    {
        $statement = $this->grammar($this->singleNodeTopology())
            ->insertMigration("evil'); DROP TABLE users; --", 1);

        self::assertStringNotContainsString('DROP TABLE', $statement->sql);
        self::assertSame("evil'); DROP TABLE users; --", $statement->bindings['migration']);
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function testSelectAllMigrationsOnSingleNode(): void
    {
        $statement = $this->grammar($this->singleNodeTopology())->selectAllMigrations();

        self::assertSame(
            <<<'SQL'
                SELECT migration
                FROM {table:Identifier}
                FINAL
                SQL,
            $statement->sql,
        );
        self::assertSame(['table' => 'migrations'], $statement->bindings);
    }

    /**
     * D5: without the quorum/consistency pair a second migrate run against a lagging
     * replica re-applies migrations.
     */
    public function testSelectAllMigrationsWhenReplicated(): void
    {
        $statement = $this->grammar($this->clusteredTopology())->selectAllMigrations();

        self::assertSame(
            <<<'SQL'
                SELECT migration
                FROM {table:Identifier}
                FINAL
                SETTINGS select_sequential_consistency = 1
                SQL,
            $statement->sql,
        );
    }

    public function testSelectLatestMigrations(): void
    {
        $statement = $this->grammar($this->singleNodeTopology())->selectLatestMigrations();

        self::assertSame(
            <<<'SQL'
                SELECT migration
                FROM {table:Identifier}
                FINAL
                ORDER BY batch DESC, migration DESC
                SQL,
            $statement->sql,
        );
    }

    public function testSelectLastBatchNumber(): void
    {
        $statement = $this->grammar($this->singleNodeTopology())->selectLastBatchNumber();

        self::assertSame(
            <<<'SQL'
                SELECT MAX(batch) AS batch
                FROM {table:Identifier}
                FINAL
                SQL,
            $statement->sql,
        );
    }

    /**
     * D5: `total()` reports pre-merge duplicates without `FINAL` — a pre-existing
     * bug on single nodes too.
     */
    public function testSelectTotal(): void
    {
        $statement = $this->grammar($this->singleNodeTopology())->selectTotal();

        self::assertSame(
            <<<'SQL'
                SELECT COUNT(*) AS count
                FROM {table:Identifier}
                FINAL
                SQL,
            $statement->sql,
        );
    }

    public function testSelectMigration(): void
    {
        $statement = $this->grammar($this->singleNodeTopology())
            ->selectMigration(self::MIGRATION_NAME);

        self::assertSame(
            <<<'SQL'
                SELECT *
                FROM {table:Identifier}
                FINAL
                WHERE migration = {migration:String}
                LIMIT 1
                SQL,
            $statement->sql,
        );
        self::assertSame(
            [
                'table' => 'migrations',
                'migration' => self::MIGRATION_NAME,
            ],
            $statement->bindings,
        );
    }

    public function testSelectMigrationKeepsSettingsClauseLast(): void
    {
        $statement = $this->grammar($this->clusteredTopology())
            ->selectMigration(self::MIGRATION_NAME);

        self::assertSame(
            <<<'SQL'
                SELECT *
                FROM {table:Identifier}
                FINAL
                WHERE migration = {migration:String}
                LIMIT 1
                SETTINGS select_sequential_consistency = 1
                SQL,
            $statement->sql,
        );
    }

    /**
     * D4: `EXISTS TABLE` only reflects the connected node, so it stays available for
     * reporting but never gates the write path. `FINAL` is meaningless here.
     */
    public function testExistsTable(): void
    {
        $statement = $this->grammar($this->clusteredTopology())->existsTable();

        self::assertSame('EXISTS TABLE {table:Identifier}', $statement->sql);
        self::assertSame(['table' => 'migrations'], $statement->bindings);
    }

    /**
     * D5: sequential consistency caps a read at the quorum-committed point, it never
     * waits for the connected replica to reach it. Draining the queue is what makes a
     * write acknowledged elsewhere visible here.
     */
    public function testSyncReplica(): void
    {
        $statement = $this->grammar($this->clusteredTopology())->syncReplica();

        self::assertSame('SYSTEM SYNC REPLICA {table:Identifier}', $statement->sql);
        self::assertSame(['table' => 'migrations'], $statement->bindings);
    }

    /**
     * Detects a registry left over from a previous topology (see MigratorTest).
     */
    public function testSelectTableEngine(): void
    {
        $statement = $this->grammar($this->clusteredTopology())->selectTableEngine();

        self::assertSame(
            <<<'SQL'
                SELECT engine
                FROM system.tables
                WHERE database = {database:String}
                  AND name = {table:String}
                SQL,
            $statement->sql,
        );
        self::assertSame(
            [
                'database' => 'analytics',
                'table' => 'migrations',
            ],
            $statement->bindings,
        );
    }

    // ------------------------------------------------------------------
    // Cross-cutting guarantees
    // ------------------------------------------------------------------

    /**
     * D3: no identifier and no value is ever interpolated into a read statement —
     * the server resolves them from `param_*`.
     */
    #[DataProvider('provideReadStatements')]
    public function testReadStatementsInterpolateNothing(
        string $topologyName,
        string $method,
    ): void {
        $grammar = $this->grammar($this->topology($topologyName));

        $statement = $method === 'selectMigration'
            ? $grammar->selectMigration(self::MIGRATION_NAME)
            : $grammar->{$method}();

        self::assertStringNotContainsString('`migrations`', $statement->sql);
        self::assertStringNotContainsString('analytics', $statement->sql);
        self::assertStringNotContainsString(self::MIGRATION_NAME, $statement->sql);
        self::assertStringContainsString('{table:', $statement->sql);
    }

    /**
     * The driver runs `Bindings::process()` over every statement before sending it.
     * Both of its passes (`{name}` and `:name`) must leave our SQL untouched,
     * otherwise ClickHouse macros or typed placeholders get mangled.
     */
    #[DataProvider('provideAllStatements')]
    public function testDriverBindingSubstitutionDoesNotAlterStatements(
        string $topologyName,
        string $method,
    ): void {
        $grammar = $this->grammar($this->topology($topologyName));

        $statement = match ($method) {
            'selectMigration' => $grammar->selectMigration(self::MIGRATION_NAME),
            'insertMigration' => $grammar->insertMigration(self::MIGRATION_NAME, 3),
            default => $grammar->{$method}(),
        };

        $bindings = new Bindings();
        $bindings->bindParams($statement->bindings);

        self::assertSame($statement->sql, $bindings->process($statement->sql));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTopologies(): array
    {
        return [
            'single node' => ['single'],
            'replicated' => ['replicated'],
            'clustered' => ['clustered'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideReadStatements(): array
    {
        $statements = [
            'selectAllMigrations',
            'selectLatestMigrations',
            'selectLastBatchNumber',
            'selectTotal',
            'selectMigration',
            'existsTable',
        ];

        $cases = [];

        foreach (['single', 'replicated', 'clustered'] as $topology) {
            foreach ($statements as $statement) {
                $cases["{$topology}: {$statement}"] = [$topology, $statement];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAllStatements(): array
    {
        $cases = self::provideReadStatements();

        foreach (['single', 'replicated', 'clustered'] as $topology) {
            foreach (['createTable', 'insertMigration', 'selectTableEngine', 'syncReplica'] as $statement) {
                $cases["{$topology}: {$statement}"] = [$topology, $statement];
            }
        }

        return $cases;
    }

    private function grammar(
        RegistryTopology $topology,
    ): RegistryGrammar {
        return new RegistryGrammar($topology);
    }

    private function topology(
        string $name,
    ): RegistryTopology {
        return match ($name) {
            'single' => $this->singleNodeTopology(),
            'replicated' => $this->replicatedTopology(),
            'clustered' => $this->clusteredTopology(),
        };
    }

    private function singleNodeTopology(): RegistryTopology
    {
        return new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
        );
    }

    private function replicatedTopology(): RegistryTopology
    {
        return new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            isReplicated: true,
        );
    }

    private function clusteredTopology(): RegistryTopology
    {
        return new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
            cluster: 'main',
            isReplicated: true,
        );
    }
}
