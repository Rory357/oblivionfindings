<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full-ticketing schema (docs/IT_TICKETING_GAP_ANALYSIS.md item 3, per the
 * pre-approved §P list):
 *
 *  §P.1  it_tickets gains reference / subcategory / source / asset +
 *        provisioning links / SLA clock columns / lifecycle stamps / CSAT.
 *  §P.2  it_ticket_comments — the conversation thread (public + internal).
 *  §P.3  it_ticket_events — polymorphic activity trail shared by tickets
 *        AND provisioning requests.
 *  §P.5  it_ticket_watchers — per-user subscriptions.
 *  §P.8  it_provisioning_requests gains priority + due_date.
 *
 * (§P.6 SLA policies land with the SLA engine; §P.7 KB with the Knowledge
 * tab; §P.4 attachments with the ticket workspace.)
 *
 * Long composite indexes are named explicitly (MySQL 64-char house rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('tenant_id');
            $table->string('subcategory')->nullable()->after('category');
            $table->string('source')->default('portal')->after('subcategory'); // portal | agent | system
            $table->foreignId('asset_id')->nullable()->after('assigned_to_user_id')
                ->constrained('assets')->nullOnDelete();
            $table->foreignId('provisioning_request_id')->nullable()->after('asset_id')
                ->constrained('it_provisioning_requests')->nullOnDelete();
            $table->datetime('first_response_due_at')->nullable();
            $table->datetime('resolution_due_at')->nullable();
            $table->datetime('first_responded_at')->nullable();
            $table->string('sla_state')->default('ok'); // ok | at_risk | breached | met
            $table->unsignedInteger('sla_paused_minutes')->default(0);
            $table->datetime('waiting_since')->nullable();
            $table->datetime('closed_at')->nullable();
            $table->unsignedTinyInteger('reopened_count')->default(0);
            $table->unsignedTinyInteger('csat_score')->nullable();
            $table->text('csat_comment')->nullable();
            $table->datetime('csat_submitted_at')->nullable();

            $table->unique(['tenant_id', 'reference'], 'it_tickets_tenant_reference_uq');
            $table->index(['tenant_id', 'sla_state'], 'it_tickets_tenant_sla_state_idx');
            $table->index(['tenant_id', 'assigned_to_user_id', 'status'], 'it_tickets_tenant_assignee_status_idx');
        });

        // Backfill: every ticket that exists when this deploys was logged by
        // an agent (storing was it.manage-gated until now), and gets a
        // per-tenant zero-padded reference in id order. New rows are stamped
        // race-safe at create time (gap-doc item 4).
        DB::table('it_tickets')->update(['source' => 'agent']);
        foreach (DB::table('it_tickets')->distinct()->pluck('tenant_id') as $tenantId) {
            $sequence = 0;
            $ids = DB::table('it_tickets')->where('tenant_id', $tenantId)->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $sequence++;
                DB::table('it_tickets')->where('id', $id)->update([
                    'reference' => sprintf('IT-%06d', $sequence),
                ]);
            }
        }

        Schema::create('it_ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['ticket_id', 'created_at'], 'it_ticket_comments_ticket_created_idx');
        });

        Schema::create('it_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->datetime('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'it_ticket_events_subject_created_idx');
        });

        Schema::create('it_ticket_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'user_id'], 'it_ticket_watchers_ticket_user_uq');
        });

        Schema::table('it_provisioning_requests', function (Blueprint $table) {
            $table->string('priority')->default('normal')->after('status'); // low | normal | high | urgent
            $table->date('due_date')->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('it_provisioning_requests', function (Blueprint $table) {
            $table->dropColumn(['priority', 'due_date']);
        });

        Schema::dropIfExists('it_ticket_watchers');
        Schema::dropIfExists('it_ticket_events');
        Schema::dropIfExists('it_ticket_comments');

        Schema::table('it_tickets', function (Blueprint $table) {
            $table->dropUnique('it_tickets_tenant_reference_uq');
            $table->dropIndex('it_tickets_tenant_sla_state_idx');
            $table->dropIndex('it_tickets_tenant_assignee_status_idx');
            $table->dropConstrainedForeignId('asset_id');
            $table->dropConstrainedForeignId('provisioning_request_id');
            $table->dropColumn([
                'reference', 'subcategory', 'source',
                'first_response_due_at', 'resolution_due_at', 'first_responded_at',
                'sla_state', 'sla_paused_minutes', 'waiting_since', 'closed_at',
                'reopened_count', 'csat_score', 'csat_comment', 'csat_submitted_at',
            ]);
        });
    }
};
