<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bayarcash_mandates', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('tenant_id')->nullable()->index();
            $table->string('mandate_id')->nullable()->unique();
            $table->string('mandate_reference_number')->nullable();
            $table->string('order_number')->index();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_id')->nullable();
            $table->integer('payer_id_type')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('payer_telephone_number')->nullable();
            $table->string('application_type')->nullable();
            $table->string('frequency_mode')->nullable();
            $table->integer('status')->nullable()->index();
            $table->string('status_description')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bayarcash_mandates');
    }
};
