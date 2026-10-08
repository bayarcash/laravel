<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Laravel\Tests\Fixtures\FakeManager;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

class ReconcileFromWebTest extends TestCase
{
    use RefreshDatabase;

    // Must be set before the app boots; it is cached.
    protected function setUp(): void
    {
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['APP_RUNNING_IN_CONSOLE']);
    }

    public function test_reconcile_can_be_called_through_artisan_from_a_web_request(): void
    {
        $this->assertFalse($this->app->runningInConsole());

        // Laravel 10 leaves a mocked console output bound.
        $this->withoutMockingConsoleOutput();

        $this->app->instance('bayarcash.manager', new FakeManager(new class {
        }));

        Route::get('admin/reconcile', function () {
            Artisan::call('bayarcash:reconcile');

            return trim(Artisan::output());
        });

        $this->get('admin/reconcile')
            ->assertOk()
            ->assertSee('Bayarcash reconcile: 0 re-queried, 0 auto-cancelled.');
    }
}
