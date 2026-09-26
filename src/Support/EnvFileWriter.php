<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

/**
 * @internal Sets one KEY=value line in a .env file atomically (temp file + rename), keeping every
 * other line and the file's permissions.
 */
class EnvFileWriter
{
    public function write(string $path, string $key, string $value): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        $line = $key.'='.self::quote($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        $updated = preg_match($pattern, $contents) === 1
            ? (string) preg_replace_callback($pattern, fn () => $line, $contents, 1)
            : ($contents === '' || str_ends_with($contents, "\n") ? $contents : $contents."\n").$line."\n";

        $temp = tempnam(dirname($path), '.env.beel');
        if ($temp === false || file_put_contents($temp, $updated) === false) {
            throw new \RuntimeException("Cannot write a temporary file next to {$path}.");
        }

        $permissions = fileperms($path);
        if ($permissions !== false) {
            chmod($temp, $permissions & 0777);
        }

        if (! rename($temp, $path)) {
            @unlink($temp);

            throw new \RuntimeException("Cannot replace {$path}.");
        }
    }

    /** Plain tokens stay bare; anything else is single-quoted so '#', spaces or '$' are taken literally. */
    private static function quote(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_\-]*$/', $value) === 1) {
            return $value;
        }

        if (str_contains($value, "'") || str_contains($value, "\n")) {
            throw new \InvalidArgumentException('The value cannot be written safely to a .env file.');
        }

        return "'{$value}'";
    }
}
