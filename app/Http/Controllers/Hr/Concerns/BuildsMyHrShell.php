<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\Shift;
use App\Models\User;

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

        $name = $profile?->user?->name ?? $user->name ?? 'there';

        return [
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
        ];
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
