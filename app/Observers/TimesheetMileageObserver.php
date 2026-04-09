<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Models\Timesheet;
use Illuminate\Support\Facades\Log;

class TimesheetMileageObserver
{
    /**
     * Dispatch mileage reimbursement GL posting when a timesheet is approved
     * and has mileage_km > 0.
     *
     * Trigger: Timesheet::updated (status → approved, mileage_km > 0)
     * GL Entry: DR 6520 Travel & Mileage / CR 2310 Expense Claims Payable
     * Rate: config('finance.mileage_rate_per_km') — NZ IRD rate default $0.95/km
     */
    public function updated(Timesheet $timesheet): void
    {
        if (! $timesheet->wasChanged('status') || $timesheet->status !== 'approved') {
            return;
        }

        if (! $timesheet->mileage_km || bccomp((string) $timesheet->mileage_km, '0', 2) <= 0) {
            return;
        }

        // Idempotency: skip if mileage already posted
        if ($timesheet->mileage_journal_id) {
            return;
        }

        try {
            $ratePerKm = (string) config('finance.mileage_rate_per_km', '0.95');
            $amount = bcmul((string) $timesheet->mileage_km, $ratePerKm, 2);

            if (bccomp($amount, '0', 2) <= 0) {
                return;
            }

            // Resolve org via shift_site_id → Site → tenant_id
            $orgId = null;
            if ($timesheet->shift_site_id) {
                $site = \App\Models\Site::find($timesheet->shift_site_id);
                $orgId = $site?->tenant_id;
            }

            if (! $orgId) {
                Log::warning("TimesheetMileageObserver: Cannot resolve org for timesheet #{$timesheet->id}");

                return;
            }

            $accountConfig = config('finance.event_accounts.mileage_reimbursement');

            ProcessFinancialEventJob::dispatch([
                'organization_id' => $orgId,
                'source_type' => Timesheet::class,
                'source_id' => $timesheet->id,
                'event_type' => 'mileage_reimbursement',
                'description' => "Mileage: {$timesheet->mileage_km}km @ \${$ratePerKm}/km"
                    . ($timesheet->staff_name_snapshot ? " — {$timesheet->staff_name_snapshot}" : ''),
                'amount' => $amount,
                'event_date' => ($timesheet->work_date ?? $timesheet->approved_at ?? now())->toDateString(),
                'debit_account_code' => $accountConfig['debit'],
                'payment_type' => FinFinancialEvent::PAYMENT_REIMBURSEMENT,
                'journal_type' => $accountConfig['journal_type'],
                'site_id' => $timesheet->shift_site_id,
                'staff_id' => $timesheet->user_id,
                'client_id' => $timesheet->client_id,
                'shift_id' => $timesheet->shift_id,
                'source_updated_at' => $timesheet->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("TimesheetMileageObserver: Failed to dispatch mileage GL job for timesheet #{$timesheet->id}: {$e->getMessage()}");
        }
    }
}
