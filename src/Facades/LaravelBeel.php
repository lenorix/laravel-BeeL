<?php

namespace Lenorix\LaravelBeel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Lenorix\LaravelBeel\BeelManager
 */
class LaravelBeel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Lenorix\LaravelBeel\BeelManager::class;
    }
}
