<?php

namespace Lenorix\LaravelBeel\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Http;
use Lenorix\LaravelBeel\LaravelBeelServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Lenorix\\LaravelBeel\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Safety net: any HTTP call not explicitly faked by a test fails loudly instead of hitting the network.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelBeelServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
         foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
         }
         */
    }
}
