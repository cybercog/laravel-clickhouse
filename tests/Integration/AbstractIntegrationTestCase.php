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

namespace Cog\Tests\Laravel\Clickhouse\Integration;

use ClickHouseDB\Client;
use Cog\Laravel\Clickhouse\Factory\ClickhouseClientFactory;
use Cog\Laravel\Clickhouse\Migration\MigrationRepository;
use Cog\Laravel\Clickhouse\Migration\RegistryTopology;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;

abstract class AbstractIntegrationTestCase extends AbstractTestCase
{
    /**
     * @var list<array{Client, string, string|null}>
     */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessReachable(...$this->splitNode($this->host()));
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTables as [$client, $table, $cluster]) {
            $onCluster = $cluster === null ? '' : " ON CLUSTER `{$cluster}`";

            $client->write(
                <<<SQL
                    DROP TABLE IF EXISTS `{$table}`{$onCluster} SYNC
                    SQL,
                [],
                false,
            );
        }

        $this->createdTables = [];

        parent::tearDown();
    }

    protected function host(): string
    {
        return (string) env('CLICKHOUSE_HOST', 'clickhouse');
    }

    protected function port(): int
    {
        return (int) env('CLICKHOUSE_PORT', 8123);
    }

    protected function database(): string
    {
        return (string) env('CLICKHOUSE_DATABASE', 'default');
    }

    /**
     * Accepts `host` or `host:port`. The port form is what lets the suites run
     * from outside the compose network, where every node is on 127.0.0.1 and
     * only the published port tells them apart.
     *
     * @return array{string, int}
     */
    protected function splitNode(
        string $node,
    ): array {
        $separator = strrpos($node, ':');

        if ($separator === false) {
            return [$node, $this->port()];
        }

        return [
            substr($node, 0, $separator),
            (int) substr($node, $separator + 1),
        ];
    }

    protected function client(
        ?string $node = null,
        int $timeout = 60,
    ): Client {
        [$host, $port] = $this->splitNode($node ?? $this->host());

        $factory = new ClickhouseClientFactory(
            [
                'host' => $host,
                'port' => $port,
                'username' => (string) env('CLICKHOUSE_USER', 'test'),
                'password' => (string) env('CLICKHOUSE_PASSWORD', ''),
                'options' => [
                    'database' => $this->database(),
                    'timeout' => $timeout,
                    'connectTimeOut' => 5,
                ],
            ],
        );

        return $factory->create();
    }

    protected function repository(
        RegistryTopology $topology,
        ?Client $client = null,
    ): MigrationRepository {
        return new MigrationRepository(
            $client ?? $this->client(),
            $topology,
        );
    }

    /**
     * Registers the table for cleanup and returns its name.
     */
    protected function registerTable(
        string $table,
        ?Client $client = null,
        ?string $cluster = null,
    ): string {
        $this->createdTables[] = [$client ?? $this->client(), $table, $cluster];

        return $table;
    }

    protected function uniqueTableName(): string
    {
        return 'test_registry_' . substr(
            md5(static::class . '::' . $this->name()),
            0,
            16,
        );
    }

    protected function skipUnlessReachable(
        string $host,
        int $port,
    ): void {
        $connection = @fsockopen($host, $port, $errorCode, $errorMessage, 2.0);

        if ($connection === false) {
            self::markTestSkipped(
                "ClickHouse at {$host}:{$port} is not reachable. Start it with `docker compose up -d`.",
            );
        }

        fclose($connection);
    }
}
