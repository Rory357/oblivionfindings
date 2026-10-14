<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('kind', 20)->default('page');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->json('draft');
            $table->unsignedInteger('draft_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('hub_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_page_id')->constrained('hub_pages')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('draft_version');
            $table->json('content');
            $table->string('state', 20)->default('submitted');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['hub_page_id', 'state', 'publish_at']);
        });
        Schema::create('hub_celebration_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('birthday_enabled')->default(false);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('hub_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('shortcuts')->nullable();
            $table->json('notifications')->nullable();
            $table->timestamps();
        });
        Schema::create('hub_bookmarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();
            $table->unique(['user_id', 'subject_type', 'subject_id']);
        });
        Schema::create('hub_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('hub_page_id')->constrained('hub_pages')->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
        Schema::create('hub_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_page_id')->constrained('hub_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('body')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->index(['hub_page_id', 'kind']);
        });
    }

    public function down(): void
    {
        foreach (['hub_interactions', 'hub_media', 'hub_bookmarks', 'hub_preferences', 'hub_celebration_settings', 'hub_revisions', 'hub_pages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
