<?php

namespace App\Http\Controllers;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        [$incident, $followup] = DB::transaction(function () use ($incident, $data, $request): array {
            $lockedIncident = ClientIncident::query()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedIncident->status === 'closed') {
                throw ValidationException::withMessages([
                    'incident' => 'Closed incidents cannot receive new follow-ups. Reopen the incident before creating more work.',
                ]);
            }

            $followup = IncidentFollowup::create([
                'client_incident_id' => $lockedIncident->id,
                'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            return [$lockedIncident, $followup];
        }, 3);

        $incident->loadMissing(['client:id,first_name,last_name']);
        $client = $incident->client;

        $targets = [];
        if (! empty($followup->assigned_to_user_id)) {
            $targets[] = (int) $followup->assigned_to_user_id;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'created',
            'incident follow-up',
            $followup,
            $client,
            [
                'event_key' => 'followups.created',
                'title' => 'Incident follow-up created',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
                'context' => [
                    'Client' => trim($client->first_name.' '.$client->last_name),
                    'Incident' => 'ClientIncident #'.$incident->id,
                    'Due' => $followup->due_at?->format('Y-m-d H:i'),
                    'Assigned to' => $followup->assigned_to_user_id ? User::query()->find($followup->assigned_to_user_id)?->name : null,
                ],
            ]
        );

        return back()->with('success', 'Follow-up created.');
    }

    public function update(Request $request, ClientIncident $incident, IncidentFollowup $followup)
    {
        $this->authorize('view', $incident);
        abort_unless((int) $followup->client_incident_id === (int) $incident->id, 404);
        $this->authorize('update', $followup);

        // Audit guardrail: completed follow-ups cannot be modified.
        abort_unless(empty($followup->completed_at), 403);

        $data = $request->validate([
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $followup->update($data);

        $incident->loadMissing(['client:id,first_name,last_name']);
        $client = $incident->client;

        $targets = [];
        if (! empty($followup->assigned_to_user_id)) {
            $targets[] = (int) $followup->assigned_to_user_id;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'updated',
            'incident follow-up',
            $followup,
            $client,
            [
                'event_key' => 'followups.updated',
                'title' => 'Incident follow-up updated',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
                'context' => [
                    'Client' => trim($client->first_name.' '.$client->last_name),
                    'Incident' => 'ClientIncident #'.$incident->id,
                    'Due' => $followup->due_at?->format('Y-m-d H:i'),
                    'Assigned to' => $followup->assigned_to_user_id ? User::query()->find($followup->assigned_to_user_id)?->name : null,
                ],
            ]
        );

        return back()->with('success', 'Follow-up updated.');
    }

    public function complete(Request $request, ClientIncident $incident, IncidentFollowup $followup)
    {
        $this->authorize('view', $incident);
        abort_unless((int) $followup->client_incident_id === (int) $incident->id, 404);
        $this->authorize('complete', $followup);

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $followup->update([
            'completed_at' => now(),
            'notes' => $data['notes'] ?? $followup->notes,
        ]);

        $incident->loadMissing(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify managers + incident team; also ping the follow-up assignee.
        $targets = [];
        if (! empty($followup->assigned_to_user_id)) {
            $targets[] = (int) $followup->assigned_to_user_id;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'completed',
            'incident follow-up',
            $followup,
            $client,
            [
                'event_key' => 'followups.completed',
                'title' => 'Incident follow-up completed',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
                'context' => [
                    'Client' => trim($client->first_name.' '.$client->last_name),
                    'Incident' => 'ClientIncident #'.$incident->id,
                    'Completed' => $followup->completed_at?->format('Y-m-d H:i'),
                    'Assigned to' => $followup->assigned_to_user_id ? User::query()->find($followup->assigned_to_user_id)?->name : null,
                ],
            ]
        );

        return back()->with('success', 'Follow-up completed.');
    }
}
