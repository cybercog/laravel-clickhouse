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

use function is_int;
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

    private readonly int|string $insertQuorum;

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
        int|string $insertQuorum = 'auto',
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

        $this->insertQuorum = self::normaliseInsertQuorum($insertQuorum);
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
            isReplicated: self::toBool($config['replicated'] ?? false),
            replicaPath: (string) ($config['replica_path'] ?? self::DEFAULT_REPLICA_PATH),
            replicaName: (string) ($config['replica_name'] ?? self::DEFAULT_REPLICA_NAME),
            insertQuorum: $config['insert_quorum'] ?? 'auto',
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

    public function getCluster(): ?string
    {
        return $this->cluster;
    }

    public function isClustered(): bool
    {
        return $this->cluster !== null;
    }

    public function isReplicated(): bool
    {
        return $this->isReplicated;
    }

    public function getEngine(): string
    {
        return $this->isReplicated
            ? self::REPLICATED_ENGINE
            : self::ENGINE;
    }

    /**
     * `{database}` and `{table}` are substituted here; every other brace token is
     * left untouched so that ClickHouse expands it as a macro on each node.
     */
    public function getReplicaPath(): string
    {
        return strtr(
            $this->replicaPath,
            [
                '{database}' => $this->database,
                '{table}' => $this->table,
            ],
        );
    }

    public function getReplicaName(): string
    {
        return $this->replicaName;
    }

    public function getInsertQuorum(): int|string
    {
        return $this->insertQuorum;
    }

    /**
     * The path and the replica name are interpolated into string literals, because
     * ClickHouse does not expand macros passed through query parameters.
     *
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
     * @throws ClickhouseConfigException
     */
    private static function normaliseInsertQuorum(
        int|string $insertQuorum,
    ): int|string {
        if ($insertQuorum === 'auto') {
            return 'auto';
        }

        if (is_int($insertQuorum) && $insertQuorum >= 0) {
            return $insertQuorum;
        }

        if (is_string($insertQuorum) && preg_match('/^\d+$/', $insertQuorum) === 1) {
            return (int) $insertQuorum;
        }

        throw new ClickhouseConfigException(
            "Invalid migration registry insert quorum '{$insertQuorum}'. "
            . "It must be 'auto' or a non-negative integer.",
        );
    }

    private static function toBool(
        mixed $value,
    ): bool {
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return (bool) $value;
    }
}
