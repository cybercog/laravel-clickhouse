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

namespace Cog\Tests\Laravel\Clickhouse\ConsoleCommand;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;
use Illuminate\Filesystem\Filesystem;

final class ClickhouseMigrateCommandTest extends AbstractTestCase
{
    private string $directory;
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->app->basePath('migration-command-' . bin2hex(random_bytes(8)));
        $filesystem = new Filesystem();

        foreach (['configured' => 101, 'selected' => 202] as $directory => $value) {
            $path = $this->directory . '/' . $directory;
            $filesystem->ensureDirectoryExists($path);
            $filesystem->put(
                $path . '/2026_01_01_000000_create_example.php',
                '<?php return new class extends \\Cog\\Laravel\\Clickhouse\\Migration\\AbstractClickhouseMigration {
                    public function up(): void { $this->clickhouseClient->write("SELECT ' . $value . '"); }
                };',
            );
        }

        config(['clickhouse.migrations.path' => $this->directory . '/configured']);

        $client = $this->createMock(Client::class);
        $client->method('select')->willReturnCallback(function (string $sql): Statement {
            $statement = $this->createMock(Statement::class);
            $statement->method('fetchOne')->willReturn(str_contains($sql, 'EXISTS') ? 1 : 0);
            $statement->method('rows')->willReturn([]);

            return $statement;
        });
        $client->method('write')->willReturnCallback(function (string $sql): Statement {
            $this->writes[] = $sql;

            return $this->createMock(Statement::class);
        });
        $client->method('insert')->willReturn($this->createMock(Statement::class));
        $this->app->instance(Client::class, $client);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function testUsesConfiguredPathByDefault(): void
    {
        $this->artisan('clickhouse:migrate')->assertSuccessful();

        self::assertSame(['SELECT 101'], $this->writes);
    }

    public function testUsesPathRelativeToApplication(): void
    {
        $this->artisan('clickhouse:migrate', [
            '--path' => basename($this->directory) . '/selected',
        ])->assertSuccessful();

        self::assertSame(['SELECT 202'], $this->writes);
    }

    public function testUsesAbsolutePathWithRealpath(): void
    {
        $this->artisan('clickhouse:migrate', [
            '--path' => $this->directory . '/selected',
            '--realpath' => true,
        ])->assertSuccessful();

        self::assertSame(['SELECT 202'], $this->writes);
    }
}
