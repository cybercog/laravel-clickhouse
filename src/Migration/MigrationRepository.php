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

final class MigrationRepository
{
    private bool $isReplicaSynced = false;

    public function __construct(
        private readonly Client $client,
        private readonly RegistryGrammar $grammar,
    ) {}

    /**
     * Creating a new table to store migrations.
     */
    public function createMigrationRegistryTable(): Statement
    {
        return $this->write($this->grammar->createTable());
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->pluckMigrations($this->grammar->selectAllMigrations());
    }

    /**
     * Get latest accepted migrations.
     *
     * @return list<string>
     */
    public function latest(): array
    {
        return $this->pluckMigrations($this->grammar->selectLatestMigrations());
    }

    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    public function getLastBatchNumber(): int
    {
        return (int) $this->selectRegistry($this->grammar->selectLastBatchNumber())
            ->fetchOne('batch');
    }

    public function add(
        string $migration,
        int $batch,
    ): Statement {
        return $this->write($this->grammar->insertMigration($migration, $batch));
    }

    public function total(): int
    {
        return (int) $this->selectRegistry($this->grammar->selectTotal())
            ->fetchOne('count');
    }

    public function exists(): bool
    {
        return (bool) $this->select($this->grammar->existsTable())
            ->fetchOne('result');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(
        string $migration,
    ): ?array {
        return $this->selectRegistry($this->grammar->selectMigration($migration))
            ->fetchOne();
    }

    /**
     * The engine of the registry as it exists on the connected node, or `null` when
     * there is no registry yet.
     */
    public function getEngine(): ?string
    {
        $engine = $this->select($this->grammar->selectTableEngine())
            ->fetchOne('engine');

        return $engine === null ? null : (string) $engine;
    }

    /**
     * A registry created for a different topology must never be adopted silently.
     *
     * @throws ClickhouseRegistryEngineMismatchException
     */
    public function ensureEngineMatchesTopology(): void
    {
        $actualEngine = $this->getEngine();

        if ($actualEngine === null) {
            return;
        }

        $topology = $this->grammar->getTopology();
        $expectedEngine = $topology->getEngine();

        if ($actualEngine === $expectedEngine) {
            return;
        }

        throw ClickhouseRegistryEngineMismatchException::make(
            $topology->getTable(),
            $actualEngine,
            $expectedEngine,
        );
    }

    /**
     * @return list<string>
     */
    private function pluckMigrations(
        RegistryStatement $statement,
    ): array {
        $rows = $this->selectRegistry($statement)->rows();

        return collect($rows)->pluck('migration')->all();
    }

    /**
     * Every read of the registry itself catches the connected replica up first.
     * `exists()` and `getEngine()` deliberately do not, because they answer questions
     * about the connected node alone and have to work before the registry exists.
     */
    private function selectRegistry(
        RegistryStatement $statement,
    ): Statement {
        $this->syncReplica();

        return $this->select($statement);
    }

    /**
     * Once per instance is enough: a repository lives for a single migrate run, and
     * whatever that run writes afterwards is written through this very connection.
     */
    private function syncReplica(): void
    {
        if ($this->isReplicaSynced || $this->grammar->getTopology()->isReplicated() === false) {
            return;
        }

        $this->write($this->grammar->syncReplica());

        $this->isReplicaSynced = true;
    }

    private function select(
        RegistryStatement $statement,
    ): Statement {
        return $this->client->select($statement->sql, $statement->bindings);
    }

    /**
     * Writes go through `write()` rather than `insert()`, because `Client::insert()`
     * builds its own `INSERT ... VALUES` and cannot carry a SETTINGS clause.
     */
    private function write(
        RegistryStatement $statement,
    ): Statement {
        return $this->client->write($statement->sql, $statement->bindings);
    }
}
