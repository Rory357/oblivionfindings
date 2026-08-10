<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrComplianceRenewalSnooze;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsComplianceHero;
use App\Http\Controllers\Hr\Concerns\ProvidesComplianceWizardData;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComplianceCalendarController extends Controller
{
    use BuildsComplianceHero;
    use ProvidesComplianceWizardData;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Renders compliance calendar showing all compliance deadlines,
     * expiry dates, and training due dates in a calendar format.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);

        $now = now();
        // Capture overdue (back 9 months) through the next 4 months of renewals.
        $rangeStart = $now->copy()->subMonths(9)->startOfDay();
        $rangeEnd = $now->copy()->addMonths(4)->endOfMonth();

        $events = collect();

        // 1. Compliance status expiries.
        HrStaffComplianceStatus::query()
            ->whereIn('user_id', $this->visibleCurrentStaffIds($user))
            ->whereNotIn('id', $this->activeSnoozedIds('compliance'))
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$rangeStart, $rangeEnd])
            ->with(['user:id,name', 'requirement:id,name,code'])
            ->get()
            ->each(function ($status) use ($events, $now) {
                $events->push($this->event(
                    'compliance', $status->id, $status->expires_at, $now,
                    $status->requirement?->name ?? 'Compliance requirement',
                    $status->user?->name ?? 'Unknown',
                    $status->user_id,
                ));
            });

        // 2. Vetting expiries.
        StaffBackgroundCheck::query()
            ->whereIn('user_id', $this->visibleCurrentStaffIds($user))
            ->whereNotIn('id', $this->activeSnoozedIds('vetting'))
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$rangeStart, $rangeEnd])
            ->with('user:id,name')
            ->get()
            ->each(function ($check) use ($events, $now) {
                $events->push($this->event(
                    'vetting', $check->id, $check->expires_at, $now,
                    ucfirst(str_replace('_', ' ', (string) $check->check_type)),
                    $check->user?->name ?? 'Unknown',
                    $check->user_id,
                ));
            });

        // 3. Driver licence expiries.
        HrDriverEligibility::query()
            ->whereIn('user_id', $this->visibleCurrentStaffIds($user))
            ->whereNotIn('id', $this->activeSnoozedIds('driver'))
            ->whereNotNull('licence_expires_at')
            ->whereBetween('licence_expires_at', [$rangeStart, $rangeEnd])
            ->with('user:id,name')
            ->get()
            ->each(function ($record) use ($events, $now) {
                $events->push($this->event(
                    'driver', $record->id, $record->licence_expires_at, $now,
                    'Driver Licence (Class '.($record->licence_class ?: '—').')',
                    $record->user?->name ?? 'Unknown',
                    $record->user_id,
                ));
            });

        $filterType = (string) $request->query('type', 'all');
        if (! in_array($filterType, ['all', 'compliance', 'vetting', 'driver'], true)) {
            $filterType = 'all';
        }
        if ($filterType !== 'all') {
            $events = $events->where('type', $filterType);
        }

        return Inertia::render('hr/compliance/calendar', [
            'hero' => $this->complianceHero($user),
            'events' => $events->sortBy('start')->values(),
            'wizard' => $this->complianceWizardData($user),
            'filters' => [
                'type' => $filterType,
            ],
            'can' => [
                'manage' => $user->canDo('hr.compliance.manage'),
                // Real perms for the shared hub header's cross-domain create actions.
                'vetting_manage' => $user->canDo('hr.vetting.manage'),
                'driver_manage' => $user->canDo('hr.driver.manage'),
            ],
        ]);
    }

    /** @return Builder<User> */
    private function visibleCurrentStaffIds(User $viewer): Builder
    {
        $query = User::query()->select('id');
        $this->siteAccess->applyStaffScope($query, $viewer);

        return $query;
    }

    /** @return Builder<HrComplianceRenewalSnooze> */
    private function activeSnoozedIds(string $entityType): Builder
    {
        return HrComplianceRenewalSnooze::query()
            ->select('entity_id')
            ->where('entity_type', $entityType)
            ->where('snoozed_until', '>', now());
    }

    /** Build a renewal event row with urgency + human "days" label. */
    private function event(string $type, int $id, $date, $now, string $requirement, string $person, ?int $userId = null): array
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $diff = (int) round($now->diffInDays($date, false));
        if ($diff < 0) {
            $urgency = 'over';
            $days = abs($diff).' days overdue';
        } elseif ($diff <= 30) {
            $urgency = 'soon';
            $days = 'in '.$diff.' days';
        } else {
            $urgency = 'far';
            $days = 'in '.$diff.' days';
        }

        return [
            'id' => $type.'-'.$id,
            'entity_type' => $type,
            'entity_id' => $id,
            'user_id' => $userId,
            'type' => $type,
            'requirement' => $requirement,
            'person' => $person,
            'start' => $date->format('Y-m-d'),
            'date' => $date->format('d M Y'),
            'month' => $urgency === 'over' ? 'Overdue' : $date->format('F Y'),
            'days' => $days,
            'urgency' => $urgency,
            'color' => $this->getEventColor($date, $now),
        ];
    }

    /**
     * Determine event color based on expiry date.
     */
    private function getEventColor($expiresAt, $now): string
    {
        if ($expiresAt->lt($now)) {
            return '#ef4444'; // Red - expired
        }

        if ($expiresAt->diffInDays($now) <= 30) {
            return '#f97316'; // Orange - expiring within 30 days
        }

        return '#3b82f6'; // Blue - upcoming
    }
}
