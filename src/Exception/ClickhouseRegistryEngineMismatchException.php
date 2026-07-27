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

namespace Cog\Laravel\Clickhouse\Exception;

use Exception;

/**
 * The migration registry that exists on the server was created for a different
 * topology than the one currently configured.
 *
 * `CREATE TABLE IF NOT EXISTS ... ON CLUSTER` would leave the existing local table
 * — and every migration recorded in it — untouched on the node that owns it, while
 * creating empty replicated tables on every other node. Nothing would fail, and the
 * next migrate run would either skip or re-apply everything depending on which node
 * it reached.
 */
final class ClickhouseRegistryEngineMismatchException extends Exception
{
    public static function make(
        string $table,
        string $actualEngine,
        string $expectedEngine,
    ): self {
        return new self(
            "The ClickHouse migration registry `{$table}` uses the {$actualEngine} engine, "
            . "but the configured topology requires {$expectedEngine}. "
            . 'Adopting it would leave the recorded migrations visible on one node only. '
            . 'Convert the registry first: create a table with the required engine next to it, '
            . 'copy the rows over with INSERT SELECT, then RENAME it into place.',
        );
    }
}
