<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Fpx;
use Bayarcash\Laravel\Events\PaymentCancelled;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\Fixtures\FakeManager;
use Bayarcash\Laravel\Tests\TestCase;
use Bayarcash\Resources\PaymentIntentResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class ReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requery_updates_pending_row_and_fires_event(): void
    {
        $stub = new class {
            public function getPaymentIntent($id): PaymentIntentResource
            {
                return new PaymentIntentResource([
                    'id'     => $id,
                    'status' => 'paid',
                    'attempts' => [[
                        'transaction_id'     => 'trx_rq',
                        'status'             => 3,
                        'status_description' => 'Successful',
                        'amount'             => '10.00',
                        'payer_bank_name'    => 'Bank',
                    ]],
                ]);
            }

            public function cancelPaymentIntent($id)
            {
                return null;
            }
        };

        $this->app->instance('bayarcash.manager', new FakeManager($stub));

        Event::fake([PaymentSucceeded::class]);

        BayarcashTransaction::create([
            'order_number' => 'INV-RQ', 'payment_intent_id' => 'pi_rq',
            'amount' => '10.00', 'status' => Fpx::STATUS_PENDING,
            'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10),
        ]);

        $this->artisan('bayarcash:reconcile')->assertSuccessful();

        $row = BayarcashTransaction::where('payment_intent_id', 'pi_rq')->first();
        $this->assertSame(Fpx::STATUS_SUCCESS, $row->status);
        $this->assertSame('trx_rq', $row->transaction_id);
        $this->assertNotNull($row->paid_at);
        $this->assertNull($row->raw_callback);

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_auto_cancels_row_past_cancel_window(): void
    {
        $stub = new class {
            public bool $cancelled = false;

            public function getPaymentIntent($id): PaymentIntentResource
            {
                return new PaymentIntentResource(['id' => $id, 'status' => 'unpaid', 'attempts' => []]);
            }

            public function cancelPaymentIntent($id)
            {
                $this->cancelled = true;

                return null;
            }
        };

        $this->app->instance('bayarcash.manager', new FakeManager($stub));

        Event::fake([PaymentCancelled::class]);

        BayarcashTransaction::create([
            'order_number' => 'INV-OLD', 'payment_intent_id' => 'pi_old',
            'amount' => '10.00', 'status' => Fpx::STATUS_PENDING,
            'created_at' => now()->subMinutes(90), 'updated_at' => now()->subMinutes(90),
        ]);

        $this->artisan('bayarcash:reconcile')->assertSuccessful();

        $row = BayarcashTransaction::where('payment_intent_id', 'pi_old')->first();
        $this->assertSame(Fpx::STATUS_CANCELLED, $row->status);
        $this->assertTrue($stub->cancelled);

        Event::assertDispatched(PaymentCancelled::class);
    }

    public function test_recent_pending_rows_are_left_untouched(): void
    {
        $stub = new class {
            public function getPaymentIntent($id): PaymentIntentResource
            {
                throw new \RuntimeException('should not be queried');
            }

            public function cancelPaymentIntent($id)
            {
                return null;
            }
        };

        $this->app->instance('bayarcash.manager', new FakeManager($stub));

        BayarcashTransaction::create([
            'order_number' => 'INV-NEW', 'payment_intent_id' => 'pi_new',
            'amount' => '10.00', 'status' => Fpx::STATUS_PENDING,
        ]);

        $this->artisan('bayarcash:reconcile')->assertSuccessful();

        $this->assertSame(Fpx::STATUS_PENDING, BayarcashTransaction::where('payment_intent_id', 'pi_new')->first()->status);
    }

    public function test_command_requires_persistence(): void
    {
        config()->set('bayarcash.persistence', false);

        $this->artisan('bayarcash:reconcile')
            ->expectsOutputToContain('requires persistence')
            ->assertSuccessful();
    }
}
