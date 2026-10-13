<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cctv_sources', function (Blueprint $table): void {
            $table->string('provider', 32)->default('unifi');
            $table->unsignedBigInteger('discovery_request_id')->nullable();
            $table->foreign('discovery_request_id', 'cctv_source_discovery')->references('id')->on('cctv_hikvision_discovery_requests')->restrictOnDelete();
            // A direct camera source has no recorder. Never fabricate an NVR.
            $table->unsignedBigInteger('recorder_device_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('cctv_sources')->where('provider', '!=', 'unifi')->orWhereNull('recorder_device_id')->exists()) {
            throw new RuntimeException('Retained direct camera sources prevent this rollback. Restore the compatible application release.');
        }
        Schema::table('cctv_sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('recorder_device_id')->nullable(false)->change();
            $table->dropForeign('cctv_source_discovery');
            $table->dropColumn(['provider', 'discovery_request_id']);
        });
    }
};
