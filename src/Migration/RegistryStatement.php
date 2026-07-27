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
 * A statement produced by the RegistryGrammar together with the bindings it needs.
 */
final class RegistryStatement
{
    /**
     * @param array<string, mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
    ) {}
}
