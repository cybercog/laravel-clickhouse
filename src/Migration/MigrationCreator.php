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

use Illuminate\Filesystem\Filesystem;

class MigrationCreator
{
    private Filesystem $filesystem;
    private ?string $migrationStubFilePath;

    public function __construct(
        Filesystem $filesystem,
        ?string $migrationStubFilePath
    ) {
        $this->filesystem = $filesystem;
        $this->migrationStubFilePath = $migrationStubFilePath;
    }

    public function create(
        string $fileName,
        string $migrationsDirectoryPath
    ): ?string {
        $stubFileContent = $this->getStubFileContent();

        $filePath = $this->generateMigrationFilePath($fileName, $migrationsDirectoryPath);

        $this->filesystem->ensureDirectoryExists(dirname($filePath));

        $this->filesystem->put($filePath, $stubFileContent);

        return $filePath;
    }

    private function generateMigrationFilePath(
        string $name,
        string $migrationsDirectoryPath
    ): string {
        return $migrationsDirectoryPath . '/' . $this->getDatePrefix() . '_' . $name . '.php';
    }

    /**
     * Get the date prefix for the migration.
     */
    protected function getDatePrefix(): string
    {
        return date('Y_m_d_His');
    }

    protected function getStubFileContent(): string
    {
        $stubFileName = 'clickhouse-migration.stub';
        $customStubFilePath = $this->migrationStubFilePath . '/' . $stubFileName;

        $stub = $this->filesystem->exists($customStubFilePath)
            ? $customStubFilePath
            : $this->getDefaultStubFilePath() . '/' . $stubFileName;

        return $this->filesystem->get($stub);
    }

    public function getDefaultStubFilePath(): string
    {
        return __DIR__ . '/../../stubs';
    }
}
