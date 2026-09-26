<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lenorix\BeelSdk\Generated\Model\WebhookDeliveryLog;
use Lenorix\BeelSdk\Generated\Model\WebhookSubscription;
use Lenorix\LaravelBeel\BeelAccount;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Events\BeelWebhookSubscriptionInactive;

final class RetryWebhookDeliveriesCommand extends Command
{
    protected $signature = 'beel:retry-webhook-deliveries
        {--account-id= : BeeL account UUID (defaults to services.beel.account_id)}
        {--api-key= : BeeL API key (defaults to services.beel.key)}
        {--webhook-id=* : Only check these webhook subscription ids}
        {--max-age= : Only retry events first attempted within this many minutes}
        {--max-attempts= : Give up on events that already have this many attempts}
        {--dry-run : Report what would be retried without asking BeeL to retry it}';

    protected $description = 'Ask BeeL to redeliver webhook events that never reached this app';

    private const PAGE_SIZE = 100;

    private bool $healthy = true;

    public function handle(BeelManager $manager): int
    {
        try {
            $account = $manager->account(
                apiKey: $this->stringOption('api-key'),
                accountId: $this->stringOption('account-id'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $maxAge = (int) ($this->stringOption('max-age') ?? config('beel.webhook_delivery_retry.max_age_minutes', 1440));
        $maxAttempts = (int) ($this->stringOption('max-attempts') ?? config('beel.webhook_delivery_retry.max_attempts', 8));
        $cutoff = Carbon::now()->subMinutes($maxAge);

        foreach ($this->subscriptionIds($account) as $webhookId) {
            $this->deliveries($account, $webhookId)
                ->groupBy(fn (WebhookDeliveryLog $log) => $log->getWebhookEventId())
                ->each(fn (Collection $attempts, string $eventId) => $this->handleEvent($account, $webhookId, $eventId, $attempts, $cutoff, $maxAttempts));
        }

        return $this->healthy ? self::SUCCESS : self::FAILURE;
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
        $label = "event {$eventId} ({$latest->getEventType()}) on webhook {$webhookId}";

        if ($latest->getAttemptNumber() >= $maxAttempts) {
            $this->warn("Giving up on {$label}: {$latest->getAttemptNumber()} attempts, none delivered.");
            $this->healthy = false;

            return;
        }

        if ($this->option('dry-run')) {
            $this->line("Would retry {$label} (delivery {$latest->getId()}).");

            return;
        }

        try {
            $account->webhooks->retryDelivery($webhookId, $latest->getId());
            $this->info("Asked BeeL to retry {$label}.");
        } catch (\Throwable $exception) {
            $this->error("Could not retry {$label}: {$exception->getMessage()}");
            $this->healthy = false;
        }
    }

    /** @return list<string> */
    private function subscriptionIds(BeelAccount $account): array
    {
        $ids = array_values(array_filter((array) $this->option('webhook-id'), 'is_string'));
        if ($ids !== []) {
            return $ids;
        }

        $ids = [];
        foreach ($this->pages(fn (int $page) => $account->webhooks->list(['page' => $page, 'limit' => self::PAGE_SIZE]), 'getWebhooks') as $subscription) {
            /** @var WebhookSubscription $subscription */
            if (! $subscription->getActive()) {
                $this->reportInactive($account, $subscription);

                continue;
            }

            $ids[] = $subscription->getId();
        }

        return $ids;
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

        $message = "BeeL webhook subscription {$event->subscriptionId} is inactive; BeeL will not deliver to it until it is reactivated.";
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
        Event::dispatch($event);

        $this->healthy = false;
    }

    /** @return Collection<int, WebhookDeliveryLog> */
    private function deliveries(BeelAccount $account, string $webhookId): Collection
    {
        return collect($this->pages(
            fn (int $page) => $account->webhooks->listDeliveries($webhookId, ['page' => $page, 'limit' => self::PAGE_SIZE]),
            'getDeliveries',
        ));
    }

    /**
     * @param  \Closure(int): mixed  $fetch
     * @return list<mixed>
     */
    private function pages(\Closure $fetch, string $itemsGetter): array
    {
        $items = [];
        $page = 1;

        do {
            $result = $fetch($page);
            array_push($items, ...$result->{$itemsGetter}());
            $page++;
        } while ($result->getPagination()->getHasNext());

        return $items;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        // Options arrive as strings from the CLI but as ints when called via Artisan::call().
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
