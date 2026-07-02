<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The Overview tab's "delight" data — recognition, celebrations, who's-out,
 * the clock-in streak and the "Needs your attention" worklist. Kept separate
 * from {@see BuildsMyHrShell} (the always-on hero/clock chrome) since it's only
 * needed on `/hr/my`.
 */
trait BuildsMyHrOverview
{
    protected function myHrOverviewProps(User $user, int $tenantId): array
    {
        $now = now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();

        return [
            'latestKudos' => $this->overviewLatestKudos($user, $tenantId),
            'shoutouts' => $this->myHrShoutouts($user, $tenantId, 'received', 12),
            'celebrations' => $this->overviewCelebrations($user, $tenantId, $now, $weekStart, $weekEnd),
            'whosOut' => $this->overviewWhosOut($user, $tenantId, $weekStart, $weekEnd),
            'leaveBalance' => $this->overviewLeaveBalance($user, $tenantId),
            'todayShift' => $this->overviewTodayShift($user, $now),
            'shiftColleagues' => $this->overviewShiftColleagues($user, $tenantId, $now),
            'streak' => $this->overviewClockStreak($user, $tenantId, $now),
            'attention' => $this->overviewAttention($user, $tenantId, $now),
        ];
    }

    /**
     * The peer-recognition "shout-outs" for the spotlight carousel + the
     * Shout-outs tab. `received` = kudos sent TO me; `given` = kudos I sent.
     * Each carries its emoji reactions (grouped by emoji → reactor list, with a
     * `you` flag) and its reply thread, so the surface is fully wired to real
     * data rather than the prototype's in-memory demo state.
     *
     * @param  'received'|'given'  $box
     * @return list<array<string, mixed>>
     */
    protected function myHrShoutouts(User $user, int $tenantId, string $box = 'received', ?int $limit = null): array
    {
        $column = $box === 'given' ? 'from_user_id' : 'to_user_id';

        $query = HrKudos::where('tenant_id', $tenantId)
            ->where($column, $user->id)
            ->with([
                'fromUser:id,name',
                'toUser:id,name',
                'reactions.user:id,name',
                'replies' => fn ($q) => $q->orderBy('created_at'),
                'replies.user:id,name',
            ])
            ->orderByDesc('created_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $kudos = $query->get();

        // Position titles for everyone involved (givers + recipients), one query.
        $userIds = $kudos->pluck('from_user_id')
            ->merge($kudos->pluck('to_user_id'))
            ->filter()
            ->unique()
            ->all();
        $roles = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->pluck('position_title', 'user_id');

        return $kudos->map(function (HrKudos $k) use ($user, $roles) {
            $reactions = ['heart' => [], 'party' => [], 'hands' => []];
            foreach ($k->reactions as $reaction) {
                if (! array_key_exists($reaction->emoji, $reactions)) {
                    continue;
                }
                $name = $reaction->user?->name ?? 'Someone';
                $reactions[$reaction->emoji][] = [
                    'id' => $reaction->user_id,
                    'name' => $name,
                    'initials' => $this->myHrInitials($name),
                    'you' => $reaction->user_id === $user->id,
                ];
            }

            $replies = $k->replies->map(fn (HrKudosReply $reply) => [
                'id' => $reply->id,
                'user_id' => $reply->user_id,
                'name' => $reply->user?->name ?? 'Someone',
                'initials' => $this->myHrInitials($reply->user?->name ?? '?'),
                'you' => $reply->user_id === $user->id,
                'body' => $reply->body,
                'created_at' => $reply->created_at?->toIso8601String(),
            ])->values()->all();

            return [
                'id' => $k->id,
                'giver' => [
                    'id' => $k->from_user_id,
                    'name' => $k->fromUser?->name ?? 'A teammate',
                    'initials' => $this->myHrInitials($k->fromUser?->name ?? '?'),
                    'role' => $roles[$k->from_user_id] ?? null,
                    'you' => $k->from_user_id === $user->id,
                ],
                'recipient' => [
                    'id' => $k->to_user_id,
                    'name' => $k->toUser?->name ?? 'A teammate',
                    'initials' => $this->myHrInitials($k->toUser?->name ?? '?'),
                    'role' => $roles[$k->to_user_id] ?? null,
                    'you' => $k->to_user_id === $user->id,
                ],
                'category' => $k->category,
                'message' => $k->message,
                'created_at' => $k->created_at?->toIso8601String(),
                'reactions' => $reactions,
                'replies' => $replies,
            ];
        })->values()->all();
    }

    private function overviewLatestKudos(User $user, int $tenantId): ?array
    {
        $kudos = HrKudos::where('tenant_id', $tenantId)
            ->where('to_user_id', $user->id)
            ->with('fromUser:id,name')
            ->orderByDesc('created_at')
            ->first();

        if (! $kudos) {
            return null;
        }

        return [
            'id' => $kudos->id,
            'from_id' => $kudos->from_user_id,
            'from' => $kudos->fromUser?->name,
            'from_initials' => $this->myHrInitials($kudos->fromUser?->name ?? '?'),
            'category' => $kudos->category,
            'message' => $kudos->message,
            'created_at' => $kudos->created_at?->toIso8601String(),
        ];
    }

    private function overviewCelebrations(User $user, int $tenantId, Carbon $now, Carbon $weekStart, Carbon $weekEnd): array
    {
        $profiles = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $user->id)
            ->with('user:id,name')
            ->get(['id', 'user_id', 'date_of_birth', 'start_date']);

        $out = [];
        foreach ($profiles as $p) {
            $name = $p->user?->name;
            if (! $name) {
                continue;
            }

            if ($p->date_of_birth) {
                $bd = $p->date_of_birth->copy()->year($now->year);
                if ($bd->between($weekStart, $weekEnd)) {
                    $out[] = [
                        'id' => 'b'.$p->id,
                        'user_id' => $p->user_id,
                        'name' => $name,
                        'occasion' => $bd->isSameDay($now)
                            ? '🎂 Birthday today'
                            : '🎂 Birthday · '.$bd->isoFormat('ddd'),
                        'type' => 'birthday',
                        'date' => $bd->isSameDay($now) ? 'Today' : $bd->isoFormat('ddd'),
                        'sort' => $bd->timestamp,
                    ];
                }
            }

            if ($p->start_date) {
                $years = $now->year - $p->start_date->year;
                $anniversary = $p->start_date->copy()->year($now->year);
                if ($years >= 1 && $anniversary->between($weekStart, $weekEnd)) {
                    $out[] = [
                        'id' => 'a'.$p->id,
                        'user_id' => $p->user_id,
                        'name' => $name,
                        'occasion' => '🎉 '.$years.' year'.($years === 1 ? '' : 's').' at Kauri Care',
                        'type' => 'anniversary',
                        'date' => $anniversary->isSameDay($now) ? 'Today' : $anniversary->isoFormat('ddd'),
                        'sort' => $anniversary->timestamp,
                    ];
                }

                if ($p->start_date->between($now->copy()->subDays(13), $now)) {
                    $out[] = [
                        'id' => 'n'.$p->id,
                        'user_id' => $p->user_id,
                        'name' => $name,
                        'occasion' => '🌱 New starter · '.$p->start_date->isoFormat('ddd'),
                        'type' => 'new_starter',
                        'date' => $p->start_date->isSameDay($now) ? 'Today' : 'This week',
                        'sort' => $p->start_date->timestamp,
                    ];
                }
            }
        }

        return collect($out)
            ->sortBy('sort')
            ->take(8)
            ->map(fn ($c) => [
                'id' => $c['id'],
                'user_id' => $c['user_id'],
                'name' => $c['name'],
                'initials' => $this->myHrInitials($c['name']),
                'occasion' => $c['occasion'],
                'type' => $c['type'],
                'date' => $c['date'],
            ])
            ->values()
            ->all();
    }

