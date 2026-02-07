<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_meeting_id')->unique()->constrained()->onDelete('cascade');
            
            // Snapshot of dashboard metrics (immutable JSON) (FK added after dashboard_snapshots exists)
            $table->unsignedBigInteger('dashboard_snapshot_id');
            
            // Document manifest
            $table->json('document_manifest')->nullable(); // List of included documents
            
            // Generation
            $table->datetime('generated_at');
            $table->foreignId('generated_by')->constrained('users');
            
            // PDF file
            $table->string('file_path')->nullable();
            $table->string('file_size')->nullable();
            $table->string('checksum')->nullable(); // For integrity verification
            
            // Watermark
            $table->string('watermark_text')->default('CONFIDENTIAL - BOARD ONLY');
            
            // Distribution tracking
            $table->datetime('distributed_at')->nullable();
            $table->json('distributed_to')->nullable(); // Array of board member IDs
            $table->json('download_tracking')->nullable(); // Who downloaded when
            $table->json('read_tracking')->nullable(); // Who marked as read
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['generated_at', 'distributed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_packs');
    }
};
