<?php

namespace Bayarcash\Laravel\Tests\Fixtures;

use Bayarcash\Bayarcash;
use Bayarcash\Laravel\BayarcashManager;
use Bayarcash\Laravel\Contracts\CredentialResolver;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

// The SDK uses Guzzle directly, so Http::fake() misses it.
class HttpFakeManager extends BayarcashManager
{
    public array $requests = [];

    public function __construct(array $config, ?CredentialResolver $resolver, protected Closure $responder)
    {
        parent::__construct($config, $resolver);
    }

    public static function install(Closure $responder, ?CredentialResolver $resolver = null): self
    {
        $manager = new self((array) config('bayarcash'), $resolver, $responder);
        app()->instance('bayarcash.manager', $manager);

        return $manager;
    }

    public function tokens(): array
    {
        return array_map(fn (RequestInterface $request) => $request->getHeaderLine('Authorization'), $this->requests);
    }

    protected function build(array $creds): Bayarcash
    {
        $sdk = parent::build($creds);

        $handler = function (RequestInterface $request) {
            $this->requests[] = $request;

            return Create::promiseFor(($this->responder)($request));
        };

        $sdk->guzzle = new Client([
            'base_uri'    => (string) $sdk->guzzle->getConfig('base_uri'),
            'http_errors' => false,
            'headers'     => $sdk->guzzle->getConfig('headers'),
            'handler'     => HandlerStack::create($handler),
        ]);

        return $sdk;
    }
}
