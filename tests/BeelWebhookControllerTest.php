<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;
use Lenorix\LaravelBeel\Http\Controllers\BeelWebhookController;
use Lenorix\LaravelBeel\Http\Controllers\WebhookClaim;

mutates(BeelWebhookController::class, WebhookClaim::class);

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

it('responds 503 for a plausible signature that does not match the secret', function () {
    Event::fake();

    // A header this well-formed but wrong is the signature of a secret that was just rotated: BeeL
    // invalidates the old secret immediately, so retryable 503s cover the deploy window instead of
    // permanently dropping the delivery, which is what a non-retried 401 would do.
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $signature = signBeelPayload($payload, 'wrong-secret');

    $response = $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature]);

    $response->assertStatus(503);
    Event::assertNotDispatched(BeelWebhookReceived::class);
});

it('rejects a validly signed but non-JSON body with 401, not 503', function () {
    Event::fake();

    // A body-level problem is not fixed by BeeL retrying: it stays a non-retryable 401.
    $body = 'not-json';
    $timestamp = time();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-webhook-secret');

    $response = $this->call('POST', '/beel/webhook', server: [
        'HTTP_BeeL-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $body);

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

it('rejects a valid signature over a payload with a wrong id, type or data', function (array $payload) {
    Event::fake();
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature])->assertStatus(400);

    Event::assertNotDispatched(BeelWebhookReceived::class);
})->with([
    'nothing' => [['foo' => 'bar']],
    'no id' => [['type' => 'invoice.issued', 'data' => []]],
    'numeric id' => [['id' => 1, 'type' => 'invoice.issued', 'data' => []]],
    'no type' => [['id' => 'evt-1', 'data' => []]],
    'data not an object' => [['id' => 'evt-1', 'type' => 'invoice.issued', 'data' => 'x']],
]);

it('registers the beel.webhook route by default', function () {
    expect(Route::has('beel.webhook'))->toBeTrue();
});

it('does not register the webhook route when disabled', function () {
    // The route file runs once at boot, before a test body can change config, so evaluate it again
    // here against a scratch router instead of rebooting the whole application.
    config()->set('beel.register_webhook_route', false);

    $router = new Router(app('events'), app());
    Route::swap($router);
    require dirname(__DIR__).'/routes/beel.php';
    $router->getRoutes()->refreshNameLookups(); // named routes are only indexed by name after this

    expect($router->has('beel.webhook'))->toBeFalse();
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

it('passes an optional trailing path segment to the secret resolver so each tenant can have its own secret', function () {
    Event::fake();
    app()->bind(WebhookSecretResolver::class, fn () => new class implements WebhookSecretResolver
    {
        public function resolve(Request $request): ?string
        {
            return ['tenant-a' => 'secret-a', 'tenant-b' => 'secret-b'][$request->route('beelWebhookKey')] ?? null;
        }
    });

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook/tenant-a', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'secret-a')])->assertStatus(202);
    $this->postJson('/beel/webhook/tenant-a', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'secret-b')])->assertStatus(503);
    $this->postJson('/beel/webhook/unknown', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'secret-a')])->assertStatus(503);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 1);
});

it('builds per-tenant webhook URLs from the route name', function () {
    expect(route('beel.webhook'))->toEndWith('/beel/webhook')
        ->and(route('beel.webhook', ['beelWebhookKey' => 'tenant-a']))->toEndWith('/beel/webhook/tenant-a');
});

it('also skips the input-trimming middleware on per-tenant webhook URLs', function () {
    $request = Request::create('/beel/webhook/tenant-a', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"k":"  x  "}');

    (new TrimStrings)->handle($request, fn ($request) => $request);

    expect($request->json('k'))->toBe('  x  ');
});

