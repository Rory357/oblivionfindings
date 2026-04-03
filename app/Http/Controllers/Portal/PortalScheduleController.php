<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyPortalSetting;
use App\Models\FamilyVisitRequest;
use App\Models\Shift;
use Illuminate\Http\Request;

class PortalScheduleController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $portalSettings = FamilyPortalSetting::where('client_id', $client->id)->first();
        $showShifts = (bool) $portalSettings?->show_shift_schedule;

        $shifts = collect();

        if ($showShifts) {
            $shifts = Shift::where('client_id', $client->id)
                ->whereBetween('starts_at', [now()->startOfDay(), now()->addDays(30)])
                ->orderBy('starts_at')
                ->with('staff:id,name,profile_photo_path')
                ->get()
                ->map(fn ($shift) => [
                    'id' => $shift->id,
                    'starts_at' => $shift->starts_at?->toISOString(),
                    'ends_at' => $shift->ends_at?->toISOString(),
                    'status' => $shift->status,
                    'type' => $shift->type,
                    'date' => $shift->starts_at?->format('Y-m-d'),
                    'staff' => $shift->staff ? [
                        'id' => $shift->staff->id,
                        'name' => $shift->staff->name,
                        'avatar' => $shift->staff->avatar,
                    ] : null,
                ]);
        }

        $visitRequests = FamilyVisitRequest::where('user_id', $user->id)
            ->where('client_id', $client->id)
            ->upcoming()
            ->orderBy('requested_date')
            ->get()
            ->map(fn ($visit) => [
                'id' => $visit->id,
                'requested_date' => $visit->requested_date?->toDateString(),
                'preferred_time_start' => $visit->preferred_time_start,
                'preferred_time_end' => $visit->preferred_time_end,
                'visit_type' => $visit->visit_type,
                'notes' => $visit->notes,
                'status' => $visit->status,
                'review_notes' => $visit->review_notes,
            ]);

        return inertia('portal/schedule', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'shifts' => $shifts->values(),
            'visitRequests' => $visitRequests->values(),
            'showShifts' => $showShifts,
        ]);
    }
}
