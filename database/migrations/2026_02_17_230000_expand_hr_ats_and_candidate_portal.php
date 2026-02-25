<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_interview_kits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('role')->nullable()->index();
            $table->json('criteria')->nullable();
            $table->text('guidance')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'role', 'is_active'], 'hr_int_kits_tenant_role_active_idx');
        });

        Schema::create('hr_job_requisitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title');
            $table->string('slug');
            $table->string('position_role')->nullable()->index();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('employment_type')->default('full_time');
            $table->unsignedInteger('openings')->default(1);
            $table->string('status')->default('draft')->index();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->longText('requirements')->nullable();
            $table->longText('responsibilities')->nullable();
            $table->foreignId('default_interview_kit_id')->nullable()->constrained('hr_interview_kits')->nullOnDelete();
            $table->foreignId('hiring_manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->date('closing_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status', 'published_at'], 'hr_job_req_tenant_status_pub_idx');
        });

        Schema::table('hr_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_applications', 'requisition_id')) {
                $table->foreignId('requisition_id')
                    ->nullable()
                    ->after('candidate_id')
                    ->constrained('hr_job_requisitions')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_applications', 'interview_kit_id')) {
                $table->foreignId('interview_kit_id')
                    ->nullable()
                    ->after('requisition_id')
                    ->constrained('hr_interview_kits')
                    ->nullOnDelete();
            }
        });

        Schema::create('hr_interview_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('hr_interviews')->cascadeOnDelete();
            $table->foreignId('kit_id')->nullable()->constrained('hr_interview_kits')->nullOnDelete();
            $table->foreignId('interviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('criteria_scores')->nullable();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->string('recommendation')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['interview_id', 'submitted_at'], 'hr_int_scores_interview_submitted_idx');
            $table->unique(['interview_id', 'interviewer_user_id'], 'hr_int_scores_interview_user_unique');
        });

        Schema::table('hr_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_offers', 'candidate_portal_token')) {
                $table->string('candidate_portal_token', 120)->nullable()->unique()->after('sent_at');
            }
            if (! Schema::hasColumn('hr_offers', 'portal_expires_at')) {
                $table->timestamp('portal_expires_at')->nullable()->after('candidate_portal_token');
            }
            if (! Schema::hasColumn('hr_offers', 'signed_full_name')) {
                $table->string('signed_full_name')->nullable()->after('response_notes');
            }
            if (! Schema::hasColumn('hr_offers', 'signed_at')) {
                $table->timestamp('signed_at')->nullable()->after('signed_full_name');
            }
            if (! Schema::hasColumn('hr_offers', 'signed_ip')) {
                $table->string('signed_ip', 45)->nullable()->after('signed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $columns = [
                'candidate_portal_token',
                'portal_expires_at',
                'signed_full_name',
                'signed_at',
                'signed_ip',
            ];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('hr_offers', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        Schema::dropIfExists('hr_interview_scores');

        Schema::table('hr_applications', function (Blueprint $table) {
            if (Schema::hasColumn('hr_applications', 'interview_kit_id')) {
                $table->dropConstrainedForeignId('interview_kit_id');
            }
            if (Schema::hasColumn('hr_applications', 'requisition_id')) {
                $table->dropConstrainedForeignId('requisition_id');
            }
        });

        Schema::dropIfExists('hr_job_requisitions');
        Schema::dropIfExists('hr_interview_kits');
    }
};

