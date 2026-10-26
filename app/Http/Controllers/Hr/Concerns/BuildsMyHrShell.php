<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Hr\Services\ESignatureService;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Shared "My HR" page chrome — the hero (greeting + live clock card) and the
 * tab-strip count badges that sit above every `/hr/my/*` surface. Each page
 * merges {@see myHrShellProps()} into its Inertia payload under the `myHr` key
 * so the hero is identical everywhere and stays in sync (clock, weekly hours,
 * "needs attention" badge counts) without each controller method re-deriving it.
 */
trait BuildsMyHrShell
{
    protected function myHrShellProps(User $user): array
    {
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->active()
            ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->with(['user:id,name,email,profile_photo_path', 'primarySite:id,name'])
            ->first();

        // ── Live clock (shared AttendanceService path; never a new endpoint) ──
        $activeClock = HrTimeEntry::query()
            ->forUser($user->id)
            ->active()
            ->first(['id', 'clock_in', 'notes']);

        $todayTotal = (float) HrTimeEntry::query()
            ->forUser($user->id)
            ->where('entry_date', now()->toDateString())
            ->whereNotNull('clock_out')
            ->sum('total_hours');

        $weekly = app(TimeTrackingService::class)->getWeeklySummary($user->id);

        // ── "Needs attention" counts (drive hero badges + tab count badges) ──
        $pendingLeave = HrLeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $docsToSign = app(ESignatureService::class)->getPendingForUser($user)->count();

        $policiesDue = HrPolicy::active()
            ->where('requires_attestation', true)
            ->whereDoesntHave('attestations', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        $onesToAck = HrSupervisionNote::forEmployee($user->id)
            ->where('is_visible_to_employee', true)
            ->where(fn ($q) => $q->whereNull('employee_acknowledged')->orWhere('employee_acknowledged', false))
            ->count();

        $kudosThisMonth = $this->feedService->visibleKudosQuery($user)
            ->receivedBy($user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        // Teammate directory for the "Send kudos" wizard (hosted in the shell,
        // so it must be available on every page). Small list for a care org.
        $teammates = app(HrCurrentStaffService::class)
            ->currentUsersQuery()
            ->whereKeyNot($user->id)
            ->with(['hrEmployeeProfile.primarySite:id,name'])
            ->orderBy('name')
            ->limit(150)
            ->get()
            ->map(fn (User $teammate) => [
                'id' => $teammate->id,
                'name' => $teammate->name,
                'initials' => $this->myHrInitials($teammate->name),
                'role' => $teammate->hrEmployeeProfile?->position_title,
                'site' => $teammate->hrEmployeeProfile?->primarySite?->name,
            ])
            ->filter()
            ->values()
            ->all();

        $name = $profile?->user?->name ?? $user->name ?? 'there';

        $props = [
            'teammates' => $teammates,
            'kudosCategories' => FeedService::KUDOS_CATEGORIES,
            'kudosImpacts' => FeedService::KUDOS_IMPACTS,
            'profile' => [
                'name' => $name,
                'first_name' => trim(explode(' ', $name)[0] ?? $name),
                'initials' => $this->myHrInitials($name),
                'position_title' => $profile?->position_title,
                'site_name' => $profile?->primarySite?->name,
                'avatar' => $profile?->user?->profile_photo_path,
            ],
            'activeClock' => $activeClock ? [
                'id' => $activeClock->id,
                'clock_in' => $activeClock->clock_in->toIso8601String(),
                'notes' => $activeClock->notes,
            ] : null,
            'attendance' => $this->myHrAttendanceProps($user),
            'todayTotal' => $todayTotal,
            'weekly' => [
                'total_hours' => (float) ($weekly['total_hours'] ?? 0),
                'daily_hours' => $weekly['daily_hours'] ?? [],
                'target_hours' => 40,
            ],
            'nextShift' => null,
            'counts' => [
                'pendingLeave' => $pendingLeave,
                'docsToSign' => $docsToSign,
                'policiesDue' => $policiesDue,
                'onesToAck' => $onesToAck,
                'kudosThisMonth' => $kudosThisMonth,
            ],
            'calendar' => $this->myHrCalendarFeed($user, now()),
        ];
        // Calendar/other model hydration must finish before this final frame.
        $scheduleReader = $this->myHrScheduleReader($user);
        $nextShift = $scheduleReader ? $this->myHrReadableScheduledShifts($scheduleReader)
            ->where('user_id', $scheduleReader->id)->where('starts_at', '>=', now()->utc())
            ->orderBy('starts_at')->orderBy('id')->first() : null;
        $props['nextShift'] = $this->myHrScheduledShiftPayload($user, $nextShift, $scheduleReader, true);
        return $props;
    }

    private function myHrScheduleReader(User $user): ?User
    {
        return app(HrCurrentStaffService::class)->currentUsersQuery()->whereKey($user->id)
            ->with('hrEmployeeProfile')->first();
    }

    /** Planned windows use existing current Site and intrinsic Shift authority. */
    private function myHrReadableScheduledShifts(User $reader): Builder
    {
        return (new UserSiteAccessService)->applyShiftScope(Shift::query(), $reader)
            ->visibleToFrontline()->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->whereNotNull('starts_at')->whereNotNull('ends_at')->whereColumn('ends_at', '>', 'starts_at')
            ->select(['id', 'user_id', 'client_id', 'site_id', 'service_context_id', 'starts_at', 'ends_at', 'location', 'shift_type']);
    }

    private function myHrScheduledContext(Shift $shift): ?ServiceContext
    {
        $siteId = (new UserSiteAccessService)->shiftSiteId($shift);
        if (! $shift->relationLoaded('myHrScheduleContext')) {
            $shift->setRelation('myHrScheduleContext', $shift->service_context_id && $siteId
                ? ServiceContext::query()->availableToSite($siteId)->whereKey($shift->service_context_id)->first(['id', 'name', 'site_id'])
                : null);
        }
        $context = $shift->getRelation('myHrScheduleContext');
        if (! ($context instanceof ServiceContext) || ! $siteId) return null;
        return ServiceContext::query()->availableToSite($siteId)->whereKey($shift->service_context_id)
            ->whereKey($context->id)->where('name', $context->name)->where('site_id', $context->site_id)->exists()
            ? $context : null;
    }

    /** Recheck the selected occurrence after related display records are read. */
    private function myHrScheduledShiftStillReadable(User $user, Shift $shift, ?User $reader): bool
    {
        if (! ($shift->starts_at instanceof CarbonInterface) || ! ($shift->ends_at instanceof CarbonInterface)
            || ! $shift->ends_at->gt($shift->starts_at) || (int) $shift->id <= 0) return false;
        if (! $reader || (int) $reader->id !== (int) $user->id || (int) $shift->user_id !== (int) $reader->id
            || ! app(HrCurrentStaffService::class)->currentUsersQuery()->whereKey($reader->id)->exists()) return false;
        $query = $this->myHrReadableScheduledShifts($reader)->whereKey($shift->id);
        $siteId = (new UserSiteAccessService)->shiftSiteId($shift);
        if (! $siteId) return false;
        $this->myHrScheduledAtSite($query, $siteId);
        foreach (['user_id', 'client_id', 'site_id', 'service_context_id', 'starts_at', 'ends_at', 'location', 'shift_type'] as $field) {
            $query->where($field, $shift->getAttribute($field));
        }
        return $query->exists();
    }

    private function myHrScheduledAtSite(Builder $query, int $siteId): Builder
    {
        return $query->where(fn ($scope) => $scope->where('site_id', $siteId)->orWhere(fn ($fallback) => $fallback
            ->whereNull('site_id')->whereHas('client', fn ($client) => $client->where('site_id', $siteId))));
    }

    private function myHrScheduledShiftPayload(User $user, ?Shift $shift, ?User $reader, bool $futureOnly = false): ?array
    {
        if (! $shift) return null;
        $context = $this->myHrScheduledContext($shift);
        if (! $this->myHrScheduledShiftStillReadable($user, $shift, $reader)) return null;
        $at = now()->utc();
        $dayEnd = $at->copy()->setTimezone((string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')))
            ->startOfDay()->addDay()->utc();
        if (! $shift->ends_at->gt($at) || ($futureOnly && $shift->starts_at->lt($at))
            || (! $futureOnly && ! $shift->starts_at->lt($dayEnd))) return null;
        return ['id' => (int) $shift->id, 'starts_at' => $shift->starts_at->toIso8601String(),
            'ends_at' => $shift->ends_at->toIso8601String(), 'location' => $shift->location,
            'service_context_name' => $context?->name];
    }

    /** Canonical self-attendance read state; never a clock command receipt. */
    private function myHrAttendanceProps(User $user): array
    {
        $state = [
            'schema_version' => 1,
            'scope' => 'my_hr_self_attendance',
            'state' => 'unavailable',
            'actor_id' => (int) $user->id,
            'timezone' => (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')),
            'can_break' => false,
            'can_clock_out' => false,
            'session' => null,
            'time_entry' => null,
        ];
        $reader = app(HrCurrentStaffService::class)->currentUsersQuery()->whereKey($user->id)->first();
        if (! $reader) {
            return $state;
        }
        $today = now($state['timezone'])->toDateString();
        $profiles = HrEmployeeProfile::query()->where('user_id', $reader->id)->where('is_active', true)
            ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->limit(2)->get();
        if ($profiles->count() !== 1) {
            return $state;
        }
        $profile = $profiles->first();

        // Resolve independently of the HrTimeEntry projection. A malformed
        // open row must not masquerade as an unlinked legacy clock entry.
        $sessions = HrAttendanceSession::query()
            ->where('user_id', $reader->id)
            ->where(fn ($query) => $query->where('status', 'open')->orWhereNull('clock_out_at'))
            ->limit(2)
            ->get(['id']);
        if ($sessions->isEmpty()) {
            $entries = HrTimeEntry::query()->forUser($reader->id)->active()->limit(2)
                ->get(['id', 'attendance_session_id', 'source_type', 'site_id', 'client_id', 'shift_id', 'clock_in', 'break_minutes']);
            if ($entries->isEmpty()) {
                $state['state'] = 'none';
            } elseif ($entries->count() === 1 && $entries->first()->attendance_session_id === null
                && $entries->first()->source_type !== 'attendance') {
                // Preserve the captured record's current Site/ownership boundary.
                // This does not certify payroll mutability or recovery eligibility.
                $entry = $entries->first();
                $siteId = (int) $entry->site_id;
                $assignedSites = collect([$profile->primary_site_id, ...($profile->secondary_site_ids ?? [])])
                    ->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id);
                if ((int) $entry->id <= 0 || $siteId <= 0 || ! $assignedSites->contains($siteId)
                    || ($entry->shift_id !== null && (int) $entry->shift_id <= 0)
                    || ! ($entry->clock_in instanceof CarbonInterface)
                    || ! is_int($entry->break_minutes) || $entry->break_minutes < 0) {
                    return $state;
                }
                try {
                    app(UserSiteAccessService::class)->activeAttendanceSiteId($siteId);
                } catch (\LogicException) {
                    return $state;
                }
                $client = $entry->client_id === null ? null : Client::query()
                    ->whereKey($entry->client_id)->where('site_id', $siteId)->first(['id']);
                if (($entry->client_id !== null && ! $client)
                    || ($entry->shift_id !== null && (! $client || ! Shift::query()
                        ->whereKey($entry->shift_id)->where('user_id', $reader->id)->where('client_id', $client->id)
                        ->where(fn ($sites) => $sites->whereNull('site_id')->orWhere('site_id', $siteId))->exists()))) {
                    return $state;
                }
                $state['state'] = 'entry_only';
                $state['time_entry'] = [
                    'id' => (int) $entry->id,
                    'shift_id' => $entry->shift_id === null ? null : (int) $entry->shift_id,
                    'clock_in_at' => $entry->clock_in->toIso8601String(),
                    'break_minutes' => $entry->break_minutes,
                ];
            }

            return $state;
        }
        if ($sessions->count() !== 1) {
            return $state;
        }

        try {
            $session = app(AttendanceService::class)->resolveSelfAttendanceSession($reader, $sessions->first()->id);
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }

            return $state;
        }
        if (! ($session instanceof HrAttendanceSession) || (int) $session->id <= 0
            || (int) $session->user_id !== (int) $reader->id || $session->status !== 'open'
            || $session->clock_out_at !== null || ! ($session->clock_in_at instanceof CarbonInterface)
            || ($session->shift_id !== null && (int) $session->shift_id <= 0)
            || ! is_int($session->break_minutes) || $session->break_minutes < 0
            || ! is_int($session->break_count) || $session->break_count < 0) {
            return $state;
        }

        $onBreak = $session->break_started_at !== null;
        $events = $session->breakEvents()->whereNull('ended_at')->limit(2)->get(['id', 'session_id', 'started_at']);
        if ((! $onBreak && $events->isNotEmpty()) || ($onBreak && (
            ! ($session->break_started_at instanceof CarbonInterface) || $events->count() !== 1
            || ! ($events->first()->started_at instanceof CarbonInterface)
            || $session->fromDateTime($events->first()->started_at) !== $session->fromDateTime($session->break_started_at)
        ))) {
            return $state;
        }

        $state['state'] = 'open';
        $state['session'] = [
            'id' => (int) $session->id,
            'shift_id' => $session->shift_id === null ? null : (int) $session->shift_id,
            'clock_in_at' => $session->clock_in_at->toIso8601String(),
            'is_on_break' => $onBreak,
            'break_started_at' => $session->break_started_at?->toIso8601String(),
            'break_minutes' => $session->break_minutes,
            'break_count' => $session->break_count,
        ];
        // The read resolver also admits historical Shift-only Site provenance;
        // actual break commands require a linked Shift's current Client row.
        $hasCommandClient = $session->shift_id === null || (
            (int) ($session->shift?->client_id ?? 0) > 0
            && $session->shift?->client instanceof Client
            && (int) $session->shift->client->id === (int) $session->shift->client_id
        );
        $hasClockGrant = (
            $reader->canDo('timesheets.create') || $reader->canDo('shifts.viewAssigned')
            || $reader->canDo('shifts.update') || $reader->canDo('shifts.manageAny')
        );
        $state['can_break'] = $hasCommandClient && $hasClockGrant
            && (! $onBreak || ! $session->break_started_at->isFuture());
        $clockOutAt = now();
        $runningBreakMinutes = $onBreak ? max(0, (int) $session->break_started_at->diffInMinutes($clockOutAt)) : 0;
        $recordedBreakMinutes = $session->break_minutes + $runningBreakMinutes;
        $elapsedMinutes = (int) $session->clock_in_at->diffInMinutes($clockOutAt);
        $state['can_clock_out'] = $hasCommandClient && $hasClockGrant && $clockOutAt->gt($session->clock_in_at)
            && (! $onBreak || ! $clockOutAt->lessThan($session->break_started_at))
            && ($recordedBreakMinutes === 0 || $recordedBreakMinutes < $elapsedMinutes);

        return $state;
    }

    /**
     * A month of hero-footer calendar events for the viewing employee — their
     * rostered shifts, approved leave, and the application's NZ public holidays.
     * The window spans the visible 6-week (Monday-first) grid so the leading /
     * trailing days of adjacent months carry their dots too. Reused verbatim by
     * the `GET /hr/my/calendar?month=YYYY-MM` paging endpoint, so a month-nav in
     * the popover returns the same shape the shell seeds the first paint with.
     *
     * @return array{month: string, events: array<string, list<array<string, mixed>>>}
     */
    protected function myHrCalendarFeed(User $user, CarbonInterface $anchor): array
    {
        $month = $anchor->copy()->startOfMonth();
        $gridStart = $month->copy()->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $gridEnd = $gridStart->copy()->addDays(41)->endOfDay();

        /** @var array<string, list<array<string, mixed>>> $events */
        $events = [];
        $push = function (string $date, array $event) use (&$events): void {
            $events[$date][] = $event;
        };

        // ── Rostered shifts (read-only from Operations) ──
        Shift::where('user_id', $user->id)
            ->visibleToFrontline()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$gridStart, $gridEnd])
            ->orderBy('starts_at')
            ->with('serviceContext:id,name')
            ->get(['id', 'starts_at', 'ends_at', 'location', 'service_context_id'])
            ->each(function (Shift $shift) use ($push): void {
                if (! $shift->starts_at) {
                    return;
                }
                $push($shift->starts_at->toDateString(), [
                    'type' => 'shift',
                    'title' => $this->myHrShiftTitle($shift),
                    'time' => $this->myHrShiftWindow($shift),
                    'site' => $shift->location ?: $shift->serviceContext?->name,
                    'ref_id' => $shift->id,
                ]);
            });

        // ── Approved leave (expanded across each covered day) ──
        HrLeaveRequest::query()
            ->where('user_id', $user->id)
            ->approved()
            ->where('starts_at', '<=', $gridEnd)
            ->where('ends_at', '>=', $gridStart)
            ->orderBy('starts_at')
            ->get(['id', 'leave_type', 'starts_at', 'ends_at'])
            ->each(function (HrLeaveRequest $leave) use ($push, $gridStart, $gridEnd): void {
                if (! $leave->starts_at || ! $leave->ends_at) {
                    return;
                }
                $cursor = $leave->starts_at->copy()->startOfDay()->max($gridStart->copy()->startOfDay());
                $last = $leave->ends_at->copy()->startOfDay()->min($gridEnd->copy()->startOfDay());
                $title = $this->myHrLeaveTitle($leave->leave_type);
                while ($cursor->lessThanOrEqualTo($last)) {
                    $push($cursor->toDateString(), [
                        'type' => 'leave',
                        'title' => $title,
                        'note' => 'Approved',
                        'ref_id' => $leave->id,
                    ]);
                    $cursor->addDay();
                }
            });

        // ── NZ public holidays (the application catalogue) ──
        HrPublicHoliday::query()
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->orderBy('date')
            ->get(['id', 'name', 'date'])
            ->each(function (HrPublicHoliday $holiday) use ($push): void {
                if (! $holiday->date) {
                    return;
                }
                $push($holiday->date->toDateString(), [
                    'type' => 'holiday',
                    'title' => $holiday->name,
                    'note' => 'Public holiday',
                    'ref_id' => $holiday->id,
                ]);
            });

        return [
            'month' => $month->format('Y-m'),
            // Cast empty to an object so JSON stays `{}` (a keyed map), matching
            // the `Record<string, Event[]>` TS contract rather than `[]`.
            'events' => $events !== [] ? $events : (object) [],
        ];
    }

    private function myHrShiftTitle(Shift $shift): string
    {
        $start = $shift->starts_at;
        $end = $shift->ends_at;

        // Crosses into the next calendar day → an overnight sleepover.
        if ($start && $end && $end->toDateString() !== $start->toDateString()) {
            return 'Sleepover';
        }

        $hour = $start ? (int) $start->format('G') : 9;

        return $hour < 12 ? 'Day shift' : 'Evening shift';
    }

    private function myHrShiftWindow(Shift $shift): ?string
    {
        if (! $shift->starts_at) {
            return null;
        }

        if (! $shift->ends_at) {
            return $shift->starts_at->format('H:i');
        }

        return $shift->starts_at->format('H:i').' – '.$shift->ends_at->format('H:i');
    }

    private function myHrLeaveTitle(?string $leaveType): string
    {
        $label = Str::of((string) $leaveType)
            ->replace(['_', '-'], ' ')
            ->squish()
            ->title()
            ->toString();

        if ($label === '') {
            return 'Leave';
        }

        return Str::contains(Str::lower($label), 'leave') ? $label : $label.' leave';
    }

    private function myHrInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach ($parts as $part) {
            if ($part !== '') {
                $initials .= mb_substr($part, 0, 1);
            }
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }

        return mb_strtoupper($initials !== '' ? $initials : mb_substr($name, 0, 2));
    }
}
