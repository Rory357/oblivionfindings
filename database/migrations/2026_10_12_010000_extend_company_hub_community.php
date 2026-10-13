<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_preferences', function (Blueprint $table): void {
            $table->json('followed_pages')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });
        Schema::table('hub_interactions', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('hub_interactions')->nullOnDelete();
        });
        Schema::create('hub_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_page_id')->constrained('hub_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_read_at');
            $table->timestamp('last_read_at');
            $table->unique(['hub_page_id', 'user_id']);
        });
        Schema::create('hub_ideas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->string('category', 60);
            $table->string('status', 20)->default('open');
            $table->text('status_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
        });
        Schema::create('hub_idea_supports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_idea_id')->constrained('hub_ideas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['hub_idea_id', 'user_id']);
        });
        Schema::create('hub_idea_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_idea_id')->constrained('hub_ideas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
        });
        Schema::create('hub_section_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 100);
            $table->json('section');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['hub_section_presets', 'hub_idea_comments', 'hub_idea_supports', 'hub_ideas', 'hub_reads'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('hub_interactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_id'));
        Schema::table('hub_preferences', fn (Blueprint $table) => $table->dropColumn(['followed_pages', 'last_seen_at']));
    }
};
