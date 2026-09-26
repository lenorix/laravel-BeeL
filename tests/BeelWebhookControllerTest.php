<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

function signBeelPayload(array $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.json_encode($payload), $secret);

    return "t={$timestamp},v1={$signature}";
}

beforeEach(function () {
    config()->set('services.beel.webhook_secret', 'test-webhook-secret');
});

it('dispatches the event and responds 202 for a validly signed webhook', function () {
    Event::fake();

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $response = $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature]);

    $response->assertStatus(202)->assertJson(['received' => true]);

    Event::assertDispatched(BeelWebhookReceived::class, function (BeelWebhookReceived $event) {
        return $event->id === 'evt_1' && $event->type === 'invoice.issued' && $event->data === ['id' => 'inv_123'];
    });
});

it('rejects a webhook with an invalid signature', function () {
    Event::fake();

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $signature = signBeelPayload($payload, 'wrong-secret');

    $response = $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature]);

    $response->assertStatus(401);
    Event::assertNotDispatched(BeelWebhookReceived::class);
});

it('rejects a webhook with a missing signature header', function () {
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $response = $this->postJson('/beel/webhook', $payload);

    $response->assertStatus(401);
});

it('does not resolve the webhook secret when the signature header is missing', function () {
    app()->bind(WebhookSecretResolver::class, fn () => new class implements WebhookSecretResolver
    {
        public function resolve(Request $request): ?string
        {
            throw new RuntimeException('The secret resolver should not run without a signature header.');
        }
    });

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $response = $this->postJson('/beel/webhook', $payload);

    $response->assertStatus(401);
});

it('responds 503 when no webhook secret is configured', function () {
    config()->set('services.beel.webhook_secret', null);

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $response = $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature]);

    $response->assertStatus(503);
});

it('rejects a valid signature over a payload missing id, type, or data', function () {
    $payload = ['foo' => 'bar'];
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $response = $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature]);

    $response->assertStatus(400);
});

it('registers the beel.webhook route by default', function () {
    expect(Route::has('beel.webhook'))->toBeTrue();
});

it('rejects a validly signed webhook older than the configured replay tolerance', function () {
    config()->set('beel.webhook_replay_tolerance_seconds', 1);

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $signature = signBeelPayload($payload, 'test-webhook-secret', timestamp: time() - 60);

    $response = $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature]);

    $response->assertStatus(401);
});

it('throttles requests once the configured limit is exceeded', function () {
    config()->set('beel.webhook_rate_limit.max_attempts_per_minute', 2);

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature])->assertStatus(202);
    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature])->assertStatus(202);
    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature])->assertStatus(429);
});
