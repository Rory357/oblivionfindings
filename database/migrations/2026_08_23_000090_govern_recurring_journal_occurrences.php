<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_recurring_journal_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recurring_journal_id');
            $table->date('scheduled_for');
            $table->char('occurrence_key', 64);
            $table->enum('status', ['processing', 'failed', 'posted'])->default('processing');
            $table->foreignId('journal_id')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('recurring_journal_id', 'fin_recur_occ_schedule_fk')
                ->references('id')
                ->on('fin_recurring_journals')
                ->restrictOnDelete();
            $table->foreign('journal_id', 'fin_recur_occ_journal_fk')
                ->references('id')
                ->on('fin_journals')
                ->restrictOnDelete();
            $table->unique('occurrence_key', 'fin_recur_occ_key_uq');
            $table->unique(
                ['recurring_journal_id', 'scheduled_for'],
                'fin_recur_occ_schedule_due_uq',
            );
            $table->unique('journal_id', 'fin_recur_occ_journal_uq');
            $table->index(['status', 'failed_at'], 'fin_recur_occ_failure_idx');
        });

        Schema::create('fin_recurring_journal_occurrence_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occurrence_id');
            $table->uuid('attempt_key');
            $table->enum('outcome', ['posted', 'failed', 'recovered']);
            $table->foreignId('journal_id')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->foreign('occurrence_id', 'fin_recur_attempt_occurrence_fk')
                ->references('id')
                ->on('fin_recurring_journal_occurrences')
                ->restrictOnDelete();
            $table->foreign('journal_id', 'fin_recur_attempt_journal_fk')
                ->references('id')
                ->on('fin_journals')
                ->restrictOnDelete();
            $table->unique('attempt_key', 'fin_recur_attempt_key_uq');
            $table->index(
                ['occurrence_id', 'started_at'],
                'fin_recur_attempt_history_idx',
            );
        });

        // Canonical GL producers take the shared sequence mutex, then their
        // schedule/occurrence locks. This generated unique key is the database
        // backstop against any non-canonical second posted effect.
        Schema::table('fin_journals', function (Blueprint $table): void {
            $table->unsignedBigInteger('recurring_occurrence_posted_source_id')
                ->nullable()
                ->storedAs(
                    "case when `source_type` = 'App\\\\Domain\\\\Finance\\\\Models\\\\FinRecurringJournalOccurrence' and `status` = 'posted' then `source_id` else null end"
                )
                ->after('source_id');
            $table->unique(
                'recurring_occurrence_posted_source_id',
                'fin_jnl_recur_occ_posted_source_uq',
            );
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('fin_recurring_journal_occurrences')
                && DB::table('fin_recurring_journal_occurrences')->exists())
            || (Schema::hasTable('fin_recurring_journal_occurrence_attempts')
                && DB::table('fin_recurring_journal_occurrence_attempts')->exists())) {
            throw new RuntimeException(
                'Cannot remove recurring journal occurrence governance while retained occurrence or attempt evidence exists.',
            );
        }

        Schema::table('fin_journals', function (Blueprint $table): void {
            $table->dropUnique('fin_jnl_recur_occ_posted_source_uq');
            $table->dropColumn('recurring_occurrence_posted_source_id');
        });

        Schema::dropIfExists('fin_recurring_journal_occurrence_attempts');
        Schema::dropIfExists('fin_recurring_journal_occurrences');
    }
};
