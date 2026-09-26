<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

/**
 * @internal Sets one KEY=value line in a .env file, keeping every other line. Writes in place (like
 * Laravel's key:generate): following a symlinked .env to the shared file instead of replacing the link,
 * and keeping the file's inode, owner, group and mode, which a temp-file-and-rename would not.
 */
class EnvFileWriter
{
    public function write(string $path, string $key, string $value): void
    {
        $real = realpath($path);
        $contents = $real === false ? false : file_get_contents($real);
        if ($real === false || $contents === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        $line = $key.'='.self::quote($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        $updated = preg_match($pattern, $contents) === 1
            ? (string) preg_replace_callback($pattern, fn () => $line, $contents, 1)
            : ($contents === '' || str_ends_with($contents, "\n") ? $contents : $contents."\n").$line."\n";

        if (file_put_contents($real, $updated, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write {$path}.");
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
