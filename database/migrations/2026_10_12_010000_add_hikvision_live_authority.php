<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cctv_live_sessions', function (Blueprint $table): void {
            $table->string('provider', 32)->default('unifi');
            $table->longText('source_context')->nullable();
            $table->timestamp('preparation_started_at')->nullable();
            $table->unsignedBigInteger('credential_lease_grant_id')->nullable();
            $table->foreign('credential_lease_grant_id', 'cctv_live_credential_grant')->references('id')->on('security_device_credential_lease_grants')->restrictOnDelete();
        });
        Schema::table('cctv_collector_presence', function (Blueprint $table): void {
            $table->string('hikvision_profile', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('cctv_live_sessions')->where('provider', '!=', 'unifi')->orWhereNotNull('source_context')->orWhereNotNull('credential_lease_grant_id')->exists()) {
            throw new RuntimeException('Retained live session authority prevents rollback.');
        }
        Schema::table('cctv_collector_presence', fn (Blueprint $table) => $table->dropColumn('hikvision_profile'));
        Schema::table('cctv_live_sessions', function (Blueprint $table): void {
            $table->dropForeign('cctv_live_credential_grant');
            $table->dropColumn(['provider', 'source_context', 'preparation_started_at', 'credential_lease_grant_id']);
        });
    }
};
