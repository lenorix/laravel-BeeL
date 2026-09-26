<?php

use Symfony\Component\Process\Process;

// Every PHP example in the README and the Boost docs must at least parse: agents and readers copy them.
$root = dirname(__DIR__);
$documents = [
    $root.'/README.md',
    $root.'/resources/boost/guidelines/core.blade.php',
    ...glob($root.'/resources/boost/skills/beel-invoicing/*.md'),
    ...glob($root.'/resources/boost/skills/beel-invoicing/references/*.md'),
];

$snippets = [];
foreach ($documents as $document) {
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents($document), $matches);
    foreach ($matches[1] as $index => $code) {
        $snippets[basename($document).' #'.($index + 1)] = [$code];
    }
}

it('has PHP examples that parse', function (string $code) {
    // A config fragment ("'key' => ...,") is the body of an array.
    $source = str_starts_with(ltrim($code), "'") ? "<?php\n\$fragment = [\n{$code}\n];\n" : "<?php\n{$code}";

    $lint = new Process([PHP_BINARY, '-l']);
    $lint->setInput($source)->run();

    expect($lint->getExitCode())->toBe(0, $lint->getOutput().$lint->getErrorOutput());
})->with($snippets);
