<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Shared "My HR" page chrome — the hero (greeting + live clock card) and the
 * tab-strip count badges that sit above every `/hr/my/*` surface. Each page
 * merges {@see myHrShellProps()} into its Inertia payload under the `myHr` key
 * so the hero is identical everywhere and stays in sync (clock, weekly hours,
 * "needs attention" badge counts) without each controller method re-deriving it.
 */
trait BuildsMyHrShell
{
    protected function myHrShellProps(User $user, int $tenantId): array
    {
        $profile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->with(['user:id,name,email,profile_photo_path', 'primarySite:id,name'])
            ->first();

        // ── Live clock (shared AttendanceService path; never a new endpoint) ──
        $activeClock = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->first(['id', 'clock_in', 'notes']);

        $todayTotal = (float) HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->where('entry_date', now()->toDateString())
            ->whereNotNull('clock_out')
            ->sum('total_hours');

        $weekly = app(TimeTrackingService::class)->getWeeklySummary($tenantId, $user->id);

        // ── Next upcoming shift (read-only from Operations) ──
        $nextShift = Shift::where('user_id', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->with('serviceContext:id,name')
            ->first(['id', 'starts_at', 'ends_at', 'location', 'service_context_id']);

        // ── "Needs attention" counts (drive hero badges + tab count badges) ──
        $pendingLeave = HrLeaveRequest::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $docsToSign = HrDocumentSignature::forSigner($user->id)
            ->pending()
            ->count();

        $policiesDue = HrPolicy::active()
            ->where('tenant_id', $tenantId)
            ->where('requires_attestation', true)
            ->whereDoesntHave('attestations', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        $onesToAck = HrSupervisionNote::forTenant($tenantId)
            ->forEmployee($user->id)
            ->where('is_visible_to_employee', true)
            ->where(fn ($q) => $q->whereNull('employee_acknowledged')->orWhere('employee_acknowledged', false))
            ->count();

        $kudosThisMonth = HrKudos::where('tenant_id', $tenantId)
            ->where('to_user_id', $user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        // Teammate directory for the "Send kudos" wizard (hosted in the shell,
        // so it must be available on every page). Small list for a care org.
        $teammates = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $user->id)
            ->with(['user:id,name', 'primarySite:id,name'])
            ->orderBy('position_title')
            ->limit(150)
            ->get()
            ->map(fn (HrEmployeeProfile $p) => $p->user?->name ? [
                'id' => $p->user_id,
                'name' => $p->user->name,
                'initials' => $this->myHrInitials($p->user->name),
                'role' => $p->position_title,
                'site' => $p->primarySite?->name,
            ] : null)
            ->filter()
            ->values()
            ->all();

        $name = $profile?->user?->name ?? $user->name ?? 'there';

        return [
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
            'todayTotal' => $todayTotal,
            'weekly' => [
                'total_hours' => (float) ($weekly['total_hours'] ?? 0),
                'daily_hours' => $weekly['daily_hours'] ?? [],
                'target_hours' => 40,
            ],
            'nextShift' => $nextShift ? [
                'id' => $nextShift->id,
                'starts_at' => $nextShift->starts_at?->toIso8601String(),
                'ends_at' => $nextShift->ends_at?->toIso8601String(),
                'location' => $nextShift->location,
                'service_context_name' => $nextShift->serviceContext?->name,
            ] : null,
            'counts' => [
                'pendingLeave' => $pendingLeave,
                'docsToSign' => $docsToSign,
                'policiesDue' => $policiesDue,
                'onesToAck' => $onesToAck,
                'kudosThisMonth' => $kudosThisMonth,
            ],
            'calendar' => $this->myHrCalendarFeed($user, $tenantId, now()),
        ];
    }

    /**
     * A month of hero-footer calendar events for the viewing employee — their
     * rostered shifts, approved leave, and the tenant's NZ public holidays.
     * The window spans the visible 6-week (Monday-first) grid so the leading /
     * trailing days of adjacent months carry their dots too. Reused verbatim by
     * the `GET /hr/my/calendar?month=YYYY-MM` paging endpoint, so a month-nav in
     * the popover returns the same shape the shell seeds the first paint with.
     *
     * @return array{month: string, events: array<string, list<array<string, mixed>>>}
     */
    protected function myHrCalendarFeed(User $user, int $tenantId, CarbonInterface $anchor): array
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
            ->visibleToFrontline($user->organization_id)
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
        HrLeaveRequest::forTenant($tenantId)
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

        // ── NZ public holidays (national + this tenant's regional set) ──
        HrPublicHoliday::query()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
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
