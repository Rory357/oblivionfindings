<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Injuries & RTW redesign — Step 1. Evidence storage for a workplace injury
 * (medical certificates, ACC forms, RTW medical-clearance letters, scene/injury
 * photos). Mirrors fleet_incident_attachments / emergency_drill_attachments /
 * safeguarding_attachments — per-module table (NOT polymorphic), carries a per-file
 * note + alt text (a11y) + a kind tag. Premium upload via the shared AttachmentUploader.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workplace_injury_attachments') || ! Schema::hasTable('workplace_injuries')) {
            return;
        }

        Schema::create('workplace_injury_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workplace_injury_id')->constrained('workplace_injuries')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('kind', 30)->nullable(); // medical_cert|acc_form|rtw_clearance|photo|document
            $table->text('notes')->nullable();
            $table->string('alt_text')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Short explicit name — avoid MySQL's 64-char auto-generated index-name limit.
            $table->index('workplace_injury_id', 'wia_injury_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workplace_injury_attachments');
    }
};
