<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy command-centre redesign — Step 1.
 *
 * Polymorphic evidence/document store shared by every privacy domain (data
 * subject requests, breaches, legal holds, retention policies, DPIAs). One
 * table + one controller + one upload pane rather than five. Mirrors the
 * Safeguarding attachment shape: per-file note + sensitivity flag (sensitive
 * files are download-gated by the owning domain's write permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable'); // attachable_type + attachable_id (+ index)
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_attachments');
    }
};
