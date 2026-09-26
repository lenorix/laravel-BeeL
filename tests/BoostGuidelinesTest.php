<?php

use Illuminate\Support\Facades\Blade;

$root = dirname(__DIR__).'/resources/boost';

it('renders the core Boost guideline as Blade without errors', function () use ($root) {
    $rendered = Blade::render(file_get_contents($root.'/guidelines/core.blade.php'));

    expect($rendered)
        ->toContain('BeelManager')
        ->toContain('beel-invoicing')
        ->toContain('Idempotency-Key')
        ->not->toContain('{{--');
});

it('ships a beel-invoicing skill with valid frontmatter', function () use ($root) {
    $skill = file_get_contents($root.'/skills/beel-invoicing/SKILL.md');

    expect($skill)
        ->toStartWith("---\nname: beel-invoicing\ndescription: ")
        ->toMatch('/^---\n.*?\n---\n/s');
});

it('only references skill files that exist', function () use ($root) {
    $skill = file_get_contents($root.'/skills/beel-invoicing/SKILL.md');

    preg_match_all('#`(references/[a-z-]+\.md)`#', $skill, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $reference) {
        expect($root.'/skills/beel-invoicing/'.$reference)->toBeFile();
    }
});
