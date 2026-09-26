<?php

use Illuminate\Support\Facades\Event;
use Lenorix\BeelSdk\Generated\Model\WebhookEventDataVeriFactuStatusUpdated;
use Lenorix\BeelSdk\Webhook\WebhookEventType;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;
use Lenorix\LaravelBeel\Events\Webhooks\BeelWebhookEvent;
use Lenorix\LaravelBeel\Events\Webhooks\InvoicePdfGenerated;
use Lenorix\LaravelBeel\Events\Webhooks\VerifactuStatusUpdated;
use Lenorix\LaravelBeel\Testing\BeelFake;
use Lenorix\LaravelBeel\Testing\InteractsWithBeelWebhooks;

uses(InteractsWithBeelWebhooks::class);

beforeEach(function () {
    config()->set('services.beel.webhook_secret', 'test-webhook-secret');
});

it('has an event class for every webhook type the SDK knows', function () {
    expect(BeelWebhookEvent::types())->toEqualCanonicalizing(array_map(fn (WebhookEventType $type) => $type->value, WebhookEventType::cases()));
});

it('dispatches the typed event after the generic one, with the typed data', function () {
    $order = [];
    Event::listen(BeelWebhookReceived::class, function () use (&$order) {
        $order[] = 'received';
    });
    Event::listen(function (VerifactuStatusUpdated $event) use (&$order) {
        $order[] = $event->data()->getNewStatus();
        expect($event->data())->toBeInstanceOf(WebhookEventDataVeriFactuStatusUpdated::class)
            ->and($event->webhook->companyId)->toBe('company-7');
    });

    $this->postBeelWebhook('verifactu.status.updated', overrides: ['company_id' => 'company-7'])->assertStatus(202);

    expect($order)->toBe(['received', 'ACCEPTED']);
});

it('dispatches only the generic event for a type it does not know', function () {
    Event::fake();

    $this->postBeelWebhook('something.new', ['x' => 1])->assertStatus(202);

    Event::assertDispatched(BeelWebhookReceived::class);
    expect(BeelWebhookEvent::for(new BeelWebhookReceived('evt', 'something.new', [], [])))->toBeNull();
});

it('answers 503 so BeeL retries when a typed listener fails, and processes the retry', function () {
    $fail = true;
    Event::listen(function (InvoicePdfGenerated $event) use (&$fail) {
        if ($fail) {
            throw new RuntimeException('queue down');
        }
    });

    $this->postBeelWebhook('invoice.pdf.generated', overrides: ['id' => 'evt-1'])->assertStatus(503);
    $fail = false;
    $this->postBeelWebhook('invoice.pdf.generated', overrides: ['id' => 'evt-1'])->assertStatus(202);
});

it('does not dispatch the typed event again for a redelivery', function () {
    $count = 0;
    Event::listen(function (InvoicePdfGenerated $event) use (&$count) {
        $count++;
    });

    $this->postBeelWebhook('invoice.pdf.generated', overrides: ['id' => 'evt-2'])->assertStatus(202);
    $this->postBeelWebhook('invoice.pdf.generated', overrides: ['id' => 'evt-2'])->assertStatus(202);

    expect($count)->toBe(1);
});

it('says so when an event\'s data is not the model its type promises', function () {
    $received = new BeelWebhookReceived('evt-9', 'invoice.issued', [], ['id' => 'evt-9', 'type' => 'invoice.issued', 'created_at' => '2025-01-20T10:30:00Z', 'api_version' => '2025-01', 'livemode' => false, 'data' => ['invoice_id' => 'i']]);
    $event = new VerifactuStatusUpdated($received);

    expect(fn () => $event->data())->toThrow(UnexpectedValueException::class, 'evt-9 (invoice.issued)');
});

it('gives every event type its typed data', function (WebhookEventType $type) {
    $payload = ['id' => 'evt-1', 'type' => $type->value, 'created_at' => '2025-01-20T10:30:00Z', 'api_version' => '2025-01', 'livemode' => false, 'data' => BeelFake::webhookData($type->value)];
    $event = BeelWebhookEvent::for(new BeelWebhookReceived('evt-1', $type->value, $payload['data'], $payload));

    expect($event)->not->toBeNull()
        ->and(class_basename($event->data()))->toStartWith('WebhookEventData');
})->with(WebhookEventType::cases());
