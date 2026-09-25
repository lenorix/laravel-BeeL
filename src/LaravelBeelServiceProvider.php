<?php

namespace Lenorix\LaravelBeel;

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
}
