<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Models\StaffBackgroundCheck;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComplianceCalendarController extends Controller
{
    /**
     * Renders compliance calendar showing all compliance deadlines,
     * expiry dates, and training due dates in a calendar format.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);

        $now = now();
        $rangeStart = $now->copy()->subMonths(1)->startOfMonth();
        $rangeEnd = $now->copy()->addMonths(3)->endOfMonth();

        $events = collect();

        // 1. Compliance status expiry dates
        $complianceStatuses = HrStaffComplianceStatus::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$rangeStart, $rangeEnd])
            ->with(['user:id,name', 'requirement:id,name,code'])
            ->get();

        foreach ($complianceStatuses as $status) {
            $events->push([
                'id' => 'compliance-' . $status->id,
                'title' => ($status->requirement?->name ?? 'Compliance') . ' - ' . ($status->user?->name ?? 'Unknown'),
                'start' => $status->expires_at->format('Y-m-d'),
                'type' => 'compliance',
                'color' => $this->getEventColor($status->expires_at, $now),
                'meta' => [
                    'employee' => $status->user?->name,
                    'requirement' => $status->requirement?->name,
                    'requirement_code' => $status->requirement?->code,
                    'status' => $status->status,
                ],
            ]);
        }

        // 2. Vetting expiry dates from staff_background_checks
        $vettingChecks = StaffBackgroundCheck::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$rangeStart, $rangeEnd])
            ->with('user:id,name')
            ->get();

        foreach ($vettingChecks as $check) {
            $events->push([
                'id' => 'vetting-' . $check->id,
                'title' => ucfirst(str_replace('_', ' ', $check->check_type)) . ' - ' . ($check->user?->name ?? 'Unknown'),
                'start' => $check->expires_at->format('Y-m-d'),
                'type' => 'vetting',
                'color' => $this->getEventColor($check->expires_at, $now),
                'meta' => [
                    'employee' => $check->user?->name,
                    'check_type' => $check->check_type,
                    'reference_number' => $check->reference_number,
                    'status' => $check->status,
                ],
            ]);
        }

        // 3. Driver license expiry dates
        $driverRecords = HrDriverEligibility::query()
            ->whereNotNull('licence_expires_at')
            ->whereBetween('licence_expires_at', [$rangeStart, $rangeEnd])
            ->with('user:id,name')
            ->get();

        foreach ($driverRecords as $record) {
            $events->push([
                'id' => 'driver-' . $record->id,
                'title' => 'Driver Licence - ' . ($record->user?->name ?? 'Unknown'),
                'start' => $record->licence_expires_at->format('Y-m-d'),
                'type' => 'driver',
                'color' => $this->getEventColor($record->licence_expires_at, $now),
                'meta' => [
                    'employee' => $record->user?->name,
                    'licence_class' => $record->licence_class,
                    'status' => $record->status,
                ],
            ]);
        }

        // 4. Training enrollment due dates (enrolled/in-progress with session dates)
        $enrollments = HrCourseEnrollment::query()
            ->whereIn('status', ['enrolled', 'in_progress', 'completed'])
            ->with(['user:id,name', 'course:id,title,code', 'session:id,session_date'])
            ->get();

        foreach ($enrollments as $enrollment) {
            $eventDate = $enrollment->completed_at
                ?? ($enrollment->session?->session_date ? $enrollment->session->session_date : null);

            if (! $eventDate) {
                continue;
            }

            $eventDateCarbon = $eventDate instanceof \Carbon\Carbon ? $eventDate : \Carbon\Carbon::parse($eventDate);

            if ($eventDateCarbon->lt($rangeStart) || $eventDateCarbon->gt($rangeEnd)) {
                continue;
            }

            $color = $enrollment->status === 'completed' ? '#22c55e' : '#3b82f6';

            $events->push([
                'id' => 'training-' . $enrollment->id,
                'title' => ($enrollment->course?->title ?? 'Training') . ' - ' . ($enrollment->user?->name ?? 'Unknown'),
                'start' => $eventDateCarbon->format('Y-m-d'),
                'type' => 'training',
                'color' => $color,
                'meta' => [
                    'employee' => $enrollment->user?->name,
                    'course' => $enrollment->course?->title,
                    'course_code' => $enrollment->course?->code,
                    'status' => $enrollment->status,
                ],
            ]);
        }

        $filterType = $request->query('type');
        if ($filterType) {
            $events = $events->where('type', $filterType)->values();
        }

        return Inertia::render('hr/compliance/calendar', [
            'events' => $events->values(),
            'filters' => [
                'type' => $filterType,
            ],
        ]);
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
