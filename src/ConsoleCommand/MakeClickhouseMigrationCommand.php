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

use Cog\Laravel\Clickhouse\Migration\MigrationCreator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as AppConfigRepositoryInterface;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Composer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'make:clickhouse-migration',
    description: 'Create a new ClickHouse migration file',
)]
final class MakeClickhouseMigrationCommand extends Command
{
    protected function getArguments(): array
    {
        return [
            new InputArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the migration',
            ),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'The location where the migration file should be created',
            ),
            new InputOption(
                'realpath',
                null,
                InputOption::VALUE_NONE,
                'Indicate any provided migration file paths are pre-resolved absolute paths',
            ),
            new InputOption(
                'fullpath',
                null,
                InputOption::VALUE_NONE,
                'Output the full path of the migration',
            ),
        ];
    }

    public function __construct(
        private MigrationCreator $migrationCreator,
        private Composer $composer,
        private AppConfigRepositoryInterface $appConfigRepository,
    ) {
        parent::__construct();
    }

    /**
     * @throws FileNotFoundException
     */
    public function handle(): int
    {
        $migrationFileName = $this->getNameArgument();

        $this->writeMigration($migrationFileName);

        $this->composer->dumpAutoloads();

        return self::SUCCESS;
    }

    /**
     * @throws FileNotFoundException
     */
    private function writeMigration(
        string $migrationFileName,
    ): void {
        $filePath = $this->migrationCreator->create(
            $migrationFileName,
            $this->getMigrationPath(),
        );

        if (!$this->option('fullpath')) {
            $filePath = pathinfo($filePath, PATHINFO_FILENAME);
        }

        $this->line("<info>Created Migration:</info> $filePath");
    }

    protected function getNameArgument(): string
    {
        return trim($this->argument('name'));
    }

    /**
     * Get migration path (either specified by '--path' option or default location).
     */
    private function getMigrationPath(): string
    {
        $targetPath = $this->input->getOption('path');

        if ($targetPath !== null) {
            return $this->isUsingRealPath()
                ? $targetPath
                : $this->laravel->basePath() . '/' . $targetPath;
        }

        return rtrim(
            $this->appConfigRepository->get('clickhouse.migrations.path'),
            '/',
        );
    }

    /**
     * Determine if the given path(s) are pre-resolved "real" paths.
     */
    protected function isUsingRealPath(): bool
    {
        return $this->input->hasOption('realpath')
            && $this->option('realpath');
    }
}
