<?php

use Illuminate\Support\Facades\Event;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;
use Lenorix\LaravelBeel\Testing\InteractsWithBeelWebhooks;
use Lenorix\LaravelBeel\Testing\WebhookSignature;

uses(InteractsWithBeelWebhooks::class);

beforeEach(function () {
    config()->set('services.beel.webhook_secret', 'test-webhook-secret');
});

it('signs a raw body the way BeeL does', function () {
    $body = '{"id":"evt_1"}';

    expect(WebhookSignature::sign($body, 'secret', 1700000000))
        ->toBe('t=1700000000,v1='.hash_hmac('sha256', '1700000000.'.$body, 'secret'));
});

it('posts a signed webhook that the package accepts and dispatches', function () {
    Event::fake();

    $this->postBeelWebhook('invoice.issued', ['invoice_id' => 'inv_1'])->assertStatus(202);

    Event::assertDispatched(BeelWebhookReceived::class, fn (BeelWebhookReceived $event) => $event->type === 'invoice.issued'
        && $event->data === ['invoice_id' => 'inv_1']
        && ! $event->isTest());
});

it('uses a fresh event id per call so deduplication does not swallow a second post', function () {
    Event::fake();

    $this->postBeelWebhook('invoice.issued')->assertStatus(202);
    $this->postBeelWebhook('invoice.issued')->assertStatus(202);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 2);
});

it('supports envelope overrides, a webhook key and a custom secret', function () {
    Event::fake();
    config()->set('services.beel.webhook_secret', 'other-secret');

    $this->postBeelWebhook('invoice.voided', ['invoice_id' => 'inv_1'], ['id' => 'evt_fixed', 'company_id' => 'company-uuid'], webhookKey: 'tenant-a', secret: 'other-secret')
        ->assertStatus(202);

    Event::assertDispatched(BeelWebhookReceived::class, fn (BeelWebhookReceived $event) => $event->id === 'evt_fixed'
        && $event->companyId === 'company-uuid'
        && $event->webhookKey === 'tenant-a');
});
