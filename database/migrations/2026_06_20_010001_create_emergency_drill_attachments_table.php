<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emergency Drills redesign — evidence storage for a drill (sign-in sheets, photos
 * of the assembly point/roll-call, the FENZ evacuation-scheme report PDF). Mirrors
 * `fleet_incident_attachments` / `safeguarding_attachments`; carries a per-file note
 * + alt text (a11y) + a kind tag. Premium upload via the shared AttachmentUploader.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('emergency_drill_attachments') || ! Schema::hasTable('emergency_drills')) {
            return;
        }

        Schema::create('emergency_drill_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_drill_id')->constrained('emergency_drills')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('kind', 30)->nullable(); // sign_in|photo|report|document
            $table->text('notes')->nullable();
            $table->string('alt_text')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('emergency_drill_id', 'eda_drill_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_drill_attachments');
    }
};
