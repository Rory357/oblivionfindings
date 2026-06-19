<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

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
            'celebrations' => $this->overviewCelebrations($user, $tenantId, $now, $weekStart, $weekEnd),
            'whosOut' => $this->overviewWhosOut($user, $tenantId, $weekStart, $weekEnd),
            'streak' => $this->overviewClockStreak($user, $tenantId, $now),
            'attention' => $this->overviewAttention($user, $tenantId, $now),
        ];
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
                        'sort' => $anniversary->timestamp,
                    ];
                }

                if ($p->start_date->between($now->copy()->subDays(13), $now)) {
                    $out[] = [
                        'id' => 'n'.$p->id,
                        'user_id' => $p->user_id,
                        'name' => $name,
                        'occasion' => '🌱 New starter · '.$p->start_date->isoFormat('ddd'),
                        'sort' => $p->start_date->timestamp,
                    ];
                }
            }
        }

        return collect($out)
            ->sortBy('sort')
            ->take(6)
            ->map(fn ($c) => [
                'id' => $c['id'],
                'user_id' => $c['user_id'],
                'name' => $c['name'],
                'initials' => $this->myHrInitials($c['name']),
                'occasion' => $c['occasion'],
            ])
            ->values()
            ->all();
    }

    private function overviewWhosOut(User $user, int $tenantId, Carbon $weekStart, Carbon $weekEnd): array
    {
        return HrLeaveRequest::where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->where('user_id', '!=', $user->id)
            ->whereDate('starts_at', '<=', $weekEnd->toDateString())
            ->whereDate('ends_at', '>=', $weekStart->toDateString())
            ->with('user:id,name')
            ->orderBy('starts_at')
            ->limit(8)
            ->get()
            ->map(function (HrLeaveRequest $l) use ($weekStart, $weekEnd) {
                $name = $l->user?->name;
                if (! $name) {
                    return null;
                }
                $start = $l->starts_at->greaterThan($weekStart) ? $l->starts_at : $weekStart;
                $end = $l->ends_at->lessThan($weekEnd) ? $l->ends_at : $weekEnd;
                $range = $start->isSameDay($end)
                    ? ($start->isToday() ? 'Today' : $start->isoFormat('ddd'))
                    : $start->isoFormat('ddd').' – '.$end->isoFormat('ddd');

                return [
                    'user_id' => $l->user_id,
                    'name' => $name,
                    'initials' => $this->myHrInitials($name),
                    'range' => $range,
                    'leave_type' => $l->leave_type,
                ];
            })
            ->filter()
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
