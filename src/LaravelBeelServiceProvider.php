<?php

namespace Lenorix\LaravelBeel;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelBeelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-beel')
            ->hasConfigFile()
            ->hasRoutes('beel');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(BeelManager::class);
        $this->app->singleton(BeelHttpClientFactory::class);
        $this->app->bind(WebhookSecretResolver::class, ConfigWebhookSecretResolver::class);
    }

    public function packageBooted(): void
    {
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
