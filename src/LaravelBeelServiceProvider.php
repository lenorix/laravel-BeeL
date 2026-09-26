<?php

namespace Lenorix\LaravelBeel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Lenorix\LaravelBeel\Commands\CheckCommand;
use Lenorix\LaravelBeel\Commands\RetryWebhookDeliveriesCommand;
use Lenorix\LaravelBeel\Commands\WebhookSubscribeCommand;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;
use Lenorix\LaravelBeel\Contracts\WebhookRetryAccounts;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelBeelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-beel')
            ->hasConfigFile()
            ->hasRoutes('beel')
            ->hasCommand(RetryWebhookDeliveriesCommand::class)
            ->hasCommand(CheckCommand::class)
            ->hasCommand(WebhookSubscribeCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(BeelManager::class);
        $this->app->singleton(BeelHttpClientFactory::class);
        $this->app->bind(CredentialsResolver::class, ConfigCredentialsResolver::class);
        $this->app->bind(WebhookSecretResolver::class, ConfigWebhookSecretResolver::class);
        $this->app->bind(WebhookRetryAccounts::class, CredentialsWebhookRetryAccounts::class);
    }

    public function packageBooted(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $cron = config('beel.webhook_delivery_retry.schedule');

            if (is_string($cron) && $cron !== '') {
                $event = $schedule->command(RetryWebhookDeliveriesCommand::class)->cron($cron)->withoutOverlapping(60); // a hard-killed run releases the lock after an hour, not the default day

                if (config('beel.webhook_delivery_retry.on_one_server', false)) {
                    $event->onOneServer();
                }
            }
        });

        if (! config('beel.register_webhook_route', true)) {
            return;
        }

        // These global middleware json_decode and walk the whole body before routing. The webhook
        // only ever reads the raw body after verifying its signature, so skip them for that path.
        $path = trim((string) config('beel.webhook_path', 'beel/webhook'), '/');
        $isWebhook = fn (Request $request): bool => $request->is($path, $path.'/*');

        TrimStrings::skipWhen($isWebhook);
        ConvertEmptyStringsToNull::skipWhen($isWebhook);
    }
}
