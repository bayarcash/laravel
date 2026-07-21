<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bayarcash_transactions', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('tenant_id')->nullable()->index();
            $table->string('transaction_id')->nullable()->unique();
            $table->string('payment_intent_id')->nullable()->index();
            $table->string('exchange_reference_number')->nullable()->index();
            $table->string('order_number')->index();
            $table->unsignedInteger('payment_channel')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('payer_telephone_number')->nullable();
            $table->string('payer_bank_name')->nullable();
            $table->integer('status')->nullable()->index();
            $table->string('status_description')->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw_callback')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bayarcash_transactions');
    }
};
