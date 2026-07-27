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
use Cog\Laravel\Clickhouse\Migration\RegistryGrammar;
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
        $grammar = $this->grammar($this->clusteredTopology());

        $this->recordStatements(engine: 'ReplicatedReplacingMergeTree');

        $this->migrator($grammar)->ensureTableExists();

        self::assertSame([$grammar->createTable()->sql], $this->writtenSql);
    }

    public function testEnsureTableExistsDoesNotProbeWithExistsTable(): void
    {
        $grammar = $this->grammar($this->clusteredTopology());

        $this->recordStatements(engine: 'ReplicatedReplacingMergeTree');

        $this->migrator($grammar)->ensureTableExists();

        foreach ($this->selectedSql as $sql) {
            self::assertStringNotContainsString('EXISTS TABLE', $sql);
        }
    }

    public function testEnsureTableExistsVerifiesEngineAgainstTopology(): void
    {
        $grammar = $this->grammar($this->clusteredTopology());

        $this->recordStatements(engine: 'ReplacingMergeTree');

        $this->expectException(ClickhouseRegistryEngineMismatchException::class);

        $this->migrator($grammar)->ensureTableExists();
    }

    public function testEnsureTableExistsIsFluent(): void
    {
        $grammar = $this->grammar($this->singleNodeTopology());

        $this->recordStatements(engine: 'ReplacingMergeTree');

        $migrator = $this->migrator($grammar);

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
        RegistryGrammar $grammar,
    ): Migrator {
        return new Migrator(
            $this->client,
            new MigrationRepository($this->client, $grammar),
            new Filesystem(),
        );
    }

    private function grammar(
        RegistryTopology $topology,
    ): RegistryGrammar {
        return new RegistryGrammar($topology);
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
