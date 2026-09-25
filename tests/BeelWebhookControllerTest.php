<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

function signBeelPayload(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return "t={$timestamp},v1={$signature}";
}

beforeEach(function () {
    config()->set('services.beel.webhook_secret', 'test-webhook-secret');
});

it('dispatches the event and responds 202 for a validly signed webhook', function () {
    Event::fake();

    $payload = json_encode([
        'type' => 'invoice.issued',
        'data' => ['id' => 'inv_123'],
    ]);
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $response = $this->call('POST', '/beel/webhook', server: [
        'HTTP_BeeL-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);

    $response->assertStatus(202)->assertJson(['received' => true]);

    Event::assertDispatched(BeelWebhookReceived::class, function (BeelWebhookReceived $event) {
        return $event->type === 'invoice.issued' && $event->data === ['id' => 'inv_123'];
    });
});

it('rejects a webhook with an invalid signature', function () {
    Event::fake();

    $payload = json_encode(['type' => 'invoice.issued', 'data' => ['id' => 'inv_123']]);
    $signature = signBeelPayload($payload, 'wrong-secret');

    $response = $this->call('POST', '/beel/webhook', server: [
        'HTTP_BeeL-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);

    $response->assertStatus(401);
    Event::assertNotDispatched(BeelWebhookReceived::class);
});

it('rejects a webhook with a missing signature header', function () {
    $payload = json_encode(['type' => 'invoice.issued', 'data' => ['id' => 'inv_123']]);

    $response = $this->call('POST', '/beel/webhook', server: [
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);

    $response->assertStatus(401);
});

it('responds 503 when no webhook secret is configured', function () {
    config()->set('services.beel.webhook_secret', null);

    $payload = json_encode(['type' => 'invoice.issued', 'data' => ['id' => 'inv_123']]);
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $response = $this->call('POST', '/beel/webhook', server: [
        'HTTP_BeeL-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);

    $response->assertStatus(503);
});

it('rejects a valid signature over a payload missing type or data', function () {
    $payload = json_encode(['foo' => 'bar']);
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $response = $this->call('POST', '/beel/webhook', server: [
        'HTTP_BeeL-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);

    $response->assertStatus(400);
});

it('registers the beel.webhook route by default', function () {
    expect(Route::has('beel.webhook'))->toBeTrue();
});
