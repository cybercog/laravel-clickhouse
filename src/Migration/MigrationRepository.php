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
use Cog\Laravel\Clickhouse\Exception\ClickhouseRegistryEngineMismatchException;

/**
 * Reads and writes the table that records which migrations have been applied.
 *
 * Everything but the `CREATE TABLE` is fully parameterised: the server resolves
 * both identifiers and values from `param_*`. The DDL is the exception, and only
 * where it has to be — `ON CLUSTER` accepts no query parameter, and neither the
 * ZooKeeper path nor the replica name may be one, because ClickHouse does not
 * expand macros passed as parameters. Those three values are validated by
 * RegistryTopology.
 */
final class MigrationRepository
{
    public function __construct(
        private readonly Client $client,
        private readonly RegistryTopology $topology,
    ) {}

    /**
     * Creating a new table to store migrations.
     */
    public function createMigrationRegistryTable(): Statement
    {
        $onCluster = $this->topology->getOnClusterClause();

        $target = Identifier::quote($this->topology->getTable())
            . ($onCluster === '' ? '' : ' ' . $onCluster);

        $engine = $this->topology->getEngineDefinition();

        return $this->client->write(
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$target} (
                    migration String,
                    batch UInt32,
                    applied_at DateTime DEFAULT now()
                )
                ENGINE = {$engine}
                ORDER BY migration
                SQL,
        );
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->pluckMigrations(
            <<<'SQL'
                SELECT migration
                FROM {table:Identifier}
                FINAL
                SQL,
        );
    }

    /**
     * Get latest accepted migrations.
     *
     * @deprecated since 0.3, to be removed in the next major release. Nothing in this
     *             package has called it since 0.1: the ordering exists to feed a
     *             rollback, and migrations here are forward-only by design. Use `all()`,
     *             which returns the same set.
     *
     * @return list<string>
     */
    public function latest(): array
    {
        return $this->pluckMigrations(
            <<<'SQL'
                SELECT migration
                FROM {table:Identifier}
                FINAL
                ORDER BY batch DESC, migration DESC
                SQL,
        );
    }

    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    public function getLastBatchNumber(): int
    {
        return (int) $this->selectRegistry(
            <<<'SQL'
                SELECT MAX(batch) AS batch
                FROM {table:Identifier}
                FINAL
                SQL,
        )->fetchOne('batch');
    }

    /**
     * Writes go through `write()` rather than `insert()`, because `Client::insert()`
     * builds its own `INSERT ... VALUES` and cannot carry a SETTINGS clause.
     */
    public function add(
        string $migration,
        int $batch,
    ): Statement {
        $quorum = $this->topology->isReplicated()
            ? "\nSETTINGS insert_quorum = 'auto'"
            : '';

        return $this->client->write(
            <<<SQL
                INSERT INTO {table:Identifier} (migration, batch){$quorum}
                VALUES ({migration:String}, {batch:UInt32})
                SQL,
            [
                'table' => $this->topology->getTable(),
                'migration' => $migration,
                'batch' => $batch,
            ],
        );
    }

    public function total(): int
    {
        return (int) $this->selectRegistry(
            <<<'SQL'
                SELECT COUNT(*) AS count
                FROM {table:Identifier}
                FINAL
                SQL,
        )->fetchOne('count');
    }

    /**
     * Only ever reflects the connected node, so it reports but never gates a write.
     */
    public function exists(): bool
    {
        return (bool) $this->client->select(
            <<<'SQL'
                EXISTS TABLE {table:Identifier}
                SQL,
            $this->tableBinding(),
        )->fetchOne('result');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(
        string $migration,
    ): ?array {
        return $this->selectRegistry(
            <<<'SQL'
                SELECT *
                FROM {table:Identifier}
                FINAL
                WHERE migration = {migration:String}
                LIMIT 1
                SQL,
            [
                'table' => $this->topology->getTable(),
                'migration' => $migration,
            ],
        )->fetchOne();
    }

    /**
     * A registry created for a different topology must never be adopted silently.
     *
     * @throws ClickhouseRegistryEngineMismatchException
     */
    public function ensureEngineMatchesTopology(): void
    {
        $actualEngine = $this->getEngineName();
        $expectedEngine = $this->topology->getEngineName();

        if ($actualEngine === null || $actualEngine === $expectedEngine) {
            return;
        }

        throw ClickhouseRegistryEngineMismatchException::make(
            $this->topology->getTable(),
            $actualEngine,
            $expectedEngine,
        );
    }

    /**
     * The engine of the registry as it exists on the connected node, or `null` when
     * there is no registry yet.
     *
     * `system.tables` is local to the node and carries no replicated data, so this
     * query takes neither FINAL nor a consistency setting.
     */
    private function getEngineName(): ?string
    {
        $engine = $this->client->select(
            <<<'SQL'
                SELECT engine
                FROM system.tables
                WHERE database = {database:String}
                  AND name = {table:String}
                SQL,
            [
                'database' => $this->topology->getDatabase(),
                'table' => $this->topology->getTable(),
            ],
        )->fetchOne('engine');

        return $engine === null ? null : (string) $engine;
    }

    /**
     * @return list<string>
     */
    private function pluckMigrations(
        string $sql,
    ): array {
        $rows = $this->selectRegistry($sql)->rows();

        return collect($rows)->pluck('migration')->all();
    }

    /**
     * Every read of the registry itself catches the connected replica up first and
     * caps itself at the last quorum-committed part.
     *
     * `exists()` and `getEngineName()` deliberately do neither, because they answer
     * questions about the connected node alone and have to work before the registry
     * exists.
     *
     * @param array<string, mixed>|null $bindings Defaults to the table name alone.
     */
    private function selectRegistry(
        string $sql,
        ?array $bindings = null,
    ): Statement {
        $this->syncReplica();

        if ($this->topology->isReplicated()) {
            $sql .= "\nSETTINGS select_sequential_consistency = 1";
        }

        return $this->client->select($sql, $bindings ?? $this->tableBinding());
    }

    /**
     * `select_sequential_consistency` caps a read at the last quorum-committed part,
     * it does not wait for the connected replica to reach it — a replica that has not
     * fetched that part yet simply returns fewer rows, with no error. Only
     * `SYSTEM SYNC REPLICA` closes the gap, by draining the replication queue.
     *
     * Every read pays for it, rather than the first one per instance. A migrate run
     * reads the registry twice, and the second call returns straight away against a
     * queue the first one already drained — cheaper than owning a flag whose
     * correctness depends on how long the repository happens to live.
     */
    private function syncReplica(): void
    {
        if ($this->topology->isReplicated() === false) {
            return;
        }

        $this->client->write(
            <<<'SQL'
                SYSTEM SYNC REPLICA {table:Identifier}
                SQL,
            $this->tableBinding(),
        );
    }

    /**
     * @return array{table: string}
     */
    private function tableBinding(): array
    {
        return [
            'table' => $this->topology->getTable(),
        ];
    }
}
