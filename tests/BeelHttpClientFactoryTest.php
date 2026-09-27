<?php

use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\BeelSdk\Exception\BeelRateLimitError;
use Lenorix\LaravelBeel\BeelHttpClientFactory;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\LaravelNetworkException;
use Lenorix\LaravelBeel\Testing\BeelFake;

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    // No real waits: backoff of 0 ms. A Retry-After is always longer than this cap, so it throws.
    config()->set('beel.http.retry_delay_ms', 0);
    config()->set('beel.http.max_retry_delay_ms', 0);
});

it('sends requests through laravel http and never touches the network', function () {
    Http::fake(['example.test/*' => Http::response(['ok' => true], 200)]);

    $response = app(BeelHttpClientFactory::class)->make()->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe(json_encode(['ok' => true]));
    Http::assertSentCount(1);
});

it('never retries in the transport: retrying is the SDK\'s job', function () {
    Http::fake(['example.test/*' => Http::response('', 503)]);

    $response = app(BeelHttpClientFactory::class)->make()->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    expect($response->getStatusCode())->toBe(503);
    Http::assertSentCount(1);
});

it('lets the SDK retry a read that failed with a 5xx, up to beel.http.retries', function () {
    config()->set('beel.http.retries', 2);
    Http::fakeSequence('*/invoices/inv-1')
        ->pushResponse(BeelFake::error(503, 'SERVICE_UNAVAILABLE'))
        ->pushResponse(BeelFake::error(502, 'BAD_GATEWAY'))
        ->pushResponse(BeelFake::ok(BeelFake::invoice(['id' => 'inv-1'])));

    expect(app(BeelManager::class)->company()->invoices->get('inv-1')->getId())->toBe('inv-1');
    Http::assertSentCount(3);
});

it('never repeats a PATCH after a 5xx: it may already have been applied', function () {
    config()->set('beel.http.retries', 3);
    Http::fake(['*' => BeelFake::error(500, 'INTERNAL_ERROR')]);

    expect(fn () => app(BeelManager::class)->company()->invoices->update('inv-1', []))->toThrow(BeelApiError::class);
    Http::assertSentCount(1);
});

it('repeats a POST after a 5xx with the same Idempotency-Key, so BeeL applies it once', function () {
    config()->set('beel.http.retries', 1);
    Http::fakeSequence('*/customers')
        ->pushResponse(BeelFake::error(503, 'SERVICE_UNAVAILABLE'))
        ->pushResponse(BeelFake::ok(BeelFake::customer(), 201));

    app(BeelManager::class)->company()->customers->create(['legal_name' => 'Cliente SL', 'nif' => 'B87654321', 'address' => ['street' => 'Calle', 'number' => '1', 'postal_code' => '28013', 'city' => 'Madrid', 'province' => 'Madrid', 'country' => 'España']]);

    $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)->unique()->filter();
    expect(Http::recorded())->toHaveCount(2)->and($keys)->toHaveCount(1);
});

it('retries a connection error on a read', function () {
    config()->set('beel.http.retries', 1);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        return ++$calls === 1 ? throw new ConnectionException('Connection refused') : BeelFake::ok(BeelFake::invoice(['id' => 'inv-1']));
    });

    expect(app(BeelManager::class)->company()->invoices->get('inv-1')->getId())->toBe('inv-1')
        ->and($calls)->toBe(2);
});

it('gives up on connection errors with the original network exception', function () {
    config()->set('beel.http.retries', 2);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('Connection refused');
    });

    expect(fn () => app(BeelManager::class)->company()->invoices->get('inv-1'))->toThrow(LaravelNetworkException::class, 'Connection refused')
        ->and($calls)->toBe(3);
});

it('throws a rate limit it would have to wait longer than max_retry_delay_ms for, with how long', function () {
    config()->set('beel.http.retries', 3);
    config()->set('beel.http.max_retry_delay_ms', 60_000);
    Http::fake(['*' => BeelFake::error(429, 'RATE_LIMIT_EXCEEDED', retryAfter: 120)]);

    expect(fn () => app(BeelManager::class)->company()->invoices->get('inv-1'))
        ->toThrow(fn (BeelRateLimitError $e) => expect($e->retryAfterSeconds)->toBe(120));
    Http::assertSentCount(1);
});

it('disables retries with beel.http.retries 0', function () {
    config()->set('beel.http.retries', 0);
    Http::fake(['*' => BeelFake::error(503, 'SERVICE_UNAVAILABLE')]);

    expect(fn () => app(BeelManager::class)->company()->invoices->get('inv-1'))->toThrow(BeelApiError::class);
    Http::assertSentCount(1);
});

it('applies the configured timeout and connect timeout options', function () {
    config()->set('beel.http.timeout', 5);
    config()->set('beel.http.connect_timeout', 2);
    Http::fake(['example.test/*' => Http::response([], 200)]);

    app(BeelHttpClientFactory::class)->make()->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));

    Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://example.test/ping');
});
