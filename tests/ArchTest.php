<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('autoloads every class on its own, one per file', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $file = (string) $file;
        preg_match_all('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)/m', (string) file_get_contents($file), $matches);

        expect($matches[1])->toBe([basename($file, '.php')], "{$file} must declare exactly the class it is named after");
    }
});
