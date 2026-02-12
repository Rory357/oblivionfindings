<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Resolutions: Decision proposal fields ──
        if (!Schema::hasColumn('resolutions', 'decision_type')) {
            Schema::table('resolutions', function (Blueprint $table) {
                $table->string('decision_type')->default('resolution')->after('title'); // resolution, budget_approval, policy_change, risk_acceptance
                $table->json('cost_impact')->nullable()->after('recommendation'); // { amount, currency, description }
                $table->json('risk_impact')->nullable()->after('cost_impact'); // { level, description }
                $table->json('attachments')->nullable()->after('risk_impact'); // [{ name, path, type }]
                $table->json('follow_up_actions')->nullable()->after('outcome_notes'); // auto-generated action items
                $table->boolean('auto_generate_actions')->default(false)->after('follow_up_actions');
            });
        }

        // ── Governance Meetings: Lock mechanism ──
        if (!Schema::hasColumn('governance_meetings', 'locked_at')) {
            Schema::table('governance_meetings', function (Blueprint $table) {
                $table->timestamp('locked_at')->nullable()->after('minutes_signed_by');
                $table->unsignedBigInteger('locked_by')->nullable()->after('locked_at');
            });
        }

        // ── Meeting Minutes: Enhanced state machine ──
        if (!Schema::hasColumn('meeting_minutes', 'signed_by')) {
            Schema::table('meeting_minutes', function (Blueprint $table) {
                $table->unsignedBigInteger('signed_by')->nullable()->after('review_notes');
                $table->timestamp('signed_at')->nullable()->after('signed_by');
                $table->timestamp('archived_at')->nullable()->after('signed_at');
            });
        }

        // ── Board Packs: Immutability ──
        if (!Schema::hasColumn('board_packs', 'frozen_at')) {
            Schema::table('board_packs', function (Blueprint $table) {
                $table->timestamp('frozen_at')->nullable()->after('distributed_at');
                $table->string('content_hash')->nullable()->after('frozen_at');
                $table->json('download_log')->nullable()->after('content_hash'); // [{ user_id, downloaded_at, ip }]
            });
        }

        // ── Performance Reviews: 360 feedback ──
        if (!Schema::hasTable('performance_feedback')) {
            Schema::create('performance_feedback', function (Blueprint $table) {
                $table->id();
                $table->foreignId('performance_review_id')->constrained()->cascadeOnDelete();
                $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
                $table->string('reviewer_role'); // board_member, peer, direct_report, self
                $table->json('ratings')->nullable(); // { category: score }
                $table->text('strengths')->nullable();
                $table->text('areas_for_improvement')->nullable();
                $table->text('comments')->nullable();
                $table->boolean('is_anonymous')->default(false);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
            });
        }

        // ── Notifiable Incidents (NZ regulatory) ──
        if (!Schema::hasTable('notifiable_incidents')) {
            Schema::create('notifiable_incidents', function (Blueprint $table) {
                $table->id();
                $table->string('incident_type'); // death, serious_harm, serious_injury, health_safety, privacy_breach
                $table->string('notification_authority'); // worksafe, health_nz, privacy_commissioner, charities_services
                $table->string('title');
                $table->text('description');
                $table->foreignId('related_incident_id')->nullable(); // link to client_incidents
                $table->string('severity'); // critical, high, medium
                $table->string('status')->default('pending'); // pending, notified, acknowledged, closed
                $table->timestamp('occurred_at');
                $table->timestamp('discovered_at')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->string('notification_reference')->nullable(); // external reference from authority
                $table->foreignId('notified_by')->nullable()->constrained('users');
                $table->foreignId('submitted_by')->constrained('users');
                $table->json('evidence')->nullable(); // [{ name, path, type }]
                $table->text('outcome')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // ── Governance Change Log ──
        if (!Schema::hasTable('governance_change_log')) {
            Schema::create('governance_change_log', function (Blueprint $table) {
                $table->id();
                $table->string('change_type'); // board_member_appointed, board_member_removed, role_changed, policy_updated, key_person_changed
                $table->string('entity_type'); // BoardMember, Resolution, StrategicPlan, etc.
                $table->unsignedBigInteger('entity_id');
                $table->foreignId('user_id')->constrained();
                $table->string('description');
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address')->nullable();
                $table->timestamps();

                $table->index(['entity_type', 'entity_id']);
                $table->index('change_type');
            });
        }

        // ── Governance Audit Log (view/download tracking) ──
        if (!Schema::hasTable('governance_audit_log')) {
            Schema::create('governance_audit_log', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained();
                $table->string('action'); // viewed, downloaded, edited, approved, voted, exported
                $table->string('resource_type'); // BoardPack, MeetingMinute, Resolution, etc.
                $table->unsignedBigInteger('resource_id');
                $table->json('metadata')->nullable(); // { ip, user_agent, reason }
                $table->string('ip_address')->nullable();
                $table->timestamps();

                $table->index(['resource_type', 'resource_id']);
                $table->index('action');
            });
        }

        // ── Risk Heatmap Snapshots (trend tracking) ──
        if (!Schema::hasTable('risk_heatmap_snapshots')) {
            Schema::create('risk_heatmap_snapshots', function (Blueprint $table) {
                $table->id();
                $table->date('snapshot_date');
                $table->json('heatmap_data'); // { cells: [{ likelihood, impact, count, risk_ids }] }
                $table->json('summary'); // { critical, high, medium, low, above_appetite }
                $table->json('by_category')->nullable(); // { category: { count, avg_score } }
                $table->unsignedBigInteger('captured_by')->nullable();
                $table->timestamps();

                $table->unique('snapshot_date');
            });
        }

        // ── Strategic Plan Change Snapshots ──
        if (!Schema::hasColumn('strategic_plans', 'last_snapshot')) {
            Schema::table('strategic_plans', function (Blueprint $table) {
                $table->json('last_snapshot')->nullable()->after('version_notes'); // snapshot of goals/progress at last meeting
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_heatmap_snapshots');
        Schema::dropIfExists('governance_audit_log');
        Schema::dropIfExists('governance_change_log');
        Schema::dropIfExists('notifiable_incidents');
        Schema::dropIfExists('performance_feedback');

        if (Schema::hasColumn('resolutions', 'decision_type')) {
            Schema::table('resolutions', function (Blueprint $table) {
                $table->dropColumn(['decision_type', 'cost_impact', 'risk_impact', 'attachments', 'follow_up_actions', 'auto_generate_actions']);
            });
        }

        if (Schema::hasColumn('governance_meetings', 'locked_at')) {
            Schema::table('governance_meetings', function (Blueprint $table) {
                $table->dropColumn(['locked_at', 'locked_by']);
            });
        }

        if (Schema::hasColumn('meeting_minutes', 'signed_by')) {
            Schema::table('meeting_minutes', function (Blueprint $table) {
                $table->dropColumn(['signed_by', 'signed_at', 'archived_at']);
            });
        }

        if (Schema::hasColumn('board_packs', 'frozen_at')) {
            Schema::table('board_packs', function (Blueprint $table) {
                $table->dropColumn(['frozen_at', 'content_hash', 'download_log']);
            });
        }

        if (Schema::hasColumn('strategic_plans', 'last_snapshot')) {
            Schema::table('strategic_plans', function (Blueprint $table) {
                $table->dropColumn('last_snapshot');
            });
        }
    }
};
