<?php

namespace Lenorix\LaravelBeel\Facades;

use Illuminate\Support\Facades\Facade;
use Lenorix\BeelSdk\Beel;
use Lenorix\LaravelBeel\BeelAccount;
use Lenorix\LaravelBeel\BeelCompany;
use Lenorix\LaravelBeel\BeelManager;

/**
 * @method static Beel client(?string $apiKey = null)
 * @method static BeelCompany company(?string $apiKey = null, ?string $companyId = null)
 * @method static BeelAccount account(?string $apiKey = null, ?string $accountId = null)
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
