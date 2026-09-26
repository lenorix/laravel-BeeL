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
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\BeelSdk\Generated\Model\ErrorResponse;
use Lenorix\BeelSdk\Generated\Model\MyIdentity;
use Lenorix\BeelSdk\Generated\Model\WebhookSubscription;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\ConfigWebhookSecretResolver;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;

/**
 * Read-only diagnosis of the BeeL setup: it only sends GET requests and never changes anything in
 * BeeL (no test deliveries either). Errors exit 1; warnings are reported but exit 0.
 */
final class CheckCommand extends Command
{
    protected $signature = 'beel:check';

    protected $description = 'Check the BeeL configuration, API key, company and webhook setup (read-only)';

    private int $errors = 0;

    public function handle(BeelManager $manager, Container $container, WebhookSecretResolver $secrets): int
    {
        $credentials = $container->make(CredentialsResolver::class);
        $apiKey = $credentials->apiKey();

        if ($apiKey === null) {
            $this->fail_('No BeeL API key: set services.beel.key (BEEL_API_KEY) or bind a CredentialsResolver that returns one.');

            return self::FAILURE;
        }

        $this->checkKeyEnvironment($apiKey);

        $beel = $manager->client();
        $identity = $this->identity($beel);

        if ($identity !== null) {
            $this->checkAccount($identity, $credentials->accountId());
            $this->checkCompany($manager, $credentials->companyId());
            $this->checkWebhooks($manager, $identity);
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
            $response = $beel->raw->getMyIdentity();
        } catch (\Throwable $exception) {
            $this->fail_('BeeL rejected the API key or could not be reached: '.BeelApiError::fromGenerated($exception)->getMessage());

            return null;
        }

        if ($response instanceof ErrorResponse || $response === null) {
            $this->fail_('BeeL rejected the API key.');

            return null;
        }

        $identity = $response->getData();
        $credential = $identity->getCredential();
        $this->ok("API key works: account {$identity->getAccountId()}, environment {$credential->getEnvironment()}.");

        return $identity;
    }

    private function checkAccount(MyIdentity $identity, ?string $accountId): void
    {
        if ($accountId !== null && $accountId !== $identity->getAccountId()) {
            $this->fail_("services.beel.account_id is {$accountId}, but the API key belongs to account {$identity->getAccountId()}.");
        }
    }

    private function checkCompany(BeelManager $manager, ?string $companyId): void
    {
        if ($companyId === null) {
            $this->note('No default company id (services.beel.company_id): skipping the issuing readiness check.');

            return;
        }

        try {
            $readiness = $manager->company(companyId: $companyId)->issuingReadiness();
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

    private function checkWebhooks(BeelManager $manager, MyIdentity $identity): void
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

        $expected = rtrim((string) config('app.url'), '/').'/'.trim((string) config('beel.webhook_path', 'beel/webhook'), '/');

        if (! str_starts_with($expected, 'https://')) {
            $this->warn_("The webhook URL {$expected} (from APP_URL) is not HTTPS; BeeL only delivers to HTTPS endpoints.");
        }

        if (in_array('webhooks:read', $missing, true)) {
            return;
        }

        try {
            $subscriptions = $this->subscriptions($manager, $identity->getAccountId());
        } catch (\Throwable $exception) {
            $this->fail_("Could not list the webhook subscriptions: {$exception->getMessage()}");

            return;
        }

        $matching = array_filter($subscriptions, fn (WebhookSubscription $s) => $s->getUrl() === $expected || str_starts_with($s->getUrl(), $expected.'/'));

        if ($matching === []) {
            $this->warn_("No BeeL webhook subscription points at {$expected} (per-tenant URLs under it also count). Check APP_URL if the app is served elsewhere.");

            return;
        }

        foreach ($matching as $subscription) {
            $subscription->getActive()
                ? $this->ok("Webhook subscription {$subscription->getId()} is active and points at {$subscription->getUrl()}.")
                : $this->fail_("Webhook subscription {$subscription->getId()} ({$subscription->getUrl()}) is inactive: BeeL delivers nothing to it until it is reactivated.");
        }
    }

    /** @return list<WebhookSubscription> */
    private function subscriptions(BeelManager $manager, string $accountId): array
    {
        $account = $manager->account(accountId: $accountId);
        $items = [];
        $page = 1;

        do {
            $result = $account->webhooks->list(['page' => $page++, 'limit' => 100]);
            array_push($items, ...$result->getWebhooks());
        } while ($result->getPagination()->getHasNext());

        return $items;
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
        $seconds = config('beel.webhook_dedupe_seconds', 900);
        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            $this->note('Webhook deduplication is disabled (beel.webhook_dedupe_seconds).');

            return;
        }

        $name = config('beel.webhook_dedupe_store');
        $name = is_string($name) && $name !== '' ? $name : null;
        $label = $name ?? (string) config('cache.default');

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
