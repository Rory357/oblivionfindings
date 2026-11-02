<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Answer 20 (Stephan, 1 Nov 2026): a done investigation's findings can be corrected, with a reason, but the correction
 * goes back through independent review and approval like the original findings. The original findings are never
 * overwritten; the latest approved correction is what people see, and every proposal (approved or not) is kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hs_investigation_corrections')) {
            return;
        }

        Schema::create('hs_investigation_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hs_investigation_id')->constrained('hs_investigations', indexName: 'hs_inv_corr_investigation_fk')->restrictOnDelete();
            $table->foreignId('hs_event_id')->constrained('hs_events', indexName: 'hs_inv_corr_event_fk')->restrictOnDelete();
            $table->string('field', 40);
            $table->text('before_value')->nullable();
            $table->text('after_value');
            $table->text('reason');
            $table->string('status', 20)->index();
            $table->foreignId('proposed_by_id')->nullable()->constrained('users', indexName: 'hs_inv_corr_proposed_fk')->nullOnDelete();
            $table->string('proposed_by_name');
            $table->timestamp('proposed_at');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users', indexName: 'hs_inv_corr_reviewed_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users', indexName: 'hs_inv_corr_approved_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users', indexName: 'hs_inv_corr_rejected_fk')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['hs_investigation_id', 'field', 'status'], 'hs_inv_corr_lookup_idx');
        });
    }

    public function down(): void
    {
        // Corrections are evidence: keep them on an application rollback.
    }
};
