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

use function is_string;

/**
 * Where the migration registry lives and how it stays consistent.
 *
 * Every impossible combination is rejected here, at wiring time, rather than
 * discovered later as diverging tables on different nodes.
 */
final class RegistryTopology
{
    public const DEFAULT_REPLICA_PATH = '/clickhouse/tables/{database}/{table}';

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
        private readonly string $replicaPath = self::DEFAULT_REPLICA_PATH,
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
            self::ensureSafeLiteral($replicaPath, 'replica path');
            self::ensureSafeLiteral($replicaName, 'replica name');

            if ($cluster !== null && str_contains($replicaPath, '{shard}')) {
                throw new ClickhouseConfigException(
                    'The migration registry replica path must not contain the {shard} macro: '
                    . "'{$replicaPath}'. It would create one replication group per shard, so each shard "
                    . 'would keep its own view of which migrations have been applied.',
                );
            }
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

        if (is_string($cluster) && trim($cluster) === '') {
            $cluster = null;
        }

        return new self(
            table: (string) ($config['table'] ?? 'migrations'),
            database: $database,
            cluster: $cluster === null ? null : (string) $cluster,
            isReplicated: (bool) ($config['replicated'] ?? false),
            replicaPath: (string) ($config['replica_path'] ?? self::DEFAULT_REPLICA_PATH),
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
     * `{database}` and `{table}` are substituted here; every other brace token is
     * left untouched so that ClickHouse expands it as a macro on each node. That is
     * also why the path and the name are interpolated rather than bound — ClickHouse
     * does not expand macros passed through query parameters.
     */
    public function getEngineDefinition(): string
    {
        if ($this->isReplicated === false) {
            return self::ENGINE;
        }

        $replicaPath = strtr(
            $this->replicaPath,
            [
                '{database}' => $this->database,
                '{table}' => $this->table,
            ],
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
}
