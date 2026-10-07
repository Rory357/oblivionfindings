<?php

namespace App\Domain\Shifts\Timesheets;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Request-local evidence only; this is not an idempotency or payroll receipt. */
class TimesheetCommandReceipt
{
    public function begin(Request $request): bool
    {
        $request->session()->forget('timesheet_result');
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

    public function committed(bool $rootEntry, string $action, TimesheetWorkflowResult $result, ?bool $submitRequested = null): ?array
    {
        if (! $rootEntry) {
            return null;
        }
        try {
            if (! $this->isPhysicalRoot() || ! $result->actorId) {
                return null;
            }
            $row = $result->timesheet;
            $receipt = [
                'action' => $action, 'actor_id' => $result->actorId, 'timesheet_id' => (int) $row->id,
                'user_id' => (int) $row->user_id, 'shift_id' => $this->id($row->shift_id),
                'client_id' => $this->id($row->client_id), 'site_id' => $this->id($row->site_id),
                'shift_site_id' => $this->id($row->shift_site_id),
                'effective_site_id' => $this->id($row->shift_site_id ?? $row->site_id),
                'status' => $row->status, 'changed' => $result->changed,
                'outcome' => $result->changed ? 'saved' : 'already_in_state',
                'work_date' => $row->work_date->toDateString(),
                'starts_at' => $row->starts_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'ends_at' => $row->ends_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'break_minutes' => (int) $row->break_minutes, 'total_hours' => number_format((float) $row->total_hours, 2, '.', ''),
                'sleepover' => (bool) $row->sleepover, 'on_call' => (bool) $row->on_call, 'activity_type' => $row->activity_type,
                'values_hash' => $this->valuesHash($action, $result),
            ];
            foreach (['submitted', 'approved', 'returned'] as $prefix) {
                $receipt[$prefix.'_by'] = $this->id($row->getAttribute($prefix.'_by'));
                $receipt[$prefix.'_at'] = $row->getAttribute($prefix.'_at')?->copy()->utc()->format('Y-m-d\TH:i:s.000\Z');
            }
            if ($action === 'create') {
                $receipt['submit_requested'] = (bool) $submitRequested;
            }

            return $receipt;
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed timesheet result could not be presented', ['action' => $action, 'exception_class' => $exception::class]);
            } catch (Throwable) {
            }

            return null;
        }
    }

    protected function valuesHash(string $action, TimesheetWorkflowResult $result): string
    {
        $row = $result->timesheet;
        if (in_array($action, ['create', 'update', 'resubmit'], true)) {
            $manual = null;
            if ($row->shift_id === null) {
                $manual = ['client_id' => $this->id($row->client_id)];
                if ($action === 'create') {
                    $manual += ['site_id' => $this->id($row->site_id), 'activity_type' => $row->activity_type];
                }
                $manual += ['sleepover' => (bool) $row->sleepover, 'on_call' => (bool) $row->on_call];
            }
            $values = ['work_date' => $row->work_date->toDateString(),
                'starts_at' => $row->starts_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'), 'ends_at' => $row->ends_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'break_minutes' => (int) $row->break_minutes, 'mileage_km' => $row->mileage_km,
                'allowance_notes' => $row->allowance_notes, 'public_holiday' => (bool) $row->public_holiday,
                'notes' => $row->notes, 'is_residential_billable' => (bool) $row->is_residential_billable, 'manual' => $manual];
            if ($action === 'create') {
                $values['activity_items'] = $row->shift_id === null ? ($row->activity_items ?? []) : null;
            }
        } else {
            $values = ['timesheet_id' => (int) $row->id, 'action' => $action];
            if ($result->changed && $action !== 'submit') {
                $values['reason'] = $action === 'return' ? $row->returned_notes : $row->decision_notes;
            }
        }

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    private function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
