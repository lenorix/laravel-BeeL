<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Contracts;

use Illuminate\Http\Request;

/**
 * Picks the secret that verifies a webhook delivery. The default reads services.beel.webhook_secret.
 *
 * For one secret per tenant, point each BeeL subscription at /beel/webhook/{key} and pick the secret
 * from `$request->route('beelWebhookKey')`. Return null for a missing or unknown key (the delivery
 * gets a retryable 503); never fall back to a shared secret. Only then is `$event->webhookKey` a
 * verified tenant: listeners should identify the tenant by it, not by the payload's company_id or
 * account_id, and ignore events whose ids don't belong to that tenant.
 */
interface WebhookSecretResolver
{
    /**
     * Resolve the signing secret from request metadata (URL, route), never from the unverified
     * payload: a tenant knowing its own secret could sign a payload naming another tenant.
     */
    public function resolve(Request $request): ?string;
}
