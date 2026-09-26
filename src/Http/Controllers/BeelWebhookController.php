<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lenorix\BeelSdk\Exception\WebhookHeaderError;
use Lenorix\BeelSdk\Exception\WebhookSignatureError;
use Lenorix\BeelSdk\Exception\WebhookVerificationError;
use Lenorix\BeelSdk\Webhook\WebhookSignatureHeader;
use Lenorix\BeelSdk\Webhook\WebhookVerifier;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;
use Lenorix\LaravelBeel\Events\Webhooks\BeelWebhookEvent;

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
            self::warn('secret_missing', 'BeeL webhook secret is not configured; BeeL will retry the delivery.', $request);

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
            if ($exception instanceof WebhookSignatureError) {
                self::warn('signature_mismatch', 'BeeL webhook signature does not match the configured secret (was it just rotated?); BeeL will retry the delivery.', $request);

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

        $claim = self::claim($id, $secret, $tolerance);
        if ($claim === false) {
            return self::received();
        }

        // Dispatched before answering, so BeeL's 202 means the listeners ran. If one fails, release the
        // claim and answer 503: BeeL retries (and the retry command sees the failed delivery) instead of
        // the event being lost after a 202. Listeners should stay light and push heavy work to queued
        // jobs: they delay the response, and BeeL gives up on a delivery after 10 seconds.
        try {
            Event::dispatch($received = new BeelWebhookReceived($id, $type, $data, $payload, $webhookKey));

            if (($typed = BeelWebhookEvent::for($received)) !== null) {
                Event::dispatch($typed);
            }
        } catch (\Throwable $exception) {
            $claim?->release();
            report($exception);

            return new JsonResponse(['message' => 'The BeeL webhook could not be processed; BeeL will retry it.'], 503);
        }

        return self::received();
    }

    /**
     * Parses the header with the SDK and checks its replay window, and additionally requires a v1
     * shaped like a lowercase SHA-256 hex digest (the only thing hash_hmac can produce), so it
     * never rejects a signature the SDK would accept. The SDK still performs the real HMAC check.
     */
    private static function isPlausibleSignature(string $header, int $tolerance): bool
    {
        try {
            $parsed = WebhookSignatureHeader::parse($header);
        } catch (WebhookHeaderError) {
            return false;
        }

        $hasDigest = false;
        foreach ($parsed->signatures as $signature) {
            $hasDigest = $hasDigest || (strlen($signature) === 64 && ctype_xdigit($signature) && strtolower($signature) === $signature);
        }

        return $hasDigest && abs(time() - $parsed->timestamp) <= $tolerance;
    }

    /**
     * BeeL redelivers an event with the same id (also sent as the Idempotency-Key header). Remember
     * accepted events so a redelivery gets the same 202 without dispatching the Laravel event twice.
     * Cache::add is atomic, so concurrent duplicates dispatch once. Only verified deliveries get here,
     * and the key is derived from the signed payload id and the secret that verified it (never the
     * unsigned header or the URL segment, which the signature doesn't cover): replaying a captured
     * delivery to another URL can't dodge it, and each subscription's secret keeps tenants apart. The
     * claim lasts at least twice the replay tolerance, the span one signature stays valid.
     *
     * Returns false when the event was already claimed, null when deduplication is disabled, and
     * otherwise a claim to release if processing fails.
     */
    private static function claim(string $eventId, string $secret, int $tolerance): WebhookClaim|false|null
    {
        $seconds = config('beel.webhook_dedupe_seconds', 900);
        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            return null;
        }

        $store = config('beel.webhook_dedupe_store');
        $cache = Cache::store(is_string($store) && $store !== '' ? $store : null);
        $key = 'beel:webhook:'.hash_hmac('sha256', $eventId, $secret);

        return $cache->add($key, true, max((int) $seconds, 2 * $tolerance)) ? new WebhookClaim($cache, $key) : false;
    }

    /**
     * Makes secret misconfiguration visible. Only for requests with a plausible BeeL signature, and at
     * most once per reason per minute: with no rate limit, forged requests could otherwise flood the
     * log. Never logs the secret, the signature or the body; the delivery id header is unverified.
     */
    private static function warn(string $reason, string $message, Request $request): void
    {
        $store = config('beel.webhook_dedupe_store');
        $firstThisMinute = rescue(
            fn () => Cache::store(is_string($store) && $store !== '' ? $store : null)->add("beel:webhook:warned:{$reason}", true, 60),
            true,
            false,
        );

        if (! $firstThisMinute) {
            return;
        }

        $webhookKey = $request->route('beelWebhookKey');
        $deliveryId = $request->header('BeeL-Delivery-Id');

        Log::warning($message, [
            'reason' => $reason,
            'webhook_key' => is_string($webhookKey) ? mb_substr($webhookKey, 0, 64) : null,
            'unverified_delivery_id' => is_string($deliveryId) ? mb_substr($deliveryId, 0, 64) : null,
        ]);
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
