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

namespace Cog\Tests\Laravel\Clickhouse\Cluster\Migration;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use Cog\Laravel\Clickhouse\Exception\ClickhouseRegistryEngineMismatchException;
use Cog\Laravel\Clickhouse\Migration\MigrationRepository;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\Cluster\AbstractClusterTestCase;

use function count;

final class ClusterRegistryTest extends AbstractClusterTestCase
{
    private const MIGRATION_NAME = '2026_07_26_120000_create_events_table';

    /**
     * D1: `ON CLUSTER` creates the registry on every node, including the shard the
     * migrate run never talked to.
     */
    public function testRegistryIsCreatedOnEveryNode(): void
    {
        $table = $this->registerClusterTable();

        $this->repositoryOn($this->firstNode(), $table)->createMigrationRegistryTable();

        foreach ($this->nodes() as $node) {
            self::assertTrue(
                $this->repositoryOn($this->client($node), $table)->exists(),
                "The registry is missing on {$node}.",
            );
        }
    }

    /**
     * D1: the registry is never sharded — its ZooKeeper path carries no `{shard}`,
     * so all nodes join a single replication group.
     */
    public function testEveryNodeJoinsOneReplicationGroup(): void
    {
        $table = $this->registerClusterTable();

        $this->repositoryOn($this->firstNode(), $table)->createMigrationRegistryTable();

        $paths = [];

        foreach ($this->nodes() as $node) {
            $row = $this->client($node)
                ->select(
                    'SELECT zookeeper_path, total_replicas FROM system.replicas WHERE database = {database:String} AND table = {table:String}',
                    [
                        'database' => $this->database(),
                        'table' => $table,
                    ],
                )
                ->fetchOne();

            self::assertIsArray($row, "{$node} does not report the registry as replicated.");
            self::assertSame(
                count($this->nodes()),
                (int) $row['total_replicas'],
                "{$node} sees a replication group of the wrong size.",
            );

            $paths[] = $row['zookeeper_path'];
        }

        self::assertCount(1, array_unique($paths));
        self::assertStringNotContainsString('{shard}', $paths[0]);
    }

    /**
     * D5: a write acknowledged on one node is visible from another one immediately —
     * `insert_quorum` on the write, `select_sequential_consistency` on the read.
     */
    public function testAMigrationRecordedOnOneNodeIsVisibleFromAnother(): void
    {
        $table = $this->registerClusterTable();

        $writer = $this->repositoryOn($this->firstNode(), $table);
        $writer->createMigrationRegistryTable();
        $writer->add(self::MIGRATION_NAME, 1);

        $reader = $this->repositoryOn($this->lastNode(), $table);

        self::assertSame([self::MIGRATION_NAME], $reader->all());
        self::assertSame(1, $reader->total());
        self::assertNotNull($reader->find(self::MIGRATION_NAME));
        self::assertSame(2, $reader->getNextBatchNumber());
    }

    /**
     * The second migrate run — landing on a different node — must see nothing pending.
     */
    public function testBackToBackRunsFromDifferentNodesSeeNoPendingMigrations(): void
    {
        $table = $this->registerClusterTable();

        $first = $this->repositoryOn($this->firstNode(), $table);
        $first->createMigrationRegistryTable();
        $first->add(self::MIGRATION_NAME, 1);

        foreach ($this->nodes() as $node) {
            $repository = $this->repositoryOn($this->client($node), $table);
            $repository->createMigrationRegistryTable();

            self::assertSame(
                [self::MIGRATION_NAME],
                $repository->all(),
                "{$node} would re-apply an already applied migration.",
            );
            self::assertSame(1, $repository->total());
        }
    }

    /**
     * D4: re-issuing the create from another node is a no-op, not a conflict.
     */
    public function testCreateIsIdempotentAcrossNodes(): void
    {
        $table = $this->registerClusterTable();

        $this->repositoryOn($this->firstNode(), $table)->createMigrationRegistryTable();
        $this->repositoryOn($this->firstNode(), $table)->add(self::MIGRATION_NAME, 1);
        $this->repositoryOn($this->lastNode(), $table)->createMigrationRegistryTable();

        self::assertSame(
            [self::MIGRATION_NAME],
            $this->repositoryOn($this->lastNode(), $table)->all(),
        );
    }

    /**
     * The same migration inserted from two nodes must collapse to one row —
     * `ReplacingMergeTree` deduplicates only under `FINAL`.
     */
    public function testConcurrentInsertsFromTwoNodesCollapseToOneRow(): void
    {
        $table = $this->registerClusterTable();

        $first = $this->repositoryOn($this->firstNode(), $table);
        $first->createMigrationRegistryTable();

        $first->add(self::MIGRATION_NAME, 1);
        $this->repositoryOn($this->lastNode(), $table)->add(self::MIGRATION_NAME, 2);

        foreach ($this->nodes() as $node) {
            $repository = $this->repositoryOn($this->client($node), $table);

            self::assertSame(1, $repository->total(), "{$node} reports duplicates.");
            self::assertSame([self::MIGRATION_NAME], $repository->all());
        }
    }

    /**
     * The split-brain case: an existing non-replicated registry on one node is silently
     * left behind by `CREATE TABLE IF NOT EXISTS ... ON CLUSTER`, which happily creates
     * empty replicated tables everywhere else. It must be reported, not tolerated.
     */
    public function testAPreExistingLocalRegistryIsReportedInsteadOfSplitBrained(): void
    {
        $table = $this->registerClusterTable();

        $this->firstNode()->write(
            "CREATE TABLE `{$table}` (migration String, batch UInt32, applied_at DateTime DEFAULT now()) ENGINE = ReplacingMergeTree ORDER BY migration",
        );

        $repository = $this->repositoryOn($this->firstNode(), $table);

        $this->expectException(ClickhouseRegistryEngineMismatchException::class);

        $repository->ensureEngineMatchesTopology();
    }

    /**
     * A replica name that is not unique cluster-wide (`{shard}` here, shared by both
     * replicas of a shard) must fail loudly rather than silently attach two nodes to
     * the same replica.
     */
    public function testNonUniqueReplicaNamesFailLoudly(): void
    {
        $table = $this->registerClusterTable();

        $repository = $this->repositoryOn(
            $this->firstNode(),
            $table,
            new RegistryTopology(
                table: $table,
                database: $this->database(),
                cluster: $this->clusterName(),
                isReplicated: true,
                replicaName: '{shard}',
            ),
        );

        $this->expectException(DatabaseException::class);

        $repository->createMigrationRegistryTable();
    }

    public function testGetEngineReportsTheReplicatedEngine(): void
    {
        $table = $this->registerClusterTable();

        $this->repositoryOn($this->firstNode(), $table)->createMigrationRegistryTable();

        foreach ($this->nodes() as $node) {
            self::assertSame(
                'ReplicatedReplacingMergeTree',
                $this->repositoryOn($this->client($node), $table)->getEngine(),
            );
        }
    }

    private function repositoryOn(
        Client $client,
        string $table,
        ?RegistryTopology $topology = null,
    ): MigrationRepository {
        return $this->repository(
            $topology ?? $this->clusterTopology($table),
            $client,
        );
    }
}
