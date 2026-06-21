<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic evidence storage for clinical records — clinical events (fall/skin/
 * infection photos, body maps, the linked WorkSafe PDF) and ABC behaviour entries
 * (injury/property-damage photos, scanned paper charts). One table shared by both
 * via a morph, mirroring the safeguarding_attachments shape (single `mime`,
 * sensitive flag, soft-deletes) so the same upload chrome works unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable'); // attachable_type + attachable_id (+ index)
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('kind')->nullable(); // photo | document | body_map
            $table->text('notes')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_attachments');
    }
};