    /**
     * Mon–Fri who's-out columns for the Leave tab's team calendar — each
     * weekday with the teammates whose approved leave covers that day.
     */
    protected function myHrWhosOutByDay(User $user, int $tenantId): array
    {
        $now = now();
        $weekStart = $now->copy()->startOfWeek();

        $leave = HrLeaveRequest::where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->where('user_id', '!=', $user->id)
            ->whereDate('starts_at', '<=', $weekStart->copy()->addDays(4)->toDateString())
            ->whereDate('ends_at', '>=', $weekStart->toDateString())
            ->with('user:id,name')
            ->get();

        $cols = [];
        for ($i = 0; $i < 5; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $people = $leave
                ->filter(fn (HrLeaveRequest $l) => $l->user
                    && $day->between($l->starts_at->copy()->startOfDay(), $l->ends_at->copy()->endOfDay()))
                ->map(fn (HrLeaveRequest $l) => [
                    'user_id' => $l->user_id,
                    'name' => $l->user->name,
                    'initials' => $this->myHrInitials($l->user->name),
                ])
                ->values()
                ->all();

            $cols[] = [
                'day' => $day->isoFormat('ddd'),
                'date' => $day->format('j'),
                'today' => $day->isSameDay($now),
                'people' => $people,
            ];
        }

        return $cols;
    }

