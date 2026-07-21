<?php

namespace Bayarcash\Laravel\Tests\Unit;

use Bayarcash\Bayarcash;
use Bayarcash\Laravel\BayarcashManager;
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

    public function test_manager_proxies_unknown_methods_to_sdk(): void
    {
        $this->assertSame('v3', app('bayarcash.manager')->getApiVersion());
    }

    public function test_manager_is_resolvable_by_class(): void
    {
        $this->assertInstanceOf(BayarcashManager::class, app(BayarcashManager::class));
    }
}
