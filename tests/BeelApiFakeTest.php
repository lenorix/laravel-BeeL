<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lenorix\BeelSdk\Builder\CustomerBuilder;
use Lenorix\BeelSdk\Exception\BeelValidationError;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;
use Lenorix\LaravelBeel\LaravelClientException;
use Lenorix\LaravelBeel\Testing\BeelFake;

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('services.beel.account_id', 'acc-1');
    config()->set('beel.http.retries', 0);
    Sleep::fake();
});

it('fakes invoice operations by name', function () {
    BeelFake::api()
        ->getInvoice(BeelFake::invoice(['id' => 'inv-1', 'status' => 'DRAFT']))
        ->issueInvoice(BeelFake::invoice(['id' => 'inv-1', 'status' => 'ISSUED']))
        ->listInvoices([BeelFake::invoice(['id' => 'inv-1']), BeelFake::invoice(['id' => 'inv-2'])])
        ->fake();
    $invoices = app(BeelManager::class)->company()->invoices;

    expect($invoices->get('inv-1')->getStatus())->toBe('DRAFT')
        ->and($invoices->issue('inv-1')->getStatus())->toBe('ISSUED')
        ->and(array_map(fn ($i) => $i->getId(), iterator_to_array($invoices->all(), false)))->toBe(['inv-1', 'inv-2']);
});

it('answers creations with 201 and errors as given', function () {
    BeelFake::api()
        ->createCustomer(BeelFake::error(422, 'VALIDATION_ERROR', 'Invalid NIF'))
        ->createCustomer(BeelFake::customer(['id' => 'c-1']))
        ->fake();
    $customers = app(BeelManager::class)->company()->customers;
    $request = CustomerBuilder::create()->name('Cliente SL')->nif('B87654321')
        ->address('Calle Mayor', '1', '28013', 'Madrid', 'Madrid', 'España')->build();

    expect(fn () => $customers->create($request))->toThrow(BeelValidationError::class, 'Invalid NIF')
        ->and($customers->create($request)->getId())->toBe('c-1');
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/companies/company-1/customers'));
});

it('narrows an operation to one id', function () {
    BeelFake::api()
        ->getCustomer(BeelFake::customer(['legal_name' => 'Uno SL']), id: 'c-1')
        ->getCustomer(BeelFake::customer(['legal_name' => 'Otro SL']))
        ->fake();
    $customers = app(BeelManager::class)->company()->customers;

    expect($customers->get('c-1')->getLegalName())->toBe('Uno SL')
        ->and($customers->get('c-2')->getLegalName())->toBe('Otro SL');
});

it('fakes the PDF link and its download so storePdf works end to end, also after a 202', function () {
    Storage::fake('invoices');
    BeelFake::api()
        ->invoicePdf(Http::response(null, 202, ['Retry-After' => '3']))
        ->invoicePdf('%PDF-1.7 from the api fake')
        ->fake();
    $invoices = app(BeelManager::class)->company()->invoices;

    expect(fn () => $invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices'))->toThrow(InvoicePdfNotReady::class);

    $invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices');
    $invoices->storePdf('inv-1', 'b.pdf', disk: 'invoices'); // the last answer repeats, download included

    expect(Storage::disk('invoices')->get('a.pdf'))->toBe('%PDF-1.7 from the api fake')
        ->and(Storage::disk('invoices')->get('b.pdf'))->toBe('%PDF-1.7 from the api fake');
});

it('fakes account-level and identity operations', function () {
    BeelFake::api()
        ->identity(BeelFake::identity(scopes: ['accounts:read']))
        ->listWebhookSubscriptions([BeelFake::webhookSubscription(['id' => 'wh-1'])])
        ->listManagedAccounts([BeelFake::managedAccount(['account_id' => 'a-1'])])
        ->issuingReadiness(BeelFake::issuingReadiness(['REPRESENTATION_NOT_SIGNED']))
        ->fake();
    $beel = app(BeelManager::class);

    expect($beel->client()->me->identity()->getCredential()->getScopes())->toBe(['accounts:read'])
        ->and(iterator_to_array($beel->account()->webhooks->all(), false)[0]->getId())->toBe('wh-1')
        ->and(iterator_to_array($beel->client()->accounts->all(), false)[0]->getAccountId())->toBe('a-1')
        ->and($beel->company()->issuingReadiness()->getBlockers())->toBe(['REPRESENTATION_NOT_SIGNED']);
});

it('fakes any other operation by path', function () {
    BeelFake::api()->on('GET', '/v1/companies/{company}/series', BeelFake::page('series', [['id' => 's-1', 'code' => 'A']]))->fake();

    expect(Http::get('https://app.beel.es/api/v1/companies/company-1/series')->json('data.series.0.code'))->toBe('A');
    Http::assertSentCount(1);
});

it('lets unfaked requests fall through to stray request prevention', function () {
    BeelFake::api()->listCustomers()->fake();

    expect(fn () => app(BeelManager::class)->company()->products->list())->toThrow(LaravelClientException::class, 'without a matching fake');
});
