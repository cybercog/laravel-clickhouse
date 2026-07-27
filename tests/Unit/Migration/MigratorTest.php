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
use Cog\Laravel\Clickhouse\Migration\Migrator;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\MockObject\MockObject;

final class MigratorTest extends AbstractTestCase
{
    private Client&MockObject $client;

    /**
     * @var list<string>
     */
    private array $writtenSql = [];

    /**
     * @var list<string>
     */
    private array $selectedSql = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(Client::class);
    }

    /**
     * D4: with `ON CLUSTER` an `EXISTS TABLE` probe only reflects the connected
     * node, so the create must be unconditional and idempotent.
     */
    public function testEnsureTableExistsAlwaysIssuesIdempotentCreate(): void
    {
        $this->recordStatements(engine: 'ReplicatedReplacingMergeTree');

        $this->migrator($this->clusteredTopology())->ensureTableExists();

        self::assertCount(1, $this->writtenSql);
        self::assertStringStartsWith(
            'CREATE TABLE IF NOT EXISTS `migrations` ON CLUSTER `main`',
            $this->writtenSql[0],
        );
    }

    public function testEnsureTableExistsDoesNotProbeWithExistsTable(): void
    {
        $this->recordStatements(engine: 'ReplicatedReplacingMergeTree');

        $this->migrator($this->clusteredTopology())->ensureTableExists();

        foreach ($this->selectedSql as $sql) {
            self::assertStringNotContainsString('EXISTS TABLE', $sql);
        }
    }

    public function testEnsureTableExistsVerifiesEngineAgainstTopology(): void
    {
        $this->recordStatements(engine: 'ReplacingMergeTree');

        $this->expectException(ClickhouseRegistryEngineMismatchException::class);

        $this->migrator($this->clusteredTopology())->ensureTableExists();
    }

    public function testEnsureTableExistsIsFluent(): void
    {
        $this->recordStatements(engine: 'ReplacingMergeTree');

        $migrator = $this->migrator($this->singleNodeTopology());

        self::assertSame($migrator, $migrator->ensureTableExists());
    }

    private function recordStatements(
        ?string $engine,
    ): void {
        $this->client
            ->method('write')
            ->willReturnCallback(
                function (string $sql): Statement {
                    $this->writtenSql[] = $sql;

                    return $this->createMock(Statement::class);
                },
            );

        $this->client
            ->method('select')
            ->willReturnCallback(
                function (string $sql) use ($engine): Statement {
                    $this->selectedSql[] = $sql;

                    $statement = $this->createMock(Statement::class);
                    $statement
                        ->method('fetchOne')
                        ->willReturn($engine);

                    return $statement;
                },
            );
    }

    private function migrator(
        RegistryTopology $topology,
    ): Migrator {
        return new Migrator(
            $this->client,
            new MigrationRepository($this->client, $topology),
            new Filesystem(),
        );
    }

    private function singleNodeTopology(): RegistryTopology
    {
        return new RegistryTopology(
            table: 'migrations',
            database: 'analytics',
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
