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

use Cog\Laravel\Clickhouse\Exception\ClickhouseConfigException;

/**
 * ClickHouse accepts no query parameter for `ON CLUSTER`, and none for the
 * `CREATE TABLE` target on the versions this package supports. Those identifiers
 * are therefore interpolated into the SQL, which makes validating them the only
 * thing standing between a config value and an injected statement.
 */
final class Identifier
{
    private const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @throws ClickhouseConfigException
     */
    public static function ensureValid(
        string $identifier,
        string $subject,
    ): string {
        if (preg_match(self::PATTERN, $identifier) !== 1) {
            throw new ClickhouseConfigException(
                "Invalid {$subject} '{$identifier}'. "
                . 'It must start with a letter or an underscore and contain only letters, digits and underscores.',
            );
        }

        return $identifier;
    }

    public static function quote(
        string $identifier,
    ): string {
        return '`' . $identifier . '`';
    }
}
