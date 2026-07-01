<?php

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Services\CycleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Seed default OKR cycles per tenant and backfill existing objectives into the
 * quarter their window falls in. Runs on deploy (deploys run migrations but
 * skip seeders), so the new cycle spine is populated in production too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_goals') || ! Schema::hasTable('hr_goal_cycles')) {
            return;
        }

        $service = app(CycleService::class);

        $tenantIds = HrGoal::query()->distinct()->pluck('tenant_id')->filter()->values()->all();
        if ($tenantIds === []) {
            $tenantIds = [1];
        }

        foreach ($tenantIds as $tenantId) {
            $service->seedDefaults($tenantId);
            $service->backfillGoals($tenantId);
        }
    }

    public function down(): void
    {
        // Cycles are dropped with their table; nothing to reverse here.
    }
};
