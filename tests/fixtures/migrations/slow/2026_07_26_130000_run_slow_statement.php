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

/*
 * `connection.options.timeout` is sent as `max_execution_time` on every request and
 * ships as `1`. Migrations — and `ON CLUSTER` DDL in particular — routinely take
 * longer than that, so the migrator must not run under the query-facing timeout.
 */
return new class extends AbstractClickhouseMigration {
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<'SQL'
                SELECT sleep(3)
                SQL,
        );
    }
};
