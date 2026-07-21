<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Bayarcash;
use Bayarcash\Laravel\Events\MandateApproved;
use Bayarcash\Laravel\Events\MandateAuthorized;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Events\WebhookReceived;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class CallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    private function sign(array $fields): array
    {
        $fields['checksum'] = (new Bayarcash('t'))->createChecksumValue('test-secret', $fields);

        return $fields;
    }

    private function signedTransaction(int $status): array
    {
        return $this->sign([
            'record_type' => 'transaction', 'transaction_id' => 'trx_9',
            'exchange_reference_number' => 'REF', 'exchange_transaction_id' => 'EX',
            'order_number' => 'INV-1', 'currency' => 'MYR', 'amount' => '10.00',
            'payer_name' => 'A', 'payer_email' => 'a@b.com', 'payer_bank_name' => 'Bank',
            'status' => (string) $status, 'status_description' => 'ok', 'datetime' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_valid_transaction_callback_upserts_and_fires_events(): void
    {
        Event::fake([PaymentSucceeded::class, WebhookReceived::class]);

        $this->postJson('bayarcash/callback', $this->signedTransaction(3))->assertOk();

        $this->assertDatabaseHas('bayarcash_transactions', [
            'transaction_id' => 'trx_9', 'status' => 3,
        ]);

        $trx = BayarcashTransaction::first();
        $this->assertNotNull($trx->paid_at);
        $this->assertNotNull($trx->raw_callback);

        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_duplicate_callback_is_idempotent(): void
    {
        $this->postJson('bayarcash/callback', $this->signedTransaction(3))->assertOk();
        $this->postJson('bayarcash/callback', $this->signedTransaction(3))->assertOk();

        $this->assertSame(1, BayarcashTransaction::where('transaction_id', 'trx_9')->count());
    }

    public function test_tampered_callback_is_rejected_with_403(): void
    {
        $payload = $this->signedTransaction(3);
        $payload['amount'] = '999.00';

        $this->postJson('bayarcash/callback', $payload)->assertForbidden();

        $this->assertSame(0, BayarcashTransaction::count());
    }

    public function test_pre_transaction_is_acknowledged_without_persisting(): void
    {
        Event::fake([WebhookReceived::class]);

        $payload = $this->sign([
            'record_type' => 'pre_transaction',
            'exchange_reference_number' => 'REF',
            'order_number' => 'INV-1',
        ]);

        $this->postJson('bayarcash/callback', $payload)->assertOk();

        $this->assertSame(0, BayarcashTransaction::count());
        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_authorization_callback_upserts_mandate_with_application_type(): void
    {
        Event::fake([MandateAuthorized::class]);

        $payload = $this->sign([
            'record_type' => 'authorization', 'transaction_id' => 'trx_1', 'mandate_id' => 'mdt_1',
            'application_type' => '01', 'exchange_reference_number' => 'REF', 'exchange_transaction_id' => 'EX',
            'order_number' => 'INV-1', 'currency' => 'MYR', 'amount' => '10.00', 'payer_name' => 'A',
            'payer_email' => 'a@b.com', 'payer_bank_name' => 'Bank', 'status' => '3',
            'status_description' => 'ok', 'datetime' => '2026-01-01 00:00:00',
        ]);

        $this->postJson('bayarcash/callback', $payload)->assertOk();

        $this->assertDatabaseHas('bayarcash_mandates', [
            'mandate_id' => 'mdt_1', 'application_type' => '01', 'status' => 3,
        ]);
        Event::assertDispatched(MandateAuthorized::class);
    }

    public function test_bank_approval_callback_upserts_mandate_and_fires_approved(): void
    {
        Event::fake([MandateApproved::class]);

        $payload = $this->sign([
            'record_type' => 'bank_approval', 'approval_date' => '2026-01-01', 'approval_status' => 'approved',
            'mandate_id' => 'mdt_2', 'mandate_reference_number' => 'MREF', 'order_number' => 'INV-2',
            'payer_bank_code_hashed' => 'h', 'payer_bank_code' => 'MB2U0227', 'payer_bank_account_no' => '123',
            'application_type' => '01',
        ]);

        $this->postJson('bayarcash/callback', $payload)->assertOk();

        $this->assertDatabaseHas('bayarcash_mandates', ['mandate_id' => 'mdt_2', 'mandate_reference_number' => 'MREF']);
        Event::assertDispatched(MandateApproved::class);
    }

    public function test_mandate_callback_is_idempotent(): void
    {
        $payload = $this->sign([
            'record_type' => 'authorization', 'transaction_id' => 'trx_1', 'mandate_id' => 'mdt_3',
            'application_type' => '01', 'exchange_reference_number' => 'REF', 'exchange_transaction_id' => 'EX',
            'order_number' => 'INV-3', 'currency' => 'MYR', 'amount' => '10.00', 'payer_name' => 'A',
            'payer_email' => 'a@b.com', 'payer_bank_name' => 'Bank', 'status' => '3',
            'status_description' => 'ok', 'datetime' => '2026-01-01 00:00:00',
        ]);

        $this->postJson('bayarcash/callback', $payload)->assertOk();
        $this->postJson('bayarcash/callback', $payload)->assertOk();

        $this->assertSame(1, \Bayarcash\Laravel\Models\BayarcashMandate::where('mandate_id', 'mdt_3')->count());
    }
}
