<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR P01 C1 — the one recording contract ("Record a dose: one pop-up
 * everywhere"). Every column is nullable: records made before P01, and by
 * paths that don't send the new fields, keep today's meaning.
 *
 * On the dose record:
 *  - late_reason: why a dose was recorded outside its window (one of the four
 *    approved reasons), kept apart from the not-given reason;
 *  - amount_mode / amount_reason / quantity_given: what was actually given —
 *    as ordered, less than ordered (with a reason), or more than ordered;
 *  - second_person_kind / second_person_status: who the second person was
 *    for (witness, medication rule, restricted-competency co-signer, a
 *    smaller amount) and whether it was confirmed by their PIN or recorded
 *    "Not confirmed by a second person" because nobody on the roster could;
 *  - review_reason_key: the reason a P01 record needs a house lead's review
 *    (review_required stays the flag; review_reason keeps its free text);
 *  - reoffer_of_id: a dose given (or refused again) after a re-offer points
 *    at the refusal it follows (NF-11);
 *  - effect_check_due_at: when an as-needed dose's effect is to be checked.
 *
 * A refusal follow-up gets its owner, and a medication error raised by
 * recording "more than ordered" points at that dose (one error per dose).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            $table->string('late_reason', 40)->nullable();
            $table->string('amount_mode', 20)->nullable();
            $table->string('amount_reason', 40)->nullable();
            $table->decimal('quantity_given', 10, 2)->nullable();
            $table->string('second_person_kind', 20)->nullable();
            $table->string('second_person_status', 30)->nullable();
            $table->string('review_reason_key', 50)->nullable();
            $table->unsignedBigInteger('reoffer_of_id')->nullable();
            $table->timestamp('effect_check_due_at')->nullable();

            $table->foreign('reoffer_of_id', 'cma_reoffer_of_fk')
                ->references('id')
                ->on('client_medication_administrations')
                ->nullOnDelete();
            $table->index(['review_required', 'review_reason_key'], 'cma_review_reason_key_idx');
        });

        Schema::table('medication_refusal_followups', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('created_by');
            $table->foreign('owner_id', 'med_refusal_owner_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('medication_errors', function (Blueprint $table) {
            $table->unsignedBigInteger('client_medication_administration_id')->nullable();
            $table->foreign('client_medication_administration_id', 'med_error_admin_fk')
                ->references('id')
                ->on('client_medication_administrations')
                ->nullOnDelete();
            $table->unique('client_medication_administration_id', 'med_error_admin_unique');
        });
    }

    public function down(): void
    {
        Schema::table('medication_errors', function (Blueprint $table) {
            $table->dropForeign('med_error_admin_fk');
            $table->dropUnique('med_error_admin_unique');
            $table->dropColumn('client_medication_administration_id');
        });

        Schema::table('medication_refusal_followups', function (Blueprint $table) {
            $table->dropForeign('med_refusal_owner_fk');
            $table->dropColumn('owner_id');
        });

        Schema::table('client_medication_administrations', function (Blueprint $table) {
            $table->dropForeign('cma_reoffer_of_fk');
            $table->dropIndex('cma_review_reason_key_idx');
            $table->dropColumn([
                'late_reason',
                'amount_mode',
                'amount_reason',
                'quantity_given',
                'second_person_kind',
                'second_person_status',
                'review_reason_key',
                'reoffer_of_id',
                'effect_check_due_at',
            ]);
        });
    }
};
