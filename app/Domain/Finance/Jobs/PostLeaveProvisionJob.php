<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Finance\Models\FinLeaveProvisionSnapshot;
use App\Domain\Finance\Services\FinancialEventService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Monthly job: calculate leave liability provision and post the DELTA to GL.
 *
 * For each active employee with leave balances:
 *   1. current_provision = balance_hours × hourly_rate
 *   2. previous_provision = last snapshot amount (or 0 if first time)
 *   3. delta = current_provision - previous_provision
 *   4. If delta > 0: DR 5020 Leave Expense / CR 2400 Accrued Leave (increase)
 *      If delta < 0: DR 2400 Accrued Leave / CR 5020 Leave Expense (decrease)
 *   5. Save new snapshot for next month's comparison
 *
 * Schedule: Run monthly, e.g. on the 1st of each month for the prior month.
 */
class PostLeaveProvisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Don't retry — run is idempotent per snapshot_date

    public function __construct(
        public readonly ?string $snapshotDate = null,
    ) {}

    public function handle(FinancialEventService $service): void
    {
        $date = $this->snapshotDate
            ? Carbon::parse($this->snapshotDate)
            : Carbon::now()->startOfMonth()->subDay(); // Last day of previous month

        $snapshotDateStr = $date->toDateString();

        // Process per-organisation
        $orgIds = HrEmployeeProfile::where('is_active', true)
            ->distinct()
            ->pluck('tenant_id')
            ->filter();

        foreach ($orgIds as $orgId) {
            try {
                $this->processOrganisation($service, (int) $orgId, $date, $snapshotDateStr);
            } catch (\Throwable $e) {
                Log::error("PostLeaveProvisionJob: Failed for org #{$orgId}: {$e->getMessage()}");
            }
        }
    }

    private function processOrganisation(
        FinancialEventService $service,
        int $orgId,
        Carbon $date,
        string $snapshotDateStr,
    ): void {
        $currentYear = $date->year;

        // Get all active employees with their profiles
        $profiles = HrEmployeeProfile::where('tenant_id', $orgId)
            ->where('is_active', true)
            ->get();

        $totalIncrease = '0';
        $totalDecrease = '0';
        $processedCount = 0;

        foreach ($profiles as $profile) {
            // hourly_rate is encrypted — must be read in PHP
            $hourlyRate = $profile->hourly_rate;
            if (! $hourlyRate || (float) $hourlyRate <= 0) {
                continue;
            }

            // Get annual leave balance only (the primary liability type)
            $balances = HrLeaveBalance::where('tenant_id', $orgId)
                ->where('user_id', $profile->user_id)
                ->where('leave_type', 'annual')
                ->where('year', $currentYear)
                ->get();

            foreach ($balances as $balance) {
                $balanceHours = (float) $balance->balance_hours;
                if ($balanceHours <= 0) {
                    continue;
                }

                $currentProvision = bcmul((string) $balanceHours, (string) $hourlyRate, 2);

                // Get previous snapshot
                $previousSnapshot = FinLeaveProvisionSnapshot::latestFor(
                    $orgId,
                    $profile->user_id,
                    $balance->leave_type,
                );

                $previousProvision = $previousSnapshot?->provision_amount ?? '0.00';
                $delta = bcsub($currentProvision, (string) $previousProvision, 2);

                // Skip if no change
                if (bccomp($delta, '0', 2) === 0) {
                    // Still save snapshot for audit trail
                    $this->saveSnapshot($orgId, $profile->user_id, $balance, $hourlyRate, $currentProvision, $snapshotDateStr, null);

                    continue;
                }

                $accountConfig = config('finance.event_accounts.leave_provision');
                $absAmount = ltrim($delta, '-');

                if (bccomp($delta, '0', 2) > 0) {
                    // Liability increased: DR Leave Expense / CR Accrued Leave
                    $debitCode = $accountConfig['debit'];   // 5020
                    $creditCode = $accountConfig['credit'];  // 2400
                    $totalIncrease = bcadd($totalIncrease, $absAmount, 2);
                } else {
                    // Liability decreased: DR Accrued Leave / CR Leave Expense (reversal)
                    $debitCode = $accountConfig['credit'];  // 2400
                    $creditCode = $accountConfig['debit'];   // 5020
                    $totalDecrease = bcadd($totalDecrease, $absAmount, 2);
                }

                try {
                    $event = $service->record([
                        'organization_id' => $orgId,
                        'source_type' => HrLeaveBalance::class,
                        'source_id' => $balance->id,
                        'event_type' => 'leave_provision',
                        'description' => "Leave provision {$balance->leave_type}: {$balanceHours}hrs × \${$hourlyRate}/hr"
                            . " (delta: \${$delta})",
                        'amount' => $absAmount,
                        'event_date' => $snapshotDateStr,
                        'debit_account_code' => $debitCode,
                        'credit_account_code' => $creditCode,
                        'payment_type' => FinFinancialEvent::PAYMENT_AP,
                        'journal_type' => 'standard',
                        'staff_id' => $profile->user_id,
                        'source_updated_at' => $snapshotDateStr, // Use date for idempotency per month
                    ]);

                    $this->saveSnapshot($orgId, $profile->user_id, $balance, $hourlyRate, $currentProvision, $snapshotDateStr, $event->journal_id);
                    $processedCount++;
                } catch (\Throwable $e) {
                    Log::error("PostLeaveProvisionJob: Failed for user #{$profile->user_id} leave #{$balance->id}: {$e->getMessage()}");

                    // Still save snapshot without journal to avoid re-processing
                    $this->saveSnapshot($orgId, $profile->user_id, $balance, $hourlyRate, $currentProvision, $snapshotDateStr, null);
                }
            }
        }

        Log::info("PostLeaveProvisionJob: Org #{$orgId} — processed {$processedCount} provisions"
            . " (increase: \${$totalIncrease}, decrease: \${$totalDecrease})");
    }

    private function saveSnapshot(
        int $orgId,
        int $userId,
        HrLeaveBalance $balance,
        string $hourlyRate,
        string $provisionAmount,
        string $snapshotDate,
        ?int $journalId,
    ): void {
        FinLeaveProvisionSnapshot::updateOrCreate(
            [
                'organization_id' => $orgId,
                'user_id' => $userId,
                'leave_type' => $balance->leave_type,
                'snapshot_date' => $snapshotDate,
            ],
            [
                'balance_hours' => $balance->balance_hours,
                'hourly_rate' => $hourlyRate,
                'provision_amount' => $provisionAmount,
                'journal_id' => $journalId,
            ],
        );
    }
}
