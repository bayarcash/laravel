<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bayarcash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->text('token');       // encrypted
            $table->text('secret_key');  // encrypted
            $table->boolean('sandbox')->default(false);
            $table->string('portal_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bayarcash_accounts');
    }
};
