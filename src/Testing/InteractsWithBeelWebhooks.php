<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Testing;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * For your app's tests: post a correctly signed BeeL webhook to the package's endpoint.
 * Use it in a Laravel TestCase (or `uses(InteractsWithBeelWebhooks::class)` in Pest).
 */
trait InteractsWithBeelWebhooks
{
    /**
     * @param  array<string, mixed>  $data  The event's `data`.
     * @param  array<string, mixed>  $overrides  Envelope fields to set or replace (e.g. `id`, `company_id`, `test`).
     * @param  string|null  $webhookKey  Optional URL segment (/beel/webhook/{key}).
     * @param  string|null  $secret  Defaults to services.beel.webhook_secret.
     */
    protected function postBeelWebhook(string $type, array $data = [], array $overrides = [], ?string $webhookKey = null, ?string $secret = null): TestResponse
    {
        // A fresh id per call, so the package's deduplication doesn't swallow a test's second post.
        $payload = array_merge([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'created_at' => now()->toIso8601ZuluString(),
            'test' => false,
            'data' => $data,
        ], $overrides);

        // Encode once and sign exactly the bytes that are sent.
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $secret ??= (string) config('services.beel.webhook_secret');
        $uri = route('beel.webhook', $webhookKey === null ? [] : ['beelWebhookKey' => $webhookKey], false);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_BEEL_SIGNATURE' => WebhookSignature::sign($body, $secret),
        ], $body);
    }
}
