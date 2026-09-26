<?php

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
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

it('rejects signatures that could never be valid without resolving the secret', function (?string $header) {
    app()->bind(WebhookSecretResolver::class, fn () => new class implements WebhookSecretResolver
    {
        public function resolve(Request $request): ?string
        {
            throw new RuntimeException('The secret resolver should not run for an implausible signature.');
        }
    });

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $headers = $header === null ? [] : ['BeeL-Signature' => $header];

    $this->postJson('/beel/webhook', $payload, $headers)->assertStatus(401);
})->with([
    'missing header' => [null],
    'blank header' => ['   '],
    'garbage' => ['garbage'],
    'non-numeric timestamp' => [fn () => 't=abc,v1='.str_repeat('a', 64)],
    'missing v1' => [fn () => 't='.time()],
    'v1 not a sha256 hex digest' => [fn () => 't='.time().',v1=not-a-digest'],
    'uppercase v1 digest' => [fn () => 't='.time().',v1='.str_repeat('A', 64)],
    'stale timestamp' => [fn () => 't='.(time() - 3600).',v1='.str_repeat('a', 64)],
    'future timestamp' => [fn () => 't='.(time() + 3600).',v1='.str_repeat('a', 64)],
]);

it('does not rate limit the webhook route', function () {
    $middleware = Route::getRoutes()->getByName('beel.webhook')->gatherMiddleware();

    expect(collect($middleware)->contains(fn ($m) => str_starts_with($m, 'throttle')))->toBeFalse();
});

it('skips the input-trimming middleware for the webhook path so the body is not parsed before verification', function () {
    $request = Request::create('/beel/webhook', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"k":"  x  ","e":""}');

    (new TrimStrings)->handle($request, fn ($request) => $request);
    (new ConvertEmptyStringsToNull)->handle($request, fn ($request) => $request);

    expect($request->json('k'))->toBe('  x  ')
        ->and($request->json('e'))->toBe('');
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

it('accepts many legitimate deliveries in a burst', function () {
    Event::fake();

    foreach (range(1, 350) as $i) {
        $payload = ['id' => "evt_{$i}", 'type' => 'invoice.issued', 'data' => ['id' => "inv_{$i}"]];
        $signature = signBeelPayload($payload, 'test-webhook-secret');

        $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature])->assertStatus(202);
    }

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 350);
});
