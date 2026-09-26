<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Lenorix\BeelSdk\Generated\Model\CreateWebhookSubscriptionRequest;
use Lenorix\BeelSdk\Generated\Model\WebhookSubscription;
use Lenorix\BeelSdk\Webhook\WebhookEventType;
use Lenorix\LaravelBeel\BeelAccount;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Support\EnvFileWriter;

/**
 * Creates this app's BeeL webhook subscription (or rotates its secret) and stores the signing secret
 * in .env. BeeL shows a secret only once, so the command checks everything it can before calling
 * BeeL, never prints the secret, and undoes a creation whose secret could not be saved.
 * Single-app: multi-tenant apps create subscriptions per tenant with $account->webhooks->create().
 */
final class WebhookSubscribeCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'beel:webhook:subscribe
        {--url= : Webhook URL (defaults to APP_URL + beel.webhook_path)}
        {--event=* : Event types to subscribe to (defaults to every non-provisioner event)}
        {--env-key=BEEL_WEBHOOK_SECRET : .env key that receives the signing secret}
        {--rotate : Rotate the secret of the existing subscription for this URL instead of creating one}
        {--force : Run in production without asking}';

    protected $description = 'Subscribe this app to BeeL webhooks (or rotate the secret) and store the secret in .env';

    /** Only sent to the account that provisioned a managed account. */
    private const PROVISIONER_EVENTS = ['account.claimed', 'company.created', 'representation.signed'];

    public function handle(BeelManager $manager, EnvFileWriter $writer): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $url = $this->stringOption('url') ?? rtrim((string) config('app.url'), '/').'/'.trim((string) config('beel.webhook_path', 'beel/webhook'), '/');
        $envKey = $this->stringOption('env-key') ?? 'BEEL_WEBHOOK_SECRET';
        $envPath = $this->laravel->environmentFilePath();

        // Everything that can fail without BeeL is checked first: a secret BeeL returns can't be read again.
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            $this->error("The webhook URL must be an absolute HTTPS URL; got {$url}. Set APP_URL or pass --url.");

            return self::FAILURE;
        }
        if (! is_file($envPath) || ! is_writable($envPath) || ! is_writable(dirname($envPath))) {
            $this->error("{$envPath} (.env) must exist and be writable, with a writable directory.");

            return self::FAILURE;
        }
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $envKey) !== 1) {
            $this->error("Invalid .env key {$envKey}.");

            return self::FAILURE;
        }

        try {
            $account = $manager->account();
            $existing = $this->subscriptionFor($account, $url);
        } catch (\Throwable $exception) {
            $this->error("Could not read the webhook subscriptions: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($this->option('rotate')) {
            if ($existing === null) {
                $this->error("There is no subscription for {$url} to rotate. Run without --rotate to create it.");

                return self::FAILURE;
            }

            return $this->rotate($account, $existing, $writer, $envPath, $envKey);
        }

        if ($existing !== null) {
            // A second subscription would sign with another secret, so its deliveries would fail verification.
            $this->error("Subscription {$existing->getId()} already delivers to {$url}. Use --rotate to replace its secret.");

            return self::FAILURE;
        }

        return $this->create($account, $url, $writer, $envPath, $envKey);
    }

    private function create(BeelAccount $account, string $url, EnvFileWriter $writer, string $envPath, string $envKey): int
    {
        try {
            $subscription = $account->webhooks->create(
                (new CreateWebhookSubscriptionRequest)->setUrl($url)->setEvents($this->events()),
            );
        } catch (\Throwable $exception) {
            $this->error("BeeL could not create the subscription: {$exception->getMessage()}");

            return self::FAILURE;
        }

        try {
            $writer->write($envPath, $envKey, $subscription->getSecret());
        } catch (\Throwable $exception) {
            // Without the secret the subscription is useless and its deliveries would fail: remove it.
            try {
                $account->webhooks->delete($subscription->getId());
                $this->error("Could not save the secret to .env ({$exception->getMessage()}); the new subscription was deleted, nothing changed.");
            } catch (\Throwable) {
                $this->error("Could not save the secret to .env ({$exception->getMessage()}) nor delete subscription {$subscription->getId()}: delete it in BeeL and run this command again.");
            }

            return self::FAILURE;
        }

        $this->info("Subscribed {$url} (subscription {$subscription->getId()}); the signing secret was saved to {$envKey} in .env.");
        $this->line('BeeL sent a test delivery while creating it, before the secret was saved, so that one probably failed; that is expected.');
        $this->afterSecretChange();

        return self::SUCCESS;
    }

    private function rotate(BeelAccount $account, WebhookSubscription $existing, EnvFileWriter $writer, string $envPath, string $envKey): int
    {
        try {
            $subscription = $account->webhooks->rotateSecret($existing->getId());
        } catch (\Throwable $exception) {
            $this->error("BeeL could not rotate the secret: {$exception->getMessage()}");

            return self::FAILURE;
        }

        try {
            $writer->write($envPath, $envKey, $subscription->getSecret());
        } catch (\Throwable $exception) {
            // The old secret is already invalid and the new one can't be fetched again: showing it once
            // is the only way to recover. This is the one case the command prints a secret.
            $this->error("Could not save the rotated secret to .env ({$exception->getMessage()}). The old secret no longer works.");
            $this->warn("Set {$envKey} to this value now; BeeL will not show it again:");
            $this->line($subscription->getSecret());

            return self::FAILURE;
        }

        $this->info("Rotated the secret of subscription {$existing->getId()}; the new secret was saved to {$envKey} in .env.");
        $this->line('Deliveries signed with the old secret between the rotation and the reload answer 503, so BeeL retries them.');
        $this->afterSecretChange();

        return self::SUCCESS;
    }

    private function afterSecretChange(): void
    {
        if ($this->laravel->configurationIsCached()) {
            $this->warn('The configuration is cached: run php artisan config:cache so the new secret is used.');
        }

        $this->line('Restart long-running processes (Octane, queue workers, Horizon) so they read the new secret.');
    }

    private function subscriptionFor(BeelAccount $account, string $url): ?WebhookSubscription
    {
        $page = 1;

        do {
            $result = $account->webhooks->list(['page' => $page++, 'limit' => 100]);

            foreach ($result->getWebhooks() as $subscription) {
                if ($subscription->getUrl() === $url) {
                    return $subscription;
                }
            }
        } while ($result->getPagination()->getHasNext());

        return null;
    }

    /** @return list<string> */
    private function events(): array
    {
        $given = array_values(array_filter((array) $this->option('event'), fn ($event) => is_string($event) && $event !== ''));
        if ($given !== []) {
            return $given;
        }

        return array_values(array_diff(
            array_map(fn (WebhookEventType $type) => $type->value, WebhookEventType::cases()),
            self::PROVISIONER_EVENTS,
        ));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
