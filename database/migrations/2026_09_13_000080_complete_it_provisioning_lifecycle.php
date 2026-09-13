<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_provisioning_workflows', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cover_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('original_effective_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
        });
        Schema::table('it_provisioning_requests', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
            $table->smallInteger('due_offset_days')->nullable();
            $table->foreignId('primary_approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cover_approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approval_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_expires_at')->nullable();
            $table->timestamp('approval_requested_at')->nullable();
            $table->string('fulfilment_mode', 30)->nullable();
            $table->foreignId('reversal_of_request_id')->nullable()->constrained('it_provisioning_requests')->restrictOnDelete();
            $table->unique('reversal_of_request_id', 'it_prov_single_reversal');
        });
        Schema::table('it_provisioning_templates', function (Blueprint $table): void {
            $table->foreignId('published_version_id')->nullable()->constrained('it_provisioning_template_versions')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
        });
        // Freeze legacy active instructions before introducing explicit publication.
        // This is imported history, not evidence of a human review.
        DB::table('it_provisioning_templates')->where('is_active', true)->whereNull('current_version_id')
            ->orderBy('id')->each(function (object $template): void {
                $contract = [];
                foreach (['name', 'description', 'lifecycle_type', 'position_role', 'site_id', 'employment_type', 'selection_priority', 'is_active'] as $field) {
                    $contract[$field] = $template->{$field};
                }
                $contract['is_active'] = (bool) $contract['is_active'];
                $contract['selection_priority'] = (int) $contract['selection_priority'];
                $contract['tasks'] = DB::table('it_provisioning_template_tasks')->where('provisioning_template_id', $template->id)
                    ->orderBy('stage')->orderBy('sort_order')->orderBy('id')->get()->map(function (object $task): array {
                        $result = [];
                        foreach (['task_key', 'title', 'description', 'category', 'action', 'request_type', 'responsible_team_id', 'stage', 'sort_order',
                            'dependency_task_keys', 'trigger_fields', 'approval_required', 'evidence_required', 'due_offset_days', 'fulfiller_fields'] as $field) {
                            $result[$field] = $task->{$field};
                        }
                        foreach (['dependency_task_keys', 'trigger_fields', 'fulfiller_fields'] as $field) {
                            $result[$field] = $result[$field] === null ? null : json_decode($result[$field], true, 512, JSON_THROW_ON_ERROR);
                        }
                        foreach (['approval_required', 'evidence_required'] as $field) {
                            $result[$field] = (bool) $result[$field];
                        }
                        foreach (['stage', 'sort_order', 'due_offset_days'] as $field) {
                            $result[$field] = (int) $result[$field];
                        }

                        return $result;
                    })->all();
                $versionId = DB::table('it_provisioning_template_versions')->insertGetId([
                    'provisioning_template_id' => $template->id, 'version' => $template->lock_version,
                    'contract' => json_encode($contract, JSON_THROW_ON_ERROR), 'provenance' => 'legacy_current',
                    'recorded_by_user_id' => null, 'created_at' => now(),
                ]);
                DB::table('it_provisioning_templates')->where('id', $template->id)->update(['current_version_id' => $versionId]);
            });
        // Preserve the existing active contract without inventing a human review.
        DB::table('it_provisioning_templates')->where('is_active', true)
            ->whereNotNull('current_version_id')->update(['published_version_id' => DB::raw('current_version_id')]);
        Schema::table('it_catalog_items', function (Blueprint $table): void {
            $table->foreignId('provisioning_template_version_id')->nullable()
                ->constrained('it_provisioning_template_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('it_provisioning_workflows')->whereNotNull('original_effective_at')->exists()
            || DB::table('it_provisioning_workflows')->whereNotNull('cancelled_at')->exists()
            || DB::table('it_provisioning_workflows')->whereNotNull('owner_user_id')->exists()
            || DB::table('it_provisioning_workflows')->whereNotNull('cover_user_id')->exists()
            || DB::table('it_provisioning_requests')->whereNotNull('approval_requested_at')->exists()
            || DB::table('it_provisioning_requests')->whereNotNull('reversal_of_request_id')->exists()
            || DB::table('it_provisioning_requests')->whereNotNull('fulfilment_mode')->exists()
            || DB::table('it_provisioning_requests')->where('lock_version', '>', 1)->exists()
            || (Schema::hasTable('it_ticket_command_receipts') && DB::table('it_ticket_command_receipts')->where('operation', 'like', 'provisioning.%')->exists())
            || (Schema::hasTable('audit_logs') && DB::table('audit_logs')->whereIn('action', ['it.provisioning.template.published', 'it.provisioning.template.unpublished'])->exists())
            || DB::table('it_provisioning_templates')->whereNotNull('published_at')->exists()
            || DB::table('it_catalog_items')->whereNotNull('provisioning_template_version_id')->exists()) {
            throw new RuntimeException('Provisioning lifecycle evidence exists. Use a reviewed forward repair; do not discard it.');
        }
        Schema::table('it_catalog_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('provisioning_template_version_id'));
        Schema::table('it_provisioning_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_version_id');
            $table->dropColumn('published_at');
        });
        Schema::table('it_provisioning_requests', function (Blueprint $table): void {
            $table->dropUnique('it_prov_single_reversal');
            foreach (['primary_approver_user_id', 'cover_approver_user_id', 'approval_requested_by', 'reversal_of_request_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['lock_version', 'due_offset_days', 'approval_expires_at', 'approval_requested_at', 'fulfilment_mode']);
        });
        Schema::table('it_provisioning_workflows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropConstrainedForeignId('cover_user_id');
            $table->dropColumn(['lock_version', 'original_effective_at', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
