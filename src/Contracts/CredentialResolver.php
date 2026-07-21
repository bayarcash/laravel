<?php

namespace Bayarcash\Laravel\Contracts;

interface CredentialResolver
{
    /**
     * Resolve the Bayarcash credentials for the given tenant.
     *
     * @return array{token: string, secret_key: string, sandbox: bool}
     */
    public function resolve(mixed $tenant = null): array;
}
