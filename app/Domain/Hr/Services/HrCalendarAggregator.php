<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Shift;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Services\ShiftCoverageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single entry point that fans the org/manager `/hr/calendar` page out across
 * every layer, returning one flat list of `CalendarLayerFeed`-shaped rows (see
 * resources/js/lib/calendar/layer-feed.ts). It DELEGATES to the existing feeds
 * rather than re-deriving them:
 *
 *   - events      → HrCalendarEvent (the only editable layer here)
 *   - leave +     → LeaveService::calendarFeed() — reuses the hub's redaction
 *     holidays       + roster-conflict context so the two surfaces never diverge
 *   - shifts +    → Shift query + ShiftCoverageService (read-only overlay; the
 *     coverage       Rostering planner stays the editor)
 *   - compliance  → the cert/vetting/licence/training expiry derivation
 *   - milestones  → HrEmployeeProfile (birthdays / anniversaries / probation…)
 *
 * Everything is tenant-scoped. Colours are emitted as design-token *names*
 * (never raw hex); the React page resolves them to hsl(var(--token)).
 */
class HrCalendarAggregator
{
    /** Free event_type string → category token (the per-category accent). */
    private const EVENT_CATEGORY_TOKENS = [
        'company' => 'category-hr',
        'team' => 'category-ops',
        'training' => 'status-info',
        'social' => 'category-finance',
        'holiday' => 'status-warning',
    ];

    public function __construct(
        private readonly LeaveService $leaveService,
        private readonly ShiftCoverageService $shiftCoverageService,
    ) {}

    /**
     * @param  list<string>  $layers   active layer keys to compute (perf gate)
     * @param  array{site_id?: int|string|null, team?: string|null, department_id?: int|string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function feed(
        ?int $tenantId,
        string $from,
        string $to,
        array $layers,
        array $filters = [],
        ?User $viewer = null,
    ): array {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        $want = fn (string $layer): bool => in_array($layer, $layers, true);

        $out = collect();

        if ($want('event')) {
            $out = $out->concat($this->events($tenantId, $start, $end, $filters));
        }
        if ($want('leave') || $want('holiday')) {
            [$leave, $holidays] = $this->leaveAndHolidays($tenantId, $start, $end, $filters, $viewer);
            if ($want('leave')) {
                $out = $out->concat($leave);
            }
            if ($want('holiday')) {
                $out = $out->concat($holidays);
            }
        }
        if ($want('shift') && $viewer && $viewer->canDo('rostering.viewAny')) {
            $out = $out->concat($this->shifts($start, $end, $filters, $viewer));
        }
        if ($want('compliance')) {
            $out = $out->concat($this->compliance($start, $end, $filters));
        }
        if ($want('milestone')) {
            $out = $out->concat($this->milestones($tenantId, $start, $end, $filters));
        }

        return $out->values()->all();
    }

    /* ── Events (editable) ───────────────────────────────────────────────── */

