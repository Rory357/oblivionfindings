<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queclink_audit_events', function (Blueprint $table) {
            $table->foreignId('provider_connection_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('integration_tenant_secrets')
                ->nullOnDelete();
            $table->foreignId('site_id')
                ->nullable()
                ->after('provider_connection_id')
                ->constrained('sites')
                ->nullOnDelete();
            $table->foreignId('canonical_device_id')
                ->nullable()
                ->after('site_id')
                ->constrained('devices')
                ->nullOnDelete();
            $table->string('outcome', 32)
                ->default('succeeded')
                ->after('event_type');

            $table->index(['provider_connection_id', 'created_at']);
            $table->index(['site_id', 'created_at']);
            $table->index(['canonical_device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('queclink_audit_events', function (Blueprint $table) {
            $table->dropIndex(['provider_connection_id', 'created_at']);
            $table->dropIndex(['site_id', 'created_at']);
            $table->dropIndex(['canonical_device_id', 'created_at']);
            $table->dropConstrainedForeignId('canonical_device_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('provider_connection_id');
            $table->dropColumn('outcome');
        });
    }
};
