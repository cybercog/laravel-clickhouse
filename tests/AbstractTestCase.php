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

namespace Cog\Tests\Laravel\ClickhouseMigrations;

use Cog\Laravel\ClickhouseMigrations\ClickhouseMigrationsServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class AbstractTestCase extends OrchestraTestCase
{
    /**
     * {@inheritdoc}
     */
    protected function getPackageProviders(
        $app
    ): array {
        return [
            ClickhouseMigrationsServiceProvider::class,
        ];
    }
}
