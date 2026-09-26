<?php

use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Support\Settings;

mutates(Settings::class);

it('reads numbers from config values and env strings', function () {
    config()->set('beel.test.int', '30');
    config()->set('beel.test.float', '2.5');

    expect(Settings::int('beel.test.int', 1))->toBe(30)
        ->and(Settings::float('beel.test.float', 1.0))->toBe(2.5)
        ->and(Settings::int('beel.test.missing', 7))->toBe(7)
        ->and(Settings::string('beel.test.int', ''))->toBe('30');
});

it('keeps an explicit null apart from a missing value when null means off', function () {
    config()->set('beel.test.off', null);

    expect(Settings::optionalInt('beel.test.off', 250))->toBeNull()
        ->and(Settings::optionalInt('beel.test.missing', 250))->toBe(250)
        ->and(Settings::int('beel.test.off', 250))->toBe(250);
});

it('fails loudly, naming the key, instead of casting a wrong value to zero', function () {
    config()->set('beel.test.int', 'thirty');

    expect(fn () => Settings::int('beel.test.int', 1))->toThrow(InvalidArgumentException::class, 'Config beel.test.int must be an integer; got string.');
});

it('surfaces a wrong setting from a real call', function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('beel.http.timeout', ['30']);

    expect(fn () => app(BeelManager::class)->client())->toThrow(InvalidArgumentException::class, 'beel.http.timeout');
});
