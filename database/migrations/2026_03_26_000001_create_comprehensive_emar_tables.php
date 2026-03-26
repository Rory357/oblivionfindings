<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Medication Rounds ─────────────────────────────────
        Schema::create('medication_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_context_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('name'); // e.g. "Morning Round", "Lunchtime Round"
            $table->string('round_type')->default('scheduled'); // scheduled, prn, controlled
            $table->time('scheduled_time'); // e.g. 08:00
            $table->integer('window_minutes')->default(60); // +/- window for on-time
            $table->date('round_date');
            $table->string('status')->default('pending'); // pending, in_progress, completed, partial
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_medications')->default(0);
            $table->integer('administered_count')->default(0);
            $table->integer('refused_count')->default(0);
            $table->integer('withheld_count')->default(0);
            $table->integer('missed_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['round_date', 'scheduled_time']);
            $table->index(['status', 'round_date']);
        });

        // Link administrations to rounds
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medication_administrations', 'medication_round_id')) {
                $table->foreignId('medication_round_id')->nullable()->after('shift_id')
                    ->constrained('medication_rounds')->nullOnDelete();
            }
        });

        // ─── Medication Round Templates ────────────────────────
        Schema::create('medication_round_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_context_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('name');
            $table->time('scheduled_time');
            $table->integer('window_minutes')->default(60);
            $table->json('days_of_week')->nullable(); // [1,2,3,4,5,6,7] or null = every day
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ─── Prescriber Orders ─────────────────────────────────
        Schema::create('medication_prescriber_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->nullOnDelete();
            $table->string('order_type'); // new, change, cease, suspend, resume, verbal, telephone
            $table->string('status')->default('pending'); // pending, confirmed, dispensed, cancelled, expired
            $table->string('prescriber_name');
            $table->string('prescriber_registration')->nullable(); // MCNZ number
            $table->string('prescriber_type')->default('gp'); // gp, specialist, nurse_practitioner, pharmacist
            $table->string('medication_name');
            $table->string('dose')->nullable();
            $table->string('route')->nullable();
            $table->string('frequency')->nullable();
            $table->text('instructions')->nullable();
            $table->text('indication')->nullable();
            $table->text('clinical_notes')->nullable();
            $table->date('order_date');
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('requires_countersign')->default(false); // verbal/telephone orders
            $table->timestamp('countersigned_at')->nullable();
            $table->foreignId('countersigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispensed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispensed_at')->nullable();
            $table->text('pharmacy_notes')->nullable();
            $table->string('pharmacy_name')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('batch_expiry')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['order_date']);
        });

        // ─── Medication Reviews ────────────────────────────────
        Schema::create('medication_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('review_type'); // routine, triggered, comprehensive, admission, discharge, incident
            $table->string('status')->default('scheduled'); // scheduled, overdue, in_progress, completed, cancelled
            $table->date('scheduled_date');
            $table->date('completed_date')->nullable();
            $table->string('reviewer_name')->nullable();
            $table->string('reviewer_role')->nullable(); // pharmacist, gp, nurse, specialist
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('trigger_reason')->nullable(); // Why this review was triggered
            $table->json('medications_reviewed')->nullable(); // Array of {medication_id, outcome, notes}
            $table->text('clinical_summary')->nullable();
            $table->text('recommendations')->nullable();
            $table->json('actions')->nullable(); // Array of follow-up actions
            $table->boolean('whanau_involved')->default(false);
            $table->text('whanau_notes')->nullable();
            $table->date('next_review_date')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['scheduled_date']);
        });

        // ─── Self-Administration Assessments ───────────────────
        Schema::create('medication_self_admin_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft'); // draft, completed, reviewed
            $table->string('outcome')->nullable(); // independent, prompted, supervised, administered
            // Assessment criteria (1-5 scale or yes/no)
            $table->integer('cognitive_capacity')->nullable(); // 1-5
            $table->integer('physical_dexterity')->nullable();
            $table->integer('vision_ability')->nullable();
            $table->integer('swallowing_ability')->nullable();
            $table->integer('understanding_score')->nullable();
            $table->boolean('can_identify_medications')->default(false);
            $table->boolean('can_read_labels')->default(false);
            $table->boolean('can_open_packaging')->default(false);
            $table->boolean('can_manage_timing')->default(false);
            $table->boolean('can_store_safely')->default(false);
            $table->boolean('willing_to_self_admin')->default(false);
            $table->text('risk_factors')->nullable();
            $table->text('support_needed')->nullable();
            $table->text('safe_storage_notes')->nullable();
            $table->text('assessor_notes')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('assessment_date')->nullable();
            $table->date('reassessment_date')->nullable();
            $table->string('reassessment_trigger')->nullable(); // scheduled, clinical_change, incident, request
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        // ─── Medication Competency Assessments ─────────────────
        Schema::create('medication_competency_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Staff being assessed
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assessment_type'); // initial, annual, refresher, remedial, observed_practice
            $table->string('status')->default('pending'); // pending, in_progress, passed, failed, expired
            $table->date('assessment_date')->nullable();
            $table->date('expiry_date')->nullable();
            // Competency areas (pass/fail or score)
            $table->boolean('medication_knowledge')->nullable();
            $table->boolean('five_rights')->nullable(); // Right patient, drug, dose, route, time
            $table->boolean('safety_checks')->nullable();
            $table->boolean('documentation')->nullable();
            $table->boolean('controlled_drugs')->nullable();
            $table->boolean('prn_assessment')->nullable();
            $table->boolean('insulin_competent')->nullable();
            $table->boolean('inhaler_competent')->nullable();
            $table->boolean('topical_competent')->nullable();
            $table->boolean('covert_admin_knowledge')->nullable();
            $table->boolean('error_reporting')->nullable();
            $table->boolean('allergy_awareness')->nullable();
            $table->integer('total_score')->nullable();
            $table->integer('pass_threshold')->nullable();
            $table->text('strengths')->nullable();
            $table->text('areas_for_improvement')->nullable();
            $table->text('action_plan')->nullable();
            $table->text('assessor_comments')->nullable();
            $table->json('observed_rounds')->nullable(); // IDs of observed rounds
            $table->boolean('can_administer_unsupervised')->default(false);
            $table->boolean('can_witness_controlled')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['expiry_date']);
        });

        // ─── Medication Destruction Records ────────────────────
        Schema::create('medication_destructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('medication_name');
            $table->string('form')->nullable(); // tablet, liquid, patch, etc.
            $table->string('strength')->nullable();
            $table->decimal('quantity', 10, 2);
            $table->string('unit')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reason'); // expired, ceased, contaminated, damaged, deceased, discharged, surplus
            $table->string('disposal_method'); // pharmacy_return, incineration, denaturing, sharps_bin, other
            $table->boolean('is_controlled_drug')->default(false);
            $table->string('controlled_drug_class')->nullable(); // B, C
            $table->string('authorised_by_name')->nullable(); // pharmacist name for controlled drugs
            $table->string('authorised_by_registration')->nullable();
            $table->foreignId('destroyed_by')->constrained('users');
            $table->foreignId('witness_1_id')->constrained('users');
            $table->foreignId('witness_2_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('destroyed_at');
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable(); // Evidence photo
            $table->timestamps();

            $table->index(['client_id']);
            $table->index(['destroyed_at']);
            $table->index(['is_controlled_drug']);
        });

        // ─── Medication Handover Records ───────────────────────
        Schema::create('medication_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('service_context_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('outgoing_user_id')->constrained('users');
            $table->foreignId('incoming_user_id')->constrained('users');
            $table->timestamp('handover_at');
            // Controlled drug counts
            $table->json('controlled_drug_counts')->nullable(); // [{medication_id, expected, actual, discrepancy}]
            $table->boolean('controlled_drugs_verified')->default(false);
            // Outstanding items
            $table->json('outstanding_medications')->nullable(); // Meds not given this shift
            $table->json('new_prescriptions')->nullable(); // New orders this shift
            $table->json('ceased_medications')->nullable(); // Stopped this shift
            $table->json('incidents')->nullable(); // Medication incidents this shift
            $table->json('prn_given')->nullable(); // PRN meds given this shift
            $table->json('flagged_clients')->nullable(); // Clients needing attention
            $table->text('general_notes')->nullable();
            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'handover_at']);
        });

        // ─── Pharmacy Integration ──────────────────────────────
        Schema::create('medication_pharmacy_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->nullOnDelete();
            $table->string('pharmacy_name');
            $table->string('pharmacy_phone')->nullable();
            $table->string('pharmacy_email')->nullable();
            $table->string('order_type')->default('repeat'); // new, repeat, urgent, stat
            $table->string('status')->default('draft'); // draft, submitted, confirmed, dispensed, delivered, cancelled
            $table->text('order_notes')->nullable();
            $table->foreignId('ordered_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('dispensed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('quantity_ordered')->nullable();
            $table->integer('quantity_received')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('batch_expiry')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        // ─── Covert Medication Records ─────────────────────────
        Schema::create('medication_covert_authorisations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->string('authorised_by_name'); // prescriber
            $table->string('authorised_by_registration')->nullable();
            $table->text('clinical_justification');
            $table->text('legal_basis')->nullable(); // e.g. best interests, PPPR Act
            $table->string('administration_method')->nullable(); // crushed in food, dissolved in drink, etc.
            $table->text('pharmacist_advice')->nullable();
            $table->date('authorised_date');
            $table->date('review_date');
            $table->string('status')->default('active'); // active, expired, revoked
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        // ─── PRN Effectiveness Reviews ─────────────────────────
        Schema::create('medication_prn_effectiveness', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_medication_administration_id');
            $table->foreign('client_medication_administration_id', 'med_prn_eff_admin_id_fk')
                ->references('id')->on('client_medication_administrations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_medication_id')->constrained('client_medications')->cascadeOnDelete();
            $table->string('effectiveness'); // effective, partially_effective, not_effective
            $table->integer('review_minutes_after')->default(30); // Reviewed X minutes after admin
            $table->text('observations')->nullable();
            $table->boolean('escalation_needed')->default(false);
            $table->text('escalation_action')->nullable();
            $table->foreignId('reviewed_by')->constrained('users');
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['client_medication_administration_id'], 'med_prn_eff_admin_id_idx');
        });

        // ─── Add review_date & self_admin fields to clients ────
        Schema::table('client_medications', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medications', 'covert')) {
                $table->boolean('covert')->default(false)->after('witness_required');
            }
            if (!Schema::hasColumn('client_medications', 'self_administered')) {
                $table->boolean('self_administered')->default(false)->after('covert');
            }
            if (!Schema::hasColumn('client_medications', 'barcode')) {
                $table->string('barcode')->nullable()->after('pharmacy');
            }
            if (!Schema::hasColumn('client_medications', 'nzulm_code')) {
                $table->string('nzulm_code')->nullable()->after('barcode'); // NZ Universal List of Medicines code
            }
            if (!Schema::hasColumn('client_medications', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('nzulm_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_medications', function (Blueprint $table) {
            $columns = ['covert', 'self_administered', 'barcode', 'nzulm_code', 'photo_path'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('client_medications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (Schema::hasColumn('client_medication_administrations', 'medication_round_id')) {
                $table->dropForeign(['medication_round_id']);
                $table->dropColumn('medication_round_id');
            }
        });

        Schema::dropIfExists('medication_prn_effectiveness');
        Schema::dropIfExists('medication_covert_authorisations');
        Schema::dropIfExists('medication_pharmacy_orders');
        Schema::dropIfExists('medication_handovers');
        Schema::dropIfExists('medication_destructions');
        Schema::dropIfExists('medication_competency_assessments');
        Schema::dropIfExists('medication_self_admin_assessments');
        Schema::dropIfExists('medication_reviews');
        Schema::dropIfExists('medication_prescriber_orders');
        Schema::dropIfExists('medication_round_templates');
        Schema::dropIfExists('medication_rounds');
    }
};
