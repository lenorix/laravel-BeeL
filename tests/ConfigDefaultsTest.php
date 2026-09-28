<?php

// Every fallback in src/ must match config/beel.php, so an app without the published key (or an older
// published file) behaves exactly as the documented default. They drifted once (100 ms vs 500 ms).

/** @return list<array{string, string, string}> [file, key, fallback literal] */
function configFallbacks(): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src'));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all("/(?:Settings::\\w+|config)\\('beel\\.([\\w.]+)',\\s*([^)]+?)\\)/", (string) file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);
        foreach ($matches as [, $key, $fallback]) {
            $found[] = [basename($file->getPathname()), $key, $fallback];
        }
    }

    return $found;
}

it('finds the fallbacks it checks', function () {
    expect(count(configFallbacks()))->toBeGreaterThan(10);
});

it('uses the published config default as every fallback in the code', function () {
    $config = require dirname(__DIR__).'/config/beel.php';

    foreach (configFallbacks() as [$file, $key, $fallback]) {
        expect(data_get($config, $key, '(missing)'))
            ->toEqual(eval("return {$fallback};"), "{$file}: beel.{$key} falls back to {$fallback}, config/beel.php says otherwise");
    }
});
