<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_feeds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('bank_account_id')->constrained('fin_bank_accounts');
            $table->enum('provider', ['asb', 'anz', 'westpac', 'bnz']);
            $table->text('consent_token')->nullable();
            $table->datetime('consent_expires_at')->nullable();
            $table->datetime('last_sync_at')->nullable();
            $table->enum('last_sync_status', ['success', 'failed', 'pending'])->nullable();
            $table->text('last_error')->nullable();
            $table->date('sync_from_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'bank_account_id'], 'fin_bank_feeds_org_bank_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_feeds');
    }
};
