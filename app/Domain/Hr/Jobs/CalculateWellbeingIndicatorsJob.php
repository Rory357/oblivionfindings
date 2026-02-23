<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Services\WellbeingIndicatorService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CalculateWellbeingIndicatorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(): void
    {
        $tenantIds = $this->tenantId
            ? collect([$this->tenantId])
            : (
                Schema::hasColumn('users', 'tenant_id')
                    ? User::select('tenant_id')
                        ->whereNotNull('tenant_id')
                        ->distinct()
                        ->pluck('tenant_id')
                    : collect([null])
            );

        foreach ($tenantIds as $tenantId) {
            $this->calculateForTenant($tenantId !== null ? (int) $tenantId : null);
        }
    }

    private function calculateForTenant(?int $tenantId): void
    {
        $periodEnd = now();
        $periodStart = now()->subWeeks(4)->startOfDay();

        $processed = app(WellbeingIndicatorService::class)->calculateAllIndicators(
            tenantId: $tenantId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );

        Log::info("Wellbeing indicators calculated for tenant " . ($tenantId ?? 'global') . ": {$processed} employees processed.");
    }
}
