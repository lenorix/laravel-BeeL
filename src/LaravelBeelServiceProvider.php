<?php

namespace Lenorix\LaravelBeel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Lenorix\LaravelBeel\Commands\RetryWebhookDeliveriesCommand;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;
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
            ->hasCommand(RetryWebhookDeliveriesCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(BeelManager::class);
        $this->app->singleton(BeelHttpClientFactory::class);
        $this->app->bind(CredentialsResolver::class, ConfigCredentialsResolver::class);
        $this->app->bind(WebhookSecretResolver::class, ConfigWebhookSecretResolver::class);
    }

    public function packageBooted(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $cron = config('beel.webhook_delivery_retry.schedule');

            if (is_string($cron) && $cron !== '') {
                $schedule->command(RetryWebhookDeliveriesCommand::class)->cron($cron)->withoutOverlapping();
            }
        });

        if (! config('beel.register_webhook_route', true)) {
            return;
        }

        // These global middleware json_decode and walk the whole body before routing. The webhook
        // only ever reads the raw body after verifying its signature, so skip them for that path.
        $path = trim((string) config('beel.webhook_path', 'beel/webhook'), '/');
        $isWebhook = fn (Request $request): bool => $request->is($path);

        TrimStrings::skipWhen($isWebhook);
        ConvertEmptyStringsToNull::skipWhen($isWebhook);
    }
}
