<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Lenorix\BeelSdk\Exception\WebhookVerificationError;
use Lenorix\BeelSdk\Webhook\WebhookVerifier;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

final class BeelWebhookController
{
    public function __invoke(Request $request, WebhookSecretResolver $secrets): JsonResponse
    {
        $tolerance = (int) config('beel.webhook_replay_tolerance_seconds', 300);

        // Cheap pre-filter on the header alone: reject anything that can never pass verification
        // before resolving the secret (a custom resolver may hit a database) or hashing the body.
        $signature = $request->header('BeeL-Signature');
        if (! is_string($signature) || ! self::isPlausibleSignature($signature, $tolerance)) {
            return self::invalidSignature();
        }

        $secret = $secrets->resolve($request);
        if ($secret === null) {
            return new JsonResponse(['message' => 'BeeL webhook secret is not configured.'], 503);
        }

        try {
            $payload = (new WebhookVerifier($secret, $tolerance))->verify($request->getContent(), $signature);
        } catch (WebhookVerificationError $exception) {
            // The header already looks like BeeL's (checked above), so a mismatch here is most
            // likely a secret that was just rotated: BeeL invalidates the old one immediately, and
            // a non-retried 401 would drop every delivery for the rest of the deploy. Answer with a
            // retryable 503 instead, so BeeL's redelivery (5 attempts, up to ~75s) covers the gap.
            // A malformed body isn't fixed by retrying, so it keeps the non-retryable 401.
            // This distinguishes the two by WebhookVerifier's exact message text, pinned by a test;
            // if lenorix/beel-sdk ever changes it, this safely falls back to the previous 401.
            if ($exception->getMessage() === 'Invalid BeeL webhook signature.') {
                return new JsonResponse(['message' => 'BeeL webhook signature does not match the configured secret.'], 503);
            }

            return self::invalidSignature();
        }

        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($id) || ! is_string($type) || ! is_array($data)) {
            return new JsonResponse(['message' => 'Invalid BeeL webhook event.'], 400);
        }

        $webhookKey = $request->route('beelWebhookKey');
        $webhookKey = is_string($webhookKey) && $webhookKey !== '' ? $webhookKey : null;

        if (! self::isFirstDelivery($id, $webhookKey)) {
            return self::received();
        }

        // Deferred to after the response is sent so listener work never delays BeeL's 202 ack.
        // Listeners that must survive a worker restart or run reliably under load should still
        // implement ShouldQueue; this only protects response latency, not delivery guarantees.
        defer(fn () => Event::dispatch(new BeelWebhookReceived($id, $type, $data, $payload, $webhookKey)));

        return self::received();
    }

    /**
     * Mirrors WebhookVerifier's header parsing and replay window, and additionally requires a v1
     * shaped like a lowercase SHA-256 hex digest (the only thing hash_hmac can produce), so it
     * never rejects a signature the SDK would accept. The SDK still performs the real HMAC check.
     */
    private static function isPlausibleSignature(string $header, int $tolerance): bool
    {
        $timestamp = null;
        $hasDigest = false;

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && $value !== null && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== null && strlen($value) === 64 && ctype_xdigit($value) && strtolower($value) === $value) {
                $hasDigest = true;
            }
        }

        return $timestamp !== null && $hasDigest && abs(time() - $timestamp) <= $tolerance;
    }

    /**
     * BeeL redelivers an event with the same id (also sent as the Idempotency-Key header). Remember
     * accepted events for a while so a redelivery gets the same 202 without dispatching the Laravel
     * event twice. Only verified, accepted deliveries are remembered, keyed on the signed payload id
     * rather than the unsigned header, so an unverified request can't block a real event. The webhook
     * key is part of the cache key so one tenant can't consume another tenant's event id. Cache::add
     * is atomic, so concurrent redeliveries dispatch once.
     */
    private static function isFirstDelivery(string $eventId, ?string $webhookKey): bool
    {
        $seconds = config('beel.webhook_dedupe_seconds', 900);
        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            return true;
        }

        $store = config('beel.webhook_dedupe_store');
        $key = 'beel:webhook:'.hash('sha256', ($webhookKey ?? '').'|'.$eventId);

        return Cache::store(is_string($store) && $store !== '' ? $store : null)->add($key, true, (int) $seconds);
    }

    private static function received(): JsonResponse
    {
        return new JsonResponse(['received' => true], 202);
    }

    private static function invalidSignature(): JsonResponse
    {
        return new JsonResponse(['message' => 'Invalid BeeL webhook signature or payload.'], 401);
    }
}
