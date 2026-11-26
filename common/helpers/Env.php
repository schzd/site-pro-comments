<?php

declare(strict_types=1);

namespace common\helpers;

use yii\base\InvalidConfigException;

/**
 * Strict access to environment configuration.
 *
 * There are deliberately no default values: a missing or malformed variable
 * stops the application at boot with a message naming the variable, instead
 * of silently running against a guessed host, channel or secret.
 */
final class Env
{
    public static function string(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new InvalidConfigException(
                "Required environment variable {$name} is not set. See docker-compose.yml (x-php-env)."
            );
        }

        return $value;
    }

    public static function int(string $name): int
    {
        $value = self::string($name);
        if (!preg_match('/^\d+$/', $value)) {
            throw new InvalidConfigException("Environment variable {$name} must be a non-negative integer, got '{$value}'.");
        }

        return (int) $value;
    }

    public static function bool(string $name): bool
    {
        $value = strtolower(self::string($name));
        return match ($value) {
            '1', 'on', 'true', 'yes' => true,
            '0', 'off', 'false', 'no' => false,
            default => throw new InvalidConfigException(
                "Environment variable {$name} must be one of on/off/true/false/1/0, got '{$value}'."
            ),
        };
    }
}
