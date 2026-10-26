<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job 3 (Stephan, 26 Oct): when a source record changes after an H&S event's WorkSafe decision was signed, or raises a
 * closed event's severity, the event is flagged for a person to review. Nothing changes the signed decision or
 * reopens the event automatically. A flag holds no narrative or clinical text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_event_review_flags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hs_event_id')->constrained('hs_events')->restrictOnDelete();
            $t->string('reason', 40);
            $t->string('source_type', 64);
            $t->unsignedBigInteger('source_id');
            $t->unsignedInteger('source_revision')->nullable();
            $t->string('from_severity', 16)->nullable();
            $t->string('to_severity', 16)->nullable();
            $t->timestamp('raised_at', 6);
            $t->timestamp('resolved_at', 6)->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('resolution_note')->nullable();
            $t->timestamps(6);
            $t->unique(['hs_event_id', 'reason', 'source_type', 'source_id', 'source_revision'], 'hs_review_flag_uq');
            $t->index(['resolved_at', 'hs_event_id'], 'hs_review_flag_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_event_review_flags');
    }
};
