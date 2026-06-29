<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsComplianceHero;
use App\Http\Controllers\Hr\Concerns\ProvidesComplianceWizardData;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Models\StaffBackgroundCheck;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComplianceCalendarController extends Controller
{
    use BuildsComplianceHero;
    use ProvidesComplianceWizardData;
    use ResolvesHrTenant;

    /**
     * Renders compliance calendar showing all compliance deadlines,
     * expiry dates, and training due dates in a calendar format.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $now = now();
        // Capture overdue (back 9 months) through the next 4 months of renewals.
        $rangeStart = $now->copy()->subMonths(9)->startOfDay();
        $rangeEnd = $now->copy()->addMonths(4)->endOfMonth();

        $events = collect();

        // 1. Compliance status expiries.
        HrStaffComplianceStatus::query()
            ->where('tenant_id', $tenantId)
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
            ->where('tenant_id', $tenantId)
            ->whereNotNull('licence_expires_at')
            ->whereBetween('licence_expires_at', [$rangeStart, $rangeEnd])
            ->with('user:id,name')
            ->get()
            ->each(function ($record) use ($events, $now) {
                $events->push($this->event(
                    'driver', $record->id, $record->licence_expires_at, $now,
                    'Driver Licence (Class ' . ($record->licence_class ?: '—') . ')',
                    $record->user?->name ?? 'Unknown',
                    $record->user_id,
                ));
            });

        $filterType = $request->query('type');
        if ($filterType && $filterType !== 'all') {
            $events = $events->where('type', $filterType);
        }

        return Inertia::render('hr/compliance/calendar', [
            'hero' => $this->complianceHero($user, $tenantId),
            'events' => $events->sortBy('start')->values(),
            'wizard' => $this->complianceWizardData($tenantId),
            'filters' => [
                'type' => $filterType ?: 'all',
            ],
            'can' => [
                'manage' => $user->canDo('hr.compliance.manage'),
            ],
        ]);
    }

    /** Build a renewal event row with urgency + human "days" label. */
    private function event(string $type, int $id, $date, $now, string $requirement, string $person, ?int $userId = null): array
    {
        $date = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);
        $diff = (int) round($now->diffInDays($date, false));
        if ($diff < 0) {
            $urgency = 'over';
            $days = abs($diff) . ' days overdue';
        } elseif ($diff <= 30) {
            $urgency = 'soon';
            $days = 'in ' . $diff . ' days';
        } else {
            $urgency = 'far';
            $days = 'in ' . $diff . ' days';
        }

        return [
            'id' => $type . '-' . $id,
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
