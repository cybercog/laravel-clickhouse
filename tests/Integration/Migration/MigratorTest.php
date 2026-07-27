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

use Cog\Laravel\Clickhouse\Migration\MigrationRepository;
use Cog\Laravel\Clickhouse\Migration\Migrator;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\Integration\AbstractIntegrationTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class MigratorTest extends AbstractIntegrationTestCase
{
    private const MIGRATION_NAME = '2026_07_26_120000_create_integration_events_table';

    protected function tearDown(): void
    {
        $this->client()->write(
            <<<'SQL'
                DROP TABLE IF EXISTS test_integration_events SYNC
                SQL,
            [],
            false,
        );

        parent::tearDown();
    }

    public function testRunUpAppliesAndRecordsMigrations(): void
    {
        $repository = $this->registryRepository();

        $migrator = $this->migrator($repository);
        $migrator->ensureTableExists();
        $migrator->runUp($this->fixturePath('basic'), $this->consoleOutput(), 0);

        self::assertSame([self::MIGRATION_NAME], $repository->all());
        self::assertSame(1, $repository->total());
        self::assertSame(
            1,
            (int) $repository->find(self::MIGRATION_NAME)['batch'],
        );
    }

    public function testSecondRunAppliesNothing(): void
    {
        $repository = $this->registryRepository();

        $migrator = $this->migrator($repository);
        $migrator->ensureTableExists();
        $migrator->runUp($this->fixturePath('basic'), $this->consoleOutput(), 0);
        $migrator->runUp($this->fixturePath('basic'), $this->consoleOutput(), 0);

        self::assertSame(1, $repository->total());
        self::assertSame([self::MIGRATION_NAME], $repository->all());
    }

    public function testEnsureTableExistsCanBeCalledRepeatedly(): void
    {
        $repository = $this->registryRepository();

        $migrator = $this->migrator($repository);
        $migrator->ensureTableExists();
        $migrator->ensureTableExists();

        self::assertTrue($repository->exists());
    }

    /**
     * The migrator must not inherit `connection.options.timeout` (1 second, sent as
     * `max_execution_time`), otherwise any non-trivial migration — and every
     * `ON CLUSTER` DDL — fails with TIMEOUT_EXCEEDED.
     */
    public function testMigrationsAreNotCappedByTheQueryTimeout(): void
    {
        config(
            [
                'clickhouse.migrations.table' => $this->registerTable($this->uniqueTableName()),
                'clickhouse.connection.options.timeout' => 1,
            ],
        );

        $migrator = $this->app->get(Migrator::class);
        $migrator->ensureTableExists();
        $migrator->runUp($this->fixturePath('slow'), $this->consoleOutput(), 0);

        self::assertSame(
            ['2026_07_26_130000_run_slow_statement'],
            $this->repository($this->topology(config('clickhouse.migrations.table')))->all(),
        );
    }

    private function migrator(
        MigrationRepository $repository,
    ): Migrator {
        return new Migrator(
            $repository,
            $this->app->get(Filesystem::class),
        );
    }

    private function registryRepository(): MigrationRepository
    {
        return $this->repository(
            $this->topology($this->registerTable($this->uniqueTableName())),
        );
    }

    private function topology(
        string $table,
    ): RegistryTopology {
        return new RegistryTopology(
            table: $table,
            database: $this->database(),
        );
    }

    private function fixturePath(
        string $set,
    ): string {
        return __DIR__ . '/../../fixtures/migrations/' . $set;
    }

    private function consoleOutput(): OutputStyle
    {
        return new OutputStyle(new ArrayInput([]), new BufferedOutput());
    }
}
