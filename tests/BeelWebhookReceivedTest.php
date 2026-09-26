<?php

use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

it('exposes the company id from the payload', function () {
    $event = new BeelWebhookReceived('evt_1', 'invoice.issued', ['id' => 'inv_1'], [
        'id' => 'evt_1',
        'type' => 'invoice.issued',
        'company_id' => 'company-uuid',
        'data' => ['id' => 'inv_1'],
    ]);

    expect($event->companyId)->toBe('company-uuid');
});

it('exposes a null company id when the payload has none', function () {
    $event = new BeelWebhookReceived('evt_1', 'account.claimed', ['id' => 'acc_1'], [
        'id' => 'evt_1',
        'type' => 'account.claimed',
        'data' => ['id' => 'acc_1'],
    ]);

    expect($event->companyId)->toBeNull();
});

it('reports test deliveries triggered from the BeeL dashboard', function () {
    $event = new BeelWebhookReceived('evt_1', 'invoice.issued', [], [
        'id' => 'evt_1',
        'type' => 'invoice.issued',
        'test' => true,
        'data' => [],
    ]);

    expect($event->isTest())->toBeTrue();
});

it('reports live deliveries as not test when the field is absent', function () {
    $event = new BeelWebhookReceived('evt_1', 'invoice.issued', [], [
        'id' => 'evt_1',
        'type' => 'invoice.issued',
        'data' => [],
    ]);

    expect($event->isTest())->toBeFalse();
});

it('reports live deliveries as not test when the field is explicitly false', function () {
    $event = new BeelWebhookReceived('evt_1', 'invoice.issued', [], [
        'id' => 'evt_1',
        'type' => 'invoice.issued',
        'test' => false,
        'data' => [],
    ]);

    expect($event->isTest())->toBeFalse();
});

it('exposes the account id from the payload', function () {
    $event = new BeelWebhookReceived('evt_1', 'account.claimed', [], ['id' => 'evt_1', 'account_id' => 'account-uuid', 'data' => []]);

    expect($event->accountId)->toBe('account-uuid');
});

it('exposes a null account id when the payload has none', function () {
    $event = new BeelWebhookReceived('evt_1', 'invoice.issued', [], ['id' => 'evt_1', 'data' => []]);

    expect($event->accountId)->toBeNull();
});
