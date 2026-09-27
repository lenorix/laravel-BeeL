<?php

use Illuminate\Support\Facades\Http;
use Lenorix\BeelSdk\Exception\BeelConflictError;
use Lenorix\BeelSdk\Exception\BeelRateLimitError;
use Lenorix\BeelSdk\Exception\BeelValidationError;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Testing\BeelFake;

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('services.beel.account_id', 'acc-1');
});

// Each factory goes through the real SDK, so a fake that drifts from what it parses fails here.

it('fakes an invoice the SDK hydrates', function () {
    Http::fake(['*/v1/companies/company-1/invoices/inv-1' => BeelFake::ok(BeelFake::invoice(['id' => 'inv-1', 'status' => 'DRAFT']))]);

    $invoice = app(BeelManager::class)->company()->invoices->get('inv-1');

    expect($invoice->getId())->toBe('inv-1')
        ->and($invoice->getStatus())->toBe('DRAFT')
        ->and($invoice->getInvoiceNumber())->toBe('A/2025/0042')
        ->and($invoice->getIssueDate()->format('Y-m-d'))->toBe('2025-01-20')
        ->and($invoice->getTotals()->getInvoiceTotal())->toEqual(1590)
        ->and($invoice->getVerifactu()->getSubmissionStatus())->toBe('ACCEPTED');
});

it('fakes a page of customers the SDK lists and iterates', function () {
    Http::fakeSequence('*/v1/companies/company-1/customers*')
        ->pushResponse(BeelFake::page('customers', [BeelFake::customer(['id' => 'c-1'])], hasNext: true))
        ->pushResponse(BeelFake::page('customers', [BeelFake::customer(['id' => 'c-2', 'address' => ['city' => 'Sevilla']])], page: 2));

    $customers = iterator_to_array(app(BeelManager::class)->company()->customers->all(), false);

    expect(array_map(fn ($c) => $c->getId(), $customers))->toBe(['c-1', 'c-2'])
        ->and($customers[1]->getAddress()->getCity())->toBe('Sevilla')
        ->and($customers[1]->getAddress()->getPostalCode())->toBe('28013')
        ->and($customers[0]->getCreatedAt()->format('Y-m-d'))->toBe('2024-03-15');
});

it('fakes the key identity, replacing the scopes list', function () {
    Http::fake(['*/v1/me/identity' => BeelFake::ok(BeelFake::identity(scopes: ['accounts:read']))]);

    $identity = app(BeelManager::class)->client()->me->identity();

    expect($identity->getCredential()->getScopes())->toBe(['accounts:read'])
        ->and($identity->getCredential()->getEnvironment())->toBe('TEST');
});

it('fakes webhook subscriptions and deliveries the SDK hydrates', function () {
    Http::fake([
        '*/v1/accounts/acc-1/webhooks/wh-1/deliveries*' => BeelFake::page('deliveries', [BeelFake::webhookDelivery(['success' => false, 'http_status' => 503])]),
        '*/v1/accounts/acc-1/webhooks*' => BeelFake::page('webhooks', [BeelFake::webhookSubscription(['id' => 'wh-1', 'events' => ['invoice.voided']])]),
    ]);
    $webhooks = app(BeelManager::class)->account()->webhooks;

    $subscription = iterator_to_array($webhooks->all(), false)[0];
    $delivery = iterator_to_array($webhooks->allDeliveries('wh-1'), false)[0];

    expect($subscription->getEvents())->toBe(['invoice.voided'])
        ->and($subscription->getCreatedAt()->format('Y-m-d'))->toBe('2025-01-10')
        ->and($delivery->getSuccess())->toBeFalse()
        ->and($delivery->getHttpStatus())->toBe(503);
});

it('fakes cursor pages for provisioned accounts', function () {
    Http::fakeSequence('*/v1/accounts*')
        ->pushResponse(BeelFake::cursorPage('accounts', [BeelFake::managedAccount(['account_id' => 'a-1'])], nextCursor: 'next'))
        ->pushResponse(BeelFake::cursorPage('accounts', [BeelFake::managedAccount(['account_id' => 'a-2', 'status' => 'PROVISIONED', 'claim' => ['status' => 'PENDING']])]));

    $accounts = iterator_to_array(app(BeelManager::class)->client()->accounts->all(), false);

    expect(array_map(fn ($a) => $a->getAccountId(), $accounts))->toBe(['a-1', 'a-2'])
        ->and($accounts[1]->getStatus())->toBe('PROVISIONED')
        ->and($accounts[1]->getClaim()->getStatus())->toBe('PENDING')
        ->and($accounts[0]->getAccessLevel())->toBe('OPERATE');
});

it('fakes errors the SDK turns into the matching exception', function () {
    config()->set('beel.http.retries', 0);
    Http::fake([
        '*/customers*' => BeelFake::error(409, 'CONFLICT', details: ['conflict_type' => 'DUPLICATE_NIF']),
        '*/invoices/inv-1' => BeelFake::error(422, 'VALIDATION_ERROR', 'Request validation failed'),
    ]);
    $company = app(BeelManager::class)->company();

    expect(fn () => $company->customers->list())->toThrow(function (BeelConflictError $e) {
        expect($e->statusCode)->toBe(409)
            ->and($e->apiCode)->toBe('CONFLICT')
            ->and($e->details)->toMatchArray(['conflict_type' => 'DUPLICATE_NIF'])
            ->and($e->requestId)->toBe(BeelFake::REQUEST_ID);
    });
    expect(fn () => $company->invoices->get('inv-1'))->toThrow(BeelValidationError::class, 'Request validation failed');
});

it('fakes a rate limit with Retry-After, surfaced at once with retries disabled', function () {
    config()->set('beel.http.retries', 0);
    Http::fake(['*' => BeelFake::error(429, 'RATE_LIMIT_EXCEEDED', retryAfter: 7)]);

    expect(fn () => app(BeelManager::class)->company()->invoices->list())->toThrow(function (BeelRateLimitError $e) {
        expect($e->apiCode)->toBe('RATE_LIMIT_EXCEEDED')->and($e->retryAfterSeconds)->toBe(7);
    });
    Http::assertSentCount(1);
});

it('gives realistic webhook data per event type and nothing for unknown types', function () {
    expect(BeelFake::webhookData('invoice.issued', ['invoice_number' => 'B/1']))
        ->toMatchArray(['invoice_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'invoice_number' => 'B/1'])
        ->and(BeelFake::webhookData('something.new'))->toBe([]);
});
