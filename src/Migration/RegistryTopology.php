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

    /**
     * @throws ClickhouseConfigException
     */
    public function __construct(
        private readonly string $table,
        private readonly string $database,
        private readonly ?string $cluster = null,
        private readonly bool $isReplicated = false,
        private readonly string $replicaPathPrefix = self::DEFAULT_REPLICA_PATH_PREFIX,
        private readonly string $replicaName = self::DEFAULT_REPLICA_NAME,
    ) {
        Identifier::ensureValid($table, 'migration registry table name');
        Identifier::ensureValid($database, 'database name');

        if ($cluster !== null) {
            Identifier::ensureValid($cluster, 'cluster name');

            if ($isReplicated === false) {
                throw new ClickhouseConfigException(
                    "The migration registry is configured to be created ON CLUSTER '{$cluster}' "
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
     * The cluster name is trimmed, and a blank one is no cluster at all rather than a
     * cluster named "" — `env('CLICKHOUSE_MIGRATION_CLUSTER')` on an empty `.env` entry
     * yields `''`. `Identifier::onClusterClause()` reads the same value the same way.
     *
     * @param array<string, mixed> $config The `clickhouse.migrations` config section.
     *
     * @throws ClickhouseConfigException
     */
    public static function fromConfig(
        array $config,
        string $database,
    ): self {
        $cluster = $config['cluster'] ?? null;

        if ($cluster !== null) {
            $cluster = trim((string) $cluster);
        }

        return new self(
            table: (string) ($config['table'] ?? 'migrations'),
            database: $database,
            cluster: $cluster === '' ? null : $cluster,
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

    public function isReplicated(): bool
    {
        return $this->isReplicated;
    }

    /**
     * The bare engine name, as `system.tables` reports it.
     */
    public function getEngine(): string
    {
        return $this->isReplicated
            ? self::REPLICATED_ENGINE
            : self::ENGINE;
    }

    /**
     * The engine with its arguments, as `CREATE TABLE` takes it.
     *
     * The replica path is fully resolved here — the prefix is a literal, and the
     * database and the table are appended by this package — so only the replica name
     * still holds macros for ClickHouse to expand on each node. That is why the name
     * is interpolated rather than bound: ClickHouse does not expand macros passed
     * through query parameters.
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
     * replica path, so the prefix has to be a literal.
     *
     * Naming the macros that differ per host would be a blocklist, and macro names are
     * the operator's to choose — `{shard}` is only the obvious one, `{layer}` or any
     * custom name does the same damage. Rejecting all of them is the only complete
     * rule, and it costs nothing: the environment can be spelled out in the prefix.
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
