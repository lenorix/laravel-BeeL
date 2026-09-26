<?php

namespace Lenorix\LaravelBeel\Facades;

use Illuminate\Support\Facades\Facade;
use Lenorix\BeelSdk\Beel;
use Lenorix\LaravelBeel\BeelCompany;
use Lenorix\LaravelBeel\BeelManager;

/**
 * @method static Beel client(?string $apiKey = null)
 * @method static BeelCompany company(?string $apiKey = null, ?string $companyId = null)
 *
 * @see BeelManager
 */
class LaravelBeel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BeelManager::class;
    }
}