it('tells listeners which webhook key verified the event', function () {
    Event::fake();
    app()->bind(WebhookSecretResolver::class, fn () => new class implements WebhookSecretResolver
    {
        public function resolve(Request $request): ?string
        {
            return 'test-webhook-secret';
        }
    });

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $other = ['id' => 'evt_2', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']]; // a distinct event, so dedupe doesn't apply

    $this->postJson('/beel/webhook/tenant-a', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')])->assertStatus(202);
    $this->postJson('/beel/webhook', $other, ['BeeL-Signature' => signBeelPayload($other, 'test-webhook-secret')])->assertStatus(202);

    Event::assertDispatched(BeelWebhookReceived::class, fn (BeelWebhookReceived $event) => $event->webhookKey === 'tenant-a');
    Event::assertDispatched(BeelWebhookReceived::class, fn (BeelWebhookReceived $event) => $event->webhookKey === null);
});

it('treats the bare path and any segment the same with the default config resolver', function () {
    Event::fake();

    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $other = ['id' => 'evt_2', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']]; // a distinct event, so dedupe doesn't apply

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')])->assertStatus(202);
    $this->postJson('/beel/webhook/anything', $other, ['BeeL-Signature' => signBeelPayload($other, 'test-webhook-secret')])->assertStatus(202);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 2);
});

it('answers a redelivered event the same way without dispatching it again', function () {
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $headers = ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret'), 'Idempotency-Key' => 'evt_1'];

    $first = $this->postJson('/beel/webhook', $payload, $headers);
    $second = $this->postJson('/beel/webhook', $payload, $headers);

    $first->assertStatus(202);
    $second->assertStatus(202)->assertExactJson($first->json());
    Event::assertDispatchedTimes(BeelWebhookReceived::class, 1);
});

it('does not re-dispatch a captured delivery replayed to a different URL segment', function () {
    // The signature covers the body, not the URL: with the default resolver any segment is accepted
    // with the same secret, so the dedupe key must not depend on the segment.
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $headers = ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')];

    $this->postJson('/beel/webhook', $payload, $headers)->assertStatus(202);
    $this->postJson('/beel/webhook/other', $payload, $headers)->assertStatus(202);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 1);
});

it('keeps deduplication separate per tenant secret so one tenant cannot swallow another tenant\'s event', function () {
    Event::fake();
    app()->bind(WebhookSecretResolver::class, fn () => new class implements WebhookSecretResolver
    {
        public function resolve(Request $request): ?string
        {
            return ['tenant-a' => 'secret-a', 'tenant-b' => 'secret-b'][$request->route('beelWebhookKey')] ?? null;
        }
    });
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook/tenant-a', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'secret-a')])->assertStatus(202);
    $this->postJson('/beel/webhook/tenant-b', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'secret-b')])->assertStatus(202);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 2);
});

it('does not remember failed deliveries, so BeeL\'s retry after fixing the secret is processed', function () {
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'old-secret')])->assertStatus(503);
    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')])->assertStatus(202);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 1);
});

it('cannot be poisoned by an unverified request carrying a real event id', function () {
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => 'garbage', 'Idempotency-Key' => 'evt_1'])->assertStatus(401);
    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret'), 'Idempotency-Key' => 'evt_1'])->assertStatus(202);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 1);
});

it('can disable webhook deduplication', function () {
    config()->set('beel.webhook_dedupe_seconds', null);
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $headers = ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')];

    $this->postJson('/beel/webhook', $payload, $headers)->assertStatus(202);
    $this->postJson('/beel/webhook', $payload, $headers)->assertStatus(202);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, 2);
});

it('remembers events at least twice the replay tolerance, so a signature can never be replayed after the claim expires', function () {
    config()->set('beel.webhook_dedupe_seconds', 60);
    config()->set('beel.webhook_replay_tolerance_seconds', 600);
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $signature = signBeelPayload($payload, 'test-webhook-secret');

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature])->assertStatus(202);
    $this->travel(10)->minutes(); // past the configured 60 s, still inside the 2 x 600 s the signature is valid for

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => $signature])->assertStatus(202);
    Event::assertDispatchedTimes(BeelWebhookReceived::class, 1);
});

it('answers 500 when the dedupe cache is unavailable, so BeeL retries the delivery', function () {
    config()->set('cache.stores.broken', ['driver' => 'broken']);
    Cache::extend('broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function add($key, $value, $seconds)
        {
            throw new RuntimeException('cache down');
        }
    }));
    config()->set('beel.webhook_dedupe_store', 'broken');
    Event::fake();
    $this->withoutExceptionHandling([RuntimeException::class]);
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')])->assertStatus(500);
    Event::assertNotDispatched(BeelWebhookReceived::class);
});

it('answers 503 when a listener fails and lets BeeL\'s retry process the event again', function () {
    $calls = 0;
    Event::listen(BeelWebhookReceived::class, function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('queue is down');
        }
    });
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $headers = ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')];

    $this->postJson('/beel/webhook', $payload, $headers)->assertStatus(503);
    $this->postJson('/beel/webhook', $payload, $headers)->assertStatus(202);

    expect($calls)->toBe(2);
});

it('logs a warning when a plausible signature does not match the secret, without leaking it', function () {
    Log::spy();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook/tenant-a', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'old-secret'), 'BeeL-Delivery-Id' => 'del_1'])
        ->assertStatus(503);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['reason'] === 'signature_mismatch'
        && $context['webhook_key'] === 'tenant-a'
        && $context['unverified_delivery_id'] === 'del_1'
        && ! str_contains($message.json_encode($context), 'test-webhook-secret'));
});

it('logs a warning when no webhook secret is configured', function () {
    config()->set('services.beel.webhook_secret', null);
    Log::spy();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'whatever')])->assertStatus(503);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['reason'] === 'secret_missing');
});

it('does not log requests rejected by the header pre-filter', function () {
    Log::spy();

    $this->postJson('/beel/webhook', ['id' => 'evt_1'], ['BeeL-Signature' => 'garbage'])->assertStatus(401);

    Log::shouldNotHaveReceived('warning');
});

it('logs at most one warning per reason per minute, so forged traffic cannot flood the log', function () {
    Log::spy();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    foreach (range(1, 3) as $i) {
        $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, "wrong-{$i}")])->assertStatus(503);
    }

    Log::shouldHaveReceived('warning')->once();
});

