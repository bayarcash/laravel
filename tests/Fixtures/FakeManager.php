<?php

namespace Bayarcash\Laravel\Tests\Fixtures;

/**
 * A drop-in replacement for BayarcashManager that returns a caller-supplied
 * stub SDK, so tests never make real HTTP requests. Checksums/verifiers on the
 * real SDK are pure and can still be used directly where needed.
 */
class FakeManager
{
    /**
     * @param  object  $stub  Stub SDK returned by sdk()/for().
     * @param  string  $secret  Default (no-tenant) secret.
     * @param  array<string, string>  $tenantSecrets  Per-tenant secret overrides.
     */
    public function __construct(
        public object $stub,
        public string $secret = 'test-secret',
        public array $tenantSecrets = [],
    ) {
    }

    public function sdk(): object
    {
        return $this->stub;
    }

    public function for(mixed $tenant = null): object
    {
        return $this->stub;
    }

    public function secretKey(mixed $tenant = null): string
    {
        if ($tenant !== null && array_key_exists((string) $tenant, $this->tenantSecrets)) {
            return $this->tenantSecrets[(string) $tenant];
        }

        return $this->secret;
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->stub->{$method}(...$args);
    }
}
