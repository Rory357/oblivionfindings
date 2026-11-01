<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3 board M5d (approved by Stephan 1 Nov 2026; decision 22: videos up to 2 minutes / 500 MB, other files up to 20 MB):
 * photos, videos and documents added to a safety review's step. A file is never deleted: removing one hides it with a
 * reason (only while the review is open), and it stays here with who hid it and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hs_event_files')) {
            return;
        }

        Schema::create('hs_event_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hs_event_id')->constrained('hs_events', indexName: 'hs_event_file_event_fk')->restrictOnDelete();
            $table->string('step', 20);
            $table->string('kind', 12);
            $table->string('original_name');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users', indexName: 'hs_event_file_uploader_fk')->nullOnDelete();
            $table->string('uploaded_by_name');
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users', indexName: 'hs_event_file_hider_fk')->nullOnDelete();
            $table->text('hidden_reason')->nullable();
            $table->timestamps();
            $table->index(['hs_event_id', 'step'], 'hs_event_file_event_step_idx');
        });
    }

    public function down(): void
    {
        // Review files are evidence: keep them on an application rollback.
    }
};