it('tells BeeL why it refused a delivery', function () {
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => 'garbage'])
        ->assertStatus(401)->assertExactJson(['message' => 'Invalid BeeL webhook signature or payload.']);
    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'wrong')])
        ->assertStatus(503)->assertExactJson(['message' => 'BeeL webhook signature does not match the configured secret.']);
    $bad = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => 'no'];
    $this->postJson('/beel/webhook', $bad, ['BeeL-Signature' => signBeelPayload($bad, 'test-webhook-secret')])
        ->assertStatus(400)->assertExactJson(['message' => 'Invalid BeeL webhook event.']);

    config()->set('services.beel.webhook_secret', null);
    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'x')])
        ->assertStatus(503)->assertExactJson(['message' => 'BeeL webhook secret is not configured.']);
});

it('reports a failing listener and answers 503 even with deduplication disabled', function () {
    config()->set('beel.webhook_dedupe_seconds', 0);
    Exceptions::fake();
    Event::listen(BeelWebhookReceived::class, fn () => throw new RuntimeException('queue is down'));
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')])
        ->assertStatus(503)->assertExactJson(['message' => 'The BeeL webhook could not be processed; BeeL will retry it.']);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'queue is down');
});

it('deduplicates for any positive number of seconds, and not for a nonsense value', function (mixed $seconds, int $dispatched) {
    config()->set('beel.webhook_dedupe_seconds', $seconds);
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $headers = ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')];

    $this->postJson('/beel/webhook', $payload, $headers)->assertStatus(202);
    $this->postJson('/beel/webhook', $payload, $headers)->assertStatus(202);

    Event::assertDispatchedTimes(BeelWebhookReceived::class, $dispatched);
})->with([
    'one second' => [1, 1],
    'a numeric string' => ['0.5', 2],
    'zero' => [0, 2],
]);

it('fails loudly on a deduplication setting that is not a number, instead of silently turning it off', function () {
    config()->set('beel.webhook_dedupe_seconds', 'soon');
    $this->withoutExceptionHandling();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    expect(fn () => $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')]))
        ->toThrow(InvalidArgumentException::class, 'beel.webhook_dedupe_seconds');
});

it('remembers events in the configured store, under a key only the secret can derive, for exactly twice the tolerance', function () {
    config()->set('cache.stores.webhooks', ['driver' => 'array']);
    config()->set('beel.webhook_dedupe_store', 'webhooks');
    config()->set('beel.webhook_dedupe_seconds', 1);
    config()->set('beel.webhook_replay_tolerance_seconds', 300);
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $key = 'beel:webhook:'.hash_hmac('sha256', 'evt_1', 'test-webhook-secret');

    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret')])->assertStatus(202);

    expect(Cache::store('webhooks')->get($key))->toBeTrue()
        ->and(Cache::store()->has($key))->toBeFalse();
    $this->travel(599)->seconds();
    expect(Cache::store('webhooks')->has($key))->toBeTrue();
    $this->travel(1)->seconds();
    expect(Cache::store('webhooks')->has($key))->toBeFalse();
});

it('accepts a signature right at the edge of the replay tolerance', function () {
    config()->set('beel.webhook_replay_tolerance_seconds', 3600);
    Event::fake();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];

    // time() may tick once during the request: the edge is approached from inside.
    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret', time() - 3599)])->assertStatus(202);
    $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'test-webhook-secret', time() - 3602)])->assertStatus(401);
});

it('logs a warning again after a minute, in the configured store, and even when that store is down', function () {
    config()->set('cache.stores.webhooks', ['driver' => 'array']);
    config()->set('beel.webhook_dedupe_store', 'webhooks');
    Log::spy();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $post = fn () => $this->postJson('/beel/webhook', $payload, ['BeeL-Signature' => signBeelPayload($payload, 'wrong')])->assertStatus(503);

    $post();
    expect(Cache::store('webhooks')->has('beel:webhook:warned:signature_mismatch'))->toBeTrue();
    $this->travel(59)->seconds();
    $post();
    $this->travel(1)->seconds();
    $post();
    Log::shouldHaveReceived('warning')->twice();

    config()->set('cache.stores.broken', ['driver' => 'broken']);
    Cache::extend('broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function add($key, $value, $seconds)
        {
            throw new RuntimeException('cache down');
        }
    }));
    config()->set('beel.webhook_dedupe_store', 'broken');
    $post();
    Log::shouldHaveReceived('warning')->times(3);
});

it('keeps unverified header values short in the log', function () {
    Log::spy();
    $payload = ['id' => 'evt_1', 'type' => 'invoice.issued', 'data' => ['id' => 'inv_123']];
    $long = str_repeat('k', 100);

    $this->postJson("/beel/webhook/{$long}", $payload, ['BeeL-Signature' => signBeelPayload($payload, 'wrong'), 'BeeL-Delivery-Id' => $long])->assertStatus(503);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['webhook_key'] === str_repeat('k', 64)
        && $context['unverified_delivery_id'] === str_repeat('k', 64));
});
