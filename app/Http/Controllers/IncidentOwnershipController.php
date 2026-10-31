<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchIncidentOwnerDelivery;
use App\Models\ClientIncident;
use App\Models\IncidentOwnerDelivery;
use App\Services\Incidents\IncidentOwnerEligibility;
use App\Services\Incidents\IncidentOwnershipService;
use Illuminate\Http\Request;
use Throwable;

class IncidentOwnershipController extends Controller
{
    public function candidates(Request $request, ClientIncident $incident, IncidentOwnershipService $owners)
    {
        $this->authorize('view', $incident);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['owners' => $owners->candidates($request->user(), $incident, trim($data['q'] ?? ''))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, ClientIncident $incident, IncidentOwnershipService $owners)
    {
        $this->authorize('view', $incident);
        $data = $request->validate([
            'owner_user_id' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'request_uuid' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:4000'],
        ]);
        $owners->assign($request->user(), $incident, (int) $data['owner_user_id'], (int) $data['expected_revision'], $data['request_uuid'], $data['reason'] ?? null);

        return back()->with('success', 'Incident owner saved. Notification delivery is tracked separately.');
    }

    public function retry(Request $request, ClientIncident $incident, IncidentOwnerDelivery $delivery, IncidentOwnerEligibility $eligibility)
    {
        $this->authorize('view', $incident);
        abort_unless((int) $delivery->change->incident_id === (int) $incident->id, 404);
        abort_unless($eligibility->canManage($request->user(), $incident), 403);
        if (! in_array($delivery->status, ['failed', 'pending'], true)) {
            return back()->with('success', 'This notification has already been handled.');
        }
        try {
            DispatchIncidentOwnerDelivery::dispatch($delivery->id);
        } catch (Throwable) {
            return back()->with('error', 'The owner is still saved. Notification delivery could not be queued; please retry.');
        }

        return back()->with('success', 'Notification retry requested. Check its delivery status.');
    }
}
