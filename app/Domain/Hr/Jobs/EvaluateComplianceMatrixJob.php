<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EvaluateComplianceMatrixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(ComplianceMatrixService $service): void
    {
        // If tenant specified, evaluate for that tenant only. Otherwise all tenants.
        if ($this->tenantId) {
            $count = $service->evaluateAllStaff($this->tenantId);
            Log::info("Compliance matrix evaluated for tenant {$this->tenantId}: {$count} staff processed.");
            return;
        }

        // All tenants (or global if tenant field is not present).
        $tenants = Schema::hasColumn('users', 'tenant_id')
            ? User::select('tenant_id')
                ->whereNotNull('tenant_id')
                ->distinct()
                ->pluck('tenant_id')
            : collect([null]);

        foreach ($tenants as $tenantId) {
            $count = $service->evaluateAllStaff($tenantId);
            Log::info("Compliance matrix evaluated for tenant " . ($tenantId ?? 'global') . ": {$count} staff processed.");
        }
    }
}
