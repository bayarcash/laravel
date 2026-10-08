<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Fpx;
use Bayarcash\Laravel\Events\PaymentCancelled;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\Fixtures\HttpFakeManager;
use Bayarcash\Laravel\Tests\Fixtures\TenantResolver;
use Bayarcash\Laravel\Tests\TestCase;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class MultiTenantReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_TOKENS = [
        'pi_t1_paid'  => 'Bearer tok-t1',
        'pi_t1_stale' => 'Bearer tok-t1',
        'pi_t2_paid'  => 'Bearer tok-t2',
        'pi_t2_stale' => 'Bearer tok-t2',
        'pi_default'  => 'Bearer test-token',
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('bayarcash.multi_tenant', true);
    }

    private function resolver(array $failing = []): TenantResolver
    {
        return new class ($failing) extends TenantResolver {
            public array $calls = [];

            public function __construct(private array $failing)
            {
            }

            public function resolve(mixed $tenant = null): array
            {
                $this->calls[] = $tenant;

                if (in_array($tenant, $this->failing, true)) {
                    throw new RuntimeException("No Bayarcash account for {$tenant}.");
                }

                return parent::resolve($tenant);
            }
        };
    }

    private function gateway(): \Closure
    {
        return function (RequestInterface $request) {
            $id = basename($request->getUri()->getPath());

            if ((self::OWNER_TOKENS[$id] ?? null) !== $request->getHeaderLine('Authorization')) {
                return new Response(401, [], json_encode(['message' => 'Unauthenticated.']));
            }

            if ($request->getMethod() === 'DELETE') {
                return new Response(200, [], json_encode(['id' => $id, 'status' => 'cancelled']));
            }

            $attempts = str_ends_with($id, '_paid') || $id === 'pi_default'
                ? [['transaction_id' => 'trx_' . $id, 'status' => Fpx::STATUS_SUCCESS, 'amount' => '10.00', 'status_description' => 'Approved']]
                : [];

            return new Response(200, [], json_encode(['id' => $id, 'status' => $attempts ? 'paid' : 'unpaid', 'attempts' => $attempts]));
        };
    }

    private function pending(?string $tenant, string $intent, int $minutesAgo): BayarcashTransaction
    {
        return BayarcashTransaction::create([
            'tenant_id' => $tenant, 'order_number' => 'INV-' . $intent, 'payment_intent_id' => $intent,
            'amount' => '10.00', 'status' => Fpx::STATUS_PENDING,
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function statusOf(string $intent): int
    {
        return BayarcashTransaction::where('payment_intent_id', $intent)->value('status');
    }

    public function test_each_row_is_requeried_with_its_own_tenant_credentials(): void
    {
        $resolver = $this->resolver();
        $fake = HttpFakeManager::install($this->gateway(), $resolver);

        Event::fake([PaymentSucceeded::class, PaymentCancelled::class]);

        $this->pending('t1', 'pi_t1_paid', 10);
        $this->pending('t2', 'pi_t2_paid', 10);
        $this->pending('t1', 'pi_t1_stale', 90);
        $this->pending('t2', 'pi_t2_stale', 90);

        $this->artisan('bayarcash:reconcile')
            ->expectsOutputToContain('2 re-queried, 2 auto-cancelled')
            ->assertSuccessful();

        foreach ($fake->requests as $request) {
            $intent = basename($request->getUri()->getPath());
            $this->assertSame(self::OWNER_TOKENS[$intent], $request->getHeaderLine('Authorization'), "{$intent} used the wrong token");
        }

        $this->assertContains('Bearer tok-t1', $fake->tokens());
        $this->assertContains('Bearer tok-t2', $fake->tokens());
        $this->assertNotContains('Bearer test-token', $fake->tokens());

        $this->assertSame(Fpx::STATUS_SUCCESS, $this->statusOf('pi_t1_paid'));
        $this->assertSame(Fpx::STATUS_SUCCESS, $this->statusOf('pi_t2_paid'));
        $this->assertSame('t1', BayarcashTransaction::where('payment_intent_id', 'pi_t1_paid')->value('tenant_id'));

        Event::assertDispatchedTimes(PaymentSucceeded::class, 2);
    }

    public function test_stale_intents_are_cancelled_per_tenant(): void
    {
        $fake = HttpFakeManager::install($this->gateway(), $this->resolver());

        Event::fake([PaymentCancelled::class]);

        $this->pending('t1', 'pi_t1_stale', 90);
        $this->pending('t2', 'pi_t2_stale', 90);

        $this->artisan('bayarcash:reconcile')->assertSuccessful();

        $this->assertSame(Fpx::STATUS_CANCELLED, $this->statusOf('pi_t1_stale'));
        $this->assertSame(Fpx::STATUS_CANCELLED, $this->statusOf('pi_t2_stale'));

        $cancels = array_values(array_filter($fake->requests, fn (RequestInterface $r) => $r->getMethod() === 'DELETE'));
        $this->assertCount(2, $cancels);
        $this->assertEqualsCanonicalizing(
            ['Bearer tok-t1', 'Bearer tok-t2'],
            array_map(fn (RequestInterface $r) => $r->getHeaderLine('Authorization'), $cancels),
        );

        Event::assertDispatchedTimes(PaymentCancelled::class, 2);
        Event::assertDispatched(PaymentCancelled::class, fn (PaymentCancelled $e) => $e->transaction->tenant_id === 't1');
        Event::assertDispatched(PaymentCancelled::class, fn (PaymentCancelled $e) => $e->transaction->tenant_id === 't2');
    }

    public function test_credentials_are_resolved_once_per_tenant_not_per_row(): void
    {
        $resolver = $this->resolver();
        HttpFakeManager::install($this->gateway(), $resolver);

        Event::fake();

        $this->pending('t1', 'pi_t1_paid', 10);
        $this->pending('t2', 'pi_t2_paid', 10);
        $this->pending('t1', 'pi_t1_stale', 90);
        $this->pending('t2', 'pi_t2_stale', 90);

        $this->artisan('bayarcash:reconcile')->assertSuccessful();

        $this->assertSame(['t1', 't2'], collect($resolver->calls)->sort()->values()->all());
    }

    public function test_rows_without_a_tenant_still_use_the_default_credentials(): void
    {
        $fake = HttpFakeManager::install($this->gateway(), $this->resolver());

        Event::fake();

        $this->pending(null, 'pi_default', 10);
        $this->pending('t1', 'pi_t1_paid', 10);

        $this->artisan('bayarcash:reconcile')->assertSuccessful();

        $this->assertSame(Fpx::STATUS_SUCCESS, $this->statusOf('pi_default'));
        $this->assertSame(Fpx::STATUS_SUCCESS, $this->statusOf('pi_t1_paid'));
        $this->assertContains('Bearer test-token', $fake->tokens());
    }

    public function test_a_tenant_whose_credentials_fail_does_not_block_the_others(): void
    {
        HttpFakeManager::install($this->gateway(), $this->resolver(failing: ['t1']));

        Event::fake();

        $this->pending('t1', 'pi_t1_paid', 10);
        $this->pending('t2', 'pi_t2_paid', 10);

        $this->artisan('bayarcash:reconcile')->assertSuccessful();

        $this->assertSame(Fpx::STATUS_PENDING, $this->statusOf('pi_t1_paid'));
        $this->assertSame(Fpx::STATUS_SUCCESS, $this->statusOf('pi_t2_paid'));
    }
}