    private function events(?int $tenantId, Carbon $start, Carbon $end, array $filters): Collection
    {
        // NOTE: events carry a free-text `department` today, not a `department_id`
        // FK — so the department filter doesn't constrain events yet (it does
        // constrain the people-derived milestone layer). Binding events to
        // HrDepartment is tracked as a follow-up (see the gap analysis).
        return HrCalendarEvent::query()
            ->when($tenantId !== null, fn ($q) => $q->forTenant($tenantId))
            ->inRange($start->toDateString(), $end->toDateString())
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->with(['creator:id,name', 'site:id,name'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (HrCalendarEvent $e) => [
                'layer' => 'event',
                'id' => 'event-'.$e->id,
                'title' => $e->title,
                'start' => optional($e->starts_at)->toIso8601String(),
                'end' => optional($e->ends_at)->toIso8601String(),
                'allDay' => (bool) $e->is_all_day,
                'color' => self::EVENT_CATEGORY_TOKENS[$e->event_type] ?? 'category-hr',
                'editable' => true,
                'extendedProps' => [
                    'eventId' => $e->id,
                    'category' => $e->event_type,
                    'site' => $e->site?->name,
                    'siteId' => $e->site_id,
                    'department' => $e->department,
                    'location' => $e->location,
                    'description' => $e->description,
                    'isAllDay' => (bool) $e->is_all_day,
                    'startRaw' => optional($e->starts_at)->toIso8601String(),
                    'endRaw' => optional($e->ends_at)->toIso8601String(),
                    'createdBy' => $e->creator?->name,
                    'recurring' => false,
                    'attendeeCount' => 0,
                ],
            ]);
    }

    /* ── Leave + holidays (read-only; reuse the hub feed + redaction) ─────── */

    /**
     * @return array{0: Collection, 1: Collection}
     */
    private function leaveAndHolidays(?int $tenantId, Carbon $start, Carbon $end, array $filters, ?User $viewer): array
    {
        $canSeeSensitive = (bool) $viewer?->canDo('hr.leave.manage');
        $leaveFilters = ! empty($filters['site_id']) ? ['site_id' => $filters['site_id']] : [];

        $leave = collect();
        $holidays = collect();
        $seenLeave = [];
        $seenHoliday = [];

        // calendarFeed() is month-bound; a visible range can span up to 3 months.
        // Walk each month, reusing the hub's exact redaction + context logic.
        $cursor = $start->copy()->startOfMonth();
        $guard = 0;
        while ($cursor->lte($end) && $guard++ < 6) {
            $feed = $this->leaveService->calendarFeed(
                $tenantId,
                $cursor->format('Y-m'),
                $leaveFilters,
                $viewer?->id,
                $canSeeSensitive,
            );

            foreach ($feed['entries'] as $entry) {
                if (isset($seenLeave[$entry['id']])) {
                    continue;
                }
                $seenLeave[$entry['id']] = true;
                $pending = ($entry['status'] ?? null) === 'pending';
                $leave->push([
                    'layer' => 'leave',
                    'id' => 'leave-'.$entry['id'],
                    'title' => $entry['user_name'].' · '.$this->humanise($entry['leave_type'] ?? 'leave'),
                    'start' => $entry['start'],
                    'end' => Carbon::parse($entry['end'])->addDay()->toDateString(), // all-day exclusive end
                    'allDay' => true,
                    'color' => 'status-neutral',
                    'editable' => false,
                    'deepLink' => '/hr/leave',
                    'extendedProps' => [
                        'person' => $entry['user_name'],
                        'site' => $entry['site'] ?? null,
                        'leaveType' => $entry['leave_type'] ?? null,
                        'pending' => $pending,
                        'redacted' => (bool) ($entry['reason_restricted'] ?? false),
                        'reason' => $entry['reason'] ?? null,
                        'hours' => $entry['hours'] ?? null,
                    ],
                ]);
            }

            foreach ($feed['public_holidays'] as $date => $h) {
                $key = $date;
                if (isset($seenHoliday[$key])) {
                    continue;
                }
                $seenHoliday[$key] = true;
                $holidays->push([
                    'layer' => 'holiday',
                    'id' => 'holiday-'.$date,
                    'title' => $h['name'],
                    'start' => $date,
                    'end' => Carbon::parse($date)->addDay()->toDateString(),
                    'allDay' => true,
                    'color' => 'status-warning',
                    'editable' => false,
                    'extendedProps' => [
                        'isNational' => (bool) ($h['is_national'] ?? true),
                        'region' => $h['region'] ?? null,
                        'isHolidayBackground' => true,
                    ],
                ]);
            }

            $cursor->addMonthNoOverflow();
        }

        return [$leave, $holidays];
    }

    /* ── Shifts & coverage (read-only overlay) ───────────────────────────── */

    private function shifts(Carbon $start, Carbon $end, array $filters, User $viewer): Collection
    {
        $siteId = ! empty($filters['site_id']) ? (int) $filters['site_id'] : null;

        $shifts = Shift::query()
            ->with(['client:id,first_name,last_name,site_id', 'site:id,name', 'staff:id,name'])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->get();

        $coverageWindows = collect($this->shiftCoverageService->buildRangeCoverage($start, $end, $siteId));

        $shiftEvents = $shifts->map(function (Shift $shift) {
            $clientName = $shift->client
                ? trim($shift->client->first_name.' '.$shift->client->last_name)
                : 'Client';
            $staffName = $shift->staff?->name ?? 'Unassigned';

            return [
                'layer' => 'shift',
                'id' => 'shift-'.$shift->id,
                'title' => $clientName.' · '.$staffName,
                'start' => optional($shift->starts_at)->toIso8601String(),
                'end' => optional($shift->ends_at)->toIso8601String(),
                'allDay' => false,
                'color' => 'live',
                'editable' => false,
                'deepLink' => '/operations/rostering?tab=calendar',
                'extendedProps' => [
                    'person' => $staffName,
                    'client' => $clientName,
                    'site' => $shift->site?->name ?? $shift->client?->site?->name,
                    'status' => $shift->status,
                    'isOpenShift' => $shift->user_id === null,
                    'gap' => false,
                ],
            ];
        });

        $gapEvents = $coverageWindows
            ->filter(fn (array $w) => ! empty($w['has_actionable_gap']))
            ->map(fn (array $w) => [
                'layer' => 'shift',
                'id' => 'coverage-gap-'.($w['rule_id'] ?? 'x').'-'.md5(($w['starts_at'] ?? '').'-'.($w['ends_at'] ?? '')),
                'title' => 'Coverage gap',
                'start' => $w['starts_at'] ?? null,
                'end' => $w['ends_at'] ?? null,
                'allDay' => false,
                'color' => 'status-critical',
                'editable' => false,
                'deepLink' => '/operations/rostering?tab=calendar',
                'extendedProps' => [
                    'gap' => true,
                    'site' => $w['site_name'] ?? null,
                    'ruleName' => $w['rule_name'] ?? null,
                    'missingStaff' => $w['missing_staff'] ?? 0,
                    'requiredStaff' => $w['required_staff'] ?? null,
                    'windowLabel' => $w['window_label'] ?? null,
                ],
            ]);

        return $shiftEvents->concat($gapEvents)->values();
    }

    /* ── Compliance renewals (read-only; deep-link to Compliance) ────────── */

    private function compliance(Carbon $start, Carbon $end, array $filters): Collection
    {
        $now = now();
        $out = collect();
        $urgency = function (Carbon $expires) use ($now): string {
            return $expires->lt($now) || $expires->diffInDays($now) <= 30 ? 'critical' : 'warning';
        };

        HrStaffComplianceStatus::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$start, $end])
            ->with(['user:id,name', 'requirement:id,name,code'])
            ->get()
            ->each(function ($s) use ($out, $urgency) {
                $out->push($this->complianceRow(
                    'compliance-'.$s->id,
                    ($s->requirement?->name ?? 'Compliance').' · '.($s->user?->name ?? 'Unknown'),
                    $s->expires_at,
                    $urgency($s->expires_at),
                    ['person' => $s->user?->name, 'requirement' => $s->requirement?->name],
                ));
            });

