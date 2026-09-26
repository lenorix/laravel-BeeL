<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

use Illuminate\Container\Container;

/**
 * Typed reads of the package's config values. Numeric strings (from env()) are accepted; anything
 * else that isn't the expected type fails loudly with the key's name, instead of a cast silently
 * turning a typo into 0.
 *
 * @internal
 */
final class Settings
{
    public static function int(string $key, int $default): int
    {
        return self::optionalInt($key, $default) ?? $default;
    }

    /** Like int(), but an explicit null (or '') stays null, for settings where null means "off". */
    public static function optionalInt(string $key, int $default): ?int
    {
        $value = self::get($key, $default);

        return match (true) {
            $value === null, $value === '' => null,
            is_int($value) => $value,
            is_string($value) && is_numeric($value), is_float($value) => (int) $value,
            default => throw self::invalid($key, 'an integer', $value),
        };
    }

    public static function float(string $key, float $default): float
    {
        $value = self::get($key, $default);

        return match (true) {
            $value === null, $value === '' => $default,
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => throw self::invalid($key, 'a number', $value),
        };
    }

    public static function string(string $key, string $default): string
    {
        $value = self::get($key, $default);

        return match (true) {
            $value === null => $default,
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => throw self::invalid($key, 'a string', $value),
        };
    }

    private static function get(string $key, mixed $default): mixed
    {
        return Container::getInstance()->make('config')->get($key, $default);
    }

    private static function invalid(string $key, string $expected, mixed $value): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Config {$key} must be {$expected}; got ".get_debug_type($value).'.');
    }
}
