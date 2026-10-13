<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->boolean('hub_managed')->default(false));
        Schema::create('hub_event_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->uuid('draft_key');
            $table->foreignId('event_id')->nullable()->constrained('hr_calendar_events');
            $table->unsignedInteger('base_edit_version')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('state', 30)->default('draft');
            $table->json('payload');
            $table->text('feedback')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'draft_key']);
            $table->index(['state', 'updated_at']);
        });
        Schema::create('hub_event_draft_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('hub_event_drafts')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size');
            $table->boolean('retained')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_event_draft_files');
        Schema::dropIfExists('hub_event_drafts');
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->dropColumn('hub_managed'));
    }
};
