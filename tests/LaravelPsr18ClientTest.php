<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Support\Facades\Http;
use Lenorix\LaravelBeel\BeelHttpClientFactory;
use Lenorix\LaravelBeel\LaravelClientException;
use Lenorix\LaravelBeel\LaravelNetworkException;

it('wraps a Guzzle connection failure into a LaravelNetworkException', function () {
    Http::fake(fn () => throw new ConnectException(
        'Could not connect',
        new Psr7Request('GET', 'https://example.test/ping'),
    ));

    $client = app(BeelHttpClientFactory::class)->make(retries: 0, retryDelayMs: 0);
    $request = new Psr7Request('GET', 'https://example.test/ping');

    try {
        $client->sendRequest($request);
        $this->fail('Expected a LaravelNetworkException to be thrown.');
    } catch (LaravelNetworkException $exception) {
        expect($exception->getRequest())->toBe($request);
    }
});

it('wraps any other transport failure into a LaravelClientException', function () {
    Http::fake(fn () => throw new RuntimeException('boom'));

    $client = app(BeelHttpClientFactory::class)->make(retries: 0, retryDelayMs: 0);

    try {
        $client->sendRequest(new Psr7Request('GET', 'https://example.test/ping'));
        $this->fail('Expected a LaravelClientException to be thrown.');
    } catch (LaravelClientException $exception) {
        expect($exception->getMessage())->toBe('boom');
    }
});
