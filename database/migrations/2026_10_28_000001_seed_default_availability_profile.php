<?php

use App\Domain\Monitoring\Models\MonitoringProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitoring_profiles')) {
            return;
        }

        MonitoringProfile::query()->firstOrCreate(
            ['name' => MonitoringProfile::DEFAULT_AVAILABILITY_NAME],
            [
                'description' => 'Default 1-minute availability check with 3-failure confirmation.',
                'interval_seconds' => 60,
                'failure_confirmations' => 3,
                'recovery_confirmations' => 2,
                'stale_after_seconds' => 300,
                'threshold_direction' => 'above',
                'is_active' => true,
                'version' => 1,
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('monitoring_profiles')) {
            return;
        }

        MonitoringProfile::query()
            ->where('name', MonitoringProfile::DEFAULT_AVAILABILITY_NAME)
            ->whereDoesntHave('monitors')
            ->delete();
    }
};
