<?php

namespace Bayarcash\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Bayarcash\Bayarcash sdk()
 * @method static \Bayarcash\Bayarcash for(mixed $tenant = null)
 * @method static string secretKey(mixed $tenant = null)
 *
 * @see \Bayarcash\Laravel\BayarcashManager
 */
class Bayarcash extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'bayarcash.manager';
    }
}
