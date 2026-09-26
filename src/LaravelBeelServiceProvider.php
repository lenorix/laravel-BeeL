<?php

namespace Lenorix\LaravelBeel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('beel-webhook', function (Request $request) {
            $maxAttempts = config('beel.webhook_rate_limit.max_attempts_per_minute', 300);

            return Limit::perMinute((int) $maxAttempts)->by($request->ip())->response(
                fn () => response()->json(['message' => 'Too many BeeL webhook requests.'], 429)
            );
        });
    }
}
