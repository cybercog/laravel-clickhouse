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

namespace Cog\Laravel\ClickhouseMigrations\Migrations;

use ClickHouseDB\Client;
use DomainException;
use Generator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;

use function in_array;

final class Migrator
{
    private Client $client;

    protected MigrationRepository $repository;

    protected Filesystem $filesystem;

    public function __construct(
        Client $client,
        MigrationRepository $repository,
        Filesystem $files
    ) {
        $this->client = $client;
        $this->filesystem = $files;
        $this->repository = $repository;
    }

    /**
     * @throws FileNotFoundException
     */
    public function runUp(
        string $migrationsDirectoryPath,
        OutputStyle $output,
        int $step
    ): void {
        $migrations = $this->getMigrationsUp($migrationsDirectoryPath);

        if ($migrations->valid() === false) {
            $output->writeln(
                '<info>Migrations are empty.</info>'
            );

            return;
        }

        $nextBatch = $this->repository->getNextBatchNumber();

        for ($i = $step; ($i > 0 || $step === 0) && $migrations->valid(); $i--) {
            $this->filesystem->requireOnce($migrations->current());

            $startTime = microtime(true);

            $migration = $this->resolveMigrationInstance($migrations->current());
            $migration->up();

            $runTime = round(microtime(true) - $startTime, 2);

            $migrationName = $this->resolveMigrationNameFromInstance($migration);

            $this->repository->add($migrationName, $nextBatch);

            $output->writeln(
                "<info>Completed in {$runTime} seconds</info> {$migrationName}"
            );

            $migrations->next();
        }
    }

    public function ensureTableExists(): self
    {
        if ($this->repository->exists() === false) {
            $this->repository->createMigrationRegistryTable();
        }

        return $this;
    }

    private function getMigrationsUp(
        string $migrationsDirectoryPath
    ): Generator {
        $migrationFiles = $this->getUnAppliedMigrationFiles($migrationsDirectoryPath);

        foreach ($migrationFiles as $migrationFile) {
            yield $migrationsDirectoryPath . '/' . $migrationFile->getFilename();
        }
    }

    private function getMigrationName(
        string $migrationFilePath
    ): string {
        return str_replace('.php', '', basename($migrationFilePath));
    }

    /**
     * @return SplFileInfo[]
     */
    private function getUnAppliedMigrationFiles(
        string $migrationsDirectoryPath
    ): array {
        $migrationFiles = $this->filesystem->files($migrationsDirectoryPath);

        return collect($migrationFiles)
            ->reject(
                fn(SplFileInfo $migrationFile) => $this->isAppliedMigration(
                    $migrationFile->getFilename(),
                )
            )->all();
    }

    /**
     * @throws FileNotFoundException
     */
    private function resolveMigrationInstance(
        string $path
    ): object {
        $class = $this->generateMigrationClassName($this->getMigrationName($path));

        if (class_exists($class) && realpath($path) === (new ReflectionClass($class))->getFileName()) {
            return new $class($this->client);
        }

        $migration = $this->filesystem->getRequire($path);

        return is_object($migration) ? $migration : new $class($this->client);
    }

    private function generateMigrationClassName(
        string $migrationName
    ): string {
        return Str::studly(
            implode('_', array_slice(explode('_', $migrationName), 4))
        );
    }

    private function resolveMigrationNameFromInstance(
        object $migration
    ): string {
        $reflectionClass = new ReflectionClass($migration);

        if ($reflectionClass->isAnonymous() === false) {
            throw new DomainException('Only anonymous migrations are supported');
        }

        return $this->getMigrationName($reflectionClass->getFileName());
    }

    private function isAppliedMigration(
        string $fileName
    ): bool {
        return in_array(
            $this->getMigrationName($fileName),
            $this->repository->all(),
            true
        );
    }
}
