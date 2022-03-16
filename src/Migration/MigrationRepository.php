<?php

/*
 * This file is part of Laravel ClickHouse Migrations.
 *
 * (c) Anton Komarev <anton@komarev.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Cog\Laravel\ClickhouseMigrations\Migration;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;

final class MigrationRepository
{
    protected Client $client;

    protected string $table;

    public function __construct(
        Client $client,
        string $table
    ) {
        $this->client = $client;
        $this->table = $table;
    }

    /**
     * Creating a new table to store migrations.
     */
    public function createMigrationRegistryTable(): Statement
    {
        return $this->client->write(
            <<<SQL
                CREATE TABLE IF NOT EXISTS {table} (
                    migration String,
                    batch UInt32,
                    applied_at DateTime DEFAULT NOW()
                )
                ENGINE = ReplacingMergeTree()
                ORDER BY migration
            SQL,
            [
                'table' => $this->table,
            ]
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
                'table' => $this->table,
            ]
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
                'table' => $this->table,
            ]
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
                    'table' => $this->table,
                ]
            )
            ->fetchOne('batch');
    }

    public function add(
        string $migration,
        int $batch
    ): Statement {
        return $this->client->insert(
            $this->table,
            [[$migration, $batch]],
            ['migration', 'batch']
        );
    }

    /**
     * @return int
     */
    public function total(): int
    {
        return (int)$this->client->select(
            <<<SQL
                SELECT COUNT(*) AS count
                FROM {table}
            SQL,
            [
                'table' => $this->table,
            ]
        )->fetchOne('count');
    }

    public function exists(): bool
    {
        return (bool)$this->client->select(
            <<<SQL
                EXISTS TABLE {table}
            SQL,
            [
                'table' => $this->table,
            ]
        )->fetchOne('result');
    }

    /**
     * @param string $migration
     * @return array|null
     */
    public function find(
        string $migration
    ): ?array {
        return $this->client->select(
            <<<SQL
                SELECT *
                FROM {table}
                WHERE migration = :migration
                LIMIT 1
            SQL,
            [
                'table' => $this->table,
                'migration' => $migration,
            ]
        )->fetchOne();
    }
}
