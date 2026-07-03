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

namespace Cog\Laravel\Clickhouse\Migration;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;

final class MigrationRepository
{
    private Client $client;
    private string $table;
    private ?string $cluster;
    private ?string $database;
    private bool $replicated;

    public function __construct(
        Client $client,
        string $table,
        ?string $cluster = null,
        ?string $database = null,
        bool $replicated = false,
    ) {
        $this->client = $client;
        $this->table = $table;
        $this->cluster = $cluster;
        $this->database = $database;
        $this->replicated = $replicated;
    }

    /**
     * Creating a new table to store migrations.
     */
    public function createMigrationRegistryTable(): Statement
    {
        if ($this->isSharded()) {
            return $this->createShardedMigrationRegistryTable();
        }

        return $this->createSimpleMigrationRegistryTable();
    }

    private function isSharded(): bool
    {
        return $this->cluster !== null && $this->cluster !== '';
    }

    private function isReplicated(): bool
    {
        return $this->replicated;
    }

    private function getTargetTable(): string
    {
        return $this->isSharded() ? "{$this->table}_distributed" : $this->table;
    }

    private function createSimpleMigrationRegistryTable(): Statement
    {
        $engine = $this->isReplicated()
            ? 'ReplicatedReplacingMergeTree()'
            : 'ReplacingMergeTree()';

        return $this->client->write(
            <<<SQL
                CREATE TABLE IF NOT EXISTS {table} (
                    migration String,
                    batch UInt32,
                    applied_at DateTime DEFAULT NOW()
                )
                ENGINE = {$engine}
                ORDER BY migration
                SQL,
            [
                'table' => $this->table,
            ],
        );
    }

    private function createShardedMigrationRegistryTable(): Statement
    {
        $engine = $this->isReplicated()
            ? 'ReplicatedReplacingMergeTree()'
            : 'ReplicatedMergeTree()';

        $this->client->write(
            <<<SQL
                CREATE TABLE IF NOT EXISTS {table} ON CLUSTER {cluster} (
                    migration String,
                    batch UInt32,
                    applied_at DateTime DEFAULT NOW()
                )
                ENGINE = {$engine}
                ORDER BY migration
                SQL,
            [
                'table' => $this->table,
                'cluster' => $this->cluster,
            ],
        );

        return $this->client->write(
            <<<SQL
                CREATE TABLE IF NOT EXISTS {table}_distributed ON CLUSTER {cluster}
                ENGINE = Distributed({cluster}, {database}, {table}, rand())
                SQL,
            [
                'table' => $this->table,
                'cluster' => $this->cluster,
                'database' => $this->database,
            ],
        );
    }

    /**
     * @return array
     */
    public function all(): array
    {
        $rows = $this->client->select(
            <<<SQL
                SELECT migration
                FROM {table}
                SQL,
            [
                'table' => $this->getTargetTable(),
            ],
        )->rows();

        return collect($rows)->pluck('migration')->all();
    }

    /**
     * Get latest accepted migrations.
     *
     * @return array
     */
    public function latest(): array
    {
        $rows = $this->client->select(
            <<<SQL
                SELECT migration
                FROM {table}
                ORDER BY batch DESC, migration DESC
                SQL,
            [
                'table' => $this->getTargetTable(),
            ],
        )->rows();

        return collect($rows)->pluck('migration')->all();
    }

    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    public function getLastBatchNumber(): int
    {
        return $this->client
            ->select(
                <<<SQL
                    SELECT MAX(batch) AS batch
                    FROM {table}
                    SQL,
                [
                    'table' => $this->getTargetTable(),
                ],
            )
            ->fetchOne('batch');
    }

    public function add(
        string $migration,
        int $batch,
    ): Statement {
        return $this->client->insert(
            $this->getTargetTable(),
            [[$migration, $batch]],
            ['migration', 'batch'],
        );
    }

    public function total(): int
    {
        return (int)$this->client->select(
            <<<SQL
                SELECT COUNT(*) AS count
                FROM {table}
                SQL,
            [
                'table' => $this->getTargetTable(),
            ],
        )->fetchOne('count');
    }

    public function exists(): bool
    {
        return (bool)$this->client->select(
            <<<SQL
                EXISTS TABLE {table}
                SQL,
            [
                'table' => $this->getTargetTable(),
            ],
        )->fetchOne('result');
    }

    /**
     * @param string $migration
     * @return array|null
     */
    public function find(
        string $migration,
    ): ?array {
        return $this->client->select(
            <<<SQL
                SELECT *
                FROM {table}
                WHERE migration = :migration
                LIMIT 1
                SQL,
            [
                'table' => $this->getTargetTable(),
                'migration' => $migration,
            ],
        )->fetchOne();
    }
}
