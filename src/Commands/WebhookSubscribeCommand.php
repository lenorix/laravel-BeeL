<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Env;
use Lenorix\LaravelBeel\BeelWebhookSubscriptions;
use Lenorix\LaravelBeel\ConfigWebhookSecretResolver;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Exceptions\RotatedWebhookSecretNotStored;
use Lenorix\LaravelBeel\Exceptions\WebhookSubscriptionAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\WebhookSubscriptionOrphaned;
use Lenorix\LaravelBeel\Support\EnvFileWriter;

/**
 * Creates this app's BeeL webhook subscription (or rotates its secret) and stores the signing secret
 * in .env. BeeL shows a secret only once, so the command checks everything it can before calling
 * BeeL, never prints the secret, and undoes a creation whose secret could not be saved.
 * Single-app: multi-tenant apps use BeelWebhookSubscriptions with their own store callback.
 */
final class WebhookSubscribeCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'beel:webhook:subscribe
        {--url= : Webhook URL (defaults to APP_URL + beel.webhook_path)}
        {--event=* : Event types to subscribe to (defaults to every non-provisioner event)}
        {--provisioner-events : Also subscribe to the integrator-only events (account.claimed, company.created, representation.signed)}
        {--account-relationship= : Which accounts to receive events from: own (default), managed or all (integrators)}
        {--env-key=BEEL_WEBHOOK_SECRET : .env key that receives the signing secret}
        {--rotate : Rotate the secret of the existing subscription for this URL instead of creating one}
        {--force : Run in production without asking}';

    protected $description = 'Subscribe this app to BeeL webhooks (or rotate the secret) and store the secret in .env';

    public function handle(BeelWebhookSubscriptions $subscriptions, EnvFileWriter $writer, WebhookSecretResolver $secrets): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $url = $this->stringOption('url') ?? $subscriptions->url();
        $envKey = $this->stringOption('env-key') ?? 'BEEL_WEBHOOK_SECRET';
        $envPath = $this->laravel->environmentFilePath();

        // Everything that can fail without BeeL is checked first: a secret BeeL returns can't be read again.
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            $this->error("The webhook URL must be an absolute HTTPS URL; got {$url}. Set APP_URL or pass --url.");

            return self::FAILURE;
        }
        $relationship = $this->stringOption('account-relationship');
        if ($relationship !== null && ! in_array($relationship, BeelWebhookSubscriptions::ACCOUNT_RELATIONSHIPS, true)) {
            $this->error('--account-relationship must be one of: '.implode(', ', BeelWebhookSubscriptions::ACCOUNT_RELATIONSHIPS).'.');

            return self::FAILURE;
        }
        $realEnvPath = realpath($envPath);
        if ($realEnvPath === false || ! is_file($realEnvPath) || ! is_writable($realEnvPath)) {
            $this->error("{$envPath} (.env) must exist and be writable.");

            return self::FAILURE;
        }
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $envKey) !== 1) {
            $this->error("Invalid .env key {$envKey}.");

            return self::FAILURE;
        }

        // The app must actually read the secret this command writes, or --rotate would kill the
        // secret it really uses and store the new one where nothing reads it.
        if (! $secrets instanceof ConfigWebhookSecretResolver) {
            $this->error('A custom WebhookSecretResolver is bound, so the app does not read the webhook secret from .env. Use Lenorix\LaravelBeel\BeelWebhookSubscriptions with your own store callback instead.');

            return self::FAILURE;
        }
        if (! $this->laravel->configurationIsCached()) {
            $configured = config('services.beel.webhook_secret');
            $current = Env::get($envKey);

            if (is_string($configured) && $configured !== '' && $configured !== $current) {
                $this->error("services.beel.webhook_secret does not come from {$envKey}; the secret would be written where the app doesn't read it. Pass the right --env-key.");

                return self::FAILURE;
            }
        }

        $saveFailure = null;
        $store = function (#[\SensitiveParameter] string $secret) use ($writer, $envPath, $envKey, &$saveFailure): void {
            try {
                $writer->write($envPath, $envKey, $secret);
            } catch (\Throwable $exception) {
                $saveFailure = $exception; // tells a failed save apart from a BeeL error below

                throw $exception;
            }
        };

        return $this->option('rotate')
            ? $this->rotate($subscriptions, $url, $store, $envKey)
            : $this->create($subscriptions, $url, $store, $envKey, $saveFailure);
    }

    /** @param \Closure(string): void $store */
    private function create(BeelWebhookSubscriptions $subscriptions, string $url, \Closure $store, string $envKey, ?\Throwable &$saveFailure): int
    {
        try {
            $subscription = $subscriptions->subscribe($store, url: $url, events: $this->events($subscriptions), accountRelationship: $this->stringOption('account-relationship'));
        } catch (WebhookSubscriptionAlreadyExists $exception) {
            // A second subscription would sign with another secret, so its deliveries would fail verification.
            $this->error("Subscription {$exception->subscriptionId} already delivers to {$url}. Use --rotate to replace its secret.");

            return self::FAILURE;
        } catch (WebhookSubscriptionOrphaned $exception) {
            $this->error("Could not save the secret to .env ({$exception->getPrevious()?->getMessage()}) nor delete subscription {$exception->subscriptionId}: delete it in BeeL and run this command again.");

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception === $saveFailure
                ? "Could not save the secret to .env ({$exception->getMessage()}); the new subscription was deleted, nothing changed."
                : "Could not create the subscription: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Subscribed {$url} (subscription {$subscription->id}); the signing secret was saved to {$envKey} in .env.");
        $this->line('BeeL sent a test delivery while creating it, before the secret was saved, so that one probably failed; that is expected.');
        $this->afterSecretChange();

        return self::SUCCESS;
    }

    /** @param \Closure(string): void $store */
    private function rotate(BeelWebhookSubscriptions $subscriptions, string $url, \Closure $store, string $envKey): int
    {
        try {
            $existing = $subscriptions->find(url: $url);
            if ($existing === null) {
                $this->error("There is no subscription for {$url} to rotate. Run without --rotate to create it.");

                return self::FAILURE;
            }
            if (! $existing->active) {
                $this->warn("Subscription {$existing->id} is inactive; rotating its secret does not reactivate it (BeeL needs a successful test delivery first).");
            }

            $subscriptions->rotate($store, url: $url);
        } catch (RotatedWebhookSecretNotStored $exception) {
            // The old secret is already invalid and the new one can't be fetched again: showing it once
            // is the only way to recover. This is the one case the command prints a secret.
            $this->error("Could not save the rotated secret to .env ({$exception->getPrevious()?->getMessage()}). The old secret no longer works.");
            $this->warn("Set {$envKey} to this value now; BeeL will not show it again:");
            $this->line($exception->secret);

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error("Could not rotate the secret: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Rotated the secret of subscription {$existing->id}; the new secret was saved to {$envKey} in .env.");
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

    /** @return list<string> */
    private function events(BeelWebhookSubscriptions $subscriptions): array
    {
        $given = array_values(array_filter((array) $this->option('event'), fn ($event) => is_string($event) && $event !== ''));

        $events = $given !== [] ? $given : $subscriptions->defaultEvents();

        return $this->option('provisioner-events')
            ? array_values(array_unique([...$events, ...BeelWebhookSubscriptions::PROVISIONER_EVENTS]))
            : $events;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
