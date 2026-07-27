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
 * both identifiers and values from `param_*`. The DDL is the exception, because
 * `ON CLUSTER` accepts no query parameter, and interpolating that clause drags
 * the rest of the statement along with it — so the DDL carries no bindings at
 * all, and RegistryTopology validates the values that reach it as text instead.
 * The reasoning is in ADR 0002 D3.
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
     *
     * The quorum sits in the statement text: `SETTINGS` has to come between the column
     * list and `VALUES`, and a setting's value is parsed as a literal, so
     * `{quorum:String}` there is a `SYNTAX_ERROR` — the one place `param_*` does not
     * reach. It is a constant, not config, so nothing operator-supplied is interpolated.
     */
    public function add(
        string $migration,
        int $batch,
    ): Statement {
        $settings = $this->topology->isReplicated()
            ? "SETTINGS insert_quorum = 'auto'\n"
            : '';

        return $this->client->write(
            <<<SQL
                INSERT INTO {table:Identifier} (migration, batch)
                {$settings}VALUES ({migration:String}, {batch:UInt32})
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
            [
                'table' => $this->topology->getTable(),
            ],
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
        $expectedEngine = $this->topology->getEngineName();

        foreach ($this->getEngineNames() as $actualEngine) {
            if ($actualEngine === $expectedEngine) {
                continue;
            }

            throw ClickhouseRegistryEngineMismatchException::make(
                $this->topology->getTable(),
                $actualEngine,
                $expectedEngine,
            );
        }
    }

    /**
     * Every engine the registry is known to use right now — one entry per distinct
     * engine, empty when no node has a registry yet.
     *
     * `system.tables` is local to a node, so off a cluster this sees the connected
     * node alone. On a cluster it has to fan out: a stale non-replicated registry on
     * a node the migrate run did not happen to reach is exactly the split brain this
     * check exists to catch, and it is invisible from anywhere else.
     *
     * The table carries no replicated data, so the query takes neither FINAL nor a
     * replica catch-up.
     *
     * @return list<string>
     */
    private function getEngineNames(): array
    {
        $cluster = $this->topology->getCluster();

        $source = $cluster === null
            ? 'system.tables'
            : 'clusterAllReplicas({cluster:String}, system.tables)';

        $bindings = [
            'database' => $this->topology->getDatabase(),
            'table' => $this->topology->getTable(),
        ];

        if ($cluster !== null) {
            $bindings['cluster'] = $cluster;
        }

        $rows = $this->client->select(
            <<<SQL
                SELECT DISTINCT engine
                FROM {$source}
                WHERE database = {database:String}
                AND name = {table:String}
                SQL,
            $bindings,
        )->rows();

        return collect($rows)->pluck('engine')->map(strval(...))->all();
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
     * Every read of the registry itself catches the connected replica up first.
     *
     * `exists()` and `getEngineNames()` deliberately do not, because they answer
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

        return $this->client->select(
            $sql,
            $bindings ?? ['table' => $this->topology->getTable()],
        );
    }

    /**
     * Draining the replication queue is what makes a read on one node see a write
     * acknowledged on another. `select_sequential_consistency` looks like it would do
     * the same and does not: ClickHouse documents it as inoperative while
     * `insert_quorum_parallel` is enabled, which it is by default — see ADR 0002 D2.
     *
     * Every read pays for it, rather than the first one per instance: a flag would
     * make correctness depend on how long the repository happens to live, and under
     * Octane or a queue worker a second migrate run would reuse one that believes it
     * has already synced. A run reads the registry twice, and the second call returns
     * straight away against a queue the first one drained.
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
            [
                'table' => $this->topology->getTable(),
            ],
        );
    }
}
