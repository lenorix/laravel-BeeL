<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Lenorix\BeelSdk\Exception\WebhookVerificationError;
use Lenorix\BeelSdk\Webhook\WebhookVerifier;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

final class BeelWebhookController
{
    public function __invoke(Request $request, WebhookSecretResolver $secrets): JsonResponse
    {
        $secret = $secrets->resolve($request);
        if ($secret === null) {
            return response()->json(['message' => 'BeeL webhook secret is not configured.'], 503);
        }

        try {
            $payload = (new WebhookVerifier($secret))->verify(
                $request->getContent(),
                $request->header('BeeL-Signature'),
            );
        } catch (WebhookVerificationError) {
            return response()->json(['message' => 'Invalid BeeL webhook signature or payload.'], 401);
        }

        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($id) || ! is_string($type) || ! is_array($data)) {
            return response()->json(['message' => 'Invalid BeeL webhook event.'], 400);
        }

        // Deferred to after the response is sent so listener work never delays BeeL's 202 ack.
        // Listeners that must survive a worker restart or run reliably under load should still
        // implement ShouldQueue; this only protects response latency, not delivery guarantees.
        defer(fn () => Event::dispatch(new BeelWebhookReceived($id, $type, $data, $payload)));

        return response()->json(['received' => true], 202);
    }
}
