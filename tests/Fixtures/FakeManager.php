<?php

namespace Bayarcash\Laravel\Tests\Fixtures;

/**
 * A drop-in replacement for BayarcashManager that returns a caller-supplied
 * stub SDK, so tests never make real HTTP requests. Checksums/verifiers on the
 * real SDK are pure and can still be used directly where needed.
 */
class FakeManager
{
    public function __construct(
        public object $stub,
        public string $secret = 'test-secret',
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
        return $this->secret;
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->stub->{$method}(...$args);
    }
}
