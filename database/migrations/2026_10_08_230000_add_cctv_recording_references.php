<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cctv_recording_references', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('camera_id');
            $table->unsignedBigInteger('recorder_device_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('actor_id');
            $table->uuid('request_key');
            $table->string('input_hash', 64);
            $table->string('metadata_hash', 64);
            $table->longText('metadata');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'request_key'], 'cctv_reference_request');
            $table->index(['camera_id', 'site_id', 'id'], 'cctv_reference_page');
            foreach (['source_id' => 'cctv_sources', 'camera_id' => 'devices', 'recorder_device_id' => 'devices', 'site_id' => 'sites', 'actor_id' => 'users'] as $column => $target) {
                $table->foreign($column, 'cctv_ref_'.$column)->references('id')->on($target)->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Never silently erase attached immutable evidence during rollback.
        if (Schema::hasTable('cctv_recording_references') && DB::table('cctv_recording_references')->exists()) {
            throw new DomainException('Retained recording references cannot be rolled back.');
        }
        Schema::dropIfExists('cctv_recording_references');
    }
};
