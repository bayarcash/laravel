<?php

namespace Bayarcash\Laravel\Tests\Unit;

use Bayarcash\Laravel\Facades\Bayarcash;
use Bayarcash\Laravel\Tests\TestCase;

class FacadeTest extends TestCase
{
    public function test_facade_proxies_to_manager_and_sdk(): void
    {
        $this->assertSame('v3', Bayarcash::getApiVersion());
        $this->assertInstanceOf(\Bayarcash\Bayarcash::class, Bayarcash::sdk());
        $this->assertSame('test-secret', Bayarcash::secretKey());
    }
}
