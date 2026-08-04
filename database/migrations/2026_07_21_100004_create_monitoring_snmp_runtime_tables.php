<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_discovery_scopes', function (Blueprint $table): void {
            $table->string('snmp_credential_reference', 190)->nullable()->after('protocols');
        });

        Schema::create('monitoring_snmp_compatibility_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->string('version', 8);
            $table->string('credential_reference', 190);
            $table->foreignId('owner_user_id');
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->string('migration_status', 64);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('owner_user_id', 'monitoring_snmp_compat_owner_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('revoked_by_user_id', 'monitoring_snmp_compat_revoker_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(
                ['site_id', 'device_id', 'version', 'expires_at'],
                'monitoring_snmp_compat_scope_expiry_idx',
            );
            $table->index(
                ['owner_user_id', 'migration_status', 'expires_at'],
                'monitoring_snmp_compat_owner_status_idx',
            );
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_snmp_compatibility_exceptions');

        Schema::table('monitoring_discovery_scopes', function (Blueprint $table): void {
            $table->dropColumn('snmp_credential_reference');
        });
    }
};
