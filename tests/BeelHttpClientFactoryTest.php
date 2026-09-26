<?php

use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Lenorix\LaravelBeel\BeelHttpClientFactory;

it('sends requests through laravel http and never touches the network', function () {
    Http::fake([
        'example.test/*' => Http::response(['ok' => true], 200),
    ]);

    $client = app(BeelHttpClientFactory::class)->make(retries: 0, retryDelayMs: 0);
    $response = $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe(json_encode(['ok' => true]));

    Http::assertSentCount(1);
});

it('retries on 429 and 5xx up to the configured number of attempts', function () {
    Http::fake([
        'example.test/*' => Http::sequence()
            ->push(['error' => true], 503)
            ->push(['error' => true], 503)
            ->push(['ok' => true], 200),
    ]);

    $client = app(BeelHttpClientFactory::class)->make(retries: 2, retryDelayMs: 0);
    $response = $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    expect($response->getStatusCode())->toBe(200);
    Http::assertSentCount(3);
});

it('waits for the Retry-After duration on a 429 instead of the fixed delay', function () {
    Sleep::fake();

    Http::fake([
        'example.test/*' => Http::sequence()
            ->push(['error' => true], 429, ['Retry-After' => '5'])
            ->push(['ok' => true], 200),
    ]);

    $client = app(BeelHttpClientFactory::class)->make(retries: 1, retryDelayMs: 100);
    $response = $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    expect($response->getStatusCode())->toBe(200);
    Http::assertSentCount(2);
    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 5000.0);
});

it('falls back to the configured delay when the 429 has no Retry-After header', function () {
    Sleep::fake();

    Http::fake([
        'example.test/*' => Http::sequence()
            ->push(['error' => true], 429)
            ->push(['ok' => true], 200),
    ]);

    $client = app(BeelHttpClientFactory::class)->make(retries: 1, retryDelayMs: 250);
    $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 250.0);
});

it('caps an excessive Retry-After to 60 seconds', function () {
    Sleep::fake();

    Http::fake([
        'example.test/*' => Http::sequence()
            ->push(['error' => true], 429, ['Retry-After' => '3600'])
            ->push(['ok' => true], 200),
    ]);

    $client = app(BeelHttpClientFactory::class)->make(retries: 1, retryDelayMs: 100);
    $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 60_000.0);
});

it('ignores a non-numeric Retry-After header', function () {
    Sleep::fake();

    Http::fake([
        'example.test/*' => Http::sequence()
            ->push(['error' => true], 429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'])
            ->push(['ok' => true], 200),
    ]);

    $client = app(BeelHttpClientFactory::class)->make(retries: 1, retryDelayMs: 150);
    $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 150.0);
});

it('does not retry on 4xx errors other than 429', function () {
    Http::fake([
        'example.test/*' => Http::response(['error' => true], 400),
    ]);

    $client = app(BeelHttpClientFactory::class)->make(retries: 2, retryDelayMs: 0);
    $response = $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    expect($response->getStatusCode())->toBe(400);
    Http::assertSentCount(1);
});

it('applies the configured timeout and connect timeout options', function () {
    config()->set('beel.http.timeout', 5);
    config()->set('beel.http.connect_timeout', 2);

    Http::fake(['example.test/*' => Http::response([], 200)]);

    $client = app(BeelHttpClientFactory::class)->make(retries: 0, retryDelayMs: 0);
    $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    Http::assertSentCount(1);
});
