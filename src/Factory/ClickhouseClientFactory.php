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

namespace Cog\Laravel\Clickhouse\Factory;

use ClickHouseDB\Client;
use Cog\Laravel\Clickhouse\Exception\ClickhouseConfigException;

final class ClickhouseClientFactory
{
    private array $defaultConfig;

    public function __construct(
        array $defaultConfig
    ) {
        $this->defaultConfig = $defaultConfig;
    }

    /**
     * Creating a new instance of ClickHouse Client.
     *
     * @param array $config
     * @return Client
     *
     * @throws ClickhouseConfigException
     */
    public function create(
        array $config = []
    ): Client {
        if (count($config) === 0) {
            $config = $this->defaultConfig;
        }

        $options = [];
        $settings = [];

        if (isset($config['options'])) {
            $options = $config['options'];

            unset($config['options']);
        }

        if (isset($config['settings'])) {
            $settings = $config['settings'];

            unset($config['settings']);
        }

        $client = new Client($config);

        foreach ($options as $option => $value) {
            $method = $this->resolveOptionMutatorMethod($client, $option);

            $client->$method($value);
        }

        foreach ($settings as $setting => $value) {
            $client->settings()->set($setting, $value);
        }

        return $client;
    }

    /**
     * @throws ClickhouseConfigException
     */
    private function resolveOptionMutatorMethod(
        Client $client,
        string $option
    ): string {
        if (method_exists($client, $option)) {
            return $option;
        }

        if (method_exists($client, 'set' . ucwords($option))) {
            return 'set' . ucwords($option);
        }

        throw new ClickhouseConfigException("Unknown ClickHouse DB option {$option}");
    }
}
