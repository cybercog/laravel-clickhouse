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

return [

    /*
    |--------------------------------------------------------------------------
    | ClickHouse Client Configuration
    |--------------------------------------------------------------------------
    |
    | Here you can configure a connection to connect to the ClickHouse
    | database and specify additional configuration options.
    |
    */

    'connection' => [
        'host' => env('CLICKHOUSE_HOST', 'localhost'),
        'port' => env('CLICKHOUSE_PORT', 8123),
        'username' => env('CLICKHOUSE_USER', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'options' => [
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'timeout' => 1,
            'connectTimeOut' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ClickHouse Migration Settings
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => env('CLICKHOUSE_MIGRATION_TABLE', 'migrations'),
        'path' => database_path('clickhouse-migrations'),

        /*
        | Migrations run under their own execution timeout. `connection.options.timeout`
        | is sent as `max_execution_time` on every request and is tuned for application
        | queries, while an `ON CLUSTER` statement waits for every host of the cluster up
        | to `distributed_ddl_task_timeout` (180 seconds by default).
        */
        'timeout' => (int) env('CLICKHOUSE_MIGRATION_TIMEOUT', 180),

        /*
        | Topology of the migration registry itself. The defaults reproduce single-node
        | behaviour exactly. `replicated` is required whenever `cluster` is set: an
        | `ON CLUSTER` registry on a non-replicated engine is one independent table per
        | shard, and they diverge from the first migration onwards.
        |
        | `replica_path` must not contain the {shard} macro on a cluster, so that every
        | host joins a single replication group. {database} and {table} are substituted
        | by this package; every other macro is expanded by ClickHouse on each node.
        | `replica_name` must be unique cluster-wide — set it to something like
        | '{shard}-{replica}' when {replica} repeats across shards.
        */
        'cluster' => env('CLICKHOUSE_MIGRATION_CLUSTER'),
        'replicated' => (bool) env('CLICKHOUSE_MIGRATION_REPLICATED', false),
        'replica_path' => env('CLICKHOUSE_MIGRATION_REPLICA_PATH', '/clickhouse/tables/{database}/{table}'),
        'replica_name' => env('CLICKHOUSE_MIGRATION_REPLICA_NAME', '{replica}'),

        /*
        | 'auto' (a majority of replicas) or a non-negative integer. Zero disables
        | quorum writes and, with them, `select_sequential_consistency` — a migrate run
        | against a lagging replica may then re-apply migrations. Requires ClickHouse 22.8+.
        */
        'insert_quorum' => env('CLICKHOUSE_MIGRATION_INSERT_QUORUM', 'auto'),
    ],
];