    private function overviewWhosOut(User $user, int $tenantId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $leave = HrLeaveRequest::where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->where('user_id', '!=', $user->id)
            ->whereDate('starts_at', '<=', $weekEnd->toDateString())
            ->whereDate('ends_at', '>=', $weekStart->toDateString())
            ->with('user:id,name')
            ->orderBy('starts_at')
            ->limit(12)
            ->get();

        // Roles + sites for everyone away, in one query (for the "See all" modal).
        $profiles = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->whereIn('user_id', $leave->pluck('user_id')->filter()->unique()->all())
            ->with('primarySite:id,name')
            ->get(['id', 'user_id', 'position_title', 'primary_site_id'])
            ->keyBy('user_id');

        return $leave->map(function (HrLeaveRequest $l) use ($weekStart, $weekEnd, $profiles) {
            $name = $l->user?->name;
            if (! $name) {
                return null;
            }
            $start = $l->starts_at->greaterThan($weekStart) ? $l->starts_at : $weekStart;
            $end = $l->ends_at->lessThan($weekEnd) ? $l->ends_at : $weekEnd;
            $range = $start->isSameDay($end)
                ? ($start->isToday() ? 'Today' : $start->isoFormat('ddd'))
                : $start->isoFormat('ddd').' – '.$end->isoFormat('ddd');

            // Whole leave span (not just the in-week slice) for the modal's day count.
            $days = (int) $l->starts_at->copy()->startOfDay()->diffInDays($l->ends_at->copy()->startOfDay()) + 1;
            $profile = $profiles->get($l->user_id);
            $role = trim(implode(' · ', array_filter([
                $profile?->position_title,
                $profile?->primarySite?->name,
            ])));

            return [
                'user_id' => $l->user_id,
                'name' => $name,
                'initials' => $this->myHrInitials($name),
                'range' => $range,
                'days' => $days,
                'days_label' => $days.' day'.($days === 1 ? '' : 's'),
                'role' => $role !== '' ? $role : 'Team member',
                'leave_type' => $l->leave_type,
            ];
        })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The viewing employee's own leave balances as labelled progress bars
     * (remaining vs. accrued), token-coloured by type. Drives the Overview's
     * "Leave balance" card.
     *
     * @return list<array<string, mixed>>
     */
    private function overviewLeaveBalance(User $user, int $tenantId): array
    {
        $order = ['annual' => 0, 'sick' => 1, 'alternative' => 2, 'time_in_lieu' => 2, 'lieu' => 2];

        return HrLeaveBalance::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('year', now()->year)
            ->get()
            ->sortBy(fn (HrLeaveBalance $b) => $order[$b->leave_type] ?? 9)
            ->take(4)
            ->map(function (HrLeaveBalance $b) {
                $accrued = (float) $b->accrued_hours;
                $remaining = (float) $b->balance_hours;
                $frac = $accrued > 0 ? min(1, max(0, $remaining / $accrued)) : 0;

                return [
                    'leave_type' => $b->leave_type,
                    'label' => $this->overviewLeaveLabel($b->leave_type),
                    'remaining_days' => round($remaining / 8, 1),
                    'frac' => round($frac, 3),
                    'token' => $this->overviewLeaveToken($b->leave_type),
                ];
            })
            ->values()
            ->all();
    }

    private function overviewLeaveLabel(?string $type): string
    {
        $key = Str::lower((string) $type);
        if ($key === 'annual') {
            return 'Annual leave';
        }
        if ($key === 'sick') {
            return 'Sick leave';
        }
        if (Str::contains($key, ['alt', 'lieu'])) {
            return 'Alternative days';
        }

        return Str::of($key)->replace('_', ' ')->title()->append(' leave')->toString();
    }

    private function overviewLeaveToken(?string $type): string
    {
        $key = Str::lower((string) $type);
        if ($key === 'sick') {
            return '--status-warning';
        }
        if (Str::contains($key, ['alt', 'lieu'])) {
            return '--status-info';
        }
        if ($key === 'annual') {
            return '--category-hr';
        }

        return '--primary';
    }

    /**
     * Today's shift for the live "Your day" feature card — its window powers the
     * timeline + "Now" marker. Distinct from the shell's `nextShift` (which only
     * looks ahead and so misses an already-started shift). Returns null on a day
     * off.
     *
     * @return array<string, mixed>|null
     */
    private function overviewTodayShift(User $user, Carbon $now): ?array
    {
        $shift = Shift::where('user_id', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->whereDate('starts_at', $now->toDateString())
            ->orderBy('starts_at')
            ->with('serviceContext:id,name')
            ->first(['id', 'starts_at', 'ends_at', 'location', 'service_context_id', 'shift_type']);

        if (! $shift || ! $shift->starts_at) {
            return null;
        }

        return [
            'id' => $shift->id,
            'starts_at' => $shift->starts_at->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'site' => $shift->serviceContext?->name ?? $shift->location ?? 'Shift',
            'shift_type' => $shift->shift_type ?? 'standard',
        ];
    }

    /**
     * Teammates sharing today's shift (same service context / site, overlapping
     * window) — powers the "On with Mere & Tomas" line on the next-shift card.
     * Returns [] whenever there's no shift today or no overlap.
     *
     * @return list<array<string, mixed>>
     */
    private function overviewShiftColleagues(User $user, int $tenantId, Carbon $now): array
    {
        $shift = Shift::where('user_id', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->whereDate('starts_at', $now->toDateString())
            ->orderBy('starts_at')
            ->first(['id', 'service_context_id', 'location', 'starts_at', 'ends_at']);

        if (! $shift || ! $shift->starts_at) {
            return [];
        }

        $end = $shift->ends_at ?: $shift->starts_at->copy()->addHours(8);

        $colleagueIds = Shift::where('user_id', '!=', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->when(
                $shift->service_context_id,
                fn ($q) => $q->where('service_context_id', $shift->service_context_id),
                fn ($q) => $shift->location ? $q->where('location', $shift->location) : $q->whereRaw('1 = 0'),
            )
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $shift->starts_at)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->take(4)
            ->all();

        if ($colleagueIds === []) {
            return [];
        }

        return User::whereIn('id', $colleagueIds)
            ->get(['id', 'name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'first_name' => trim(explode(' ', $u->name)[0] ?? $u->name),
                'initials' => $this->myHrInitials($u->name),
            ])
            ->values()
            ->all();
    }

    private function overviewClockStreak(User $user, int $tenantId, Carbon $now): int
    {
        $dates = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->where('entry_date', '>=', $now->copy()->subDays(45)->toDateString())
            ->pluck('entry_date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->unique()
            ->flip();

        $streak = 0;
        $cursor = $now->copy()->startOfDay();
        // Not having clocked in *yet* today shouldn't break a run — start at
        // yesterday if today has no entry.
        if (! $dates->has($cursor->toDateString())) {
            $cursor->subDay();
        }
        while ($dates->has($cursor->toDateString())) {
            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }

    private function overviewAttention(User $user, int $tenantId, Carbon $now): array
    {
        $items = [];

        // 0. An active improvement plan waiting on the employee's acknowledgement.
        //    The subject can open their own plan read-only (PipController::show).
        $pip = HrPerformanceImprovementPlan::query()
            // Legacy plans were stored with a NULL tenant (users carry no
            // tenant_id column) — match those too so they still surface.
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('employee_user_id', $user->id)
            ->whereIn('status', ['active', 'in_progress'])
            ->where('employee_acknowledged', false)
            ->orderBy('start_date')
            ->first(['id', 'title', 'start_date', 'end_date']);
        if ($pip) {
            $items[] = [
                'id' => 'pip',
                'tone' => 'critical',
                'icon' => 'alert',
                'label' => 'Review & acknowledge your support plan: '.$pip->title,
                'meta' => 'Your manager has set out expectations and support — please read and acknowledge it',
                'badge' => 'Acknowledge',
                'cta' => 'Review',
                'go' => 'overview',
                'href' => "/hr/performance/pips/{$pip->id}",
            ];
        }

        // 1. Documents awaiting signature.
        $pendingSigs = HrDocumentSignature::forSigner($user->id)
            ->pending()
            ->with('document:id,title')
            ->orderBy('requested_at')
            ->get();
        if ($pendingSigs->count() > 0) {
            $titles = $pendingSigs->pluck('document.title')->filter()->take(2)->implode(' · ');
            $items[] = [
                'id' => 'sign',
                'tone' => 'critical',
                'icon' => 'pen',
                'label' => $pendingSigs->count().' document'.($pendingSigs->count() === 1 ? '' : 's').' awaiting your signature',
                'meta' => $titles !== '' ? $titles : 'Sent by HR',
                'badge' => 'Sign',
                'cta' => 'Sign now',
                'go' => 'documents',
            ];
        }

        // 1.5 Overdue tasks on my own active onboarding checklist (only the
        //     ones the subject can actually action — assigned to them, or
        //     unassigned and not sign-off). Deep-links to the Overview's
        //     "Getting started" card.
        $profileId = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->value('id');
        if ($profileId) {
            $overdueOnboarding = HrOnboardingTask::query()
                ->whereHas('checklist', fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('employee_profile_id', $profileId)
                    ->whereIn('status', ['pending', 'in_progress']))
                ->where('status', '!=', 'completed')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $now->toDateString())
                ->where(fn ($q) => $q
                    ->where('assigned_to_user_id', $user->id)
                    ->orWhere(fn ($qq) => $qq
                        ->whereNull('assigned_to_user_id')
                        ->where('sign_off_required', false)))
                ->count();

            if ($overdueOnboarding > 0) {
                $items[] = [
                    'id' => 'onboarding',
                    'tone' => 'warning',
                    'icon' => 'alert',
                    'label' => $overdueOnboarding.' onboarding task'.($overdueOnboarding === 1 ? '' : 's').' overdue',
                    'meta' => 'Finish your Getting started checklist below',
                    'badge' => 'Onboarding',
                    'cta' => 'Open',
                    'go' => 'overview',
                ];
            }
        }

        // 2. Policy attestation due.
        $policy = HrPolicy::active()
            ->where('tenant_id', $tenantId)
            ->where('requires_attestation', true)
            ->whereDoesntHave('attestations', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('title')
            ->first(['id', 'title']);
        $policyCount = HrPolicy::active()
            ->where('tenant_id', $tenantId)
            ->where('requires_attestation', true)
            ->whereDoesntHave('attestations', fn ($q) => $q->where('user_id', $user->id))
            ->count();
        if ($policy) {
            $items[] = [
                'id' => 'attest',
                'tone' => 'warning',
                'icon' => 'shield',
                'label' => 'Policy attestation due: '.$policy->title,
                'meta' => $policyCount > 1 ? ($policyCount - 1).' more outstanding' : 'Read &amp; attest',
                'badge' => 'Attest',
                'cta' => 'Review',
                'go' => 'policies',
            ];
        }

        // 3. Prep your next 1:1.
        $note = HrSupervisionNote::forTenant($tenantId)
            ->forEmployee($user->id)
            ->where('is_visible_to_employee', true)
            ->whereNotNull('next_session_date')
            ->whereDate('next_session_date', '>=', $now->toDateString())
            ->with('supervisor:id,name')
            ->orderBy('next_session_date')
            ->first();
        if ($note) {
            $items[] = [
                'id' => 'prep',
                'tone' => 'info',
                'icon' => 'message',
                'label' => 'Prep your 1:1 with '.($note->supervisor?->name ?? 'your manager'),
                'meta' => $note->next_session_date->isoFormat('dddd D MMM').' · add talking points',
                'badge' => '1:1',
                'cta' => 'Prep',
                'go' => 'one',
            ];
        }

        // 4. Certificate expiring soon.
        $cert = HrStaffComplianceStatus::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'expiring_soon')
            ->with('requirement:id,name')
            ->orderBy('expires_at')
            ->first();
        if ($cert) {
            $days = $cert->expires_at
                ? (int) ceil($now->copy()->startOfDay()->diffInDays($cert->expires_at->copy()->startOfDay(), false))
                : null;
            $items[] = [
                'id' => 'cert',
                'tone' => 'warning',
                'icon' => 'alert',
                'label' => ($cert->requirement?->name ?? 'A certificate').' expires'.($days !== null && $days >= 0 ? ' in '.$days.' day'.($days === 1 ? '' : 's') : ' soon'),
                'meta' => 'Renewal available in the LMS',
                'badge' => 'Expiring',
                'cta' => 'Renew',
                'go' => 'training',
            ];
        }

        return $items;
    }
}
