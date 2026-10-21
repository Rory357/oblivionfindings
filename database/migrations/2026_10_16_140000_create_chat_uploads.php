<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Retain private cleanup/receipt metadata if a source record is later removed.
            $table->unsignedBigInteger('conversation_id')->index();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('access_boundary', 64);
            $table->string('state', 24);
            $table->string('storage_path')->nullable();
            $table->string('name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->uuid('scan_token')->nullable();
            $table->timestamp('scan_started_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->unsignedBigInteger('message_id')->nullable()->index();
            $table->timestamps();
            $table->index(['actor_id', 'conversation_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_uploads');
    }
};
