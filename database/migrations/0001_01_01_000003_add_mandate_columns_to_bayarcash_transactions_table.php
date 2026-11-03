<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bayarcash_transactions', function (Blueprint $table) {
            $table->string('mandate_id')->nullable()->index()->after('payment_intent_id');
            $table->unsignedInteger('cycle')->nullable()->after('mandate_id');
        });
    }

    public function down(): void
    {
        Schema::table('bayarcash_transactions', function (Blueprint $table) {
            $table->dropIndex(['mandate_id']);
            $table->dropColumn(['mandate_id', 'cycle']);
        });
    }
};
