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

namespace Cog\Laravel\Clickhouse\ConsoleCommand;

use Cog\Laravel\Clickhouse\Migration\Migrator;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Config\Repository as AppConfigRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'clickhouse:migrate',
    description: 'Run the ClickHouse database migrations',
)]
final class ClickhouseMigrateCommand extends Command
{
    use ConfirmableTrait;

    protected static $defaultName = 'clickhouse:migrate';

    /**
     * {@inheritdoc}
     */
    protected $signature = 'clickhouse:migrate
                {--force : Force the operation to run when in production}
                {--path= : Path to Clickhouse directory with migrations}
                {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                {--step= : Number of migrations to run}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Run the ClickHouse database migrations';

    public function handle(
        Migrator $migrator
    ): int {
        if (!$this->confirmToProceed()) {
            return self::FAILURE;
        }

        $migrator->ensureTableExists();

        $migrator->runUp(
            $this->getMigrationsDirectoryPath(),
            $this->getOutput(),
            $this->getStep(),
        );

        return self::SUCCESS;
    }

    private function getStep(): int
    {
        return intval($this->option('step'));
    }

    private function getMigrationsDirectoryPath(): string
    {
        $appConfigRepository = $this->laravel->get(AppConfigRepositoryInterface::class);

        return $appConfigRepository->get(
            'clickhouse.migrations.path',
        );
    }
}
