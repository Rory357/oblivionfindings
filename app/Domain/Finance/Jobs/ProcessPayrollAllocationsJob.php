<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\PayrollCostAllocationService;
use App\Domain\Hr\Models\HrPayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job to create cost allocations from a posted payroll journal.
 *
 * Processes both wage allocations (payroll_cost) and employer on-cost
 * allocations (employer_oncost) in a single job execution.
 *
 * Dispatched by AllocatePayrollCosts listener when a payroll-type journal is posted.
 */
class ProcessPayrollAllocationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $payrollRunId,
    ) {}

    public function handle(PayrollCostAllocationService $service): void
    {
        $run = HrPayrollRun::find($this->payrollRunId);

        if (! $run) {
            Log::warning("ProcessPayrollAllocationsJob: Payroll run #{$this->payrollRunId} not found.");

            return;
        }

        // Skip if both wages and on-costs are already allocated
        if ($run->cost_allocated_at !== null && $run->oncost_allocated_at !== null) {
            return;
        }

        try {
            $result = $service->allocate($run);

            $wageCount = $result['wages']['allocated_count'];
            $oncostCount = $result['oncosts']['allocated_count'];

            Log::info("ProcessPayrollAllocationsJob: Run #{$this->payrollRunId} — wages: {$wageCount}, on-costs: {$oncostCount}");
        } catch (\Throwable $e) {
            Log::error("ProcessPayrollAllocationsJob: Failed for run #{$this->payrollRunId} (attempt {$this->attempts()}): {$e->getMessage()}");

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::critical("ProcessPayrollAllocationsJob: PERMANENTLY FAILED for run #{$this->payrollRunId}: {$e->getMessage()}");
    }
}
