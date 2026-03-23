<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AvailabilityController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $staff = User::query()
            ->where('organization_id', $auth->organization_id)
            ->staff()
            ->with(['staffAvailability', 'staffTimeOff' => function ($q) {
                $q->where('ends_at', '>=', now());
            }])
            ->orderBy('name')
            ->get();

        $upcomingLeave = collect();

        if (Schema::hasTable('hr_leave_requests')) {
            $upcomingLeave = \App\Domain\Hr\Models\HrLeaveRequest::query()
                ->whereIn('user_id', $staff->pluck('id'))
                ->where('status', 'approved')
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at')
                ->get()
                ->groupBy('user_id');
        }

        return inertia('operations/availability/Index', [
            'staff' => $staff,
            'upcomingLeave' => $upcomingLeave,
        ]);
    }
}
