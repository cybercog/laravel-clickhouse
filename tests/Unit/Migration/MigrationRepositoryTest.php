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
use ClickHouseDB\Statement;
use Cog\Laravel\Clickhouse\Exception\ClickhouseRegistryEngineMismatchException;
use Cog\Laravel\Clickhouse\Migration\MigrationRepository;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class MigrationRepositoryTest extends AbstractTestCase
{
    private const MIGRATION_NAME = '2026_07_26_120000_create_events_table';

    private Client&MockObject $client;

    /**
     * @var list<array{sql: string, bindings: array<string, mixed>}>
     */
    private array $writes = [];

    /**
     * @var list<array{sql: string, bindings: array<string, mixed>}>
     */
    private array $selects = [];

    private ?Statement $selectResult = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writes = [];
        $this->selects = [];
        $this->selectResult = null;

        $this->client = $this->createMock(Client::class);

        $this->client
            ->method('write')
            ->willReturnCallback(
                function (string $sql, array $bindings = []): Statement {
                    $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

                    return $this->createMock(Statement::class);
                },
            );

        $this->client
            ->method('select')
            ->willReturnCallback(
                function (string $sql, array $bindings = []): Statement {
                    $this->selects[] = ['sql' => $sql, 'bindings' => $bindings];

                    return $this->selectResult ?? $this->createMock(Statement::class);
                },
            );
    }

    public function testCreateTableOnASingleNode(): void
    {
        $this->repository()->createMigrationRegistryTable();

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
            $this->writes[0]['sql'],
        );
    }

    /**
     * The DDL carries no bindings: `ON CLUSTER` takes no query parameter, and neither
     * does the replica name — ClickHouse does not expand macros passed as parameters,
     * so `param_replica={replica}` makes two nodes claim one replica.
     */
    public function testCreateTableOnACluster(): void
    {
        $this->repository($this->clusteredTopology())->createMigrationRegistryTable();

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
            $this->writes[0]['sql'],
        );

        self::assertSame([], $this->writes[0]['bindings']);
    }

    /**
     * Writes go through `write()`, not `Client::insert()`, so the quorum setting can
     * ride along on the statement.
     */
    public function testAddIssuesAParameterisedInsert(): void
    {
        $this->client
            ->expects(self::never())
            ->method('insert');

        $this->repository()->add(self::MIGRATION_NAME, 4);

        self::assertSame(
            <<<'SQL'
                INSERT INTO {table:Identifier} (migration, batch)
                VALUES ({migration:String}, {batch:UInt32})
                SQL,
            $this->writes[0]['sql'],
        );

        self::assertSame(
            [
                'table' => 'migrations',
                'migration' => self::MIGRATION_NAME,
                'batch' => 4,
            ],
            $this->writes[0]['bindings'],
        );
    }

    public function testAddTakesAQuorumOnAReplicatedRegistry(): void
    {
        $this->repository($this->replicatedTopology())->add(self::MIGRATION_NAME, 4);

        self::assertStringContainsString(
            "SETTINGS insert_quorum = 'auto'",
            $this->lastWrite()['sql'],
        );
    }

    /**
     * `ReplacingMergeTree` returns pre-merge duplicates without it, so `total()`
     * over-counts and a migration applied twice shows up twice.
     */
    public function testEveryRegistryReadIsFinal(): void
    {
        $repository = $this->repository();

        $repository->all();
        $repository->latest();
        $repository->total();
        $repository->getLastBatchNumber();
        $repository->find(self::MIGRATION_NAME);

        self::assertCount(5, $this->selects);

        foreach ($this->selects as $select) {
            self::assertStringContainsString('FINAL', $select['sql']);
            self::assertStringNotContainsString('select_sequential_consistency', $select['sql']);
        }
    }

    public function testRegistryReadsAreSequentiallyConsistentWhenReplicated(): void
    {
        $repository = $this->repository($this->replicatedTopology());

        $repository->all();
        $repository->total();

        foreach ($this->selects as $select) {
            self::assertStringContainsString('SETTINGS select_sequential_consistency = 1', $select['sql']);
        }
    }

    /**
     * `select_sequential_consistency` caps a read at the quorum-committed point but
     * never waits for the connected replica to get there, so reads of the registry
     * drain the replication queue first — once per instance.
     */
    public function testReadsCatchTheReplicaUpOnce(): void
    {
        $repository = $this->repository($this->replicatedTopology());

        $repository->all();
        $repository->latest();

        self::assertCount(1, $this->writes);
        self::assertSame('SYSTEM SYNC REPLICA {table:Identifier}', $this->writes[0]['sql']);
        self::assertSame(['table' => 'migrations'], $this->writes[0]['bindings']);
    }

    public function testReadsDoNotCatchUpOnANonReplicatedTopology(): void
    {
        $this->repository()->all();

        self::assertSame([], $this->writes);
    }

    /**
     * Both answer questions about the connected node alone, and both have to work
     * before the registry exists — when there is no replica to catch up with.
     */
    public function testExistsAndGetEngineDoNotCatchTheReplicaUp(): void
    {
        $repository = $this->repository($this->replicatedTopology());

        $repository->exists();
        $repository->getEngine();

        self::assertSame([], $this->writes);

        foreach ($this->selects as $select) {
            self::assertStringNotContainsString('select_sequential_consistency', $select['sql']);
        }
    }

    public function testAllPlucksMigrations(): void
    {
        $this->selectResult = $this->statementWithRows(
            [
                ['migration' => 'a'],
                ['migration' => 'b'],
            ],
        );

        self::assertSame(['a', 'b'], $this->repository()->all());
        self::assertSame(['table' => 'migrations'], $this->selects[0]['bindings']);
    }

    public function testLatestOrdersByBatchThenName(): void
    {
        $this->selectResult = $this->statementWithRows([['migration' => 'b'], ['migration' => 'a']]);

        self::assertSame(['b', 'a'], $this->repository()->latest());
        self::assertStringContainsString('ORDER BY batch DESC, migration DESC', $this->selects[0]['sql']);
    }

    public function testTotal(): void
    {
        $this->selectResult = $this->statementWithFetchOne('count', '7');

        self::assertSame(7, $this->repository()->total());
    }

    public function testGetNextBatchNumberIncrementsLastBatch(): void
    {
        $this->selectResult = $this->statementWithFetchOne('batch', '3');

        self::assertSame(3, $this->repository()->getLastBatchNumber());
        self::assertSame(4, $this->repository()->getNextBatchNumber());
    }

    public function testGetNextBatchNumberOnEmptyRegistry(): void
    {
        $this->selectResult = $this->statementWithFetchOne('batch', null);

        self::assertSame(1, $this->repository()->getNextBatchNumber());
    }

    public function testExists(): void
    {
        $this->selectResult = $this->statementWithFetchOne('result', '1');

        self::assertTrue($this->repository()->exists());
        self::assertSame('EXISTS TABLE {table:Identifier}', $this->selects[0]['sql']);
    }

    public function testFind(): void
    {
        $row = [
            'migration' => self::MIGRATION_NAME,
            'batch' => 1,
            'applied_at' => '2026-07-26 12:00:00',
        ];

        $statement = $this->createMock(Statement::class);
        $statement
            ->method('fetchOne')
            ->willReturn($row);

        $this->selectResult = $statement;

        self::assertSame($row, $this->repository()->find(self::MIGRATION_NAME));
        self::assertSame(
            [
                'table' => 'migrations',
                'migration' => self::MIGRATION_NAME,
            ],
            $this->selects[0]['bindings'],
        );
    }

    public function testGetEngineReadsTheLocalSystemTable(): void
    {
        $this->selectResult = $this->statementWithFetchOne('engine', 'ReplacingMergeTree');

        self::assertSame('ReplacingMergeTree', $this->repository()->getEngine());
        self::assertStringContainsString('FROM system.tables', $this->selects[0]['sql']);
        self::assertSame(
            [
                'database' => 'analytics',
                'table' => 'migrations',
            ],
            $this->selects[0]['bindings'],
        );
    }

    public function testGetEngineReturnsNullWhenRegistryIsAbsent(): void
    {
        $this->selectResult = $this->statementWithFetchOne('engine', null);

        self::assertNull($this->repository()->getEngine());
    }

    public function testEnsureEngineMatchesTopologyPassesForMatchingEngine(): void
    {
        $this->selectResult = $this->statementWithFetchOne('engine', 'ReplacingMergeTree');

        $this->repository()->ensureEngineMatchesTopology();

        self::assertCount(1, $this->selects);
    }

    public function testEnsureEngineMatchesTopologyPassesForAbsentRegistry(): void
    {
        $this->selectResult = $this->statementWithFetchOne('engine', null);

        $this->repository($this->replicatedTopology())->ensureEngineMatchesTopology();

        self::assertCount(1, $this->selects);
    }

    /**
     * The upgrade trap: a registry created before cluster mode was enabled stays a
     * plain `ReplacingMergeTree` on the node that owns it, while `CREATE TABLE IF
     * NOT EXISTS ... ON CLUSTER` silently creates empty replicated tables elsewhere.
     */
    public function testEnsureEngineMatchesTopologyRejectsNonReplicatedRegistry(): void
    {
        $this->selectResult = $this->statementWithFetchOne('engine', 'ReplacingMergeTree');

        $this->expectException(ClickhouseRegistryEngineMismatchException::class);
        $this->expectExceptionMessageMatches('/ReplacingMergeTree/');
        $this->expectExceptionMessageMatches('/ReplicatedReplacingMergeTree/');

        $this->repository($this->replicatedTopology())->ensureEngineMatchesTopology();
    }

    public function testEnsureEngineMatchesTopologyRejectsReplicatedRegistryOnSingleNodeTopology(): void
    {
        $this->selectResult = $this->statementWithFetchOne('engine', 'ReplicatedReplacingMergeTree');

        $this->expectException(ClickhouseRegistryEngineMismatchException::class);

        $this->repository()->ensureEngineMatchesTopology();
    }

    private function repository(
        ?RegistryTopology $topology = null,
    ): MigrationRepository {
        return new MigrationRepository(
            $this->client,
            $topology ?? new RegistryTopology(table: 'migrations', database: 'analytics'),
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

    /**
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    private function lastWrite(): array
    {
        return $this->writes[array_key_last($this->writes)];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function statementWithRows(
        array $rows,
    ): Statement&MockObject {
        $statement = $this->createMock(Statement::class);
        $statement
            ->method('rows')
            ->willReturn($rows);

        return $statement;
    }

    private function statementWithFetchOne(
        string $key,
        mixed $value,
    ): Statement&MockObject {
        $statement = $this->createMock(Statement::class);
        $statement
            ->method('fetchOne')
            ->with($key)
            ->willReturn($value);

        return $statement;
    }
}
