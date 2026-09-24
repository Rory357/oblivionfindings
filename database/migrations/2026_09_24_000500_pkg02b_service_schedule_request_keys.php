<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service schedule created from the vehicle profile keeps the request key
 * it was created with, so a retried save returns that schedule instead of
 * adding a duplicate. Schedules from the legacy list keep null keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_service_schedules') || Schema::hasColumn('fleet_service_schedules', 'request_key')) {
            return;
        }

        Schema::table('fleet_service_schedules', function (Blueprint $table): void {
            $table->string('request_key', 100)->nullable()->after('lock_version');
            $table->char('request_fingerprint', 64)->nullable()->after('request_key');
            $table->unique(['asset_id', 'request_key'], 'fleet_service_schedules_request_uq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fleet_service_schedules') || ! Schema::hasColumn('fleet_service_schedules', 'request_key')) {
            return;
        }

        Schema::table('fleet_service_schedules', function (Blueprint $table): void {
            $table->dropUnique('fleet_service_schedules_request_uq');
            $table->dropColumn(['request_key', 'request_fingerprint']);
        });
    }
};
