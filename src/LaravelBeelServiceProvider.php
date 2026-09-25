<?php

namespace Lenorix\LaravelBeel;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Lenorix\LaravelBeel\Commands\LaravelBeelCommand;

class LaravelBeelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-beel')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_beel_table')
            ->hasCommand(LaravelBeelCommand::class);
    }
}
