<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collisions = [
            'job requisition slug' => DB::table('hr_job_requisitions')
                ->select('slug', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('slug')
                ->havingRaw('COUNT(*) > 1')
                ->first(),
            'offer application' => DB::table('hr_offers')
                ->select('application_id', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('application_id')
                ->havingRaw('COUNT(*) > 1')
                ->first(),
            'interview kit name' => DB::table('hr_interview_kits')
                ->select('name', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('name')
                ->havingRaw('COUNT(*) > 1')
                ->first(),
            'candidate email template name' => DB::table('hr_candidate_email_templates')
                ->select('name', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('name')
                ->havingRaw('COUNT(*) > 1')
                ->first(),
        ];

        foreach ($collisions as $identity => $collision) {
            if ($collision !== null) {
                throw new RuntimeException(sprintf(
                    'Cannot enforce application recruitment identity for %s: duplicate rows exist.',
                    $identity,
                ));
            }
        }

        $this->addIndex('hr_job_requisitions', 'hr_job_req_slug_uq', fn (Blueprint $table) => $table->unique('slug', 'hr_job_req_slug_uq'));
        $this->addIndex('hr_offers', 'hr_offers_application_uq', fn (Blueprint $table) => $table->unique('application_id', 'hr_offers_application_uq'));
        $this->addIndex('hr_interview_kits', 'hr_interview_kits_name_uq', fn (Blueprint $table) => $table->unique('name', 'hr_interview_kits_name_uq'));
        $this->addIndex('hr_candidate_email_templates', 'hr_candidate_email_templates_name_uq', fn (Blueprint $table) => $table->unique('name', 'hr_candidate_email_templates_name_uq'));

        $this->addIndex('hr_candidates', 'hr_candidates_status_stage_idx', fn (Blueprint $table) => $table->index(['status', 'current_stage_entered_at'], 'hr_candidates_status_stage_idx'));
        $this->addIndex('hr_applications', 'hr_applications_role_status_idx', fn (Blueprint $table) => $table->index(['position_role', 'status'], 'hr_applications_role_status_idx'));
        $this->addIndex('hr_applications', 'hr_applications_site_status_idx', fn (Blueprint $table) => $table->index(['target_site_id', 'status'], 'hr_applications_site_status_idx'));
        $this->addIndex('hr_job_requisitions', 'hr_job_req_status_published_idx', fn (Blueprint $table) => $table->index(['status', 'published_at'], 'hr_job_req_status_published_idx'));
        $this->addIndex('hr_job_requisitions', 'hr_job_req_site_status_idx', fn (Blueprint $table) => $table->index(['site_id', 'status'], 'hr_job_req_site_status_idx'));
        $this->addIndex('hr_interview_kits', 'hr_interview_kits_active_role_idx', fn (Blueprint $table) => $table->index(['is_active', 'role'], 'hr_interview_kits_active_role_idx'));

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type]) {
                $this->dropIndex($table, $name, $type);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type, $columns]) {
                $this->addIndex($table, $name, function (Blueprint $table) use ($name, $type, $columns): void {
                    $type === 'unique'
                        ? $table->unique($columns, $name)
                        : $table->index($columns, $name);
                });
            }
        }

        foreach ([
            ['hr_interview_kits', 'hr_interview_kits_active_role_idx', 'index'],
            ['hr_job_requisitions', 'hr_job_req_site_status_idx', 'index'],
            ['hr_job_requisitions', 'hr_job_req_status_published_idx', 'index'],
            ['hr_applications', 'hr_applications_site_status_idx', 'index'],
            ['hr_applications', 'hr_applications_role_status_idx', 'index'],
            ['hr_candidates', 'hr_candidates_status_stage_idx', 'index'],
            ['hr_candidate_email_templates', 'hr_candidate_email_templates_name_uq', 'unique'],
            ['hr_interview_kits', 'hr_interview_kits_name_uq', 'unique'],
            ['hr_offers', 'hr_offers_application_uq', 'unique'],
            ['hr_job_requisitions', 'hr_job_req_slug_uq', 'unique'],
        ] as [$table, $name, $type]) {
            $this->dropIndex($table, $name, $type);
        }
    }

    /** @return array<string, list<array{string, string, list<string>}>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_candidates' => [
                ['hr_candidates_tenant_id_index', 'index', ['tenant_id']],
                ['hr_candidates_tenant_id_status_index', 'index', ['tenant_id', 'status']],
            ],
            'hr_applications' => [
                ['hr_applications_tenant_id_index', 'index', ['tenant_id']],
                ['hr_applications_tenant_id_position_role_index', 'index', ['tenant_id', 'position_role']],
            ],
            'hr_interview_kits' => [
                ['hr_interview_kits_tenant_id_index', 'index', ['tenant_id']],
                ['hr_interview_kits_tenant_id_is_active_index', 'index', ['tenant_id', 'is_active']],
                ['hr_int_kits_tenant_role_active_idx', 'index', ['tenant_id', 'role', 'is_active']],
            ],
            'hr_job_requisitions' => [
                ['hr_job_requisitions_tenant_id_index', 'index', ['tenant_id']],
                ['hr_job_requisitions_tenant_id_slug_unique', 'unique', ['tenant_id', 'slug']],
                ['hr_job_req_tenant_status_pub_idx', 'index', ['tenant_id', 'status', 'published_at']],
            ],
            'hr_candidate_email_templates' => [
                ['hr_candidate_email_templates_tenant_id_index', 'index', ['tenant_id']],
                ['hr_candidate_email_templates_tenant_id_name_index', 'index', ['tenant_id', 'name']],
            ],
            'hr_candidate_documents' => [
                ['hr_candidate_documents_tenant_id_index', 'index', ['tenant_id']],
            ],
            'hr_talent_pool' => [
                ['hr_talent_pool_tenant_id_index', 'index', ['tenant_id']],
            ],
        ];
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, string $type): void
    {
        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $type): void {
            $type === 'unique'
                ? $table->dropUnique($name)
                : $table->dropIndex($name);
        });
    }
};
