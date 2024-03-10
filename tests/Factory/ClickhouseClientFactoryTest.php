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

namespace Cog\Tests\Laravel\Clickhouse\Factory;

use Cog\Laravel\Clickhouse\Exception\ClickhouseConfigException;
use Cog\Laravel\Clickhouse\Factory\ClickhouseClientFactory;
use Cog\Tests\Laravel\Clickhouse\AbstractTestCase;
use Exception;

final class ClickhouseClientFactoryTest extends AbstractTestCase
{
    public function testInitializationWithMainConfig(): void
    {
        $clickhouse = new ClickhouseClientFactory(
            [
                'host' => 'example.com',
                'port' => '9000',
                'username' => 'test_user',
                'password' => 'secret',
                'options' => [
                    'database' => 'test_database',
                    'timeout' => 150,
                    'connectTimeOut' => 151,
                ],
            ]
        );

        $client = $clickhouse->create();

        self::assertSame('example.com', $client->getConnectHost());
        self::assertSame('9000', $client->getConnectPort());
        self::assertSame('test_user', $client->getConnectUsername());
        self::assertSame('secret', $client->getConnectPassword());
        self::assertSame('test_database', $client->settings()->getDatabase());
        self::assertSame(150, $client->getTimeout());
        self::assertSame(151.0, $client->getConnectTimeOut());
    }

    public function testInitializationWithNonExistsOption(): void
    {
        $clickhouseFactory = new ClickhouseClientFactory(
            [
                'host' => 'example.com',
                'port' => '9000',
                'username' => 'test_user',
                'password' => 'secret',
                'options' => [
                    'database' => 'test_database',
                    'timeout' => 150,
                    'connectTimeOut' => 151,
                    'nonExistsOption' => 'value',
                ],
            ]
        );

        try {
            $clickhouseFactory->create();

            self::fail(ClickhouseConfigException::class . 'is not thrown');
        } catch (Exception $exception) {
            self::assertSame(ClickhouseConfigException::class, get_class($exception));
        }
    }
}
