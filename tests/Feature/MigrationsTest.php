<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('bayarcash_transactions'));

        $this->assertTrue(Schema::hasColumns('bayarcash_transactions', [
            'owner_type', 'owner_id', 'tenant_id', 'transaction_id', 'payment_intent_id',
            'exchange_reference_number', 'order_number', 'payment_channel', 'amount', 'currency',
            'payer_name', 'payer_email', 'payer_telephone_number', 'payer_bank_name',
            'status', 'status_description', 'metadata', 'raw_callback', 'paid_at',
            'created_at', 'updated_at',
        ]));
    }

    public function test_mandates_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('bayarcash_mandates'));

        $this->assertTrue(Schema::hasColumns('bayarcash_mandates', [
            'owner_type', 'owner_id', 'tenant_id', 'mandate_id', 'mandate_reference_number',
            'order_number', 'amount', 'currency', 'payer_name', 'payer_id', 'payer_id_type',
            'payer_email', 'payer_telephone_number', 'application_type', 'frequency_mode',
            'status', 'status_description', 'effective_date', 'expiry_date', 'metadata',
            'created_at', 'updated_at',
        ]));
    }
}
