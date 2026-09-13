<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_kb_articles', function (Blueprint $table): void {
            $table->json('diagrams')->nullable();
            $table->json('file_ids')->nullable();
        });
        Schema::create('it_kb_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('it_kb_articles')->restrictOnDelete();
            $table->uuid('series_id')->index();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('path')->unique();
            $table->string('state', 32)->default('reserved');
            $table->uuid('request_uuid')->nullable();
            $table->unsignedInteger('expected_version')->default(1);
            $table->unsignedBigInteger('replaces_file_id')->nullable();
            $table->string('audience', 32);
            $table->json('site_scope')->nullable();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['series_id', 'version']);
            $table->unique(['uploaded_by_user_id', 'request_uuid']);
            $table->index(['article_id', 'uploaded_by_user_id', 'state', 'id']);
        });
        Schema::create('it_kb_revision_files', function (Blueprint $table): void {
            $table->foreignId('revision_id')->constrained('it_kb_revisions')->restrictOnDelete();
            $table->foreignId('file_id')->constrained('it_kb_files')->restrictOnDelete();
            $table->primary(['revision_id', 'file_id']);
            $table->index(['file_id', 'revision_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_kb_revision_files');
        Schema::dropIfExists('it_kb_files');
        Schema::table('it_kb_articles', fn (Blueprint $table) => $table->dropColumn(['diagrams', 'file_ids']));
    }
};
