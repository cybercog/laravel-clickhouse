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

namespace Cog\Tests\Laravel\Clickhouse\Integration\Migration;

use Cog\Laravel\Clickhouse\Exception\ClickhouseRegistryEngineMismatchException;
use Cog\Laravel\Clickhouse\Migration\MigrationRepository;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\Integration\AbstractIntegrationTestCase;

final class MigrationRegistryTest extends AbstractIntegrationTestCase
{
    private const MIGRATION_NAME = '2026_07_26_120000_create_events_table';

    public function testRoundTrip(): void
    {
        $repository = $this->singleNodeRepository();

        $repository->createMigrationRegistryTable();

        self::assertTrue($repository->exists());
        self::assertSame([], $repository->all());
        self::assertSame(0, $repository->total());
        self::assertSame(1, $repository->getNextBatchNumber());

        $repository->add(self::MIGRATION_NAME, 1);

        self::assertSame([self::MIGRATION_NAME], $repository->all());
        self::assertSame(1, $repository->total());
        self::assertSame(2, $repository->getNextBatchNumber());

        $found = $repository->find(self::MIGRATION_NAME);

        self::assertIsArray($found);
        self::assertSame(self::MIGRATION_NAME, $found['migration']);
        self::assertSame(1, (int) $found['batch']);
    }

    public function testFindReturnsNullForUnknownMigration(): void
    {
        $repository = $this->singleNodeRepository();
        $repository->createMigrationRegistryTable();

        self::assertNull($repository->find('2026_01_01_000000_never_applied'));
    }

    /**
     * D4: the create is issued on every run.
     */
    public function testCreateMigrationRegistryTableIsIdempotent(): void
    {
        $repository = $this->singleNodeRepository();

        $repository->createMigrationRegistryTable();
        $repository->add(self::MIGRATION_NAME, 1);
        $repository->createMigrationRegistryTable();

        self::assertSame([self::MIGRATION_NAME], $repository->all());
    }

    /**
     * D5: `ReplacingMergeTree` returns pre-merge duplicates without `FINAL`, so
     * `total()` over-reports — a pre-existing bug on single nodes.
     */
    public function testTotalDeduplicatesRowsImmediatelyAfterInsert(): void
    {
        $repository = $this->singleNodeRepository();
        $repository->createMigrationRegistryTable();

        $repository->add(self::MIGRATION_NAME, 1);
        $repository->add(self::MIGRATION_NAME, 2);

        self::assertSame(1, $repository->total());
        self::assertSame([self::MIGRATION_NAME], $repository->all());
    }

    public function testExistsIsFalseBeforeTheRegistryIsCreated(): void
    {
        self::assertFalse($this->singleNodeRepository()->exists());
    }

    /**
     * D3: identifiers and values reach the server as `param_*`, never as SQL text.
     * `{name:Identifier}` in `SELECT` / `INSERT` / `EXISTS` needs ClickHouse 24.8+.
     */
    public function testParameterisedStatementsAreUnderstoodByTheServer(): void
    {
        $repository = $this->singleNodeRepository();
        $repository->createMigrationRegistryTable();

        $hostile = "2026_01_01_000000_o'brien\\_table";

        $repository->add($hostile, 1);

        self::assertSame([$hostile], $repository->all());
        self::assertNotNull($repository->find($hostile));
        self::assertSame(1, $repository->total());
    }

    /**
     * The engine probe reads a real `system.tables`: it must accept an absent
     * registry, and one this package has just created.
     */
    public function testEngineCheckAcceptsAnAbsentAndAFreshRegistry(): void
    {
        $repository = $this->singleNodeRepository();

        $repository->ensureEngineMatchesTopology();

        $repository->createMigrationRegistryTable();
        $repository->ensureEngineMatchesTopology();

        self::assertTrue($repository->exists());
    }

    /**
     * A registry left over from another topology must not be used silently.
     */
    public function testEngineMismatchIsDetected(): void
    {
        $table = $this->registerTable($this->uniqueTableName());
        $client = $this->client();

        $client->write(
            "CREATE TABLE `{$table}` (migration String, batch UInt32, applied_at DateTime DEFAULT now()) ENGINE = MergeTree ORDER BY migration",
        );

        $repository = new MigrationRepository($client, $this->topology($table));

        $this->expectException(ClickhouseRegistryEngineMismatchException::class);

        $repository->ensureEngineMatchesTopology();
    }

    private function singleNodeRepository(): MigrationRepository
    {
        $table = $this->registerTable($this->uniqueTableName());

        return $this->repository($this->topology($table));
    }

    private function topology(
        string $table,
    ): RegistryTopology {
        return new RegistryTopology(
            table: $table,
            database: $this->database(),
        );
    }
}
