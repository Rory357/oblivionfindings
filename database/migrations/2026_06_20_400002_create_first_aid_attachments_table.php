<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First Aid Register gold-standard upgrade — Step 1 (docs/first-aid-redesign §0.5).
 *
 * Premium evidence storage for first-aid treatments — ACC45 forms, injury photos,
 * treatment notes. Uploaded from the detail modal via the shared AttachmentUploader
 * (@/components/ui/file-dropzone). Mirrors emergency_drill_attachments; per-file note
 * + alt text (a11y) + a kind tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('first_aid_attachments') || ! Schema::hasTable('first_aid_records')) {
            return;
        }

        Schema::create('first_aid_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('first_aid_record_id')->constrained('first_aid_records')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('kind', 30)->nullable(); // acc45|injury_photo|treatment_note|document
            $table->text('notes')->nullable();
            $table->string('alt_text')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('first_aid_record_id', 'faa_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_aid_attachments');
    }
};
