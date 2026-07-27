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
use Cog\Laravel\Clickhouse\Migration\RegistryGrammar;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class MigrationRepositoryTest extends AbstractTestCase
{
    private const MIGRATION_NAME = '2026_07_26_120000_create_events_table';

    private Client&MockObject $client;

    private RegistryGrammar $grammar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(Client::class);
        $this->grammar = new RegistryGrammar(
            new RegistryTopology(
                table: 'migrations',
                database: 'analytics',
            ),
        );
    }

    public function testCreateMigrationRegistryTableIssuesGrammarStatement(): void
    {
        $expected = $this->grammar->createTable();

        $this->client
            ->expects(self::once())
            ->method('write')
            ->with($expected->sql, $expected->bindings)
            ->willReturn($this->createMock(Statement::class));

        $this->repository()->createMigrationRegistryTable();
    }

    public function testAllIssuesGrammarStatementAndPlucksMigrations(): void
    {
        $expected = $this->grammar->selectAllMigrations();

        $this->client
            ->expects(self::once())
            ->method('select')
            ->with($expected->sql, $expected->bindings)
            ->willReturn(
                $this->statementWithRows(
                    [
                        ['migration' => 'a'],
                        ['migration' => 'b'],
                    ],
                ),
            );

        self::assertSame(['a', 'b'], $this->repository()->all());
    }

    public function testLatestIssuesGrammarStatement(): void
    {
        $expected = $this->grammar->selectLatestMigrations();

        $this->client
            ->expects(self::once())
            ->method('select')
            ->with($expected->sql, $expected->bindings)
            ->willReturn($this->statementWithRows([['migration' => 'b'], ['migration' => 'a']]));

        self::assertSame(['b', 'a'], $this->repository()->latest());
    }

    /**
     * D5: writes go through a parameterised `INSERT`, not `Client::insert()`, so the
     * quorum setting can ride along on the statement.
     */
    public function testAddIssuesParameterisedInsert(): void
    {
        $expected = $this->grammar->insertMigration(self::MIGRATION_NAME, 4);

        $this->client
            ->expects(self::once())
            ->method('write')
            ->with($expected->sql, $expected->bindings)
            ->willReturn($this->createMock(Statement::class));

        $this->client
            ->expects(self::never())
            ->method('insert');

        $this->repository()->add(self::MIGRATION_NAME, 4);
    }

    /**
     * D5: `select_sequential_consistency` caps a read at the quorum-committed point
     * but never waits for the connected replica to get there, so reads of the registry
     * drain the replication queue first.
     */
    public function testReadsCatchTheReplicaUpFirst(): void
    {
        $grammar = $this->replicatedGrammar();
        $expected = $grammar->syncReplica();

        $this->client
            ->expects(self::once())
            ->method('write')
            ->with($expected->sql, $expected->bindings)
            ->willReturn($this->createMock(Statement::class));

        $this->client
            ->method('select')
            ->willReturn($this->statementWithRows([['migration' => 'a']]));

        $repository = new MigrationRepository($this->client, $grammar);

        self::assertSame(['a'], $repository->all());
        self::assertSame(['a'], $repository->latest());
    }

    public function testReadsDoNotCatchUpOnANonReplicatedTopology(): void
    {
        $this->client
            ->expects(self::never())
            ->method('write');

        $this->client
            ->method('select')
            ->willReturn($this->statementWithRows([]));

        self::assertSame([], $this->repository()->all());
    }

    /**
     * Both answer questions about the connected node alone, and both have to work
     * before the registry exists — when there is no replica to catch up with.
     */
    public function testExistsAndGetEngineDoNotCatchTheReplicaUp(): void
    {
        $this->client
            ->expects(self::never())
            ->method('write');

        $statement = $this->createMock(Statement::class);
        $statement
            ->method('fetchOne')
            ->willReturn(null);

        $this->client
            ->method('select')
            ->willReturn($statement);

        $repository = new MigrationRepository($this->client, $this->replicatedGrammar());

        self::assertFalse($repository->exists());
        self::assertNull($repository->getEngine());
    }

    public function testTotalIssuesGrammarStatement(): void
    {
        $expected = $this->grammar->selectTotal();

        $this->client
            ->expects(self::once())
            ->method('select')
            ->with($expected->sql, $expected->bindings)
            ->willReturn($this->statementWithFetchOne('count', '7'));

        self::assertSame(7, $this->repository()->total());
    }

    public function testGetLastBatchNumberIssuesGrammarStatement(): void
    {
        $expected = $this->grammar->selectLastBatchNumber();

        $this->client
            ->expects(self::once())
            ->method('select')
            ->with($expected->sql, $expected->bindings)
            ->willReturn($this->statementWithFetchOne('batch', '3'));

        self::assertSame(3, $this->repository()->getLastBatchNumber());
    }

    public function testGetNextBatchNumberIncrementsLastBatch(): void
    {
        $this->client
            ->method('select')
            ->willReturn($this->statementWithFetchOne('batch', '3'));

        self::assertSame(4, $this->repository()->getNextBatchNumber());
    }

    public function testGetNextBatchNumberOnEmptyRegistry(): void
    {
        $this->client
            ->method('select')
            ->willReturn($this->statementWithFetchOne('batch', null));

        self::assertSame(1, $this->repository()->getNextBatchNumber());
    }

    public function testExistsIssuesGrammarStatement(): void
    {
        $expected = $this->grammar->existsTable();

        $this->client
            ->expects(self::once())
            ->method('select')
            ->with($expected->sql, $expected->bindings)
            ->willReturn($this->statementWithFetchOne('result', '1'));

        self::assertTrue($this->repository()->exists());
    }

    public function testFindIssuesGrammarStatement(): void
    {
        $expected = $this->grammar->selectMigration(self::MIGRATION_NAME);

        $row = [
            'migration' => self::MIGRATION_NAME,
            'batch' => 1,
            'applied_at' => '2026-07-26 12:00:00',
        ];

        $statement = $this->createMock(Statement::class);
        $statement
            ->method('fetchOne')
            ->willReturn($row);

        $this->client
            ->expects(self::once())
            ->method('select')
            ->with($expected->sql, $expected->bindings)
            ->willReturn($statement);

        self::assertSame($row, $this->repository()->find(self::MIGRATION_NAME));
    }

    public function testGetEngineReturnsNullWhenRegistryIsAbsent(): void
    {
        $this->client
            ->method('select')
            ->willReturn($this->statementWithFetchOne('engine', null));

        self::assertNull($this->repository()->getEngine());
    }

    public function testEnsureEngineMatchesTopologyPassesForMatchingEngine(): void
    {
        $this->client
            ->method('select')
            ->willReturn($this->statementWithFetchOne('engine', 'ReplacingMergeTree'));

        $repository = $this->repository();
        $repository->ensureEngineMatchesTopology();

        self::assertSame('ReplacingMergeTree', $repository->getEngine());
    }

    public function testEnsureEngineMatchesTopologyPassesForAbsentRegistry(): void
    {
        $this->client
            ->method('select')
            ->willReturn($this->statementWithFetchOne('engine', null));

        $repository = $this->repository();
        $repository->ensureEngineMatchesTopology();

        self::assertNull($repository->getEngine());
    }

    /**
     * The upgrade trap: a registry created before cluster mode was enabled stays a
     * plain `ReplacingMergeTree` on the node that owns it, while `CREATE TABLE IF
     * NOT EXISTS ... ON CLUSTER` silently creates empty replicated tables elsewhere.
     * Reproduced on a 2x2 cluster running 24.8.
     */
    public function testEnsureEngineMatchesTopologyRejectsNonReplicatedRegistry(): void
    {
        $repository = new MigrationRepository($this->client, $this->replicatedGrammar());

        $this->client
            ->method('select')
            ->willReturn($this->statementWithFetchOne('engine', 'ReplacingMergeTree'));

        $this->expectException(ClickhouseRegistryEngineMismatchException::class);
        $this->expectExceptionMessageMatches('/ReplacingMergeTree/');
        $this->expectExceptionMessageMatches('/ReplicatedReplacingMergeTree/');

        $repository->ensureEngineMatchesTopology();
    }

    public function testEnsureEngineMatchesTopologyRejectsReplicatedRegistryOnSingleNodeTopology(): void
    {
        $this->client
            ->method('select')
            ->willReturn($this->statementWithFetchOne('engine', 'ReplicatedReplacingMergeTree'));

        $this->expectException(ClickhouseRegistryEngineMismatchException::class);

        $this->repository()->ensureEngineMatchesTopology();
    }

    private function repository(): MigrationRepository
    {
        return new MigrationRepository($this->client, $this->grammar);
    }

    private function replicatedGrammar(): RegistryGrammar
    {
        return new RegistryGrammar(
            new RegistryTopology(
                table: 'migrations',
                database: 'analytics',
                cluster: 'main',
                isReplicated: true,
            ),
        );
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
