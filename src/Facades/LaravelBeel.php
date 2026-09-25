<?php

namespace Lenorix\LaravelBeel\Facades;

use Illuminate\Support\Facades\Facade;
use Lenorix\LaravelBeel\BeelManager;

/**
 * @see BeelManager
 */
class LaravelBeel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BeelManager::class;
    }
}
