<?php

namespace Bayarcash\Laravel;

use Bayarcash\Bayarcash;
use Bayarcash\Laravel\Contracts\CredentialResolver;

/**
 * Builds and caches configured Bayarcash SDK instances.
 *
 * This is the single place credentials are read, which is exactly the seam
 * multi-tenancy needs. The SDK is always pinned to Bayarcash API v3.
 *
 * @mixin \Bayarcash\Bayarcash
 */
class BayarcashManager
{
    /**
     * The Bayarcash API version this package targets.
     */
    public const API_VERSION = 'v3';

    /**
     * Cached SDK instances keyed by credential fingerprint.
     *
     * @var array<string, \Bayarcash\Bayarcash>
     */
    protected array $instances = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected ?CredentialResolver $resolver = null,
    ) {
    }

    /**
     * Get the default (config-credential) SDK instance.
     */
    public function sdk(): Bayarcash
    {
        return $this->for(null);
    }

    /**
     * Get an SDK instance scoped to the given tenant.
     */
    public function for(mixed $tenant = null): Bayarcash
    {
        $creds = $this->credentials($tenant);
        $key = ($creds['token'] ?? '') . '|' . (($creds['sandbox'] ?? false) ? '1' : '0');

        return $this->instances[$key] ??= $this->build($creds);
    }

    /**
     * Get the API secret key for the given tenant.
     */
    public function secretKey(mixed $tenant = null): string
    {
        return (string) ($this->credentials($tenant)['secret_key'] ?? '');
    }

    /**
     * Resolve the credential set for the given tenant.
     *
     * @return array{token: string, secret_key: string, sandbox: bool}
     */
    protected function credentials(mixed $tenant): array
    {
        if ($this->resolver && $tenant !== null) {
            return $this->resolver->resolve($tenant);
        }

        return [
            'token'      => (string) ($this->config['token'] ?? ''),
            'secret_key' => (string) ($this->config['secret_key'] ?? ''),
            'sandbox'    => (bool) ($this->config['sandbox'] ?? false),
        ];
    }

    /**
     * Build a fresh SDK instance from a credential set (always API v3).
     *
     * @param  array{token: string, secret_key: string, sandbox: bool}  $creds
     */
    protected function build(array $creds): Bayarcash
    {
        $sdk = new Bayarcash($creds['token']);
        $sdk->setTimeout((int) ($this->config['timeout'] ?? 30));
        $sdk->setApiVersion(self::API_VERSION);

        if (! empty($creds['sandbox'])) {
            $sdk->useSandbox();
        }

        return $sdk;
    }

    /**
     * Proxy unknown method calls to the default SDK instance.
     *
     * @param  array<int, mixed>  $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->sdk()->{$method}(...$args);
    }
}