        StaffBackgroundCheck::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$start, $end])
            ->with('user:id,name')
            ->get()
            ->each(function ($c) use ($out, $urgency) {
                $out->push($this->complianceRow(
                    'vetting-'.$c->id,
                    $this->humanise($c->check_type).' · '.($c->user?->name ?? 'Unknown'),
                    $c->expires_at,
                    $urgency($c->expires_at),
                    ['person' => $c->user?->name, 'requirement' => $this->humanise($c->check_type)],
                ));
            });

        HrDriverEligibility::query()
            ->whereNotNull('licence_expires_at')
            ->whereBetween('licence_expires_at', [$start, $end])
            ->with('user:id,name')
            ->get()
            ->each(function ($r) use ($out, $urgency) {
                $out->push($this->complianceRow(
                    'driver-'.$r->id,
                    'Driver licence · '.($r->user?->name ?? 'Unknown'),
                    $r->licence_expires_at,
                    $urgency($r->licence_expires_at),
                    ['person' => $r->user?->name, 'requirement' => 'Driver licence'],
                ));
            });

        return $out->values();
    }

    private function complianceRow(string $id, string $title, Carbon $date, string $urgency, array $extra): array
    {
        return [
            'layer' => 'compliance',
            'id' => $id,
            'title' => $title,
            'start' => $date->toDateString(),
            'end' => $date->copy()->addDay()->toDateString(),
            'allDay' => true,
            'color' => $urgency === 'critical' ? 'status-critical' : 'status-warning',
            'editable' => false,
            'deepLink' => '/hr/compliance',
            'extendedProps' => array_merge(['urgency' => $urgency], $extra),
        ];
    }

    /* ── People milestones (read-only, privacy-aware) ────────────────────── */

    private function milestones(?int $tenantId, Carbon $start, Carbon $end, array $filters): Collection
    {
        $out = collect();

        HrEmployeeProfile::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('primary_site_id', $filters['site_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->with('user:id,name')
            ->get()
            ->each(function (HrEmployeeProfile $p) use ($out, $start, $end) {
                $name = $p->user?->name ?? 'Employee';

                // Birthday — recurring; no year exposed.
                if ($dob = $this->safeDate($p->date_of_birth)) {
                    if ($day = $this->anniversaryInRange($dob, $start, $end)) {
                        $out->push($this->milestoneRow('birthday-'.$p->id, $name.' · Birthday', $day, [
                            'person' => $name, 'kind' => 'birthday',
                        ]));
                    }
                }

                // Work anniversary — recurring; show the year count.
                if ($p->start_date && ($day = $this->anniversaryInRange($p->start_date, $start, $end))) {
                    $years = $day->year - $p->start_date->year;
                    if ($years >= 1) {
                        $out->push($this->milestoneRow('anniversary-'.$p->id, $name.' · '.$years.'yr anniversary', $day, [
                            'person' => $name, 'kind' => 'anniversary', 'years' => $years,
                        ]));
                    }
                }

                // Probation end — one-off.
                if ($p->probation_end_date && $p->probation_end_date->between($start, $end)) {
                    $out->push($this->milestoneRow('probation-'.$p->id, $name.' · Probation ends', $p->probation_end_date, [
                        'person' => $name, 'kind' => 'probation_end',
                    ]));
                }

                // Contract end — one-off.
                if ($p->end_date && $p->end_date->between($start, $end)) {
                    $out->push($this->milestoneRow('contract-'.$p->id, $name.' · Contract ends', $p->end_date, [
                        'person' => $name, 'kind' => 'contract_end',
                    ]));
                }
            });

        return $out->values();
    }

    private function milestoneRow(string $id, string $title, Carbon $date, array $extra): array
    {
        return [
            'layer' => 'milestone',
            'id' => $id,
            'title' => $title,
            'start' => $date->toDateString(),
            'end' => $date->copy()->addDay()->toDateString(),
            'allDay' => true,
            'color' => 'category-finance',
            'editable' => false,
            'extendedProps' => $extra,
        ];
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    /** The next occurrence of a month/day anniversary that falls within the range. */
    private function anniversaryInRange(Carbon $source, Carbon $start, Carbon $end): ?Carbon
    {
        foreach (range($start->year, $end->year) as $year) {
            try {
                $candidate = Carbon::create($year, $source->month, $source->day);
            } catch (\Throwable) {
                continue; // 29 Feb in a non-leap year, etc.
            }
            if (! $candidate instanceof Carbon) {
                continue;
            }
            $candidate = $candidate->startOfDay();
            if ($candidate->between($start, $end)) {
                return $candidate;
            }
        }

        return null;
    }

    private function safeDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function humanise(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }
}
