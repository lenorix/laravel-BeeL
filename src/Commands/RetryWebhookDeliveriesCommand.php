<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\BeelSdk\Generated\Model\WebhookDeliveryLog;
use Lenorix\BeelSdk\Generated\Model\WebhookSubscription;
use Lenorix\BeelSdk\Http\RequestOptions;
use Lenorix\LaravelBeel\AccountCredentials;
use Lenorix\LaravelBeel\BeelAccount;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Contracts\WebhookRetryAccounts;
use Lenorix\LaravelBeel\Events\BeelWebhookDeliveryAbandoned;
use Lenorix\LaravelBeel\Events\BeelWebhookSubscriptionInactive;

/**
 * Safety net for webhook deliveries that never reached the app: BeeL retries a failed delivery only
 * 5 times over ~75 s, and never after a 4xx. For every event with no successful attempt, first
 * attempted within max_age_minutes and whose latest attempt is at least 2 minutes old, it asks BeeL
 * to redeliver it (idempotently, keyed on the delivery attempt), so it goes through the normal
 * verified endpoint.
 *
 * Events reaching max_attempts dispatch BeelWebhookDeliveryAbandoned; subscriptions BeeL
 * deactivated dispatch BeelWebhookSubscriptionInactive. Both repeat on every run while the
 * condition lasts, and the command then exits with failure. It checks the CredentialsResolver's
 * account, or every account a bound WebhookRetryAccounts returns. The key needs webhooks:read and
 * webhooks:write.
 */
final class RetryWebhookDeliveriesCommand extends Command
{
    protected $signature = 'beel:retry-webhook-deliveries
        {--account-id= : BeeL account UUID (defaults to services.beel.account_id)}
        {--api-key= : BeeL API key (defaults to services.beel.key)}
        {--webhook-id=* : Only check these webhook subscription ids (of a single account)}
        {--max-age= : Only retry events first attempted within this many minutes}
        {--max-attempts= : Give up on events that already have this many attempts}
        {--dry-run : Report what would be retried without asking BeeL to retry it}';

    protected $description = 'Ask BeeL to redeliver webhook events that never reached this app';

    private const PAGE_SIZE = 100;

    /**
     * BeeL's own retries of a delivery (5 attempts, backing off 5/10/20/40 s) finish about 75 s after
     * the first one. Leave an event alone while its latest attempt is this recent, so a run never
     * races BeeL's automatic retries, a dashboard retry, or an overlapping run.
     */
    private const GRACE_SECONDS = 120;

    private bool $healthy = true;

    public function handle(BeelManager $manager, Container $container): int
    {
        $maxAge = (int) ($this->stringOption('max-age') ?? config('beel.webhook_delivery_retry.max_age_minutes', 1440));
        $maxAttempts = (int) ($this->stringOption('max-attempts') ?? config('beel.webhook_delivery_retry.max_attempts', 8));
        $cutoff = Carbon::now()->subMinutes($maxAge);

        $apiKey = $this->stringOption('api-key');
        $accountId = $this->stringOption('account-id');
        // Subscription ids belong to one account, so --webhook-id also means a single account: the
        // given one or the CredentialsResolver default, never every account of the provider.
        $singleAccount = $apiKey !== null || $accountId !== null || $this->webhookIdsOption() !== [];
        $accounts = $singleAccount
            ? [new AccountCredentials($accountId, $apiKey)]
            : $container->make(WebhookRetryAccounts::class)->accounts();

        foreach ($accounts as $credentials) {
            try {
                $account = $manager->account(apiKey: $credentials->apiKey, accountId: $credentials->accountId);
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage());
                $this->healthy = false;

                continue;
            }

            $this->processAccount($account, $cutoff, $maxAttempts);
        }

