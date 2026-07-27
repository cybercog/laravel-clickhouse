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

use Cog\Laravel\Clickhouse\Exception\ClickhouseConfigException;

/**
 * Where the migration registry lives and how it stays consistent.
 *
 * Every impossible combination is rejected here, at wiring time, rather than
 * discovered later as diverging tables on different nodes.
 */
final class RegistryTopology
{
    public const DEFAULT_REPLICA_PATH_PREFIX = '/clickhouse/tables';

    public const DEFAULT_REPLICA_NAME = '{replica}';

    private const ENGINE = 'ReplacingMergeTree';

    private const REPLICATED_ENGINE = 'ReplicatedReplacingMergeTree';

    private readonly ?string $cluster;

    /**
     * @throws ClickhouseConfigException
     */
    public function __construct(
        private readonly string $table,
        private readonly string $database,
        ?string $cluster = null,
        private readonly bool $isReplicated = false,
        private readonly string $replicaPathPrefix = self::DEFAULT_REPLICA_PATH_PREFIX,
        private readonly string $replicaName = self::DEFAULT_REPLICA_NAME,
    ) {
        Identifier::ensureValid($table, 'migration registry table name');
        Identifier::ensureValid($database, 'database name');

        $this->cluster = Identifier::normalizeCluster($cluster);

        if ($this->cluster !== null) {
            Identifier::ensureValid($this->cluster, 'cluster name');

            if ($isReplicated === false) {
                throw new ClickhouseConfigException(
                    "The migration registry is configured to be created ON CLUSTER '{$this->cluster}' "
                    . 'while replication is disabled. That creates one independent registry per shard, '
                    . 'which immediately diverges. Set `clickhouse.migrations.replicated` to true, '
                    . 'or unset `clickhouse.migrations.cluster`.',
                );
            }
        }

        if ($isReplicated) {
            self::ensureSafeLiteral($replicaPathPrefix, 'replica path prefix');
            self::ensureSafeLiteral($replicaName, 'replica name');
            self::ensureNoMacro($replicaPathPrefix);
        }
    }

    /**
     * @param array<string, mixed> $config The `clickhouse.migrations` config section.
     *
     * @throws ClickhouseConfigException
     */
    public static function fromConfig(
        array $config,
        string $database,
    ): self {
        $cluster = $config['cluster'] ?? null;

        return new self(
            table: (string) ($config['table'] ?? 'migrations'),
            database: $database,
            cluster: $cluster === null ? null : (string) $cluster,
            isReplicated: (bool) ($config['replicated'] ?? false),
            replicaPathPrefix: (string) ($config['replica_path_prefix'] ?? self::DEFAULT_REPLICA_PATH_PREFIX),
            replicaName: (string) ($config['replica_name'] ?? self::DEFAULT_REPLICA_NAME),
        );
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * Normalised: never a blank name, never padded.
     */
    public function getCluster(): ?string
    {
        return $this->cluster;
    }

    public function isReplicated(): bool
    {
        return $this->isReplicated;
    }

    /**
     * The bare engine name, as `system.tables` reports it. `getEngineDefinition()` is
     * the same engine with its arguments, as `CREATE TABLE` takes it.
     */
    public function getEngineName(): string
    {
        return $this->isReplicated
            ? self::REPLICATED_ENGINE
            : self::ENGINE;
    }

    /**
     * The engine with its arguments, as `CREATE TABLE` takes it.
     *
     * The replica path is fully resolved here, so only the replica name still holds
     * macros for ClickHouse to expand per node. Quoted rather than bound — ADR 0002 D3.
     */
    public function getEngineDefinition(): string
    {
        if ($this->isReplicated === false) {
            return self::ENGINE;
        }

        $replicaPath = sprintf(
            '%s/%s/%s',
            rtrim($this->replicaPathPrefix, '/'),
            $this->database,
            $this->table,
        );

        return sprintf(
            "%s('%s', '%s')",
            self::REPLICATED_ENGINE,
            $replicaPath,
            $this->replicaName,
        );
    }

    /**
     * `ON CLUSTER` accepts no query parameter, so the name is validated as an
     * identifier and quoted instead. Empty off a cluster.
     */
    public function getOnClusterClause(): string
    {
        return Identifier::onClusterClause($this->cluster);
    }

    /**
     * @throws ClickhouseConfigException
     */
    private static function ensureSafeLiteral(
        string $literal,
        string $subject,
    ): void {
        if ($literal === '') {
            throw new ClickhouseConfigException(
                "The migration registry {$subject} must not be empty.",
            );
        }

        if (preg_match('/[\'\\\\\r\n]/', $literal) === 1) {
            throw new ClickhouseConfigException(
                "The migration registry {$subject} '{$literal}' contains a quote, a backslash or a line break.",
            );
        }
    }

    /**
     * The registry is replicated, never sharded: every host must resolve the same
     * replica path, so the prefix has to be a literal. Rejecting every macro rather
     * than naming `{shard}` is the only complete rule — ADR 0002 D1.
     *
     * @throws ClickhouseConfigException
     */
    private static function ensureNoMacro(
        string $replicaPathPrefix,
    ): void {
        if (preg_match('/[{}]/', $replicaPathPrefix) !== 1) {
            return;
        }

        throw new ClickhouseConfigException(
            "The migration registry replica path prefix '{$replicaPathPrefix}' contains a macro. "
            . 'A macro that resolves differently on different hosts — {shard}, or any custom one — '
            . 'puts them in separate replication groups, so each keeps its own view of which '
            . 'migrations have been applied. Write the value out literally instead.',
        );
    }
}
