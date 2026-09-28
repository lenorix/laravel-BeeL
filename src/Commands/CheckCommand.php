<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Commands;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NullStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Generated\Model\MyIdentity;
use Lenorix\BeelSdk\Generated\Model\WebhookSubscription;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\BeelWebhookSubscriptions;
use Lenorix\LaravelBeel\ConfigWebhookSecretResolver;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Support\Settings;

/**
 * Read-only diagnosis of the BeeL setup: it only sends GET requests and never changes anything in
 * BeeL (no test deliveries either). Errors exit 1; warnings are reported but exit 0.
 */
final class CheckCommand extends Command
{
    protected $signature = 'beel:check
        {--api-key= : Check this API key instead of the default credentials (e.g. one tenant\'s; it shows in ps and shell history)}
        {--company-id= : Company to check the issuing readiness of}
        {--account-id= : Account the key is expected to belong to}';

    protected $description = 'Check the BeeL configuration, API key, company and webhook setup (read-only)';

    private int $errors = 0;

    public function handle(BeelManager $manager, Container $container, WebhookSecretResolver $secrets): int
    {
        $credentials = $container->make(CredentialsResolver::class);
        // With --api-key, the ids come only from the options: the default ones belong to another key.
        $tenant = $this->stringOption('api-key') !== null;
        $apiKey = $tenant ? $this->stringOption('api-key') : $credentials->apiKey();
        $companyId = $this->stringOption('company-id') ?? ($tenant ? null : $credentials->companyId());
        $accountId = $this->stringOption('account-id') ?? ($tenant ? null : $credentials->accountId());

        if ($apiKey === null) {
            $this->fail_('No BeeL API key: set services.beel.key (BEEL_API_KEY), bind a CredentialsResolver that returns one, or pass --api-key.');

            return self::FAILURE;
        }

        $this->checkKeyEnvironment($apiKey);

        $beel = $manager->client($apiKey);
        $identity = $this->identity($beel);

        if ($identity !== null) {
            if (self::isIntegrator($identity)) {
                $this->note('The API key has the integrator scopes (accounts:*): it manages provisioned accounts.');
            }
            $this->checkAccount($identity, $accountId);
            $this->checkCompany($manager, $apiKey, $companyId);
            $this->checkWebhooks($manager, $apiKey, $identity);
        }

        $this->checkWebhookSecret($secrets);
        $this->checkDedupeStore();

        return $this->errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkKeyEnvironment(string $apiKey): void
    {
        $production = $this->laravel->environment('production');

        if (str_starts_with($apiKey, 'beel_sk_test_') && $production) {
            $this->warn_('The API key is a sandbox key (beel_sk_test_) but APP_ENV is production: invoices will not reach the real AEAT.');
        } elseif (str_starts_with($apiKey, 'beel_sk_live_') && ! $production) {
            $this->warn_('The API key is a live key (beel_sk_live_) outside production: invoices issued here are real.');
        } elseif (! str_starts_with($apiKey, 'beel_sk_test_') && ! str_starts_with($apiKey, 'beel_sk_live_')) {
            $this->warn_('The API key does not look like a BeeL key (beel_sk_test_... or beel_sk_live_...).');
        }
    }

    private function identity(Beel $beel): ?MyIdentity
    {
        try {
            $identity = $beel->me->identity();
        } catch (\Throwable $exception) {
            $this->fail_('BeeL rejected the API key or could not be reached: '.$exception->getMessage());

            return null;
        }

        $credential = $identity->getCredential();
        $this->ok("API key works: account {$identity->getAccountId()}, environment {$credential->getEnvironment()}.");

        return $identity;
    }

    private static function isIntegrator(MyIdentity $identity): bool
    {
        return array_intersect(['accounts:read', 'accounts:write'], $identity->getCredential()->getScopes()) !== [];
    }

    private function checkAccount(MyIdentity $identity, ?string $accountId): void
    {
        if ($accountId !== null && $accountId !== $identity->getAccountId()) {
            $this->fail_("The expected account is {$accountId} (services.beel.account_id or --account-id), but the API key belongs to account {$identity->getAccountId()}.");
        }
    }

    private function checkCompany(BeelManager $manager, string $apiKey, ?string $companyId): void
    {
        if ($companyId === null) {
            $this->note('No company id (services.beel.company_id or --company-id): skipping the issuing readiness check.');

            return;
        }

        try {
            $readiness = $manager->company(apiKey: $apiKey, companyId: $companyId)->issuingReadiness();
        } catch (\Throwable $exception) {
            $this->fail_("Could not read the issuing readiness of company {$companyId}: {$exception->getMessage()}");

            return;
        }

        if ($readiness->getReady()) {
            $this->ok("Company {$companyId} is ready to issue invoices.");
        } else {
            $this->fail_("Company {$companyId} cannot issue invoices yet: ".implode(', ', $readiness->getBlockers()).'.');
        }
    }

    private function checkWebhooks(BeelManager $manager, string $apiKey, MyIdentity $identity): void
    {
        $scopes = $identity->getCredential()->getScopes();
        $missing = array_values(array_diff(['webhooks:read', 'webhooks:write'], $scopes));

        if ($missing !== []) {
            $this->warn_('The API key lacks '.implode(' and ', $missing).', needed by beel:retry-webhook-deliveries (and to check webhook subscriptions here).');
        }

        if (! config('beel.register_webhook_route', true)) {
            $this->note('The package webhook route is disabled (beel.register_webhook_route): skipping the subscription check.');

            return;
        }

        $expected = rtrim(Settings::string('app.url', ''), '/').'/'.trim(Settings::string('beel.webhook_path', 'beel/webhook'), '/');

        if (! str_starts_with($expected, 'https://')) {
            $this->warn_("The webhook URL {$expected} (from APP_URL) is not HTTPS; BeeL only delivers to HTTPS endpoints.");
        }

        if (in_array('webhooks:read', $missing, true)) {
            return;
        }

        try {
            $subscriptions = $this->subscriptions($manager, $apiKey, $identity->getAccountId());
        } catch (\Throwable $exception) {
            $this->fail_("Could not list the webhook subscriptions: {$exception->getMessage()}");

            return;
        }

        $matching = array_filter($subscriptions, fn (WebhookSubscription $s) => rtrim($s->getUrl(), '/') === $expected || str_starts_with($s->getUrl(), $expected.'/'));

        if ($matching === []) {
            $this->warn_("No BeeL webhook subscription points at {$expected} (per-tenant URLs under it also count). Check APP_URL if the app is served elsewhere.");

            return;
        }

        $byUrl = [];
        foreach ($matching as $subscription) {
            $byUrl[rtrim($subscription->getUrl(), '/')][] = $subscription->getId();
        }
        foreach ($byUrl as $url => $ids) {
            if (count($ids) > 1) {
                $this->fail_('Subscriptions '.implode(', ', $ids)." all deliver to {$url}; each signs with its own secret, so the app can verify only one of them. Delete the extra ones.");
            }
        }

        if (self::isIntegrator($identity)) {
            foreach ($matching as $subscription) {
                if ($subscription->isInitialized('accountRelationship') && $subscription->getAccountRelationship() === 'own') {
                    $this->warn_("Webhook subscription {$subscription->getId()} only receives events from your own account (account_relationship own), not from the accounts you manage; subscribe with --account-relationship=all if the app needs them.");
                }
                $missingEvents = array_values(array_diff(BeelWebhookSubscriptions::provisionerEvents(), $subscription->getEvents()));
                if ($missingEvents !== []) {
                    $this->warn_("Webhook subscription {$subscription->getId()} does not receive the integrator events ".implode(', ', $missingEvents).'; subscribe with --provisioner-events if the app needs them.');
                }
            }
        }

        foreach ($matching as $subscription) {
            $subscription->getActive()
                ? $this->ok("Webhook subscription {$subscription->getId()} is active and points at {$subscription->getUrl()}.")
                : $this->fail_("Webhook subscription {$subscription->getId()} ({$subscription->getUrl()}) is inactive: BeeL delivers nothing to it until it is reactivated.");
        }
    }

    /** @return list<WebhookSubscription> */
    private function subscriptions(BeelManager $manager, string $apiKey, string $accountId): array
    {
        $account = $manager->account(apiKey: $apiKey, accountId: $accountId);

        return iterator_to_array($account->webhooks->all(['limit' => 100]), false);
    }

    private function checkWebhookSecret(WebhookSecretResolver $secrets): void
    {
        if (! $secrets instanceof ConfigWebhookSecretResolver) {
            $this->note('A custom WebhookSecretResolver is bound: not checking webhook secrets.');

            return;
        }

        $secret = config('services.beel.webhook_secret');
        is_string($secret) && trim($secret) !== ''
            ? $this->ok('A webhook secret is configured (services.beel.webhook_secret).')
            : $this->warn_('No webhook secret (services.beel.webhook_secret / BEEL_WEBHOOK_SECRET): every webhook delivery will answer 503.');
    }

    private function checkDedupeStore(): void
    {
        $seconds = Settings::optionalInt('beel.webhook_dedupe_seconds', 900);
        if ($seconds === null || $seconds <= 0) {
            $this->note('Webhook deduplication is disabled (beel.webhook_dedupe_seconds).');

            return;
        }

        $name = Settings::string('beel.webhook_dedupe_store', '');
        $name = $name !== '' ? $name : null;
        $label = $name ?? Settings::string('cache.default', 'default');

        try {
            $store = Cache::store($name)->getStore();
        } catch (\Throwable $exception) {
            $this->fail_("The webhook dedupe cache store '{$label}' cannot be used: {$exception->getMessage()}");

            return;
        }

        if ($store instanceof ArrayStore || $store instanceof NullStore) {
            $this->fail_("The webhook dedupe cache store '{$label}' is an array/null store: it is not atomic nor shared between processes, so webhook deduplication silently does nothing. Set beel.webhook_dedupe_store to redis, memcached, database or dynamodb.");
        } elseif ($store instanceof FileStore) {
            $this->warn_("The webhook dedupe cache store '{$label}' is a file store: it only protects a single server. Use redis, memcached, database or dynamodb if several servers receive webhooks.");
        } else {
            $this->ok("The webhook dedupe cache store '{$label}' supports atomic, shared claims.");
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function ok(string $message): void
    {
        $this->line("<info>[ok]</info> {$message}");
    }

    private function note(string $message): void
    {
        $this->line("[--] {$message}");
    }

    private function warn_(string $message): void
    {
        $this->line("<comment>[warn]</comment> {$message}");
    }

    private function fail_(string $message): void
    {
        $this->errors++;
        $this->line("<error>[error]</error> {$message}");
    }
}
