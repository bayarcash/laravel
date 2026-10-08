<?php

namespace Bayarcash\Laravel;

use Bayarcash\Bayarcash;
use Bayarcash\Laravel\Contracts\CredentialResolver;
use Bayarcash\Resources\PortalResource;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

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

    public function sdk(): Bayarcash
    {
        return $this->for(null);
    }

    public function for(mixed $tenant = null): Bayarcash
    {
        $creds = $this->credentials($tenant);
        $key = ($creds['token'] ?? '') . '|' . (($creds['sandbox'] ?? false) ? '1' : '0');

        return $this->instances[$key] ??= $this->build($creds);
    }

    public function secretKey(mixed $tenant = null): string
    {
        return (string) ($this->credentials($tenant)['secret_key'] ?? '');
    }

    public function portals(mixed $tenant = null): Collection
    {
        return $this->lazyPortals($tenant)->collect();
    }

    public function hasPortal(string $portalKey, mixed $tenant = null): bool
    {
        return $this->lazyPortals($tenant)->contains(fn (PortalResource $portal) => $portal->portalKey === $portalKey);
    }

    // getPortals() stops at page 1, so walk every page.
    protected function lazyPortals(mixed $tenant): LazyCollection
    {
        return LazyCollection::make(function () use ($tenant) {
            $sdk = $this->for($tenant);
            $page = 1;

            do {
                $response = $sdk->get('portals?page=' . $page);
                $items = is_array($response) ? ($response['data'] ?? $response) : [];

                foreach ($items as $item) {
                    yield new PortalResource($item, $sdk);
                }

                $lastPage = (int) ($response['meta']['last_page'] ?? $page);
            } while ($items !== [] && $page++ < $lastPage);
        });
    }

    /**
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
     * @param  array<int, mixed>  $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->sdk()->{$method}(...$args);
    }
}
