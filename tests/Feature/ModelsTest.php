<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Fpx;
use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_casts_scopes_and_status_label(): void
    {
        BayarcashTransaction::create([
            'order_number' => 'INV-1', 'amount' => '10.00',
            'status' => Fpx::STATUS_SUCCESS, 'metadata' => ['k' => 'v'],
            'raw_callback' => ['record_type' => 'transaction'],
        ]);
        BayarcashTransaction::create([
            'order_number' => 'INV-2', 'amount' => '5.00', 'status' => Fpx::STATUS_PENDING,
        ]);
        BayarcashTransaction::create([
            'order_number' => 'INV-3', 'amount' => '5.00', 'status' => Fpx::STATUS_NEW,
        ]);
        BayarcashTransaction::create([
            'order_number' => 'INV-4', 'amount' => '5.00', 'status' => Fpx::STATUS_FAILED,
        ]);

        $this->assertCount(1, BayarcashTransaction::successful()->get());
        $this->assertCount(2, BayarcashTransaction::pending()->get());
        $this->assertCount(1, BayarcashTransaction::failed()->get());

        $trx = BayarcashTransaction::successful()->first();
        $this->assertSame(['k' => 'v'], $trx->metadata);
        $this->assertSame(['record_type' => 'transaction'], $trx->raw_callback);
        $this->assertSame('Successful', $trx->statusLabel());
    }

    public function test_mandate_casts_and_status_label(): void
    {
        $mandate = BayarcashMandate::create([
            'order_number' => 'INV-1', 'amount' => '10.00',
            'status' => FpxDirectDebit::STATUS_ACTIVE, 'payer_id_type' => FpxDirectDebit::NRIC,
            'effective_date' => '2026-08-01', 'metadata' => ['x' => 1],
        ]);

        $this->assertSame(FpxDirectDebit::NRIC, $mandate->payer_id_type);
        $this->assertSame(['x' => 1], $mandate->metadata);
        $this->assertSame('Active', $mandate->statusLabel());
    }
}
