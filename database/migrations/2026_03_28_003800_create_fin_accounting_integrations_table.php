<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_accounting_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->enum('provider', ['xero', 'myob']);
            $table->string('tenant_id')->nullable()->comment('Xero tenant ID or MYOB company file URI');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('last_sync_at')->nullable();
            $table->enum('last_sync_status', ['success', 'failed', 'pending'])->nullable();
            $table->text('last_error')->nullable();
            $table->enum('sync_direction', ['push', 'pull', 'bidirectional'])->default('bidirectional');
            $table->json('account_mapping')->nullable()->comment('Maps local account IDs to external IDs');
            $table->json('tax_mapping')->nullable()->comment('Maps local tax rate IDs to external tax type IDs');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'provider'], 'fin_acct_integ_org_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_accounting_integrations');
    }
};
