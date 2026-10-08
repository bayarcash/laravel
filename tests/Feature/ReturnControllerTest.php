<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Bayarcash;
use Bayarcash\Fpx;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

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
            ->assertRedirect('https://shop.test/thank-you?order_number=INV-R&transaction_id=trx_r&status=3');

        $this->assertSame(Fpx::STATUS_SUCCESS, BayarcashTransaction::where('transaction_id', 'trx_r')->first()->status);
    }

    public function test_verified_return_appends_the_reference_to_a_named_route(): void
    {
        Route::get('payments/{payment?}', fn () => 'ok')->name('payments.result');
        config()->set('bayarcash.return.redirect', 'payments.result');

        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r', 'status' => Fpx::STATUS_PENDING,
        ]);

        $this->get('bayarcash/return?' . http_build_query($this->signedReturn(2)))
            ->assertRedirect(url('payments') . '?order_number=INV-R&transaction_id=trx_r&status=2');
    }

    public function test_reference_reports_the_stored_status_not_the_returned_one(): void
    {
        config()->set('bayarcash.return.redirect', 'https://shop.test/thank-you');

        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r', 'status' => Fpx::STATUS_SUCCESS,
        ]);

        $this->get('bayarcash/return?' . http_build_query($this->signedReturn(Fpx::STATUS_PENDING)))
            ->assertRedirect('https://shop.test/thank-you?order_number=INV-R&transaction_id=trx_r&status=3');
    }

    public function test_reference_is_merged_into_an_existing_query_string(): void
    {
        config()->set('bayarcash.return.redirect', 'https://shop.test/done?lang=ms#top');

        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r', 'status' => Fpx::STATUS_PENDING,
        ]);

        $this->get('bayarcash/return?' . http_build_query($this->signedReturn(3)))
            ->assertRedirect('https://shop.test/done?lang=ms&order_number=INV-R&transaction_id=trx_r&status=3#top');
    }

    public function test_unverified_return_redirects_without_the_reference(): void
    {
        config()->set('bayarcash.return.redirect', 'https://shop.test/thank-you');

        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r', 'status' => Fpx::STATUS_PENDING,
        ]);

        $payload = $this->signedReturn(3);
        $payload['amount'] = '999.00';

        $this->get('bayarcash/return?' . http_build_query($payload))
            ->assertRedirect('https://shop.test/thank-you');
    }

    public function test_return_without_checksum_redirects_without_the_reference(): void
    {
        config()->set('bayarcash.return.redirect', 'https://shop.test/thank-you');

        $this->get('bayarcash/return?order_number=INV-X&transaction_id=trx_x&status=3')
            ->assertRedirect('https://shop.test/thank-you');
    }

    public function test_reference_can_be_turned_off(): void
    {
        config()->set('bayarcash.return.redirect', 'https://shop.test/thank-you');
        config()->set('bayarcash.return.include_reference', false);

        BayarcashTransaction::create([
            'order_number' => 'INV-R', 'transaction_id' => 'trx_r', 'status' => Fpx::STATUS_PENDING,
        ]);

        $this->get('bayarcash/return?' . http_build_query($this->signedReturn(3)))
            ->assertRedirect('https://shop.test/thank-you');

        $this->assertSame(Fpx::STATUS_SUCCESS, BayarcashTransaction::where('transaction_id', 'trx_r')->first()->status);
    }

    public function test_reference_is_appended_in_stateless_mode(): void
    {
        config()->set('bayarcash.store_records', false);
        config()->set('bayarcash.return.redirect', 'https://shop.test/thank-you');

        $this->get('bayarcash/return?' . http_build_query($this->signedReturn(3)))
            ->assertRedirect('https://shop.test/thank-you?order_number=INV-R&transaction_id=trx_r&status=3');
    }
}
