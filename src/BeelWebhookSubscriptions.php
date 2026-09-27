<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Lenorix\BeelSdk\Generated\Model\CreateWebhookSubscriptionRequest;
use Lenorix\BeelSdk\Generated\Model\WebhookSubscription;
use Lenorix\BeelSdk\Generated\Model\WebhookSubscriptionWithSecret;
use Lenorix\BeelSdk\Webhook\WebhookEventType;
use Lenorix\LaravelBeel\Exceptions\RotatedWebhookSecretNotStored;
use Lenorix\LaravelBeel\Exceptions\WebhookSubscriptionAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\WebhookSubscriptionNotFound;
use Lenorix\LaravelBeel\Exceptions\WebhookSubscriptionOrphaned;
use Lenorix\LaravelBeel\Support\Settings;

/**
 * Manages BeeL webhook subscriptions pointing at this app, e.g. one per tenant
 * (`/beel/webhook/{webhookKey}`). BeeL shows a subscription's secret only once, so it is handed to a
 * `store` callback (persist it where your WebhookSecretResolver reads it) and never returned: if the
 * callback fails after a create, the new subscription is deleted so no secret is lost.
 *
 * Credentials default to the bound CredentialsResolver; pass `apiKey`/`accountId` per tenant.
 * The API key needs the webhooks:read and webhooks:write scopes.
 */
final class BeelWebhookSubscriptions
{
    public const ACCOUNT_RELATIONSHIPS = ['own', 'managed', 'all'];

    public function __construct(private BeelManager $manager) {}

    /** The webhook URL for a key: APP_URL + beel.webhook_path [+ '/' + key]. */
    public function url(?string $webhookKey = null): string
    {
        $url = rtrim(Settings::string('app.url', ''), '/').'/'.trim(Settings::string('beel.webhook_path', 'beel/webhook'), '/');

        return $webhookKey === null || $webhookKey === '' ? $url : $url.'/'.rawurlencode($webhookKey);
    }

    /** @return list<string> Every event type except the provisioner-only ones. */
    public function defaultEvents(): array
    {
        return array_values(array_diff($this->allEvents(), self::provisionerEvents()));
    }

    /**
     * Every event type, including the provisioner-only ones: for integrators, whose key has the
     * privileged `accounts:*` scopes, to also hear when a provisioned account is claimed, a company
     * created or an AEAT representation signed.
     *
     * @return list<string>
     */
    public function allEvents(): array
    {
        return array_map(fn (WebhookEventType $type) => $type->value, WebhookEventType::cases());
    }

    /**
     * The events BeeL only sends to the account that provisioned a managed account (integrators).
     *
     * BeeL's webhook docs (https://docs.beel.es/webhooks/events) list three; lenorix/beel-sdk 0.6's
     * WebhookEventType::isProvisionerOnly() only flags account.*, so the documented list is kept here
     * and anything the SDK flags is added to it.
     *
     * @return list<string>
     */
    public static function provisionerEvents(): array
    {
        $flagged = array_map(
            fn (WebhookEventType $type) => $type->value,
            array_filter(WebhookEventType::cases(), fn (WebhookEventType $type) => $type->isProvisionerOnly()),
        );

        return array_values(array_unique(['account.claimed', 'company.created', 'representation.signed', ...$flagged]));
    }

