<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\TimelineEvent;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientIncidentController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $incidents = ClientIncident::query()
            ->where('client_id', $client->id)
            ->with([
                'reporter:id,name,email',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        return inertia('clients/incidents', [
            'client' => $client->only(['id', 'first_name', 'last_name', 'status']),
            'incidents' => $incidents->map(function (ClientIncident $i) {
                return [
                    'id' => $i->id,
                    'type' => $i->type,
                    'severity' => $i->severity,
                    'status' => $i->status,
                    'occurred_at' => optional($i->occurred_at)->toDateTimeString(),
                    'location' => $i->location,
                    'title' => $i->title,
                    'description' => $i->description,
                    'immediate_action' => $i->immediate_action,
                    'follow_up_required' => $i->follow_up_required,
                    'reported_by' => $i->reporter ? [
                        'id' => $i->reporter->id,
                        'name' => $i->reporter->name,
                        'email' => $i->reporter->email,
                    ] : null,
                    'reviewed_at' => optional($i->reviewed_at)->toDateTimeString(),
                    'closed_at' => optional($i->closed_at)->toDateTimeString(),
                ];
            }),
            'can' => [
                'create' => $request->user()?->canDo('incidents.create') ?? false,
                'update' => $request->user()?->canDo('incidents.update') ?? false,
                'approve' => $request->user()?->canDo('incidents.approve') ?? false,
            ],
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('incidents.create'), 403);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'string', 'max:40'],
            'occurred_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'immediate_action' => ['nullable', 'string'],
            'follow_up_required' => ['nullable', 'string'],
        ]);

        try {
            $incident = ClientIncident::create([
                ...$data,
                'client_id' => $client->id,
                'reported_by' => $request->user()?->id,
                'status' => 'draft',
            ]);

            // log viewable event into timeline (internal)
            TimelineEvent::create([
                'client_id' => $client->id,
                'type' => 'incident',
                'subject' => 'Incident: ' . $incident->title,
                'body' => $incident->description,
                'visibility' => 'internal',
                'occurred_at' => $incident->occurred_at ?? now(),
                'created_by' => $request->user()?->id,
                'actor_user_id' => $request->user()?->id,
                'source_type' => 'client_incident',
                'source_id' => $incident->id,
            ]);

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'incident', $incident, $client, [
                'title' => "Incident created: {$incident->title}",
                'body' => "Severity: {$incident->severity}\nType: {$incident->type}",
                'url' => url("/clients/{$client->id}/incidents"),
            ]);

            return redirect()
                ->route('clients.incidents.index', $client)
                ->with('success', 'Incident created successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to create incident: ' . $e->getMessage());
        }
    }

    public function uploadAttachment(Request $request, Client $client, ClientIncident $incident)
    {
        $this->authorize('view', $client);
        abort_unless($incident->client_id === $client->id, 404);
        abort_unless($request->user()?->canDo('incidents.update'), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'portal_visible' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $file = $request->file('file');
            $path = $file->store('incident_attachments', 'public');

            $attachment = ClientIncidentAttachment::create([
                'incident_id' => $incident->id,
                'uploaded_by' => $request->user()?->id,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'portal_visible' => (bool)($data['portal_visible'] ?? false),
                'notes' => $data['notes'] ?? null,
            ]);

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'incident attachment', $attachment ?? null, $client, [
                'title' => "Incident attachment uploaded",
                'body' => "Incident: {$incident->title}",
                'url' => url("/clients/{$client->id}/incidents"),
            ]);

            return back()->with('success', 'Attachment uploaded successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Failed to upload attachment: ' . $e->getMessage());
        }
    }

    public function downloadAttachment(Request $request, Client $client, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        $this->authorize('view', $client);
        abort_unless($incident->client_id === $client->id, 404);
        abort_unless($attachment->incident_id === $incident->id, 404);

        // portal access is handled by portal controller in later step; for now require incidents.view permissions
        abort_unless($request->user()?->canDo('incidents.viewAny') || $request->user()?->canDo('incidents.viewAssigned'), 403);

        return Storage::disk('public')->download($attachment->path, $attachment->original_name);
    }
}
