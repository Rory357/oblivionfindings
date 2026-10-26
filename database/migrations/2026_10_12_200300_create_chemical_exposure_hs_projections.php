<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chemical_exposure_hs_projections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exposure_id');
            $table->unsignedBigInteger('hs_event_id');
            $table->unsignedBigInteger('source_version_id');
            $table->unsignedBigInteger('audit_log_id');
            $table->unsignedInteger('source_revision');
            $table->char('source_content_digest', 64);
            $table->json('projection');
            $table->char('projection_digest', 64);
            $table->unsignedBigInteger('recorded_by');
            $table->dateTime('recorded_at', 6);
            $table->unique('source_version_id', 'ce_hs_projection_version_uq');
            $table->unique(['exposure_id', 'source_revision'], 'ce_hs_projection_revision_uq');
            $table->index(['exposure_id', 'hs_event_id'], 'ce_hs_projection_event_idx');
            $table->foreign('exposure_id', 'ce_hs_projection_exposure_fk')->references('id')->on('substance_exposure_records')->restrictOnDelete();
            $table->foreign('hs_event_id', 'ce_hs_projection_event_fk')->references('id')->on('hs_events')->restrictOnDelete();
            $table->foreign('source_version_id', 'ce_hs_projection_version_fk')->references('id')->on('chemical_exposure_source_versions')->restrictOnDelete();
            $table->foreign('audit_log_id', 'ce_hs_projection_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('chemical_exposure_hs_projections')->exists()) {
            throw new RuntimeException('Retained exposure delivery evidence prevents destructive rollback.');
        }
        Schema::drop('chemical_exposure_hs_projections');
    }
};