    /**
     * Create the subscription and pass its secret to `$store`. If `$store` throws, the new
     * subscription is deleted and the exception rethrown (or WebhookSubscriptionOrphaned if the
     * delete fails too).
     *
     * @param  callable(string): mixed  $store
     * @param  list<string>|null  $events  Defaults to defaultEvents().
     * @param  string|null  $accountRelationship  Which accounts it receives events from: `own` (BeeL's default),
     *                                            `managed` (accounts you provisioned) or `all`. Integrators
     *                                            need `managed` or `all` to hear from their accounts.
     *
     * @throws WebhookSubscriptionAlreadyExists
     */
    public function subscribe(callable $store, ?string $webhookKey = null, ?array $events = null, ?string $url = null, ?string $apiKey = null, ?string $accountId = null, ?string $accountRelationship = null): BeelWebhookSubscription
    {
        if ($accountRelationship !== null && ! in_array($accountRelationship, self::ACCOUNT_RELATIONSHIPS, true)) {
            throw new \InvalidArgumentException('The account relationship must be one of: '.implode(', ', self::ACCOUNT_RELATIONSHIPS).'.');
        }

        $url = $this->validatedUrl($url ?? $this->url($webhookKey));
        $account = $this->manager->account(apiKey: $apiKey, accountId: $accountId);

        $existing = $this->findIn($account, $url);
        if ($existing !== null) {
            throw new WebhookSubscriptionAlreadyExists($existing->getId(), $existing->getUrl());
        }

        $request = (new CreateWebhookSubscriptionRequest)->setUrl($url)->setEvents($events ?? $this->defaultEvents());
        if ($accountRelationship !== null) {
            $request->setAccountRelationship($accountRelationship);
        }
        $created = self::withSecret($account->webhooks->create($request));

        try {
            $store($created->getSecret());
        } catch (\Throwable $storeFailure) {
            try {
                $account->webhooks->delete($created->getId());
            } catch (\Throwable) {
                throw new WebhookSubscriptionOrphaned($created->getId(), $storeFailure);
            }

            throw $storeFailure;
        }

        return BeelWebhookSubscription::fromSdk($created);
    }

    /**
     * Rotate the secret of the subscription for that URL and pass the new one to `$store`. BeeL
     * invalidates the old secret immediately. If `$store` throws, RotatedWebhookSecretNotStored
     * carries the new secret, the only copy left.
     *
     * @param  callable(string): mixed  $store
     *
     * @throws WebhookSubscriptionNotFound
     * @throws RotatedWebhookSecretNotStored
     */
    public function rotate(callable $store, ?string $webhookKey = null, ?string $url = null, ?string $apiKey = null, ?string $accountId = null): BeelWebhookSubscription
    {
        $url = $this->validatedUrl($url ?? $this->url($webhookKey));
        $account = $this->manager->account(apiKey: $apiKey, accountId: $accountId);

        $existing = $this->findIn($account, $url) ?? throw new WebhookSubscriptionNotFound($url);
        $rotated = self::withSecret($account->webhooks->rotateSecret($existing->getId()));

        try {
            $store($rotated->getSecret());
        } catch (\Throwable $storeFailure) {
            throw new RotatedWebhookSecretNotStored($existing->getId(), $rotated->getSecret(), $storeFailure);
        }

        return BeelWebhookSubscription::fromSdk($existing);
    }

    /** The subscription delivering to that URL (trailing slash ignored), if any. */
    public function find(?string $webhookKey = null, ?string $url = null, ?string $apiKey = null, ?string $accountId = null): ?BeelWebhookSubscription
    {
        $found = $this->findIn($this->manager->account(apiKey: $apiKey, accountId: $accountId), $url ?? $this->url($webhookKey));

        return $found === null ? null : BeelWebhookSubscription::fromSdk($found);
    }

    /** Delete the subscription delivering to that URL. Returns false if there was none. */
    public function unsubscribe(?string $webhookKey = null, ?string $url = null, ?string $apiKey = null, ?string $accountId = null): bool
    {
        $account = $this->manager->account(apiKey: $apiKey, accountId: $accountId);
        $existing = $this->findIn($account, $url ?? $this->url($webhookKey));

        if ($existing === null) {
            return false;
        }

        $account->webhooks->delete($existing->getId());

        return true;
    }

    private function findIn(BeelAccount $account, string $url): ?WebhookSubscription
    {
        foreach ($account->webhooks->all(['limit' => 100]) as $subscription) {
            if (rtrim($subscription->getUrl(), '/') === rtrim($url, '/')) {
                return $subscription;
            }
        }

        return null;
    }

    private function validatedUrl(string $url): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException("The webhook URL must be an absolute HTTPS URL; got {$url}. Set APP_URL or pass a url.");
        }

        return $url;
    }

    /** The SDK types these responses as mixed; anything but a subscription with its secret is a bug to surface. */
    private static function withSecret(mixed $response): WebhookSubscriptionWithSecret
    {
        return $response instanceof WebhookSubscriptionWithSecret
            ? $response
            : throw new \UnexpectedValueException('BeeL returned '.get_debug_type($response).' instead of a webhook subscription with its secret.');
    }
}
