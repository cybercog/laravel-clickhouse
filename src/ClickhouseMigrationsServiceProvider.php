<?php

/*
 * This file is part of Laravel ClickHouse Migrations.
 *
 * (c) Anton Komarev <anton@komarev.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Cog\Laravel\ClickhouseMigrations;

use ClickHouseDB\Client as ClickhouseClient;
use Cog\Laravel\ClickhouseMigrations\ConsoleCommand\ClickhouseMigrationsMigrateCommand;
use Cog\Laravel\ClickhouseMigrations\ConsoleCommand\MakeClickhouseMigrationCommand;
use Cog\Laravel\ClickhouseMigrations\Factory\ClickhouseClientFactory;
use Cog\Laravel\ClickhouseMigrations\Migration\MigrationCreator;
use Cog\Laravel\ClickhouseMigrations\Migration\MigrationRepository;
use Cog\Laravel\ClickhouseMigrations\Migration\Migrator;
use Illuminate\Contracts\Config\Repository as AppConfigRepositoryInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ClickhouseMigrationsServiceProvider extends ServiceProvider
{
    private const CONFIG_FILE_PATH = __DIR__ . '/../config/clickhouse.php';

    public function register(): void
    {
        $this->app->singleton(
            'clickhouse',
            static function (Application $app): ClickhouseClient {
                $configRepository = $app->get(AppConfigRepositoryInterface::class);
                $config = $configRepository->get('clickhouse.connection', []);

                $clickhouse = new ClickhouseClientFactory($config);

                return $clickhouse->create();
            }
        );

        $this->app->singleton(
            Migrator::class,
            static function (Application $app): Migrator {
                $client = $app->get('clickhouse');
                $filesystem = $app->get(Filesystem::class);
                $configRepository = $app->get(AppConfigRepositoryInterface::class);
                $table = $configRepository->get('clickhouse.migrations.table');

                $repository = new MigrationRepository(
                    $client,
                    $table,
                );

                return new Migrator(
                    $client,
                    $repository,
                    $filesystem,
                );
            }
        );

        $this->app->singleton(
            MigrationCreator::class,
            static function (Application $app): MigrationCreator {
                return new MigrationCreator(
                    $app->get(Filesystem::class),
                    $app->basePath('stubs')
                );
            }
        );
    }

    public function boot(): void
    {
        $this->configure();
        $this->registerConsoleCommands();
        $this->registerPublishes();
    }

    private function configure(): void
    {
        if (!$this->app->configurationIsCached()) {
            $this->mergeConfigFrom(
                self::CONFIG_FILE_PATH,
                'clickhouse'
            );
        }
    }

    private function registerConsoleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(
                [
                    ClickhouseMigrationsMigrateCommand::class,
                    MakeClickhouseMigrationCommand::class,
                ]
            );
        }
    }

    private function registerPublishes(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [
                    self::CONFIG_FILE_PATH => $this->app->configPath('clickhouse.php'),
                ],
                'config'
            );
        }
    }
}
