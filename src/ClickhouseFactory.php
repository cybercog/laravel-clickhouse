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

use ClickHouseDB\Client;
use Cog\Laravel\ClickhouseMigrations\Exceptions\ClickhouseConfigException;

final class ClickhouseFactory
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

        if (isset($config['options'])) {
            $options = $config['options'];

            unset($config['options']);
        }

        $client = new Client($config);

        foreach ($options as $option => $value) {
            if (method_exists($client, $option)) {
                $method = $option;
            } elseif (method_exists($client, 'set' . ucwords($option))) {
                $method = 'set' . ucwords($option);
            } else {
                throw new ClickhouseConfigException("Unknown ClickHouse DB option {$option}");
            }

            $client->$method($value);
        }

        return $client;
    }
}
