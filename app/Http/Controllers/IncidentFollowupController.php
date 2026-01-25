<?php

namespace App\Http\Controllers;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\User;
use Illuminate\Http\Request;

class IncidentFollowupController extends Controller
{
    public function store(Request $request, ClientIncident $incident)
    {
        $this->authorize('view', $incident);
        abort_unless($request->user()?->canDo('incidents.followups.manage'), 403);

        $data = $request->validate([
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $followup = IncidentFollowup::create([
            'client_incident_id' => $incident->id,
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Follow-up created.');
    }

    public function update(Request $request, ClientIncident $incident, IncidentFollowup $followup)
    {
        $this->authorize('view', $incident);
        abort_unless((int)$followup->client_incident_id === (int)$incident->id, 404);
        $this->authorize('update', $followup);

        $data = $request->validate([
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $followup->update($data);

        return back()->with('success', 'Follow-up updated.');
    }

    public function complete(Request $request, ClientIncident $incident, IncidentFollowup $followup)
    {
        $this->authorize('view', $incident);
        abort_unless((int)$followup->client_incident_id === (int)$incident->id, 404);
        $this->authorize('complete', $followup);

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $followup->update([
            'completed_at' => now(),
            'notes' => $data['notes'] ?? $followup->notes,
        ]);

        return back()->with('success', 'Follow-up completed.');
    }
}
