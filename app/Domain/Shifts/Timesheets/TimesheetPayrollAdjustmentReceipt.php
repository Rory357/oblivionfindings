<?php

namespace App\Domain\Shifts\Timesheets;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Request-local committed marker evidence, never bank/payment evidence. */
class TimesheetPayrollAdjustmentReceipt
{
    public function begin(Request $request): bool
    {
        $request->session()->forget('timesheet_payroll_adjustment_result');
        try {
            return $this->isPhysicalRoot();
        } catch (Throwable) {
            return false;
        }
    }

    protected function isPhysicalRoot(): bool
    {
        return DB::connection()->transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
    }

    public function committed(bool $rootEntry, TimesheetPayrollAdjustmentResult $result): ?array
    {
        if (! $rootEntry || $result->error !== null) {
            return null;
        }
        try {
            if (! $this->isPhysicalRoot() || ! $result->amendment->exists || ! $result->amendment->applied_at) {
                return null;
            }

            return [
                'action' => 'process_payroll_adjustment', 'actor_id' => $result->actorId,
                'amendment_id' => (int) $result->amendment->id,
                'timesheet_id' => (int) $result->amendment->timesheet_id,
                'changed' => $result->changed,
                'outcome' => $result->changed ? 'recorded_external_processing' : 'already_recorded',
                'processing_method' => 'external',
                'applied_at' => $result->amendment->applied_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            ];
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed payroll-adjustment marker could not be presented', ['exception_class' => $exception::class]);
            } catch (Throwable) {
            }

            return null;
        }
    }
}
