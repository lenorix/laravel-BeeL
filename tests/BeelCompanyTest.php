<?php

use Illuminate\Support\Facades\Http;
use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Http\RequestOptions;
use Lenorix\BeelSdk\Resource\CompanyScope;
use Lenorix\LaravelBeel\BeelCompany;
use Lenorix\LaravelBeel\BeelCompanyInvoices;
use Lenorix\LaravelBeel\BeelHttpClientFactory;

beforeEach(function () {
    $this->client = new Beel(apiKey: 'test-key', baseUrl: 'https://example.test/api');
    $this->company = new BeelCompany($this->client, 'company-uuid');
});

it('exposes the company id, sdk scope, and raw client', function () {
    expect($this->company->companyId)->toBe('company-uuid')
        ->and($this->company->scope)->toBeInstanceOf(CompanyScope::class)
        ->and($this->company->scope->companyId)->toBe('company-uuid')
        ->and($this->company->raw)->toBe($this->client->raw);
});

it('delegates property access to known scope resources', function () {
    expect($this->company->customers)->toBe($this->company->scope->customers)
        ->and($this->company->invoices)->toBeInstanceOf(BeelCompanyInvoices::class)
        ->and($this->company->invoices->resource)->toBe($this->company->scope->invoices)
        ->and($this->company->invoices->schedule)->toBe($this->company->scope->invoices->schedule);
});

it('keeps the invoices decorator through withOptions()', function () {
    $invoices = $this->company->invoices->withOptions(new RequestOptions(idempotencyKey: 'k-1'));

    expect($invoices)->toBeInstanceOf(BeelCompanyInvoices::class)
        ->and($invoices->resource)->not->toBe($this->company->scope->invoices);
});

it('throws for unknown scope properties', function () {
    /** @phpstan-ignore-next-line */
    $this->company->doesNotExist;
})->throws(LogicException::class, 'Unknown BeeL company resource [doesNotExist].');

it('delegates method calls to the scope', function () {
    Http::fake([
        'example.test/*' => Http::response([
            'data' => ['id' => 'company-uuid', 'name' => 'Acme'],
        ], 200),
    ]);

    $client = new Beel(
        apiKey: 'test-key',
        baseUrl: 'https://example.test/api',
        httpClient: app(BeelHttpClientFactory::class)->make(0, 0),
    );
    $company = new BeelCompany($client, 'company-uuid');

    // get() is a real, non-deprecated CompanyScope method reached only through BeelCompany::__call.
    $company->get();

    Http::assertSentCount(1);
});
