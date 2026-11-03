<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Bayarcash;
use Bayarcash\Fpx;
use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\Events\PaymentCancelled;
use Bayarcash\Laravel\Events\PaymentFailed;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\Fixtures\Buyer;
use Bayarcash\Laravel\Tests\Fixtures\TenantResolver;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class DeductionCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('buyers', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }

    private function signedDeduction(
        int $status = 3,
        string $transactionId = 'trx_dd_1',
        string $secret = 'test-secret',
        bool $withGatewayId = true,
    ): array {
        $fields = [
            'record_type' => 'transaction', 'batch_number' => 'HLB1',
            'mandate_id' => 'md_1', 'mandate_reference_number' => 'SUB-1',
            'transaction_id' => $transactionId, 'datetime' => '2026-11-01 00:00:00',
            'reference_number' => 'REF-' . $transactionId, 'amount' => '10.00',
            'status' => (string) $status, 'status_description' => $status === 3 ? 'Successful' : 'Failed',
            'cycle' => '2',
        ];

        if ($withGatewayId) {
            $fields['payment_gateway_id'] = '3';
        }

        $fields['checksum'] = (new Bayarcash('t'))->createChecksumValue($secret, $fields);

        return $fields;
    }

    private function seedMandate(?string $tenant = null): BayarcashMandate
    {
        $buyer = Buyer::create();

        return $buyer->mandates()->create([
            'tenant_id'    => $tenant,
            'mandate_id'   => 'md_1',
            'order_number' => 'SUB-1',
            'amount'       => '10.00',
            'currency'     => 'MYR',
            'payer_name'   => 'Ahmad',
            'payer_email'  => 'ahmad@example.com',
            'status'       => FpxDirectDebit::STATUS_ACTIVE,
        ]);
    }

    public function test_successful_deduction_is_recorded_as_a_direct_debit_payment(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $mandate = $this->seedMandate();

        $this->postJson('bayarcash/callback', $this->signedDeduction())->assertOk();

        $payment = BayarcashTransaction::sole();
        $this->assertSame('trx_dd_1', $payment->transaction_id);
        $this->assertSame(Bayarcash::FPX_DIRECT_DEBIT, $payment->payment_channel);
        $this->assertTrue($payment->isDirectDebit());
        $this->assertSame('md_1', $payment->mandate_id);
        $this->assertSame(2, $payment->cycle);
        $this->assertSame('SUB-1', $payment->order_number);
        $this->assertSame('ahmad@example.com', $payment->payer_email);
        $this->assertSame(Fpx::STATUS_SUCCESS, $payment->status);
        $this->assertSame('REF-trx_dd_1', $payment->exchange_reference_number);
        $this->assertSame('2026-11-01 00:00:00', $payment->paid_at->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i:s'));
        $this->assertSame('HLB1', $payment->raw_callback['batch_number']);
        $this->assertTrue($payment->mandate->is($mandate));
        $this->assertTrue($payment->owner->is($mandate->owner));
        $this->assertSame([$payment->id], $mandate->owner->payments()->directDebit()->pluck('id')->all());
        $this->assertSame([$payment->id], $mandate->payments()->pluck('id')->all());

        Event::assertDispatched(PaymentSucceeded::class, fn ($event) => $event->transaction->is($payment));
    }

    public function test_each_cycle_is_its_own_payment(): void
    {
        $mandate = $this->seedMandate();

        $this->postJson('bayarcash/callback', $this->signedDeduction(transactionId: 'trx_dd_1'))->assertOk();
        $this->postJson('bayarcash/callback', $this->signedDeduction(transactionId: 'trx_dd_2'))->assertOk();

        $this->assertSame(['trx_dd_1', 'trx_dd_2'], $mandate->payments()->orderBy('id')->pluck('transaction_id')->all());
    }

    public function test_deduction_without_payment_gateway_id_is_still_direct_debit(): void
    {
        $this->seedMandate();

        $this->postJson('bayarcash/callback', $this->signedDeduction(withGatewayId: false))->assertOk();

        $this->assertSame(Bayarcash::FPX_DIRECT_DEBIT, BayarcashTransaction::sole()->payment_channel);
    }

    public function test_failed_deduction_fires_payment_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->seedMandate();

        $this->postJson('bayarcash/callback', $this->signedDeduction(Fpx::STATUS_FAILED))->assertOk();

        $this->assertSame(Fpx::STATUS_FAILED, BayarcashTransaction::sole()->status);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_deleted_billing_file_is_recorded_without_a_cancelled_event(): void
    {
        Event::fake([PaymentCancelled::class]);
        $this->seedMandate();

        $this->postJson('bayarcash/callback', $this->signedDeduction(Fpx::STATUS_CANCELLED))->assertOk();

        $this->assertSame(Fpx::STATUS_CANCELLED, BayarcashTransaction::sole()->status);
        Event::assertNotDispatched(PaymentCancelled::class);
    }

    public function test_duplicate_deduction_callback_is_idempotent(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $this->seedMandate();

        $this->postJson('bayarcash/callback', $this->signedDeduction())->assertOk();
        $paidAt = BayarcashTransaction::sole()->paid_at;
        $this->travel(5)->minutes();
        $this->postJson('bayarcash/callback', $this->signedDeduction())->assertOk();

        $this->assertSame(1, BayarcashTransaction::count());
        $this->assertTrue(BayarcashTransaction::sole()->paid_at->eq($paidAt));
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_successful_deduction_is_never_downgraded(): void
    {
        Event::fake([PaymentFailed::class]);
        $this->seedMandate();

        $this->postJson('bayarcash/callback', $this->signedDeduction())->assertOk();
        $this->postJson('bayarcash/callback', $this->signedDeduction(Fpx::STATUS_FAILED))->assertOk();

        $this->assertSame(Fpx::STATUS_SUCCESS, BayarcashTransaction::sole()->status);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_tampered_deduction_is_rejected_with_403(): void
    {
        $this->seedMandate();
        $payload = $this->signedDeduction();
        $payload['amount'] = '999.00';

        $this->postJson('bayarcash/callback', $payload)->assertForbidden();

        $this->assertSame(0, BayarcashTransaction::count());
    }

    public function test_stateless_mode_fires_the_event_without_storing(): void
    {
        config()->set('bayarcash.store_records', false);
        Event::fake([PaymentSucceeded::class]);

        $this->postJson('bayarcash/callback', $this->signedDeduction())->assertOk();

        $this->assertSame(0, BayarcashTransaction::count());
        Event::assertDispatched(PaymentSucceeded::class, fn ($event) => ! $event->transaction->exists
            && $event->transaction->isDirectDebit()
            && $event->transaction->order_number === 'SUB-1');
    }

    public function test_multi_tenant_deduction_verifies_with_the_mandate_tenant_secret(): void
    {
        config()->set('bayarcash.multi_tenant', true);
        config()->set('bayarcash.credential_resolver', TenantResolver::class);
        $this->seedMandate('t2');

        $this->postJson('bayarcash/callback', $this->signedDeduction(secret: 'secret-t1'))->assertForbidden();
        $this->postJson('bayarcash/callback', $this->signedDeduction(secret: 'secret-t2'))->assertOk();

        $this->assertSame('t2', BayarcashTransaction::sole()->tenant_id);
    }

    public function test_multi_tenant_deduction_for_an_unknown_mandate_fails_closed(): void
    {
        config()->set('bayarcash.multi_tenant', true);
        config()->set('bayarcash.credential_resolver', TenantResolver::class);

        $this->postJson('bayarcash/callback', $this->signedDeduction())->assertForbidden();

        $this->assertSame(0, BayarcashTransaction::count());
    }
}
