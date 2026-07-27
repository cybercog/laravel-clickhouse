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

namespace Cog\Tests\Laravel\Clickhouse\Cluster;

use ClickHouseDB\Client;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\Integration\AbstractIntegrationTestCase;

use function count;

/**
 * Requires compose.cluster.yml: 1 Keeper, 2 shards x 2 replicas, cluster `main`,
 * replica names unique cluster-wide.
 *
 *   docker compose -f compose.yml -f compose.cluster.yml up -d
 *
 * `CLICKHOUSE_CLUSTER_NODES` holds one entry per node, as `host` or `host:port`.
 */
abstract class AbstractClusterTestCase extends AbstractIntegrationTestCase
{
    protected function setUp(): void
    {
        foreach ($this->nodes() as $node) {
            $this->skipUnlessReachable(...$this->splitNode($node));
        }

        parent::setUp();
    }

    /**
     * @return list<string>
     */
    protected function nodes(): array
    {
        $nodes = array_filter(
            array_map(
                'trim',
                explode(',', (string) env('CLICKHOUSE_CLUSTER_NODES', '')),
            ),
        );

        if (count($nodes) === 0) {
            self::markTestSkipped('CLICKHOUSE_CLUSTER_NODES is not configured.');
        }

        return array_values($nodes);
    }

    protected function host(): string
    {
        return $this->nodes()[0];
    }

    protected function clusterName(): string
    {
        return (string) env('CLICKHOUSE_CLUSTER_NAME', 'main');
    }

    /**
     * The node a migrate run happens to talk to.
     */
    protected function firstNode(): Client
    {
        return $this->client($this->nodes()[0]);
    }

    /**
     * A node in the other shard — where nothing was ever written directly.
     */
    protected function lastNode(): Client
    {
        $nodes = $this->nodes();

        return $this->client($nodes[count($nodes) - 1]);
    }

    protected function clusterTopology(
        string $table,
    ): RegistryTopology {
        return new RegistryTopology(
            table: $table,
            database: $this->database(),
            cluster: $this->clusterName(),
            isReplicated: true,
        );
    }

    protected function registerClusterTable(): string
    {
        return $this->registerTable(
            $this->uniqueTableName(),
            $this->firstNode(),
            $this->clusterName(),
        );
    }
}
