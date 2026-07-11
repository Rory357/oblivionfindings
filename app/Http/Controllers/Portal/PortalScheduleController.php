<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyVisitRequest;
use App\Models\RespiteBooking;
use App\Models\Shift;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Http\Request;

class PortalScheduleController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $sectionAccess = app(PortalClientSectionAccess::class)->for($user, $client);
        $showShifts = $sectionAccess['show_shift_schedule'];
        $showRespite = $sectionAccess['show_respite'];
        $rangeStart = now()->startOfDay();
        $rangeEnd = now()->addDays(30);

        $shifts = collect();
        $respiteStays = collect();

        if ($showShifts) {
            $shifts = Shift::where('client_id', $client->id)
                ->where('starts_at', '<', $rangeEnd)
                ->where('ends_at', '>', $rangeStart)
                ->orderBy('starts_at')
                ->with(['staff:id,name,profile_photo_path', 'serviceContext:id,name,type'])
                ->get()
                ->map(fn ($shift) => [
                    'id' => $shift->id,
                    'starts_at' => $shift->starts_at?->toISOString(),
                    'ends_at' => $shift->ends_at?->toISOString(),
                    'status' => $shift->status,
                    'shift_type' => $shift->shift_type ?? 'standard',
                    'is_sleepover' => (bool) $shift->is_sleepover,
                    'is_on_call' => (bool) $shift->is_on_call,
                    'expected_break_minutes' => $shift->expected_break_minutes,
                    'location' => $shift->location,
                    'service_context' => $shift->serviceContext ? [
                        'id' => $shift->serviceContext->id,
                        'name' => $shift->serviceContext->name,
                        'type' => $shift->serviceContext->type?->value,
                    ] : null,
                    'date' => $shift->starts_at?->format('Y-m-d'),
                    'staff' => $shift->staff ? [
                        'id' => $shift->staff->id,
                        'name' => $shift->staff->name,
                        'avatar' => $shift->staff->avatar,
                    ] : null,
                ]);
        }

        if ($showRespite) {
            $respiteStays = RespiteBooking::query()
                ->where('client_id', $client->id)
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->where('start_at', '<', $rangeEnd)
                ->where('end_at', '>', $rangeStart)
                ->with(['stays' => fn ($query) => $query->latest('actual_start')])
                ->orderBy('start_at')
                ->get()
                ->map(fn (RespiteBooking $booking) => [
                    'id' => $booking->id,
                    'starts_at' => $booking->start_at?->toISOString(),
                    'ends_at' => $booking->end_at?->toISOString(),
                    'status' => $booking->status,
                    'stay_status' => $booking->stays->first()?->status,
                    'date' => $booking->start_at?->format('Y-m-d'),
                    'cancellation_reason' => $booking->cancellation_reason,
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
            'respiteStays' => $respiteStays->values(),
            'visitRequests' => $visitRequests->values(),
            'showShifts' => $showShifts,
            'showRespite' => $showRespite,
        ]);
    }
}
