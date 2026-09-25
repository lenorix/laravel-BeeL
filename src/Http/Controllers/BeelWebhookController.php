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

        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($type) || ! is_array($data)) {
            return response()->json(['message' => 'Invalid BeeL webhook event.'], 400);
        }

        Event::dispatch(new BeelWebhookReceived($type, $data, $payload));

        return response()->json(['received' => true], 202);
    }
}
