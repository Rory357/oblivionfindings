<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════
        // PHASE 1: Foundation Fixes & New Core Tables
        // ══════════════════════════════════════════════

        // ── Action Items: Progress tracking ──
        if (!Schema::hasColumn('action_items', 'progress_pct')) {
            Schema::table('action_items', function (Blueprint $table) {
                $table->unsignedTinyInteger('progress_pct')->default(0)->after('status');
                $table->text('progress_notes')->nullable()->after('progress_pct');
                $table->timestamp('blocked_at')->nullable()->after('progress_notes');
                $table->text('blocked_reason')->nullable()->after('blocked_at');
            });
        }

        // ── Meeting RSVP ──
        if (!Schema::hasTable('meeting_rsvps')) {
            Schema::create('meeting_rsvps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('governance_meeting_id')->constrained()->cascadeOnDelete();
                $table->foreignId('board_member_id')->constrained()->cascadeOnDelete();
                $table->string('response'); // accepted, declined, tentative
                $table->text('decline_reason')->nullable();
                $table->boolean('dietary_requirements')->default(false);
                $table->text('dietary_notes')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->timestamps();

                $table->unique(['governance_meeting_id', 'board_member_id']);
            });
        }

        // ── Board Member Interests Register ──
        if (!Schema::hasTable('board_member_interests')) {
            Schema::create('board_member_interests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('board_member_id')->constrained()->cascadeOnDelete();
                $table->string('interest_type'); // financial, directorship, employment, family, property, other
                $table->string('entity_name'); // company/org name
                $table->text('description');
                $table->string('nature'); // direct, indirect
                $table->date('declared_at');
                $table->date('ceased_at')->nullable();
                $table->boolean('is_current')->default(true);
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by')->constrained('users');
                $table->timestamps();

                $table->index(['board_member_id', 'is_current']);
            });
        }

        // ── Governance Meetings: Recurring schedule + RSVP deadline ──
        if (!Schema::hasColumn('governance_meetings', 'recurring_schedule_id')) {
            Schema::table('governance_meetings', function (Blueprint $table) {
                $table->unsignedBigInteger('recurring_schedule_id')->nullable()->after('locked_by');
                $table->date('rsvp_deadline')->nullable()->after('recurring_schedule_id');
                $table->date('preread_deadline')->nullable()->after('rsvp_deadline');
            });
        }

        // ── Recurring Meeting Schedules ──
        if (!Schema::hasTable('recurring_meeting_schedules')) {
            Schema::create('recurring_meeting_schedules', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_type');
                $table->unsignedBigInteger('board_committee_id')->nullable();
                $table->string('title_template'); // e.g. "Board Meeting - {month} {year}"
                $table->string('frequency'); // monthly, bimonthly, quarterly
                $table->unsignedTinyInteger('day_of_month')->default(15);
                $table->string('time')->default('14:00'); // HH:MM
                $table->unsignedSmallInteger('duration_minutes')->default(120);
                $table->string('location')->nullable();
                $table->string('virtual_link')->nullable();
                $table->unsignedBigInteger('default_chair_id')->nullable();
                $table->unsignedBigInteger('default_secretary_id')->nullable();
                $table->unsignedTinyInteger('quorum_required')->default(50);
                $table->unsignedTinyInteger('preread_days_before')->default(7);
                $table->unsignedTinyInteger('rsvp_days_before')->default(3);
                $table->boolean('is_active')->default(true);
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->timestamps();
            });
        }

        // ══════════════════════════════════════════════
        // PHASE 2: CEO Reports, Policies, Board Pack PDF
        // ══════════════════════════════════════════════

        // ── CEO Reports (submitted before each board meeting) ──
        if (!Schema::hasTable('ceo_board_reports')) {
            Schema::create('ceo_board_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('governance_meeting_id')->constrained()->cascadeOnDelete();
                $table->foreignId('submitted_by')->constrained('users');
                $table->string('status')->default('draft'); // draft, submitted, included_in_pack
                $table->text('operational_summary')->nullable();
                $table->text('key_achievements')->nullable();
                $table->text('challenges_and_risks')->nullable();
                $table->text('staffing_update')->nullable();
                $table->text('compliance_status')->nullable();
                $table->text('financial_summary')->nullable();
                $table->text('recommendations')->nullable();
                $table->json('attachments')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('deadline')->nullable();
                $table->timestamps();

                $table->unique('governance_meeting_id');
            });
        }

        // ── Governance Policies (versioned policy framework) ──
        if (!Schema::hasTable('governance_policies')) {
            Schema::create('governance_policies', function (Blueprint $table) {
                $table->id();
                $table->string('policy_code')->unique();
                $table->string('title');
                $table->string('category'); // governance, risk, compliance, hr, health_safety, privacy, clinical, financial, operational
                $table->text('purpose')->nullable();
                $table->longText('content');
                $table->unsignedInteger('version_number')->default(1);
                $table->string('status')->default('draft'); // draft, under_review, approved, superseded, archived
                $table->foreignId('approval_resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
                $table->foreignId('owner_id')->constrained('users');
                $table->foreignId('approved_by')->nullable()->constrained('users');
                $table->timestamp('approved_at')->nullable();
                $table->date('effective_from')->nullable();
                $table->date('review_due')->nullable();
                $table->date('next_review_date')->nullable();
                $table->unsignedBigInteger('supersedes_policy_id')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['category', 'status']);
                $table->index('status');
                $table->foreign('supersedes_policy_id')->references('id')->on('governance_policies')->nullOnDelete();
            });
        }

        // ── Policy Attestations (staff acknowledge policy) ──
        if (!Schema::hasTable('policy_attestations')) {
            Schema::create('policy_attestations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('governance_policy_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('acknowledged')->default(false);
                $table->timestamp('acknowledged_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['governance_policy_id', 'user_id']);
            });
        }

        // ── Shared Evidence Library ──
        if (!Schema::hasTable('evidence_library')) {
            Schema::create('evidence_library', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('evidence_type'); // document, audit_report, certification, attestation, screenshot, system_export, policy, procedure
                $table->text('description')->nullable();
                $table->string('file_path');
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('mime_type')->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                $table->json('tags')->nullable();
                $table->foreignId('uploaded_by')->constrained('users');
                $table->timestamp('uploaded_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index('evidence_type');
                $table->index('valid_until');
            });
        }

        // ── Evidence-Obligation Pivot (many-to-many) ──
        if (!Schema::hasTable('evidence_obligation')) {
            Schema::create('evidence_obligation', function (Blueprint $table) {
                $table->id();
                $table->foreignId('evidence_library_id')->constrained('evidence_library')->cascadeOnDelete();
                $table->foreignId('compliance_obligation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('linked_by')->constrained('users');
                $table->timestamps();

                $table->unique(['evidence_library_id', 'compliance_obligation_id'], 'evidence_obligation_unique');
            });
        }

        // ── Risk Appetite Settings (board-configurable) ──
        if (!Schema::hasTable('risk_appetite_settings')) {
            Schema::create('risk_appetite_settings', function (Blueprint $table) {
                $table->id();
                $table->string('category')->unique();
                $table->unsignedTinyInteger('threshold');
                $table->text('rationale')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users');
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approval_resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
                $table->timestamps();
            });
        }

        // ══════════════════════════════════════════════
        // PHASE 3: Board Maturity & NZ Sector Alignment
        // ══════════════════════════════════════════════

        // ── Board Skills Matrix ──
        if (!Schema::hasTable('board_skills_matrix')) {
            Schema::create('board_skills_matrix', function (Blueprint $table) {
                $table->id();
                $table->foreignId('board_member_id')->constrained()->cascadeOnDelete();
                $table->string('skill_category'); // governance, finance, legal, clinical, it, hr, risk, strategy, sector_knowledge, te_tiriti, fundraising
                $table->unsignedTinyInteger('proficiency_level'); // 1=awareness, 2=working, 3=proficient, 4=expert
                $table->text('notes')->nullable();
                $table->date('assessed_at');
                $table->foreignId('assessed_by')->nullable()->constrained('users');
                $table->timestamps();

                $table->unique(['board_member_id', 'skill_category']);
            });
        }

        // ── Board Effectiveness Evaluations ──
        if (!Schema::hasTable('board_evaluations')) {
            Schema::create('board_evaluations', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('evaluation_type'); // annual_self_assessment, peer_review, external_review
                $table->unsignedSmallInteger('year');
                $table->string('status')->default('draft'); // draft, open, closed, reported
                $table->text('summary')->nullable();
                $table->json('questions')->nullable(); // [{id, category, question, type: rating|text|yesno}]
                $table->json('aggregate_results')->nullable();
                $table->text('recommendations')->nullable();
                $table->text('action_plan')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
            });
        }

        // ── Board Evaluation Responses ──
        if (!Schema::hasTable('board_evaluation_responses')) {
            Schema::create('board_evaluation_responses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('board_evaluation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('board_member_id')->constrained()->cascadeOnDelete();
                $table->json('answers')->nullable(); // [{question_id, answer, rating, comment}]
                $table->boolean('is_anonymous')->default(true);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->unique(['board_evaluation_id', 'board_member_id'], 'eval_response_unique');
            });
        }

        // ── Clinical Governance Indicators ──
        if (!Schema::hasTable('clinical_governance_indicators')) {
            Schema::create('clinical_governance_indicators', function (Blueprint $table) {
                $table->id();
                $table->string('indicator_code')->unique();
                $table->string('category'); // falls, medication_errors, pressure_injuries, restraint, infections, safeguarding, complaints
                $table->string('name');
                $table->text('definition')->nullable();
                $table->string('data_source')->nullable(); // auto-calculated from operational data
                $table->string('unit')->nullable(); // count, rate, percentage
                $table->decimal('target_value', 10, 2)->nullable();
                $table->decimal('warning_threshold', 10, 2)->nullable();
                $table->decimal('critical_threshold', 10, 2)->nullable();
                $table->string('frequency')->default('monthly'); // monthly, quarterly
                $table->boolean('is_automated')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // ── Clinical Governance Snapshots (periodic captures) ──
        if (!Schema::hasTable('clinical_governance_snapshots')) {
            Schema::create('clinical_governance_snapshots', function (Blueprint $table) {
                $table->id();
                $table->date('period_start');
                $table->date('period_end');
                $table->string('period_type'); // monthly, quarterly
                $table->json('indicator_values'); // [{indicator_id, value, status: normal|warning|critical, trend: up|down|stable}]
                $table->json('summary')->nullable();
                $table->text('narrative')->nullable(); // Board-level clinical governance narrative
                $table->foreignId('captured_by')->nullable()->constrained('users');
                $table->timestamps();

                $table->unique(['period_start', 'period_type']);
            });
        }

        // ── Service User Feedback (governance escalation pathway) ──
        if (!Schema::hasTable('governance_feedback_escalations')) {
            Schema::create('governance_feedback_escalations', function (Blueprint $table) {
                $table->id();
                $table->string('source_type'); // complaint, feedback, survey, advocacy
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('title');
                $table->text('description');
                $table->string('severity'); // low, medium, high, critical
                $table->string('status')->default('open'); // open, investigating, resolved, escalated_to_board
                $table->text('resolution_notes')->nullable();
                $table->foreignId('assigned_to')->nullable()->constrained('users');
                $table->foreignId('escalated_by')->nullable()->constrained('users');
                $table->timestamp('escalated_at')->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained('users');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'severity']);
            });
        }

        // ── Te Tiriti Obligations ──
        if (!Schema::hasTable('te_tiriti_obligations')) {
            Schema::create('te_tiriti_obligations', function (Blueprint $table) {
                $table->id();
                $table->string('principle'); // partnership, participation, protection, equity, options
                $table->string('title');
                $table->text('description');
                $table->string('status')->default('not_started'); // not_started, in_progress, achieved, ongoing
                $table->text('evidence')->nullable();
                $table->text('actions_taken')->nullable();
                $table->date('target_date')->nullable();
                $table->unsignedTinyInteger('progress_pct')->default(0);
                $table->foreignId('owner_id')->nullable()->constrained('users');
                $table->timestamps();

                $table->index('principle');
                $table->index('status');
            });
        }

        // ── Board Members: Skills & succession fields ──
        if (!Schema::hasColumn('board_members', 'succession_priority')) {
            Schema::table('board_members', function (Blueprint $table) {
                $table->string('succession_priority')->nullable()->after('expertise_areas'); // critical, important, standard
                $table->text('succession_notes')->nullable()->after('succession_priority');
                $table->date('induction_completed_at')->nullable()->after('succession_notes');
            });
        }

        // ── Governance Meetings: CEO report linkage ──
        if (!Schema::hasColumn('governance_meetings', 'ceo_report_deadline')) {
            Schema::table('governance_meetings', function (Blueprint $table) {
                $table->date('ceo_report_deadline')->nullable()->after('preread_deadline');
            });
        }

        // ── Document Library ──
        if (!Schema::hasTable('governance_documents')) {
            Schema::create('governance_documents', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('document_type'); // constitution, terms_of_reference, policy, procedure, template, report, certificate
                $table->string('category')->nullable(); // governance, risk, compliance, hr, finance, clinical
                $table->text('description')->nullable();
                $table->string('file_path');
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedInteger('version_number')->default(1);
                $table->foreignId('uploaded_by')->constrained('users');
                $table->date('effective_from')->nullable();
                $table->date('expires_at')->nullable();
                $table->boolean('is_current')->default(true);
                $table->unsignedBigInteger('supersedes_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['document_type', 'is_current']);
                $table->foreign('supersedes_id')->references('id')->on('governance_documents')->nullOnDelete();
            });
        }

        // ── Incident Governance Escalations (auto-escalation from incidents) ──
        if (!Schema::hasTable('incident_governance_escalations')) {
            Schema::create('incident_governance_escalations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_incident_id');
                $table->foreignId('notifiable_incident_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('risk_register_entry_id')->nullable()->constrained()->nullOnDelete();
                $table->string('escalation_reason'); // death, serious_harm, pattern_detected, regulatory_breach
                $table->string('status')->default('pending'); // pending, acknowledged, actioned, closed
                $table->foreignId('notified_chair')->nullable()->constrained('users');
                $table->foreignId('notified_ceo')->nullable()->constrained('users');
                $table->timestamp('chair_notified_at')->nullable();
                $table->timestamp('ceo_notified_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->foreignId('acknowledged_by')->nullable()->constrained('users');
                $table->text('action_taken')->nullable();
                $table->timestamps();

                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_governance_escalations');
        Schema::dropIfExists('governance_documents');
        Schema::dropIfExists('te_tiriti_obligations');
        Schema::dropIfExists('governance_feedback_escalations');
        Schema::dropIfExists('clinical_governance_snapshots');
        Schema::dropIfExists('clinical_governance_indicators');
        Schema::dropIfExists('board_evaluation_responses');
        Schema::dropIfExists('board_evaluations');
        Schema::dropIfExists('board_skills_matrix');
        Schema::dropIfExists('risk_appetite_settings');
        Schema::dropIfExists('evidence_obligation');
        Schema::dropIfExists('evidence_library');
        Schema::dropIfExists('policy_attestations');
        Schema::dropIfExists('governance_policies');
        Schema::dropIfExists('ceo_board_reports');
        Schema::dropIfExists('recurring_meeting_schedules');
        Schema::dropIfExists('meeting_rsvps');
        Schema::dropIfExists('board_member_interests');

        if (Schema::hasColumn('action_items', 'progress_pct')) {
            Schema::table('action_items', function (Blueprint $table) {
                $table->dropColumn(['progress_pct', 'progress_notes', 'blocked_at', 'blocked_reason']);
            });
        }

        if (Schema::hasColumn('governance_meetings', 'recurring_schedule_id')) {
            Schema::table('governance_meetings', function (Blueprint $table) {
                $table->dropColumn(['recurring_schedule_id', 'rsvp_deadline', 'preread_deadline', 'ceo_report_deadline']);
            });
        }

        if (Schema::hasColumn('board_members', 'succession_priority')) {
            Schema::table('board_members', function (Blueprint $table) {
                $table->dropColumn(['succession_priority', 'succession_notes', 'induction_completed_at']);
            });
        }
    }
};
