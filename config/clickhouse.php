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

            /*
            | Sent to the server as `max_execution_time` on every request the application
            | makes. Migrations do not run under this cap — see `migrations.timeout`.
            */
            'timeout' => (int) env('CLICKHOUSE_QUERY_TIMEOUT', 1),
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
        | Migrations run on their own client, under this execution timeout instead of
        | `CLICKHOUSE_QUERY_TIMEOUT`, which is tuned for application queries. An
        | `ON CLUSTER` statement waits for every host of the cluster up to
        | `distributed_ddl_task_timeout` (180 seconds by default).
        */
        'timeout' => (int) env('CLICKHOUSE_MIGRATION_TIMEOUT', 180),

        /*
        | Topology of the migration registry itself. The defaults reproduce single-node
        | behaviour exactly. `replicated` is required whenever `cluster` is set: an
        | `ON CLUSTER` registry on a non-replicated engine is one independent table per
        | shard, and they diverge from the first migration onwards.
        |
        | The replica path is `replica_path_prefix` followed by the database and the
        | table. The prefix must hold no macro: the registry is replicated and never
        | sharded, so every host has to resolve the same path. Change it only to keep
        | several installations apart in a shared Keeper — and spell the value out,
        | '/clickhouse/staging/tables' rather than a macro.
        |
        | `replica_name`, in contrast, is expanded by ClickHouse on each node and must
        | be unique cluster-wide — set it to something like '{shard}-{replica}' when
        | {replica} repeats across shards.
        */
        'cluster' => env('CLICKHOUSE_MIGRATION_CLUSTER'),
        'replicated' => (bool) env('CLICKHOUSE_MIGRATION_REPLICATED', false),
        'replica_path_prefix' => env('CLICKHOUSE_MIGRATION_REPLICA_PATH_PREFIX', '/clickhouse/tables'),
        'replica_name' => env('CLICKHOUSE_MIGRATION_REPLICA_NAME', '{replica}'),
    ],
];
