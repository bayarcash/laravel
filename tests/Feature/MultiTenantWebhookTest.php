<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Bayarcash;
use Bayarcash\Fpx;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\Fixtures\TenantResolver;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

/**
 * The single shared webhook: one callback URL for every tenant. The tenant is
 * resolved from the payload's local record and the checksum is verified with
 * that tenant's secret. No local record => fail closed (403).
 */
class MultiTenantWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('bayarcash.multi_tenant', true);
        $app['config']->set('bayarcash.credential_resolver', TenantResolver::class);
    }

    /**
     * Sign a transaction callback payload with the given tenant secret.
     */
    private function signedTransaction(string $secret, int $status = 3, string $order = 'INV-MT'): array
    {
        $fields = [
            'record_type' => 'transaction', 'transaction_id' => 'trx_mt',
            'exchange_reference_number' => 'REF', 'exchange_transaction_id' => 'EX',
            'order_number' => $order, 'currency' => 'MYR', 'amount' => '10.00',
            'payer_name' => 'A', 'payer_email' => 'a@b.com', 'payer_bank_name' => 'Bank',
            'status' => (string) $status, 'status_description' => 'ok', 'datetime' => '2026-01-01 00:00:00',
        ];
        $fields['checksum'] = (new Bayarcash('t'))->createChecksumValue($secret, $fields);

        return $fields;
    }

    private function seedTenantTransaction(string $tenant = 't1', string $order = 'INV-MT'): BayarcashTransaction
    {
        return BayarcashTransaction::create([
            'tenant_id'    => $tenant,
            'order_number' => $order,
            'amount'       => '10.00',
            'status'       => Fpx::STATUS_PENDING,
        ]);
    }

    public function test_callback_signed_with_tenant_secret_verifies_and_updates(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $this->seedTenantTransaction('t1', 'INV-MT');

        $this->postJson('bayarcash/callback', $this->signedTransaction('secret-t1'))->assertOk();

        $trx = BayarcashTransaction::where('order_number', 'INV-MT')->first();
        $this->assertSame(Fpx::STATUS_SUCCESS, $trx->status);
        $this->assertSame('trx_mt', $trx->transaction_id);
        $this->assertSame('t1', $trx->tenant_id);
        $this->assertNotNull($trx->paid_at);

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_callback_signed_with_wrong_secret_is_rejected_with_403(): void
    {
        $this->seedTenantTransaction('t1', 'INV-MT');

        // Signed with the default secret instead of t1's secret.
        $this->postJson('bayarcash/callback', $this->signedTransaction('test-secret'))->assertForbidden();

        $trx = BayarcashTransaction::where('order_number', 'INV-MT')->first();
        $this->assertSame(Fpx::STATUS_PENDING, $trx->status);
    }

    public function test_callback_with_no_matching_record_fails_closed_with_403(): void
    {
        // No local transaction seeded for INV-MISSING.
        $this->postJson('bayarcash/callback', $this->signedTransaction('secret-t1', order: 'INV-MISSING'))
            ->assertForbidden();

        $this->assertSame(0, BayarcashTransaction::count());
    }

    public function test_tenant_secrets_are_isolated_between_tenants(): void
    {
        // A t2-owned order signed with t1's secret must not verify.
        $this->seedTenantTransaction('t2', 'INV-MT2');

        $this->postJson('bayarcash/callback', $this->signedTransaction('secret-t1', order: 'INV-MT2'))
            ->assertForbidden();

        // The same order signed with t2's secret verifies.
        $this->postJson('bayarcash/callback', $this->signedTransaction('secret-t2', order: 'INV-MT2'))
            ->assertOk();

        $this->assertSame(Fpx::STATUS_SUCCESS, BayarcashTransaction::where('order_number', 'INV-MT2')->first()->status);
    }
}
