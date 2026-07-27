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

return new class extends AbstractClickhouseMigration {
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<SQL
                CREATE TABLE IF NOT EXISTS test_integration_events {on_cluster} (
                    id UInt32,
                    created_at DateTime DEFAULT now()
                )
                ENGINE = MergeTree
                ORDER BY id
                SQL,
            $this->getBindings(),
        );
    }
};
