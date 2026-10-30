<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_media', function (Blueprint $table): void {
            $table->foreignId('document_root_id')->nullable()->constrained('hub_media')->restrictOnDelete();
            $table->unsignedInteger('document_sequence')->default(1);
            $table->unsignedInteger('document_version')->default(1);
        });
        Schema::create('hub_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('root_media_id')->constrained('hub_media')->restrictOnDelete();
            $table->foreignId('hub_media_id')->constrained('hub_media')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('action', 20);
            $table->unsignedBigInteger('restored_from_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['root_media_id', 'sequence'], 'hub_document_edition_unique');
        });
        Schema::create('hub_document_upload_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('payload_hash', 64);
            $table->foreignId('hub_page_id')->constrained('hub_pages')->restrictOnDelete();
            $table->foreignId('hub_media_id')->constrained('hub_media')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'request_id'], 'hub_document_upload_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_document_upload_receipts');
        Schema::dropIfExists('hub_document_versions');
        Schema::table('hub_media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_root_id');
            $table->dropColumn(['document_sequence', 'document_version']);
        });
    }
};
