<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\IncidentTemplate;
use App\Services\NotificationService;
use App\Support\WorkerClock;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientIncidentController extends Controller
{
    use ServesPrivateAttachments;

    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $user = $request->user();

        $incidents = ClientIncident::query()
            ->where('client_id', $client->id)
            ->when(
                ! $user?->canDo('hr.cases.view'),
                fn ($q) => $q->where(fn ($q2) => $q2->where('is_hr_confidential', false)->orWhereNull('is_hr_confidential'))
            )
            ->with([
                'reporter:id,name,email',
                'shift:id,starts_at,ends_at,actual_ends_at',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $templates = IncidentTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return inertia('operations/clients/incidents', [
            'client' => $client->only(['id', 'first_name', 'last_name', 'status']),
            'incidents' => $incidents,
            'templates' => $templates,
            'can' => [
                'create' => $request->user()?->canDo('incidents.create') ?? false,
                'templatesManage' => $request->user()?->canDo('incidents.templates.manage') ?? false,
            ],
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('incidents.create'), 403);

        $data = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:incident_templates,id'],
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'in:low,medium,high'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'requires_followup' => ['sometimes', 'boolean'],
            'immediate_action_taken' => ['nullable', 'string'],
            'witnesses' => ['nullable', 'string'],

            // Near-miss fields
            'potential_severity' => ['nullable', 'in:low,medium,high,critical'],
            'potential_consequence' => ['nullable', 'string'],

            // Injury details
            'injured_person_name' => ['nullable', 'string', 'max:255'],
            'injured_person_role' => ['nullable', 'in:staff,client,visitor,contractor'],
            'injured_person_age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'injury_body_part' => ['nullable', 'string', 'max:255'],
            'injury_nature' => ['nullable', 'in:fracture,burn,laceration,sprain,bruising,concussion,poisoning,other'],
            'injury_classification' => ['nullable', 'in:minor,moderate,serious,notifiable'],
            'medical_treatment_type' => ['nullable', 'in:none,first_aid,medical_centre,hospital,ambulance'],

            // WorkSafe
            'is_notifiable' => ['sometimes', 'boolean'],
        ]);

        $incident = ClientIncident::create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'reported_by' => $request->user()?->id,
            'shift_id' => null,
            'template_id' => $data['template_id'] ?? null,
            'type' => $data['type'],
            'severity' => $data['severity'],
            'status' => 'draft',
            'occurred_at' => WorkerClock::toUtc($data['occurred_at'] ?? null) ?? now(),
            'description' => $data['description'] ?? null,
            'requires_followup' => (bool) ($data['requires_followup'] ?? false),
            'immediate_action_taken' => $data['immediate_action_taken'] ?? null,
            'witnesses' => $data['witnesses'] ?? null,
            'title' => $data['type'].' incident',

            // Near-miss
            'potential_severity' => $data['potential_severity'] ?? null,
            'potential_consequence' => $data['potential_consequence'] ?? null,

            // Injury details
            'injured_person_name' => $data['injured_person_name'] ?? null,
            'injured_person_role' => $data['injured_person_role'] ?? null,
            'injured_person_age' => $data['injured_person_age'] ?? null,
            'injury_body_part' => $data['injury_body_part'] ?? null,
            'injury_nature' => $data['injury_nature'] ?? null,
            'injury_classification' => $data['injury_classification'] ?? null,
            'medical_treatment_type' => $data['medical_treatment_type'] ?? null,

            // WorkSafe
            'is_notifiable' => (bool) ($data['is_notifiable'] ?? false),
        ]);

        if ($incident->severity === 'high') {
            app(NotificationService::class)->notifyCrud(
                $request->user(),
                'created',
                'incident',
                $incident,
                $client,
                [
                    'title' => 'High severity incident drafted',
                    'body' => "Client: {$client->first_name} {$client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}",
                    'url' => url("/incidents/{$incident->id}"),
                    'include_assigned_workers' => false,
                ]
            );
        } else {
            app(NotificationService::class)->notifyCrud(
                $request->user(),
                'created',
                'incident',
                $incident,
                $client,
                [
                    'title' => "Incident created: {$incident->type}",
                    'body' => "Severity: {$incident->severity}",
                    'url' => url("/incidents/{$incident->id}"),
                ]
            );
        }

        return redirect()->route('incidents.show', $incident)->with('success', 'Incident draft created.');
    }

    public function uploadAttachment(Request $request, Client $client, ClientIncident $incident)
    {
        $this->authorize('view', $client);
        abort_unless((int) $incident->client_id === (int) $client->id, 404);

        $this->authorize('update', $incident);

        // Additional rule: attachments removable only while editable for reporter (admins allowed)
        $user = $request->user();
        if ($user && ! $user->canDo('incidents.viewAny')) {
            abort_unless($incident->isEditableByReporter($user), 403);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        $disk = 'private';
        $path = $file->store('incident_attachments', $disk);

        ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        return back()->with('success', 'Attachment uploaded.');
    }

    public function downloadAttachment(Request $request, Client $client, ClientIncident $incident, ClientIncidentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $client);
        abort_unless((int) $incident->client_id === (int) $client->id, 404);
        abort_unless((int) $attachment->incident_id === (int) $incident->id, 404);

        // require incident view perms
        $this->authorize('view', $incident);

        // Private disk + nosniff + CSP sandbox — see ServesPrivateAttachments.
        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }
}
