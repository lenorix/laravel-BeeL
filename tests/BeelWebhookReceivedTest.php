<?php

use Illuminate\Support\Facades\Event;
use Lenorix\BeelSdk\Exception\WebhookPayloadError;
use Lenorix\BeelSdk\Generated\Model\WebhookEventDataInvoiceIssued;
use Lenorix\BeelSdk\Webhook\WebhookEventType;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;
use Lenorix\LaravelBeel\Testing\BeelFake;
use Lenorix\LaravelBeel\Testing\InteractsWithBeelWebhooks;

uses(InteractsWithBeelWebhooks::class);

function webhookEvent(string $type, array $data, array $envelope = []): BeelWebhookReceived
{
    $payload = array_merge(['id' => 'evt_1', 'type' => $type, 'created_at' => '2025-01-20T10:30:00Z', 'api_version' => '2025-01', 'livemode' => false, 'data' => $data], $envelope);

    return new BeelWebhookReceived('evt_1', $type, $data, $payload);
}

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

it('gives the delivered event as the SDK typed model', function () {
    config()->set('services.beel.webhook_secret', 'test-webhook-secret');
    Event::fake();

    $this->postBeelWebhook('invoice.issued', BeelFake::webhookData('invoice.issued', ['invoice_number' => 'A/2025/0099']))->assertStatus(202);

    Event::assertDispatched(BeelWebhookReceived::class, function (BeelWebhookReceived $event) {
        $data = $event->typed()->getData();

        return $data instanceof WebhookEventDataInvoiceIssued
            && $data->getInvoiceNumber() === 'A/2025/0099'
            && $event->typed()->getCreatedAt() instanceof DateTimeInterface;
    });
});

it('types the realistic data of every BeeL event type', function (WebhookEventType $type) {
    $data = webhookEvent($type->value, BeelFake::webhookData($type->value))->typed()->getData();

    expect($data)->toBeObject()
        ->and(class_basename($data))->toStartWith('WebhookEventData');
})->with(WebhookEventType::cases());

it('keeps the data of an event type the SDK does not know as an array', function () {
    $event = webhookEvent('something.new', ['x' => 1]);

    expect($event->typed()->getType())->toBe('something.new')
        ->and($event->typed()->getData())->toBe(['x' => 1]);
});

it('throws a payload error only when a listener asks for a malformed event', function () {
    $event = webhookEvent('invoice.issued', ['invoice_id' => 'inv_1'], ['created_at' => 'yesterday']);

    expect($event->id)->toBe('evt_1')
        ->and(fn () => $event->typed())->toThrow(WebhookPayloadError::class);
});

it('stays serializable for queued listeners after typing', function () {
    $event = webhookEvent('invoice.issued', BeelFake::webhookData('invoice.issued'));
    $event->typed();

    $copy = unserialize(serialize($event));

    expect($copy->typed()->getData()->getInvoiceId())->toBe('f47ac10b-58cc-4372-a567-0e02b2c3d479');
});