        return $this->healthy ? self::SUCCESS : self::FAILURE;
    }

    private function processAccount(BeelAccount $account, Carbon $cutoff, int $maxAttempts): void
    {
        try {
            $webhookIds = $this->subscriptionIds($account);
        } catch (\Throwable $exception) {
            $this->error("Could not list the webhook subscriptions of account {$account->accountId}: {$exception->getMessage()}");
            $this->healthy = false;

            return;
        }

        foreach ($webhookIds as $webhookId) {
            try {
                $deliveries = $this->deliveries($account, $webhookId);
            } catch (\Throwable $exception) {
                $this->error("Could not read the deliveries of webhook {$webhookId} of account {$account->accountId}: {$exception->getMessage()}");
                $this->healthy = false;

                continue;
            }

            $deliveries
                ->groupBy(fn (WebhookDeliveryLog $log) => $log->getWebhookEventId())
                ->each(fn (Collection $attempts, string $eventId) => $this->handleEvent($account, $webhookId, $eventId, $attempts, $cutoff, $maxAttempts));
        }
    }

    /** @param Collection<int, WebhookDeliveryLog> $attempts */
    private function handleEvent(BeelAccount $account, string $webhookId, string $eventId, Collection $attempts, Carbon $cutoff, int $maxAttempts): void
    {
        if ($attempts->contains(fn (WebhookDeliveryLog $log) => $log->getSuccess())) {
            return;
        }

        $firstAttemptAt = $attempts->min(fn (WebhookDeliveryLog $log) => $log->getDeliveredAt()->getTimestamp());
        if ($firstAttemptAt < $cutoff->getTimestamp()) {
            return;
        }

        /** @var WebhookDeliveryLog $latest */
        $latest = $attempts->sortByDesc(fn (WebhookDeliveryLog $log) => $log->getAttemptNumber())->first();
        if ($latest->getDeliveredAt()->getTimestamp() > Carbon::now()->subSeconds(self::GRACE_SECONDS)->getTimestamp()) {
            return;
        }
        $label = "event {$eventId} ({$latest->getEventType()}) on webhook {$webhookId} of account {$account->accountId}";

        if ($latest->getAttemptNumber() >= $maxAttempts) {
            $this->reportAbandoned($account, $webhookId, $eventId, $latest, $label);

            return;
        }

        if ($this->option('dry-run')) {
            $this->line("Would retry {$label} (delivery {$latest->getId()}).");

            return;
        }

        try {
            $this->retry($account, $webhookId, $latest->getId())
                ? $this->info("Asked BeeL to retry {$label}.")
                : $this->info("{$label} is already being retried by another run.");
        } catch (\Throwable $exception) {
            $code = $exception instanceof BeelApiError && $exception->apiCode !== null ? " [{$exception->apiCode}]" : '';
            $this->error("Could not retry {$label}{$code}: {$exception->getMessage()}");
            $this->healthy = false;
        }
    }

    /**
     * The key is per delivery attempt (a UUID, so always a valid key): overlapping runs asking to retry
     * the same attempt don't make BeeL redeliver twice, while a later retry targets the new latest
     * attempt and so gets a new key. Returns false when another run's request with the same key is
     * still in flight (BeeL answers 409 IDEMPOTENCY_KEY_PROCESSING): that retry is already happening.
     */
    private function retry(BeelAccount $account, string $webhookId, string $deliveryId): bool
    {
        try {
            $account->webhooks
                ->withOptions(new RequestOptions(idempotencyKey: "beel-webhook-retry-{$deliveryId}"))
                ->retryDelivery($webhookId, $deliveryId);
        } catch (BeelApiError $exception) {
            if ($exception->apiCode === 'IDEMPOTENCY_KEY_PROCESSING') {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    /** @return list<string> */
    private function subscriptionIds(BeelAccount $account): array
    {
        $ids = $this->webhookIdsOption();
        if ($ids !== []) {
            return $ids;
        }

        $ids = [];
        foreach (iterator_to_array($account->webhooks->all(['limit' => self::PAGE_SIZE]), false) as $subscription) {
            /** @var WebhookSubscription $subscription */
            if (! $subscription->getActive()) {
                $this->reportInactive($account, $subscription);

                continue;
            }

            $ids[] = $subscription->getId();
        }

        return $ids;
    }

    /** The app never received this event and we stop retrying it: how to recover is the app's call. */
    private function reportAbandoned(BeelAccount $account, string $webhookId, string $eventId, WebhookDeliveryLog $latest, string $label): void
    {
        $payload = $latest->getPayload() !== null ? json_decode($latest->getPayload(), true) : null;

        $event = new BeelWebhookDeliveryAbandoned(
            accountId: $account->accountId,
            subscriptionId: $webhookId,
            eventId: $eventId,
            eventType: $latest->getEventType(),
            attempts: $latest->getAttemptNumber(),
            lastDeliveryId: $latest->getId(),
            lastHttpStatus: $latest->getHttpStatus(),
            lastError: $latest->getErrorMessage(),
            payload: is_array($payload) ? $payload : null,
        );

        $message = "Giving up on {$label}: {$event->attempts} attempts, none delivered.";
        $this->warn($message);
        Log::warning($message, [
            'account_id' => $event->accountId,
            'subscription_id' => $event->subscriptionId,
            'event_id' => $event->eventId,
            'event_type' => $event->eventType,
            'attempts' => $event->attempts,
            'last_delivery_id' => $event->lastDeliveryId,
            'last_http_status' => $event->lastHttpStatus,
            'last_error' => $event->lastError,
        ]);
        $this->dispatchSafely($event);

        $this->healthy = false;
    }

    /**
     * BeeL pauses a subscription (deactivated_by "beel") after 25 consecutive failed deliveries over
     * more than 48 hours, and retries can't reach it until it is reactivated. What to do about it
     * (notify, open an incident, ...) is the app's call, so log it and dispatch an event.
     */
    private function reportInactive(BeelAccount $account, WebhookSubscription $subscription): void
    {
        // These fields are absent while a subscription is active, so their typed getters can't be called blindly.
        $field = fn (string $property, \Closure $get) => $subscription->isInitialized($property) ? $get() : null;

        $event = new BeelWebhookSubscriptionInactive(
            accountId: $account->accountId,
            subscriptionId: $subscription->getId(),
            url: $subscription->getUrl(),
            deactivatedBy: $field('deactivatedBy', fn () => $subscription->getDeactivatedBy()),
            deactivatedAt: $field('deactivatedAt', fn () => $subscription->getDeactivatedAt()),
            consecutiveFailures: $field('consecutiveFailures', fn () => $subscription->getConsecutiveFailures()),
            lastError: $field('lastError', fn () => $subscription->getLastError()),
        );

        $message = "BeeL webhook subscription {$event->subscriptionId} of account {$event->accountId} is inactive; BeeL will not deliver to it until it is reactivated.";
        $this->warn($message);
        Log::warning($message, [
            'account_id' => $event->accountId,
            'subscription_id' => $event->subscriptionId,
            'url' => $event->url,
            'deactivated_by' => $event->deactivatedBy,
            'deactivated_at' => $event->deactivatedAt?->format(DATE_ATOM),
            'consecutive_failures' => $event->consecutiveFailures,
            'last_error' => $event->lastError,
        ]);
        $this->dispatchSafely($event);

        $this->healthy = false;
    }

    /** A failing notification listener must not stop the safety net from handling the other events. */
    private function dispatchSafely(object $event): void
    {
        try {
            Event::dispatch($event);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** @return Collection<int, WebhookDeliveryLog> */
    private function deliveries(BeelAccount $account, string $webhookId): Collection
    {
        return collect(iterator_to_array($account->webhooks->allDeliveries($webhookId, ['limit' => self::PAGE_SIZE]), false));
    }

    /** @return list<string> */
    private function webhookIdsOption(): array
    {
        return array_values(array_filter((array) $this->option('webhook-id'), fn ($id) => is_string($id) && $id !== ''));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        // Options arrive as strings from the CLI but as ints when called via Artisan::call().
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
