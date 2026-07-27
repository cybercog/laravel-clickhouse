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

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

/**
 * Touches no server, so the unit suite can count what a run costs the registry.
 */
return new class extends AbstractClickhouseMigration {
    public function up(): void {}
};
