<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_fund_transactions', function (Blueprint $table) {
            $table->uuid('idempotency_key')
                ->nullable()
                ->after('client_fund_id');
            $table->unique(
                ['client_fund_id', 'idempotency_key'],
                'client_fund_transactions_fund_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('client_fund_transactions', function (Blueprint $table) {
            $table->dropUnique('client_fund_transactions_fund_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
