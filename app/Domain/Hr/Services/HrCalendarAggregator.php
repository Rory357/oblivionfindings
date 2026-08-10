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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
 * Everything is bounded by canonical Site and audience access. Colours are
 * emitted as design-token *names* (never raw hex); the React page resolves
 * them to hsl(var(--token)).
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

    /** The current viewer's id, set per feed() call so eventRow can surface "my RSVP". */
    private ?int $viewerId = null;

    private ?User $viewer = null;

    public function __construct(
        private readonly LeaveService $leaveService,
        private readonly ShiftCoverageService $shiftCoverageService,
        private readonly HrCalendarAccessService $access,
    ) {}

    /**
     * @param  list<string>  $layers  active layer keys to compute (perf gate)
     * @param  array{site_id?: int|string|null, team?: string|null, department_id?: int|string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function feed(
        string $from,
        string $to,
        array $layers,
        array $filters = [],
        ?User $viewer = null,
    ): array {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        $this->viewer = $viewer;
        $want = fn (string $layer): bool => in_array($layer, $layers, true);

        $out = collect();

        if ($want('event')) {
            $out = $out->concat($this->events($start, $end, $filters, $viewer));
        }
        if ($want('leave') || $want('holiday')) {
            [$leave, $holidays] = $this->leaveAndHolidays($start, $end, $filters, $viewer);
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
            $out = $out->concat($this->compliance($start, $end, $filters, $viewer));
        }
        if ($want('milestone')) {
            $out = $out->concat($this->milestones($start, $end, $filters, $viewer));
        }

        return $out->values()->all();
    }

    /* ── Events (editable) ───────────────────────────────────────────────── */

    private function events(Carbon $start, Carbon $end, array $filters, ?User $viewer = null): Collection
    {
        if (! Schema::hasTable('hr_calendar_events')) {
            return collect();
        }

        $this->viewerId = $viewer?->id;
        $teamFilter = HrEmployeeProfile::normalizeTeam($filters['team'] ?? null);

        // Top-level events only (exception/override children are folded into
        // their parent's expansion below). Pull non-recurring events overlapping
        // the range plus any recurring base whose window touches the range.
        $baseQuery = HrCalendarEvent::query()
            ->active()
            ->whereNull('recurrence_parent_id')
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->where(function ($q) use ($start, $end) {
                $q->where(function ($q2) use ($start, $end) {
                    $q2->whereNull('rrule')
                        ->where('starts_at', '<=', $end)
                        ->where('ends_at', '>=', $start);
                })->orWhere(function ($q2) use ($start) {
                    $q2->whereNotNull('rrule')
                        ->where(fn ($q3) => $q3->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', $start));
                });
            })
            ->with(['creator:id,name', 'site:id,name', 'departmentRef:id,name', 'attendees.user:id,name', 'reminders', 'attachments'])
            ->orderBy('starts_at');

        if ($viewer) {
            $this->access->applySiteScope($baseQuery, $viewer);
        } else {
            $baseQuery->whereRaw('1 = 0');
        }

        $base = $baseQuery->get()
            ->filter(fn (HrCalendarEvent $event) => $viewer && $this->access->canViewEvent($viewer, $event))
            ->filter(fn (HrCalendarEvent $event) => $this->eventMatchesTeamFilter($event, $teamFilter));

        // Override children for the recurring bases in scope.
        $recurringIds = $base->whereNotNull('rrule')->pluck('id');
        $exceptions = $recurringIds->isEmpty()
            ? collect()
            : HrCalendarEvent::query()
                ->whereIn('recurrence_parent_id', $recurringIds->all())
                ->active()
                ->where('is_exception', true)
                ->with([
                    'creator:id,name',
                    'site:id,name',
                    'departmentRef:id,name',
                    'attendees.user:id,name',
                    'reminders',
                    'attachments',
                    'recurrenceParent.attendees.user:id,name',
                    'recurrenceParent.reminders',
                    'recurrenceParent.attachments',
                ])
                ->get()
                ->groupBy('recurrence_parent_id');

        $out = collect();
        foreach ($base as $e) {
            if (! $e->rrule) {
                $out->push($this->eventRow($e, $e->starts_at, $e->ends_at));

                continue;
            }

            $children = $exceptions->get($e->id) ?? collect();
            $skip = $children->pluck('exception_date')->filter()
                ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)->all();

            foreach ($this->occurrences($e, $start, $end) as $occ) {
                if (in_array($occ['date'], $skip, true)) {
                    continue;
                }
                $out->push($this->eventRow($e, $occ['start'], $occ['end'], $occ['date']));
            }

            // Render override children that land in range as their own events.
            foreach ($children as $child) {
                if ($child->starts_at && $child->ends_at
                    && $child->starts_at->lte($end) && $child->ends_at->gte($start)) {
                    $out->push($this->eventRow($child, $child->starts_at, $child->ends_at, null, true));
                }
            }
        }

        return $out->values();
    }

    /** Build one feed row for an event (or one expanded occurrence of it). */
    private function eventRow(HrCalendarEvent $e, ?Carbon $start, ?Carbon $end, ?string $occurrenceDate = null, bool $isException = false): array
    {
        $recurring = (bool) $e->rrule || $isException || $occurrenceDate !== null;
        $presentationEvent = $isException && $e->recurrenceParent ? $e->recurrenceParent : $e;
        $audience = $this->attendeeSummary($presentationEvent);

        return [
            'layer' => 'event',
            'id' => $occurrenceDate ? 'event-'.$e->id.'-'.$occurrenceDate : 'event-'.$e->id,
            'title' => $e->title,
            'start' => optional($start)->toIso8601String(),
            'end' => optional($end)->toIso8601String(),
            'allDay' => (bool) $e->is_all_day,
            'color' => self::EVENT_CATEGORY_TOKENS[$e->event_type] ?? 'category-hr',
            'editable' => $this->viewer !== null && $this->access->canManageEvent($this->viewer, $e),
            'extendedProps' => [
                'eventId' => $e->id,
                'category' => $e->event_type,
                'site' => $e->site?->name,
                'siteId' => $e->site_id,
                'department' => $e->departmentRef?->name ?? $e->department,
                'departmentId' => $e->department_id,
                'location' => $e->location,
                'description' => $e->description,
                'isAllDay' => (bool) $e->is_all_day,
                'startRaw' => optional($start)->toIso8601String(),
                'endRaw' => optional($end)->toIso8601String(),
                'createdBy' => $e->creator?->name,
                'recurring' => $recurring,
                'rrule' => $e->rrule,
                'recurrenceUntil' => optional($e->recurrence_until)->toDateString(),
                'occurrenceDate' => $occurrenceDate,
                'isException' => $isException,
                'recurrenceParentId' => $e->recurrence_parent_id,
                'attendeeCount' => $audience['count'],
                'audienceType' => $audience['type'],
                'audienceRef' => $audience['ref'],
                'attendeeSample' => $audience['sample'],
                'attendeeUserIds' => $audience['userIds'],
                'rsvp' => $audience['rsvp'],
                'myRsvp' => $audience['myRsvp'],
                'reminders' => $presentationEvent->relationLoaded('reminders')
                    ? $presentationEvent->reminders->map(fn ($r) => [
                        'offset_minutes' => (int) $r->offset_minutes,
                        'channel' => $r->channel,
                    ])->values()->all()
                    : [],
                'attachments' => $presentationEvent->relationLoaded('attachments')
                    ? $presentationEvent->attachments->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->original_name,
                        'mime' => $a->mime,
                        'size' => (int) $a->size,
                        'url' => url('/hr/calendar/attachments/'.$a->id.'/download'),
                    ])->values()->all()
                    : [],
            ],
        ];
    }

    /**
     * Summarise an event's audience for the feed: invited-people count, a sample
     * of names, RSVP tallies, and the current viewer's own response.
     *
     * @return array{count: int, type: string|null, ref: string|null, sample: list<string>, userIds: list<int>, rsvp: array<string,int>, myRsvp: string|null}
     */
    private function attendeeSummary(HrCalendarEvent $e): array
    {
        if (! $e->relationLoaded('attendees')) {
            return ['count' => 0, 'type' => null, 'ref' => null, 'sample' => [], 'userIds' => [], 'rsvp' => [], 'myRsvp' => null];
        }

        $people = $e->attendees->where('audience_type', 'person');
        $group = $e->attendees->firstWhere(fn ($a) => $a->audience_type !== 'person');

        $rsvp = ['yes' => 0, 'no' => 0, 'maybe' => 0, 'none' => 0];
        foreach ($people as $p) {
            $rsvp[$p->rsvp_status] = ($rsvp[$p->rsvp_status] ?? 0) + 1;
        }

        return [
            'count' => $people->count(),
            'type' => $group?->audience_type ?? ($people->isNotEmpty() ? 'people' : null),
            'ref' => $group?->audience_ref,
            'sample' => $people->take(4)->map(fn ($p) => $p->user?->name)->filter()->values()->all(),
            'userIds' => $people->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            'rsvp' => $rsvp,
            'myRsvp' => $this->viewerId
                ? $people->firstWhere('user_id', $this->viewerId)?->rsvp_status
                : null,
        ];
    }

    private function eventMatchesTeamFilter(HrCalendarEvent $event, ?string $teamFilter): bool
    {
        if ($teamFilter === null) {
            return true;
        }

        $teamAudience = $event->attendees->firstWhere('audience_type', 'team');
        if (! $teamAudience) {
            return true;
        }

        $audienceTeam = HrEmployeeProfile::normalizeTeam($teamAudience->audience_ref);

        return $audienceTeam !== null
            && mb_strtolower($audienceTeam) === mb_strtolower($teamFilter);
    }

    /**
     * Expand a recurring base event's occurrences within [rangeStart, rangeEnd].
     * Supports a small RFC-5545 subset: FREQ=DAILY|WEEKLY|MONTHLY, INTERVAL,
     * COUNT; the optional UNTIL is carried on the `recurrence_until` column.
     *
     * @return list<array{date: string, start: Carbon, end: Carbon}>
     */
    private function occurrences(HrCalendarEvent $e, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rule = $this->parseRrule($e->rrule, $e->id);
        if (! $rule || ! $e->starts_at) {
            return [];
        }

        $durationSec = $e->ends_at ? max(0, $e->ends_at->getTimestamp() - $e->starts_at->getTimestamp()) : 0;
        $hardEnd = $e->recurrence_until ? $rangeEnd->copy()->min($e->recurrence_until) : $rangeEnd->copy();

        $cursor = $e->starts_at->copy();
        $out = [];
        $index = 0;
        while ($cursor->lte($hardEnd) && $index < 400) {
            $index++;
            if ($rule['count'] !== null && $index > $rule['count']) {
                break;
            }
            if ($cursor->gte($rangeStart)) {
                $occStart = $cursor->copy();
                $out[] = [
                    'date' => $occStart->toDateString(),
                    'start' => $occStart,
                    'end' => $occStart->copy()->addSeconds($durationSec),
                ];
            }
            match ($rule['freq']) {
                'DAILY' => $cursor->addDays($rule['interval']),
                'WEEKLY' => $cursor->addWeeks($rule['interval']),
                'MONTHLY' => $cursor->addMonthsNoOverflow($rule['interval']),
                default => $cursor->addYears(1000),
            };
        }

        return $out;
    }

    /** @return array{freq: string, interval: int, count: int|null}|null */
    private function parseRrule(?string $rrule, ?int $eventId = null): ?array
    {
        if (! $rrule) {
            return null;
        }
        $parts = [];
        foreach (explode(';', $rrule) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            $parts[strtoupper(trim((string) $k))] = trim((string) $v);
        }
        $freq = $parts['FREQ'] ?? null;
        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY'], true)) {
            // A non-empty RRULE we can't expand means the event silently loses
            // all its occurrences — leave a trace instead of failing mute.
            Log::info('HR calendar RRULE failed to parse; recurring event will not expand.', [
                'event_id' => $eventId,
                'rrule' => $rrule,
            ]);

            return null;
        }

        return [
            'freq' => $freq,
            'interval' => max(1, (int) ($parts['INTERVAL'] ?? 1)),
            'count' => isset($parts['COUNT']) ? max(1, (int) $parts['COUNT']) : null,
        ];
    }

    /* ── Leave + holidays (read-only; reuse the hub feed + redaction) ─────── */

    /**
     * @return array{0: Collection, 1: Collection}
     */
    private function leaveAndHolidays(Carbon $start, Carbon $end, array $filters, ?User $viewer): array
    {
        if (! Schema::hasTable('hr_leave_requests')) {
            return [collect(), collect()];
        }

        $canSeeSensitive = (bool) $viewer?->canDo('hr.leave.manage');
        $leaveFilters = ! empty($filters['site_id']) ? ['site_id' => $filters['site_id']] : [];
        $visibleUserIds = $viewer
            ? $this->access->visibleCurrentStaffQuery($viewer)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $leave = collect();
        $holidays = collect();
        $seenLeave = [];
        $seenHoliday = [];

        // calendarFeed() is month-bound; a visible range can span up to 3 months.
        // Walk each month, reusing the hub's exact redaction + context logic.
        $cursor = $start->copy()->startOfMonth();
        $guard = 0;
        while ($cursor->lte($end) && $guard++ < 6) {
            if (! $viewer) {
                break;
            }
            $feed = $this->leaveService->calendarFeed(
                $viewer,
                $cursor->format('Y-m'),
                $leaveFilters,
                $viewer->canDo('hr.leave.approve') || $viewer->canDo('hr.leave.manage'),
                $canSeeSensitive,
            );

            foreach ($feed['entries'] as $entry) {
                if (! in_array((int) ($entry['user_id'] ?? 0), $visibleUserIds, true)) {
                    continue;
                }
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
        if (! Schema::hasTable('shifts')) {
            return collect();
        }

        $siteId = ! empty($filters['site_id']) ? (int) $filters['site_id'] : null;

        $shiftQuery = Shift::query()
            ->with(['client:id,first_name,last_name,site_id', 'site:id,name', 'staff:id,name'])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId));
        $this->access->applyShiftScope($shiftQuery, $viewer);
        $shifts = $shiftQuery->get();

        $coverageSiteIds = $siteId ? [$siteId] : $this->access->accessibleSiteIds($viewer);
        $coverageWindows = collect($coverageSiteIds)
            ->flatMap(fn (int $visibleSiteId) => $this->shiftCoverageService
                ->buildRangeCoverage($start, $end, $visibleSiteId));

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

    private function compliance(Carbon $start, Carbon $end, array $filters, ?User $viewer): Collection
    {
        $now = now();
        $out = collect();
        $urgency = function (Carbon $expires) use ($now): string {
            return $expires->lt($now) || $expires->diffInDays($now) <= 30 ? 'critical' : 'warning';
        };

        $visibleUserIds = $viewer
            ? $this->access->visibleCurrentStaffQuery($viewer)->select('users.id')
            : User::query()->select('users.id')->whereRaw('1 = 0');

        if (Schema::hasTable('hr_staff_compliance_statuses')) {
            HrStaffComplianceStatus::query()
                ->whereIn('user_id', clone $visibleUserIds)
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
        }

        if (Schema::hasTable('staff_background_checks')) {
            StaffBackgroundCheck::query()
                ->whereIn('user_id', clone $visibleUserIds)
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
        }

        if (Schema::hasTable('hr_driver_eligibility')) {
            HrDriverEligibility::query()
                ->whereIn('user_id', clone $visibleUserIds)
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
        }

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

    private function milestones(Carbon $start, Carbon $end, array $filters, ?User $viewer): Collection
    {
        $out = collect();

        if (! Schema::hasTable('hr_employee_profiles')) {
            return $out;
        }

        $profiles = $viewer
            ? $this->access->visibleCurrentProfilesQuery($viewer)
            : HrEmployeeProfile::query()->whereRaw('1 = 0');

        $profiles
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
