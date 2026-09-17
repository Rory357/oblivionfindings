<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit, immutable decision authority (GOV-R03 / GOV-R08).
 *
 * A carried resolution may only approve the exact record it was bound to
 * while the paper was still a draft. The binding records the subject
 * identity, its revision and a SHA-256 fingerprint of the canonical terms
 * the board voted on (plus the budget line, amount and direction for
 * adjustments, or the governing body and document for voting rules).
 * Approval re-derives the fingerprint from the locked subject and consumes
 * the binding exactly once. Subject columns deliberately carry no foreign
 * keys so that deleting or re-pointing a subject can never rewrite the
 * historical authority record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('governance_resolution_bindings')) {
            return;
        }

        Schema::create('governance_resolution_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_id')->constrained('resolutions')->cascadeOnDelete();
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_revision', 100)->nullable();
            $table->char('subject_fingerprint', 64);
            $table->string('governing_body', 30)->nullable();
            $table->unsignedBigInteger('board_committee_id')->nullable();
            $table->string('document_reference')->nullable();
            $table->string('document_version', 50)->nullable();
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->unsignedBigInteger('budget_line_item_id')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('direction', 20)->nullable();
            $table->json('bound_terms');
            $table->foreignId('bound_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('bound_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['resolution_id', 'subject_type', 'subject_id'], 'gov_res_bindings_subject_unique');
            $table->index(['subject_type', 'subject_id'], 'gov_res_bindings_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_resolution_bindings');
    }
};
