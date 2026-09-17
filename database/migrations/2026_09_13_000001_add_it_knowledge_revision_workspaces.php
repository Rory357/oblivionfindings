<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_kb_articles', function (Blueprint $table): void {
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->string('document_type', 32)->default('guide');
            $table->json('structured_content')->nullable();
            $table->json('related_records')->nullable();
        });
        Schema::create('it_kb_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('it_kb_articles')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('audience', 32);
            $table->json('site_scope')->nullable();
            $table->longText('snapshot');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['article_id', 'revision_number']);
        });
        Schema::create('it_kb_working_copies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->unique()->constrained('it_kb_articles')->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('audience', 32);
            $table->json('site_scope')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->longText('snapshot');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_kb_working_copies');
        Schema::dropIfExists('it_kb_revisions');
        Schema::table('it_kb_articles', fn (Blueprint $table) => $table->dropColumn(['lock_version', 'document_type', 'structured_content', 'related_records']));
    }
};
