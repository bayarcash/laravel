<?php

namespace Bayarcash\Laravel\Tests\Unit;

use Bayarcash\Fpx;
use Bayarcash\Laravel\Events\PaymentCancelled;
use Bayarcash\Laravel\Events\PaymentFailed;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\PaymentRecorder;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class PaymentRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function recorder(): PaymentRecorder
    {
        return new PaymentRecorder();
    }

    public function test_completes_pending_row_matched_by_payment_intent_id(): void
    {
        $row = BayarcashTransaction::create([
            'order_number'      => 'INV-1',
            'payment_intent_id' => 'pi_1',
            'amount'            => '10.00',
            'status'            => Fpx::STATUS_NEW,
        ]);

        Event::fake([PaymentSucceeded::class]);

        $result = $this->recorder()->record([
            'payment_intent_id'  => 'pi_1',
            'transaction_id'     => 'trx_1',
            'order_number'       => 'INV-1',
            'amount'             => '10.00',
            'status'             => '3',
            'status_description' => 'Successful',
        ], 'callback');

        $this->assertSame($row->id, $result->id);
        $this->assertSame('trx_1', $result->transaction_id);
        $this->assertSame(Fpx::STATUS_SUCCESS, $result->status);
        $this->assertNotNull($result->paid_at);
        $this->assertNotNull($result->raw_callback);
        $this->assertSame(1, BayarcashTransaction::count());
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_falls_back_to_transaction_id_then_order_number(): void
    {
        $byTrx = BayarcashTransaction::create([
            'order_number' => 'INV-T', 'transaction_id' => 'trx_2', 'status' => Fpx::STATUS_PENDING,
        ]);
        $byOrder = BayarcashTransaction::create([
            'order_number' => 'INV-O', 'status' => Fpx::STATUS_PENDING,
        ]);

        $r1 = $this->recorder()->record(['transaction_id' => 'trx_2', 'order_number' => 'INV-T', 'status' => '3'], 'callback');
        $r2 = $this->recorder()->record(['order_number' => 'INV-O', 'status' => '3'], 'callback');

        $this->assertSame($byTrx->id, $r1->id);
        $this->assertSame($byOrder->id, $r2->id);
        $this->assertSame(2, BayarcashTransaction::count());
    }

    public function test_never_downgrades_a_successful_row(): void
    {
        BayarcashTransaction::create([
            'order_number' => 'INV-2', 'transaction_id' => 'trx_3',
            'status' => Fpx::STATUS_SUCCESS, 'amount' => '10.00',
        ]);

        Event::fake();

        $result = $this->recorder()->record([
            'transaction_id' => 'trx_3', 'order_number' => 'INV-2', 'status' => (string) Fpx::STATUS_PENDING,
        ], 'callback');

        $this->assertSame(Fpx::STATUS_SUCCESS, $result->status);
        Event::assertNotDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentCancelled::class);
    }

    public function test_raw_callback_is_stored_only_via_callback(): void
    {
        $viaCallback = $this->recorder()->record([
            'transaction_id' => 'trx_c', 'order_number' => 'INV-C', 'status' => '3',
        ], 'callback');
        $viaReturn = $this->recorder()->record([
            'transaction_id' => 'trx_r', 'order_number' => 'INV-R', 'status' => '3',
        ], 'return');
        $viaRequery = $this->recorder()->record([
            'transaction_id' => 'trx_q', 'order_number' => 'INV-Q', 'status' => '3',
        ], 'requery');

        $this->assertNotNull($viaCallback->fresh()->raw_callback);
        $this->assertNull($viaReturn->fresh()->raw_callback);
        $this->assertNull($viaRequery->fresh()->raw_callback);
    }

    public function test_stateless_builds_unsaved_model_and_still_fires_event(): void
    {
        config()->set('bayarcash.store_records', false);

        Event::fake([PaymentSucceeded::class]);

        $result = $this->recorder()->record([
            'transaction_id' => 'trx_s', 'order_number' => 'INV-S', 'status' => '3',
        ], 'callback');

        $this->assertFalse($result->exists);
        $this->assertSame(0, BayarcashTransaction::count());
        Event::assertDispatched(PaymentSucceeded::class);
    }
}
