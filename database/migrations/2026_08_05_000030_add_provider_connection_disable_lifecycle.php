<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_tenant_secrets', function (Blueprint $table): void {
            $table->timestamp('disabled_at')->nullable()->after('rotated_at');
            $table->foreignId('disabled_by')->nullable()->after('disabled_at')
                ->constrained(
                    table: 'users',
                    indexName: 'int_provider_secrets_disabled_by_fk',
                )->nullOnDelete();
            $table->string('disabled_reason', 64)->nullable()->after('disabled_by');
            $table->boolean('requires_credential_replacement')->default(false)->after('disabled_reason');
            $table->timestamp('recovery_credentials_replaced_at')->nullable()->after('requires_credential_replacement');
            $table->foreignId('recovery_credentials_replaced_by')->nullable()->after('recovery_credentials_replaced_at')
                ->constrained(
                    table: 'users',
                    indexName: 'int_provider_secrets_recovery_by_fk',
                )->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integration_tenant_secrets', function (Blueprint $table): void {
            $table->dropForeign('int_provider_secrets_recovery_by_fk');
            $table->dropColumn(['recovery_credentials_replaced_by', 'recovery_credentials_replaced_at']);
            $table->dropColumn('requires_credential_replacement');
            $table->dropColumn('disabled_reason');
            $table->dropForeign('int_provider_secrets_disabled_by_fk');
            $table->dropColumn(['disabled_by', 'disabled_at']);
        });
    }
};
