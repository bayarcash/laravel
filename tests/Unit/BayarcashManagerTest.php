<?php

namespace Bayarcash\Laravel\Tests\Unit;

use Bayarcash\Bayarcash;
use Bayarcash\Laravel\BayarcashManager;
use Bayarcash\Laravel\Tests\Fixtures\TenantResolver;
use Bayarcash\Laravel\Tests\TestCase;

class BayarcashManagerTest extends TestCase
{
    public function test_sdk_returns_configured_v3_instance(): void
    {
        $manager = app('bayarcash.manager');

        $this->assertInstanceOf(BayarcashManager::class, $manager);
        $this->assertInstanceOf(Bayarcash::class, $manager->sdk());
        $this->assertSame('v3', $manager->sdk()->getApiVersion());
        $this->assertSame('test-secret', $manager->secretKey());
    }

    public function test_null_tenant_returns_default_config_credentials(): void
    {
        $manager = new BayarcashManager(
            ['token' => 'cfg-token', 'secret_key' => 'cfg-secret', 'sandbox' => true],
            new TenantResolver(),
        );

        // for(null)/secretKey(null) must ignore the resolver and use .env config.
        $this->assertSame('cfg-secret', $manager->secretKey());
        $this->assertSame('cfg-secret', $manager->secretKey(null));
        $this->assertInstanceOf(Bayarcash::class, $manager->for(null));
    }

    public function test_tenant_credentials_go_through_the_resolver(): void
    {
        $manager = new BayarcashManager(
            ['token' => 'cfg-token', 'secret_key' => 'cfg-secret', 'sandbox' => true],
            new TenantResolver(),
        );

        $this->assertSame('secret-t1', $manager->secretKey('t1'));
        $this->assertSame('secret-t2', $manager->secretKey('t2'));
        $this->assertInstanceOf(Bayarcash::class, $manager->for('t1'));
    }

    public function test_tenant_without_resolver_falls_back_to_config(): void
    {
        $manager = new BayarcashManager(
            ['token' => 'cfg-token', 'secret_key' => 'cfg-secret', 'sandbox' => true],
        );

        $this->assertSame('cfg-secret', $manager->secretKey('t1'));
    }

    public function test_manager_proxies_unknown_methods_to_sdk(): void
    {
        $this->assertSame('v3', app('bayarcash.manager')->getApiVersion());
    }

    public function test_manager_is_resolvable_by_class(): void
    {
        $this->assertInstanceOf(BayarcashManager::class, app(BayarcashManager::class));
    }
}
