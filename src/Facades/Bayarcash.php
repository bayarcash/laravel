<?php

namespace Bayarcash\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Bayarcash\Bayarcash sdk()
 * @method static \Bayarcash\Bayarcash for(mixed $tenant = null)
 * @method static string secretKey(mixed $tenant = null)
 * @method static \Illuminate\Support\Collection<int, \Bayarcash\Resources\PortalResource> portals(mixed $tenant = null)
 * @method static bool hasPortal(string $portalKey, mixed $tenant = null)
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
