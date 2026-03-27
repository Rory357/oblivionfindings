<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_payment_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('bank_transaction_id')->constrained('fin_bank_transactions');
            $table->string('matchable_type');
            $table->unsignedBigInteger('matchable_id')->nullable();
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->json('match_reasons')->nullable();
            $table->enum('status', ['suggested', 'confirmed', 'rejected', 'auto_confirmed'])->default('suggested');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->datetime('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'fin_pm_org_status_idx');
            $table->index(['bank_transaction_id'], 'fin_pm_bank_txn_idx');
            $table->index(['matchable_type', 'matchable_id'], 'fin_pm_matchable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_payment_matches');
    }
};
