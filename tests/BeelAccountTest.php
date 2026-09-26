<?php

use Illuminate\Support\Facades\Http;
use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Http\RequestOptions;
use Lenorix\BeelSdk\Resource\AccountScope;
use Lenorix\LaravelBeel\BeelAccount;
use Lenorix\LaravelBeel\BeelHttpClientFactory;

beforeEach(function () {
    $this->client = new Beel(apiKey: 'test-key', baseUrl: 'https://example.test/api');
    $this->account = new BeelAccount($this->client, 'account-uuid');
});

it('exposes the account id, sdk scope, and raw client', function () {
    expect($this->account->accountId)->toBe('account-uuid')
        ->and($this->account->scope)->toBeInstanceOf(AccountScope::class)
        ->and($this->account->scope->accountId)->toBe('account-uuid')
        ->and($this->account->raw)->toBe($this->client->raw);
});

it('delegates property access to known scope resources', function () {
    expect($this->account->companies)->toBe($this->account->scope->companies)
        ->and($this->account->members)->toBe($this->account->scope->members);
});

it('throws for unknown scope properties', function () {
    /** @phpstan-ignore-next-line */
    $this->account->doesNotExist;
})->throws(LogicException::class, 'Unknown BeeL account resource [doesNotExist].');

it('delegates method calls to the scope', function () {
    Http::fake([
        'example.test/*' => Http::response([
            'data' => ['id' => 'account-uuid'],
        ], 200),
    ]);

    $client = new Beel(
        apiKey: 'test-key',
        baseUrl: 'https://example.test/api',
        httpClient: app(BeelHttpClientFactory::class)->make(0, 0),
    );
    $account = new BeelAccount($client, 'account-uuid');

    // get() is a real, non-deprecated AccountScope method reached only through BeelAccount::__call.
    $account->get();

    Http::assertSentCount(1);
});

it('keeps the account decorator through withOptions()', function () {
    $account = $this->account->withOptions(new RequestOptions(headers: ['X-Trace' => 't']));

    expect($account)->toBeInstanceOf(BeelAccount::class)
        ->and($account->accountId)->toBe($this->account->accountId)
        ->and($account->raw)->toBe($this->account->raw)
        ->and($account->scope)->not->toBe($this->account->scope);
});
