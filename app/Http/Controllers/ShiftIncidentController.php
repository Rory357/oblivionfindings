<?php

namespace App\Http\Controllers;

use App\Models\ClientIncident;
use App\Models\Shift;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\NotificationService;
use App\Services\Timeline\TimelineEmitter;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShiftIncidentController extends Controller
{
    public function store(Request $request, Shift $shift)
    {
        $shift->loadMissing('client:id,first_name,last_name,site_id');

        // Must be able to view the shift's client and create incidents
        $this->authorize('view', $shift->client);
        $actor = $request->user();
        abort_unless($actor?->canDo('incidents.create'), 403);

        $data = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:incident_templates,id'],
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'in:low,medium,high'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'requires_followup' => ['sometimes', 'boolean'],
            'immediate_action_taken' => [
                Rule::requiredIf(fn () => $request->input('severity') === 'high'),
                'nullable',
                'string',
                'max:5000',
            ],
            'witnesses' => ['nullable', 'string'],
        ]);

        $siteId = app(UserSiteAccessService::class)->shiftSiteId($shift);
        $incident = DB::transaction(function () use ($actor, $data, $shift, $siteId): ClientIncident {
            $incident = ClientIncident::create([
                'client_id' => $shift->client_id,
                'site_id' => $siteId,
                'reported_by' => $actor->id,
                'shift_id' => $shift->id,
                'template_id' => $data['template_id'] ?? null,
                'type' => $data['type'],
                'severity' => $data['severity'],
                'status' => 'submitted',
                'submitted_at' => now(),
                'occurred_at' => $data['occurred_at'] ?? now(),
                'description' => $data['description'] ?? null,
                'requires_followup' => (bool) ($data['requires_followup'] ?? false),
                'immediate_action_taken' => filled($data['immediate_action_taken'] ?? null)
                    ? trim((string) $data['immediate_action_taken'])
                    : null,
                'witnesses' => $data['witnesses'] ?? null,
                // legacy compatibility
                'title' => $data['type'].' incident',
            ]);

            return app(IncidentJourneyService::class)
                ->ensureForSubmittedIncident($incident, $actor)
                ->incident;
        }, 3);

        app(TimelineEmitter::class)->record([
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'type' => 'incident',
            'subject' => 'Incident: '.($incident->title ?? $incident->type),
            'body' => $incident->description,
            'visibility' => 'internal',
            'occurred_at' => $incident->occurred_at ?? now(),
            'created_by' => $request->user()?->id,
            'actor_user_id' => $request->user()?->id,
            'source_type' => 'client_incident',
            'source_id' => $incident->id,
        ]);

        app(NotificationService::class)->notifyCrud(
            $actor,
            'submitted',
            'incident',
            $incident,
            $shift->client,
            [
                'title' => $incident->severity === 'high'
                    ? 'High severity incident submitted from shift'
                    : 'Incident submitted from shift',
                'body' => "Client: {$shift->client->first_name} {$shift->client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}",
                'url' => url("/incidents/{$incident->id}"),
                'include_entity_user' => false,
                'include_assigned_workers' => $incident->severity !== 'high',
            ]
        );

        return back()->with('success', 'Incident recorded for shift.');
    }
}
