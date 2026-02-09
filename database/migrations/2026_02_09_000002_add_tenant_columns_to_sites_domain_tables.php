<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addTenantColumn('site_checklist_templates');
        $this->addTenantColumn('site_checklist_assignments');
        $this->addTenantColumn('site_checklist_runs');
        $this->addTenantColumn('site_checklist_responses');
        $this->addTenantColumn('site_vendors');
        $this->addTenantColumn('site_credentials');
        $this->addTenantColumn('site_house_rooms');
        $this->addTenantColumn('site_house_room_history');
        $this->addTenantColumn('site_ho_resources');
        $this->addTenantColumn('site_ho_settings');
        $this->addTenantColumn('site_facility_zones');
        $this->addTenantColumn('site_inspection_schedules');
        $this->addTenantColumn('site_inspection_records');
        $this->addTenantColumn('site_credential_audit_logs');

        $this->backfillFromSite('site_checklist_assignments');
        $this->backfillFromSite('site_checklist_runs');
        $this->backfillFromSite('site_vendors');
        $this->backfillFromSite('site_credentials');
        $this->backfillFromSite('site_house_rooms');
        $this->backfillFromSite('site_ho_resources');
        $this->backfillFromSite('site_ho_settings');
        $this->backfillFromSite('site_facility_zones');
        $this->backfillFromSite('site_inspection_schedules');
        $this->backfillFromSite('site_inspection_records');

        // checklist responses inherit tenancy from their run
        if (Schema::hasTable('site_checklist_responses')) {
            DB::table('site_checklist_responses')
                ->whereNull('tenant_id')
                ->orderBy('id')
                ->chunkById(500, function ($responses) {
                    $runIds = $responses->pluck('run_id')->filter()->unique()->values();
                    $runTenantMap = DB::table('site_checklist_runs')
                        ->whereIn('id', $runIds)
                        ->pluck('tenant_id', 'id');

                    foreach ($responses as $response) {
                        $tenantId = $runTenantMap[$response->run_id] ?? null;
                        if ($tenantId !== null) {
                            DB::table('site_checklist_responses')
                                ->where('id', $response->id)
                                ->update(['tenant_id' => $tenantId]);
                        }
                    }
                });
        }

        if (Schema::hasTable('site_house_room_history')) {
            DB::table('site_house_room_history')
                ->whereNull('tenant_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $roomIds = $rows->pluck('room_id')->filter()->unique()->values();
                    $roomTenantMap = DB::table('site_house_rooms')
                        ->whereIn('id', $roomIds)
                        ->pluck('tenant_id', 'id');

                    foreach ($rows as $row) {
                        $tenantId = $roomTenantMap[$row->room_id] ?? null;
                        if ($tenantId !== null) {
                            DB::table('site_house_room_history')
                                ->where('id', $row->id)
                                ->update(['tenant_id' => $tenantId]);
                        }
                    }
                });
        }

        if (Schema::hasTable('site_credential_audit_logs')) {
            DB::table('site_credential_audit_logs')
                ->whereNull('tenant_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $credentialIds = $rows->pluck('credential_id')->filter()->unique()->values();
                    $credentialTenantMap = DB::table('site_credentials')
                        ->whereIn('id', $credentialIds)
                        ->pluck('tenant_id', 'id');

                    foreach ($rows as $row) {
                        $tenantId = $credentialTenantMap[$row->credential_id] ?? null;
                        if ($tenantId !== null) {
                            DB::table('site_credential_audit_logs')
                                ->where('id', $row->id)
                                ->update(['tenant_id' => $tenantId]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        $this->dropTenantColumn('site_credential_audit_logs');
        $this->dropTenantColumn('site_inspection_records');
        $this->dropTenantColumn('site_inspection_schedules');
        $this->dropTenantColumn('site_facility_zones');
        $this->dropTenantColumn('site_ho_settings');
        $this->dropTenantColumn('site_ho_resources');
        $this->dropTenantColumn('site_house_room_history');
        $this->dropTenantColumn('site_house_rooms');
        $this->dropTenantColumn('site_credentials');
        $this->dropTenantColumn('site_vendors');
        $this->dropTenantColumn('site_checklist_responses');
        $this->dropTenantColumn('site_checklist_runs');
        $this->dropTenantColumn('site_checklist_assignments');
        $this->dropTenantColumn('site_checklist_templates');
    }

    private function addTenantColumn(string $table): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('tenant_id')->nullable()->index();
        });
    }

    private function dropTenantColumn(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('tenant_id');
        });
    }

    private function backfillFromSite(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'site_id') || !Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        DB::table($table)
            ->whereNull('tenant_id')
            ->whereNotNull('site_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table) {
                $siteIds = $rows->pluck('site_id')->filter()->unique()->values();
                $siteTenantMap = DB::table('sites')
                    ->whereIn('id', $siteIds)
                    ->pluck('tenant_id', 'id');

                foreach ($rows as $row) {
                    $tenantId = $siteTenantMap[$row->site_id] ?? null;
                    if ($tenantId !== null) {
                        DB::table($table)->where('id', $row->id)->update([
                            'tenant_id' => $tenantId,
                        ]);
                    }
                }
            });
    }
};
