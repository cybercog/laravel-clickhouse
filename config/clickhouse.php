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
        | behaviour exactly. Three rules the package cannot infer for you:
        |
        |   - `replicated` is required whenever `cluster` is set;
        |   - `replica_path_prefix` — followed by the database and the table — must hold
        |     no macro, so that every host resolves the same path;
        |   - `replica_name` is expanded per node and must be unique cluster-wide; use
        |     '{shard}-{replica}' when {replica} repeats across shards.
        |
        | Why, and how to convert an existing registry: see the upgrade guide and
        | doc/adr/0002-cluster-aware-migration-registry.md.
        */
        'cluster' => env('CLICKHOUSE_MIGRATION_CLUSTER'),
        'replicated' => (bool) env('CLICKHOUSE_MIGRATION_REPLICATED', false),
        'replica_path_prefix' => env('CLICKHOUSE_MIGRATION_REPLICA_PATH_PREFIX', '/clickhouse/tables'),
        'replica_name' => env('CLICKHOUSE_MIGRATION_REPLICA_NAME', '{replica}'),
    ],
];
