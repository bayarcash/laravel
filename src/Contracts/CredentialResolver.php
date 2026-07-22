<?php

namespace Bayarcash\Laravel\Contracts;

interface CredentialResolver
{
    /**
     * @return array{token: string, secret_key: string, sandbox: bool}
     */
    public function resolve(mixed $tenant = null): array;
}
