<?php

namespace App\Domain\Shifts\Timesheets;

use App\Models\Client;
use App\Models\Timesheet;
use App\Models\TimesheetClientAllocation;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One financial roster and allocation contract for worker review and approval. */
class TimesheetAllocationService
{
    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function candidates(Timesheet $timesheet, User $actor): array
    {
        $this->sites->assertCanAccessTimesheet($actor, $timesheet);
        $siteId = $this->sites->timesheetSiteId($timesheet);
        // Time attribution uses the authorised shift/site roster. It does not
        // confer permission to open a person's clinical record.
        $query = Client::query()->where('site_id', $siteId)->where('status', '!=', 'archived');
        if (! $timesheet->shift_id) {
            $query->whereKey($timesheet->client_id);
        }

        return $query->orderBy('first_name')->orderBy('last_name')->get()
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name),
                'is_primary' => (int) $client->id === (int) $timesheet->client_id])->all();
    }

    public function revision(Timesheet $timesheet): string
    {
        return hash('sha256', json_encode([
            // Reconciliation stamps review metadata after a rejected submit.
            // That must not strand the worker's unchanged allocation draft.
            // Compare the complete allocation/time boundary, not unrelated timestamps.
            $timesheet->only(['id', 'user_id', 'shift_id', 'client_id', 'site_id', 'shift_site_id', 'work_date', 'status', 'starts_at', 'ends_at', 'break_minutes']),
            $timesheet->clientAllocations()->orderBy('client_id')->get()->map(fn ($row) => $row->only([
                'client_id', 'hours', 'allocation_method', 'starts_at', 'ends_at', 'notes', 'sort_order',
            ]))->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function assertRevision(Timesheet $timesheet, string $expected): void
    {
        if (! hash_equals($this->revision($timesheet), $expected)) {
            throw ValidationException::withMessages(['timesheet' => 'This timesheet changed while you were reviewing it. Reopen it to check the latest hours and saved split.']);
        }
    }

    public function validate(Timesheet $timesheet, User $actor, array $rows, bool $complete): array
    {
        $input = Validator::make(['client_allocations' => $rows], [
            'client_allocations' => ['array', 'min:1', 'max:50'],
            'client_allocations.*' => ['array:client_id,hours,allocation_method,starts_at,ends_at,notes,sort_order'],
            'client_allocations.*.client_id' => ['required', 'integer', 'distinct'],
            'client_allocations.*.hours' => ['required', 'numeric', 'min:0', 'max:168', 'decimal:0,2'],
            'client_allocations.*.allocation_method' => ['required', 'in:'.implode(',', TimesheetClientAllocation::METHODS)],
            'client_allocations.*.starts_at' => ['nullable', 'date'],
            'client_allocations.*.ends_at' => ['nullable', 'date'],
            'client_allocations.*.notes' => ['nullable', 'string', 'max:2000'],
            'client_allocations.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ])->validate()['client_allocations'];
        $allowed = array_column($this->candidates($timesheet, $actor), 'id');
        $methods = collect($input)->pluck('allocation_method')->unique();
        if ($methods->count() !== 1 || ($methods->first() === 'single' && count($input) !== 1)) {
            throw ValidationException::withMessages(['client_allocations' => 'Choose one allocation method. “One person” must contain exactly one person.']);
        }
        $normal = [];
        foreach ($input as $index => $row) {
            if (! in_array((int) $row['client_id'], $allowed, true)) {
                throw ValidationException::withMessages(['client_allocations' => 'A selected person is no longer on this timesheet’s eligible roster. Reopen the review and check who you supported.']);
            }
            $segmented = $row['allocation_method'] === 'time_segmented';
            $start = $segmented && ! empty($row['starts_at']) ? $this->instant($row['starts_at']) : null;
            $end = $segmented && ! empty($row['ends_at']) ? $this->instant($row['ends_at']) : null;
            if ($segmented && $complete && (! $start || ! $end)) {
                throw ValidationException::withMessages(["client_allocations.$index.starts_at" => 'Choose the date and time this support started and ended.']);
            }
            if ($start && ($start->lt($timesheet->starts_at) || $start->gt($timesheet->ends_at))
                || $end && ($end->lt($timesheet->starts_at) || $end->gt($timesheet->ends_at))
                || $start && $end && $end->lte($start)) {
                throw ValidationException::withMessages(["client_allocations.$index.starts_at" => 'Keep each support period within the timesheet, with its end after its start.']);
            }
            $hours = round((float) $row['hours'], 2);
            if ($start && $end && (int) round($hours * 100) !== (int) round($start->floatDiffInRealHours($end) * 100)) {
                throw ValidationException::withMessages(["client_allocations.$index.hours" => 'Hours must match this support period’s duration. Leave unpaid breaks out of the support periods.']);
            }
            if ($complete && $hours <= 0) {
                throw ValidationException::withMessages(["client_allocations.$index.hours" => 'Enter the time you supported this person, or remove them from this timesheet.']);
            }
            $normal[] = ['client_id' => (int) $row['client_id'], 'hours' => $hours, 'allocation_method' => $row['allocation_method'],
                'starts_at' => $start, 'ends_at' => $end, 'notes' => $row['notes'] ?? null, 'sort_order' => $index];
        }
        $segments = collect($normal)->filter(fn ($row) => $row['starts_at'] && $row['ends_at'])->sortBy('starts_at')->values();
        for ($i = 1; $i < $segments->count(); $i++) {
            if ($segments[$i]['starts_at']->lt($segments[$i - 1]['ends_at'])) {
                throw ValidationException::withMessages(['client_allocations' => 'Support periods overlap. Use “Split evenly” or “Enter time per person” for shared support.']);
            }
        }
        if ($complete) {
            $this->assertTotal($timesheet, $normal);
        }
        if (in_array($methods->first(), ['equal_split', 'residential_house'], true) && $complete) {
            $cents = array_map(fn ($row) => (int) round($row['hours'] * 100), $normal);
            if (max($cents) - min($cents) > 1) {
                throw ValidationException::withMessages(['client_allocations' => 'An equal split must give everyone the same share, allowing one hundredth of an hour for rounding.']);
            }
        }

        return $normal;
    }

    public function assertTotal(Timesheet $timesheet, array $rows): void
    {
        if (array_sum(array_map(fn ($row) => (int) round((float) $row['hours'] * 100), $rows)) !== (int) round((float) $timesheet->total_hours * 100)) {
            throw ValidationException::withMessages(['client_allocations' => 'The time shared between people must equal the timesheet’s paid hours. Review the split after changing hours or breaks.']);
        }
    }

    public function assertSavedSplitComplete(Timesheet $timesheet, User $actor): void
    {
        $rows = $timesheet->clientAllocations()->orderBy('sort_order')->get();
        if ($rows->isEmpty()) {
            return;
        }
        $this->validate($timesheet, $actor, $rows->map(fn ($row) => [
            ...$row->only(['client_id', 'hours', 'allocation_method', 'notes', 'sort_order']),
            'starts_at' => $row->starts_at?->toIso8601String(),
            'ends_at' => $row->ends_at?->toIso8601String(),
        ])->all(), true);
    }

    public function persist(Timesheet $timesheet, array $rows): void
    {
        foreach ($rows as $row) {
            $timesheet->clientAllocations()->updateOrCreate(['client_id' => $row['client_id']], $row);
        }
        $timesheet->clientAllocations()->whereNotIn('client_id', array_column($rows, 'client_id'))->delete();
        $timesheet->unsetRelation('clientAllocations');
    }

    private function instant(string $value): CarbonImmutable
    {
        if (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $value)) {
            return CarbonImmutable::parse($value)->utc();
        }

        // Legacy controls send NZ wall time. Resolve it only when exactly one
        // instant exists; Carbon's default would silently choose a DST fold
        // or move a nonexistent time forward by an hour.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/D', $value)) {
            throw ValidationException::withMessages(['client_allocations' => 'Choose a full date and time for each support period.']);
        }
        $wall = str_replace('T', ' ', $value);
        if (strlen($wall) === 16) {
            $wall .= ':00';
        }
        $naive = CarbonImmutable::parse($wall, 'UTC');
        $zone = new \DateTimeZone(config('app.worker_timezone', 'Pacific/Auckland'));
        $offsets = array_unique(array_column($zone->getTransitions($naive->timestamp - 172800, $naive->timestamp + 172800), 'offset'));
        $choices = [];
        foreach ($offsets as $offset) {
            $candidate = $naive->subSeconds($offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $wall) {
                $choices[] = $candidate;
            }
        }
        if (count($choices) !== 1) {
            throw ValidationException::withMessages(['client_allocations' => 'This local time is repeated or skipped when daylight saving changes. Choose the time again and, when shown, choose its first or second occurrence.']);
        }

        return $choices[0];
    }
}
