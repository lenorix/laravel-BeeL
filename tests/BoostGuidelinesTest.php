<?php

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__).'/resources/boost';

it('renders the core Boost guideline as Blade without errors', function () use ($root) {
    $rendered = Blade::render(file_get_contents($root.'/guidelines/core.blade.php'));

    expect($rendered)
        ->toContain('BeelManager')
        ->toContain('beel-invoicing')
        ->toContain('Idempotency-Key')
        ->not->toContain('{{--');
});

it('ships a beel-invoicing skill whose frontmatter follows the Agent Skills spec', function () use ($root) {
    $skill = file_get_contents($root.'/skills/beel-invoicing/SKILL.md');

    expect(preg_match('/\A---\n(.*?)\n---\n/s', $skill, $frontmatter))->toBe(1);

    // https://agentskills.io/specification
    $meta = Yaml::parse($frontmatter[1]);

    expect($meta['name'])->toBe('beel-invoicing')
        ->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/')
        ->and(strlen($meta['name']))->toBeLessThanOrEqual(64)
        ->and($meta['description'])->toBeString()->not->toBeEmpty()
        ->and(mb_strlen($meta['description']))->toBeLessThanOrEqual(1024)
        ->and($meta['metadata'])->each->toBeString();
});

it('only references skill files that exist', function () use ($root) {
    $skill = file_get_contents($root.'/skills/beel-invoicing/SKILL.md');

    preg_match_all('#`(references/[a-z-]+\.md)`#', $skill, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $reference) {
        expect($root.'/skills/beel-invoicing/'.$reference)->toBeFile();
    }
});
