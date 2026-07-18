<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_kb_articles', function (Blueprint $table): void {
            $table->string('audience', 32)->default('all_staff')->after('status');
            $table->json('site_scope')->nullable()->after('audience');
            $table->foreignId('owner_user_id')->nullable()->after('author_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->after('owner_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('related_service_id')->nullable()->after('reviewed_by_user_id')->constrained('it_services')->nullOnDelete();
            $table->date('review_due_at')->nullable()->after('related_service_id');
            $table->timestamp('review_started_at')->nullable()->after('review_due_at');
            $table->timestamp('published_at')->nullable()->after('review_started_at');
            $table->timestamp('retired_at')->nullable()->after('published_at');
            $table->text('retirement_reason')->nullable()->after('retired_at');
            $table->unsignedInteger('deflection_count')->default(0)->after('helpful_no');

            $table->index(['tenant_id', 'audience', 'status'], 'it_kb_articles_tenant_audience_idx');
            $table->index(['tenant_id', 'review_due_at'], 'it_kb_articles_review_due_idx');
        });

        Schema::create('it_kb_interactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('it_kb_article_id')->constrained('it_kb_articles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('it_ticket_id')->nullable()->constrained('it_tickets')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('source', 32)->default('help_centre');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'it_kb_article_id', 'event_type'], 'it_kb_interactions_article_event_idx');
            $table->index(['tenant_id', 'user_id', 'occurred_at'], 'it_kb_interactions_user_time_idx');
        });

        Schema::create('it_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->uuid('notification_uuid')->unique();
            $table->foreignId('retry_of_delivery_id')->nullable()->unique()->constrained('it_email_deliveries')->nullOnDelete();
            $table->foreignId('it_ticket_id')->nullable()->constrained('it_tickets')->nullOnDelete();
            $table->foreignId('it_provisioning_request_id')->nullable()->constrained('it_provisioning_requests')->nullOnDelete();
            $table->foreignId('it_ticket_comment_id')->nullable()->constrained('it_ticket_comments')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('notification_type', 160);
            $table->json('notification_context')->nullable();
            $table->string('audience', 32)->nullable();
            $table->string('subject');
            $table->string('provider', 64)->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('provider_status_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->foreignId('last_retried_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at'], 'it_email_deliveries_status_idx');
            $table->index(['tenant_id', 'it_ticket_id', 'created_at'], 'it_email_deliveries_ticket_idx');
            $table->index(['tenant_id', 'it_provisioning_request_id', 'created_at'], 'it_email_deliveries_provisioning_idx');
        });

        Schema::create('it_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('automation_key', 120)->index();
            $table->string('schedule_expression', 100)->nullable();
            $table->string('status', 32)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->text('error_summary')->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamps();

            $table->index(['automation_key', 'status', 'started_at'], 'it_automation_runs_key_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_automation_runs');
        Schema::dropIfExists('it_email_deliveries');
        Schema::dropIfExists('it_kb_interactions');

        Schema::table('it_kb_articles', function (Blueprint $table): void {
            $table->dropIndex('it_kb_articles_tenant_audience_idx');
            $table->dropIndex('it_kb_articles_review_due_idx');
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('related_service_id');
            $table->dropColumn([
                'audience', 'site_scope', 'review_due_at', 'review_started_at',
                'published_at', 'retired_at', 'retirement_reason', 'deflection_count',
            ]);
        });
    }
};
