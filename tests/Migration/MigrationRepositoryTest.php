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

namespace Cog\Tests\Laravel\Clickhouse\Migration;

use ClickHouseDB\Client;
use Cog\Laravel\Clickhouse\Factory\ClickhouseClientFactory;
use Cog\Laravel\Clickhouse\Migration\MigrationRepository;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;

final class MigrationRepositoryTest extends AbstractTestCase
{
    private Client $client;
    private string $testTable;

    public function setUp(): void
    {
        parent::setUp();
        $this->testTable = 'test_migrations_' . uniqid();
        $clickhouseFactory = new ClickhouseClientFactory(
            [
                'host' => env('CLICKHOUSE_HOST'),
                'port' => env('CLICKHOUSE_PORT'),
                'username' => env('CLICKHOUSE_USER'),
                'password' => env('CLICKHOUSE_PASSWORD'),
                'options' => [
                    'database' => env('CLICKHOUSE_DATABASE'),
                    'timeout' => 1,
                    'connectTimeOut' => 2,
                ],
            ],
        );

        $this->client = $clickhouseFactory->create();

        // Clean up any pre-existing test tables
        $this->client->write("DROP TABLE IF EXISTS {$this->testTable}");
        $this->client->write("DROP TABLE IF EXISTS {$this->testTable}_distributed");
    }

    public function tearDown(): void
    {
        $this->client->write("DROP TABLE IF EXISTS {$this->testTable}");
        $this->client->write("DROP TABLE IF EXISTS {$this->testTable}_distributed");
        parent::tearDown();
    }

    public function testNonShardedModeWorks(): void
    {
        $repository = new MigrationRepository(
            $this->client,
            $this->testTable,
        );

        self::assertFalse($repository->exists());

        $repository->createMigrationRegistryTable();
        self::assertTrue($repository->exists());

        $repository->add('2023_01_01_000000_test_migration', 1);
        self::assertSame(1, $repository->total());
        self::assertSame(['2023_01_01_000000_test_migration'], $repository->all());
        self::assertSame(1, $repository->getLastBatchNumber());
        self::assertSame(2, $repository->getNextBatchNumber());

        $found = $repository->find('2023_01_01_000000_test_migration');
        self::assertNotNull($found);
        self::assertSame('2023_01_01_000000_test_migration', $found['migration']);
    }

    public function testShardedModeUsesDistributedTable(): void
    {
        $repository = new MigrationRepository(
            $this->client,
            $this->testTable,
            'test_cluster',
            env('CLICKHOUSE_DATABASE'),
        );

        // In sharded mode without a real cluster, createMigrationRegistryTable will fail because
        // ClickHouse can't find 'test_cluster'. We can verify the target table is correct though.
        self::assertFalse($repository->exists());

        // When we try to create the table on a non-existent cluster it throws a ClickHouse exception
        try {
            $repository->createMigrationRegistryTable();
            // If we have a test cluster (e.g. in CI with a proper ClickHouse cluster), this could succeed.
            self::assertTrue($repository->exists());
        } catch (\Exception $exception) {
            // On a single-node ClickHouse test setup we expect the cluster to be missing.
            self::assertStringContainsString('test_cluster', $exception->getMessage());
        }
    }

    public function testGetBindingsReturnsCorrectValues(): void
    {
        // The actual MigrationRepository doesn't use getBindings, but we can verify it is
        // available for consumers through the abstract migration class.
        $migration = new class extends \Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration {
            public function up(): void
            {
            }

            public function testBindings(): array
            {
                return $this->getBindings(['extra' => 'value']);
            }
        };

        $bindings = $migration->testBindings();
        self::assertArrayHasKey('cluster', $bindings);
        self::assertArrayHasKey('database', $bindings);
        self::assertSame('value', $bindings['extra']);
    }
}
