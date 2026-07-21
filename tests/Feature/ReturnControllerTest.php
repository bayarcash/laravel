<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Bayarcash;
use Bayarcash\Fpx;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReturnControllerTest extends TestCase
{
    use RefreshDatabase;

    private function signedReturn(int $status, string $trx = 'trx_r', string $order = 'INV-R'): array
    {
        $fields = [
            'transaction_id' => $trx, 'exchange_reference_number' => 'REF', 'exchange_transaction_id' => 'EX',
            'order_number' => $order, 'currency' => 'MYR', 'amount' => '10.00', 'payer_bank_name' => 'Bank',
            'status' => (string) $status, 'status_description' => 'ok',
        ];
        $fields['checksum'] = (new Bayarcash('t'))->createChecksumValue('test-secret', $fields);

        return $fields;
    }

    public function test_valid_return_settles_row_without_raw_callback(): void
    {
        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r',
            'status' => Fpx::STATUS_PENDING, 'amount' => '10.00',
        ]);

        $this->get('bayarcash/return?' . http_build_query($this->signedReturn(3)))->assertOk();

        $trx = BayarcashTransaction::where('transaction_id', 'trx_r')->first();
        $this->assertSame(Fpx::STATUS_SUCCESS, $trx->status);
        $this->assertNotNull($trx->paid_at);
        $this->assertNull($trx->raw_callback);
    }

    public function test_return_returns_json_of_transaction_when_no_redirect(): void
    {
        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r', 'status' => Fpx::STATUS_PENDING,
        ]);

        $this->get('bayarcash/return?' . http_build_query($this->signedReturn(3)))
            ->assertOk()
            ->assertJsonPath('status', Fpx::STATUS_SUCCESS);
    }

    public function test_return_never_aborts_on_tampered_checksum(): void
    {
        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r', 'status' => Fpx::STATUS_PENDING,
        ]);

        $payload = $this->signedReturn(3);
        $payload['amount'] = '999.00';

        $this->get('bayarcash/return?' . http_build_query($payload))->assertOk();

        $trx = BayarcashTransaction::where('transaction_id', 'trx_r')->first();
        $this->assertSame(Fpx::STATUS_PENDING, $trx->status);
        $this->assertNull($trx->raw_callback);
    }

    public function test_return_never_aborts_when_checksum_missing(): void
    {
        $this->get('bayarcash/return?order_number=INV-X&status=3')->assertOk();
    }

    public function test_return_redirects_when_configured(): void
    {
        config()->set('bayarcash.return.redirect', 'https://shop.test/thank-you');

        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r', 'status' => Fpx::STATUS_PENDING,
        ]);

        $this->get('bayarcash/return?' . http_build_query($this->signedReturn(3)))
            ->assertRedirect('https://shop.test/thank-you');

        $this->assertSame(Fpx::STATUS_SUCCESS, BayarcashTransaction::where('transaction_id', 'trx_r')->first()->status);
    }
}
