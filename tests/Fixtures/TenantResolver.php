<?php

namespace Bayarcash\Laravel\Tests\Fixtures;

use Bayarcash\Laravel\Contracts\CredentialResolver;

/**
 * Test resolver mapping tenant ids to a fixed credential set. Stands in for a
 * real resolver that would read per-tenant credentials from the database.
 */
class TenantResolver implements CredentialResolver
{
    /**
     * @var array<string, array{token: string, secret_key: string, sandbox: bool}>
     */
    public const CREDENTIALS = [
        't1' => ['token' => 'tok-t1', 'secret_key' => 'secret-t1', 'sandbox' => true],
        't2' => ['token' => 'tok-t2', 'secret_key' => 'secret-t2', 'sandbox' => true],
    ];

    /**
     * @return array{token: string, secret_key: string, sandbox: bool}
     */
    public function resolve(mixed $tenant = null): array
    {
        return self::CREDENTIALS[(string) $tenant]
            ?? ['token' => '', 'secret_key' => '', 'sandbox' => true];
    }
}
