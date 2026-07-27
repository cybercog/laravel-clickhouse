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

/**
 * Builds every statement the migration registry needs.
 *
 * Reads, the INSERT and the EXISTS probe are fully parameterised: the server
 * resolves both identifiers and values from `param_*`, so nothing is interpolated
 * into those statements at all.
 *
 * The DDL is the exception, and only where it has to be: `ON CLUSTER` accepts no
 * query parameter, and neither the ZooKeeper path nor the replica name may be one,
 * because ClickHouse does not expand macros passed as parameters. Those three
 * values are validated by RegistryTopology and quoted here. For the same reason the
 * DDL carries no bindings — the driver substitutes `{name}` with a raw str_replace,
 * which would eat the `{replica}` macro before the server ever sees it.
 */
final class RegistryGrammar
{
    public function __construct(
        private readonly RegistryTopology $topology,
    ) {}

    public function getTopology(): RegistryTopology
    {
        return $this->topology;
    }

    public function createTable(): RegistryStatement
    {
        $table = Identifier::quote($this->topology->getTable());
        $onCluster = $this->topology->isClustered()
            ? ' ON CLUSTER ' . Identifier::quote((string) $this->topology->getCluster())
            : '';

        $engine = $this->topology->isReplicated()
            ? sprintf(
                "ReplicatedReplacingMergeTree('%s', '%s')",
                $this->topology->getReplicaPath(),
                $this->topology->getReplicaName(),
            )
            : $this->topology->getEngine();

        return new RegistryStatement(
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$table}{$onCluster} (
                    migration String,
                    batch UInt32,
                    applied_at DateTime DEFAULT now()
                )
                ENGINE = {$engine}
                ORDER BY migration
                SQL,
        );
    }

    public function insertMigration(
        string $migration,
        int $batch,
    ): RegistryStatement {
        $quorum = $this->insertQuorumClause();

        return new RegistryStatement(
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

    public function selectAllMigrations(): RegistryStatement
    {
        return new RegistryStatement(
            $this->withSequentialConsistency(
                <<<'SQL'
                    SELECT migration
                    FROM {table:Identifier}
                    FINAL
                    SQL,
            ),
            $this->tableBinding(),
        );
    }

    public function selectLatestMigrations(): RegistryStatement
    {
        return new RegistryStatement(
            $this->withSequentialConsistency(
                <<<'SQL'
                    SELECT migration
                    FROM {table:Identifier}
                    FINAL
                    ORDER BY batch DESC, migration DESC
                    SQL,
            ),
            $this->tableBinding(),
        );
    }

    public function selectLastBatchNumber(): RegistryStatement
    {
        return new RegistryStatement(
            $this->withSequentialConsistency(
                <<<'SQL'
                    SELECT MAX(batch) AS batch
                    FROM {table:Identifier}
                    FINAL
                    SQL,
            ),
            $this->tableBinding(),
        );
    }

    public function selectTotal(): RegistryStatement
    {
        return new RegistryStatement(
            $this->withSequentialConsistency(
                <<<'SQL'
                    SELECT COUNT(*) AS count
                    FROM {table:Identifier}
                    FINAL
                    SQL,
            ),
            $this->tableBinding(),
        );
    }

    public function selectMigration(
        string $migration,
    ): RegistryStatement {
        return new RegistryStatement(
            $this->withSequentialConsistency(
                <<<'SQL'
                    SELECT *
                    FROM {table:Identifier}
                    FINAL
                    WHERE migration = {migration:String}
                    LIMIT 1
                    SQL,
            ),
            [
                'table' => $this->topology->getTable(),
                'migration' => $migration,
            ],
        );
    }

    /**
     * `select_sequential_consistency` caps a read at the last quorum-committed part,
     * it does not wait for the connected replica to reach it — a replica that has not
     * fetched that part yet simply returns fewer rows, with no error. Only this
     * statement closes the gap, by draining the replication queue.
     */
    public function syncReplica(): RegistryStatement
    {
        return new RegistryStatement(
            'SYSTEM SYNC REPLICA {table:Identifier}',
            $this->tableBinding(),
        );
    }

    /**
     * Only ever reflects the connected node, so it reports but never gates a write.
     */
    public function existsTable(): RegistryStatement
    {
        return new RegistryStatement(
            'EXISTS TABLE {table:Identifier}',
            $this->tableBinding(),
        );
    }

    /**
     * `system.tables` is local to the node and carries no replicated data, so this
     * statement takes neither FINAL nor a consistency setting.
     */
    public function selectTableEngine(): RegistryStatement
    {
        return new RegistryStatement(
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

    /**
     * Keeps a read from returning rows the quorum never accepted, so a write that was
     * rolled back cannot be mistaken for an applied migration. Catching up with the
     * other replicas is a separate concern — that is what `syncReplica()` is for.
     */
    private function withSequentialConsistency(
        string $sql,
    ): string {
        return $this->topology->isReplicated()
            ? $sql . "\nSETTINGS select_sequential_consistency = 1"
            : $sql;
    }

    private function insertQuorumClause(): string
    {
        if ($this->topology->isReplicated() === false) {
            return '';
        }

        $quorum = $this->topology->getInsertQuorum();

        if ($quorum === 0) {
            return '';
        }

        $value = $quorum === 'auto' ? "'auto'" : (string) $quorum;

        return "\nSETTINGS insert_quorum = {$value}";
    }
}
