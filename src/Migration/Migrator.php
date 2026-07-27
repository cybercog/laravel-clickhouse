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

namespace Cog\Laravel\Clickhouse\Migration;

use DomainException;
use Generator;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;

use function in_array;

final class Migrator
{
    public function __construct(
        private readonly MigrationRepository $repository,
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @throws FileNotFoundException
     */
    public function runUp(
        string $migrationsDirectoryPath,
        OutputStyle $output,
        int $step,
    ): void {
        $migrations = $this->getMigrationsUp($migrationsDirectoryPath);

        if ($migrations->valid() === false) {
            $output->writeln(
                '<info>Migrations are empty.</info>',
            );

            return;
        }

        $nextBatch = $this->repository->getNextBatchNumber();

        for ($i = $step; ($i > 0 || $step === 0) && $migrations->valid(); $i--) {
            $startTime = microtime(true);

            $migration = $this->resolveMigrationInstance($migrations->current());
            $migration->up();

            $runTime = round(microtime(true) - $startTime, 2);

            $migrationName = $this->resolveMigrationNameFromInstance($migration);

            $this->repository->add($migrationName, $nextBatch);

            $output->writeln(
                "<info>Completed in {$runTime} seconds</info> {$migrationName}",
            );

            $migrations->next();
        }
    }

    /**
     * `EXISTS TABLE` only reflects the node the client happens to talk to, so it
     * cannot gate an `ON CLUSTER` create. The create is issued unconditionally and
     * is idempotent — but only after making sure the registry that may already be
     * there was built for the configured topology.
     */
    public function ensureTableExists(): self
    {
        $this->repository->ensureEngineMatchesTopology();
        $this->repository->createMigrationRegistryTable();

        return $this;
    }

    /**
     * @return Generator<int, string, mixed, void> Absolute paths, in file order.
     */
    private function getMigrationsUp(
        string $migrationsDirectoryPath,
    ): Generator {
        $migrationFiles = $this->getUnAppliedMigrationFiles($migrationsDirectoryPath);

        foreach ($migrationFiles as $migrationFile) {
            yield $migrationsDirectoryPath . '/' . $migrationFile->getFilename();
        }
    }

    private function getMigrationName(
        string $migrationFilePath,
    ): string {
        return str_replace('.php', '', basename($migrationFilePath));
    }

    /**
     * The registry is read once per run, not once per file: in replicated mode every
     * read drains the replication queue, so a per-file check would make a deploy pay
     * for it as many times as the directory has migrations.
     *
     * @return list<SplFileInfo>
     */
    private function getUnAppliedMigrationFiles(
        string $migrationsDirectoryPath,
    ): array {
        $appliedMigrations = $this->repository->all();

        return collect($this->filesystem->files($migrationsDirectoryPath))
            ->reject(
                fn(SplFileInfo $migrationFile) => in_array(
                    $this->getMigrationName($migrationFile->getFilename()),
                    $appliedMigrations,
                    true,
                ),
            )->all();
    }

    /**
     * A migration file returns the anonymous class it declares, already built — it
     * reaches for its own client, because nothing here can hand it one.
     *
     * @throws FileNotFoundException
     */
    private function resolveMigrationInstance(
        string $path,
    ): object {
        $migration = $this->filesystem->getRequire($path);

        if (is_object($migration) === false) {
            throw new DomainException("Migration {$path} must return a migration instance");
        }

        return $migration;
    }

    /**
     * Read off the file the class was declared in, which is why only anonymous
     * classes are supported: a named one could be declared anywhere.
     */
    private function resolveMigrationNameFromInstance(
        object $migration,
    ): string {
        $reflectionClass = new ReflectionClass($migration);

        if ($reflectionClass->isAnonymous() === false) {
            throw new DomainException('Only anonymous migrations are supported');
        }

        return $this->getMigrationName($reflectionClass->getFileName());
    }
}
