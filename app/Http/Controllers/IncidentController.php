<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\IncidentTemplate;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned')), 403);

        $q = trim((string) $request->get('q', ''));
        $type = $request->get('type');
        $status = $request->get('status');
        $severity = $request->get('severity');
        $clientId = $request->get('client_id');
        $reviewed = $request->get('reviewed'); // yes|no|null
        $from = $request->get('from');
        $to = $request->get('to');

        $incidents = ClientIncident::query()
            ->with(['client:id,first_name,last_name', 'reporter:id,name', 'shift:id,starts_at,ends_at,actual_ends_at'])
            ->when($user->canDo('incidents.viewAssigned') && !$user->canDo('incidents.viewAny'), function ($query) use ($user) {
                $query->whereHas('client.supportWorkers', fn ($q) => $q->whereKey($user->id));
            })
            ->when($q, function ($query) use ($q) {
                $searchTerm = '%' . $q . '%';
                $query->where(function ($sub) use ($searchTerm) {
                    $sub->where('description', 'like', $searchTerm)
                        ->orWhere('type', 'like', $searchTerm)
                        ->orWhere('title', 'like', $searchTerm);
                });
            })
            ->when($type, fn($query) => $query->where('type', $type))
            ->when($status, fn($query) => $query->where('status', $status))
            ->when($severity, fn($query) => $query->where('severity', $severity))
            ->when($clientId, fn($query) => $query->where('client_id', $clientId))
            ->when($from, fn($query) => $query->whereDate('occurred_at', '>=', $from))
            ->when($to, fn($query) => $query->whereDate('occurred_at', '<=', $to))
            ->when($reviewed === 'yes', fn($query) => $query->whereNotNull('reviewed_at'))
            ->when($reviewed === 'no', fn($query) => $query->whereNull('reviewed_at'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $clients = null;
        if ($user->canDo('incidents.viewAny')) {
            $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        }

        return inertia('incidents/index', [
            'filters' => [
                'q' => $q,
                'type' => $type,
                'status' => $status,
                'severity' => $severity,
                'client_id' => $clientId,
                'reviewed' => $reviewed,
                'from' => $from,
                'to' => $to,
            ],
            'clients' => $clients,
            'incidents' => $incidents,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->canDo('incidents.create'), 403);

        $clients = Client::query()
            ->when(! $user->canDo('clients.viewAny'), function ($query) use ($user) {
                $query->whereHas('supportWorkers', fn ($staff) => $staff->whereKey($user->id));
            })
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        $templates = IncidentTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Wizard continuation: if an incident id was passed back after Step 2 create,
        // hydrate the draft so Step 3 (optional detail) can pick up without re-creating.
        $resumeIncident = null;
        if ($request->filled('incident')) {
            $incident = ClientIncident::query()->find((int) $request->query('incident'));
            if ($incident && $request->user()?->can('update', $incident) && $incident->status === 'draft') {
                $resumeIncident = $incident->only([
                    'id', 'client_id', 'type', 'severity', 'occurred_at', 'description',
                    'immediate_action_taken', 'witnesses', 'injured_person_name',
                    'injured_person_role', 'injury_body_part', 'injury_nature',
                    'medical_treatment_type',
                ]);
            }
        }

        return inertia('incidents/create', [
            'clients' => $clients,
            'templates' => $templates,
            'resumeIncident' => $resumeIncident,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->canDo('incidents.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
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

        $client = Client::query()->findOrFail($data['client_id']);
        $this->authorize('view', $client);

        $incident = ClientIncident::create([
            'client_id' => $client->id,
            'reported_by' => $request->user()?->id,
            'shift_id' => null,
            'template_id' => $data['template_id'] ?? null,
            'type' => $data['type'],
            'severity' => $data['severity'],
            'status' => 'draft',
            'occurred_at' => $data['occurred_at'] ?? now(),
            'description' => $data['description'] ?? null,
            'requires_followup' => (bool)($data['requires_followup'] ?? false),
            'immediate_action_taken' => $data['immediate_action_taken'] ?? null,
            'witnesses' => $data['witnesses'] ?? null,
            'title' => $data['type'] . ' incident',

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
            'is_notifiable' => (bool)($data['is_notifiable'] ?? false),
        ]);

        // Auto-escalate abuse/neglect incidents to safeguarding
        if (preg_match('/abuse|neglect/i', $incident->type)) {
            \App\Models\SafeguardingConcern::create([
                'subject_type' => \App\Models\Client::class,
                'subject_id' => $client->id,
                'subject_name' => $client->first_name . ' ' . $client->last_name,
                'concern_type' => 'incident_escalation',
                'severity' => $incident->severity,
                'description' => $incident->description,
                'occurred_at' => $incident->occurred_at,
                'reported_by_user_id' => $request->user()?->id,
                'reported_by_name' => $request->user()?->name,
                'status' => 'open',
                'requires_external_referral' => true,
                'related_incident_id' => $incident->id,
                'created_by' => $request->user()?->id,
            ]);
        }

        // High severity alert -> managers only (drafts can still be high severity)
        if ($incident->severity === 'high') {
            app(NotificationService::class)->notifyCrud(
                $request->user(),
                'created',
                'incident',
                $incident,
                $client,
                [
                    'event_key' => 'incidents.high_severity_alert',
                    'severity' => $incident->severity,
                    'title' => 'High severity incident drafted',
                    'body' => "Client: {$client->first_name} {$client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}",
                    'url' => url("/incidents/{$incident->id}"),
                    'include_assigned_workers' => false,
                ]
            );
        }

        if ($request->boolean('continue_wizard')) {
            return redirect()
                ->route('incidents.create', ['incident' => $incident->id])
                ->with('success', 'Incident saved. Add any extra detail below.');
        }

        return redirect()->route('incidents.show', $incident)->with('success', 'Incident draft created.');
    }

    public function show(Request $request, ClientIncident $incident)
    {
        $this->authorize('view', $incident);

        $incident->load([
            'client:id,first_name,last_name',
            'reporter:id,name,email',
            'shift:id,starts_at,ends_at,actual_ends_at',
            'attachments',
            'template',
            'followups.assignedTo:id,name',
            'followups.creator:id,name',
            'investigator:id,name,email',
        ]);

        $user = $request->user();

        $staff = null;
        if ($user && ($user->canDo('incidents.followups.manage') || $user->canDo('incidents.viewAny'))) {
            // Assignable staff (exclude portal users)
            $staff = User::staff()
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']);
        }

        return inertia('incidents/show', [
            'incident' => $incident,
            'can' => [
                'update' => $user ? $user->can('update', $incident) : false,
                'submit' => $user ? $user->can('submit', $incident) : false,
                'review' => $user ? $user->can('review', $incident) : false,
                'close' => $user ? $user->can('close', $incident) : false,
                'reopen' => $user ? $user->can('reopen', $incident) : false,
                'templatesManage' => $user?->canDo('incidents.templates.manage') ?? false,
                'followupsManage' => $user?->canDo('incidents.followups.manage') ?? false,
                'followupsComplete' => $user?->canDo('incidents.followups.complete') ?? false,
                'portalManage' => $user?->canDo('incidents.portal.manage') ?? false,
            ],
            'is_editable' => $user ? $incident->isEditableByReporter($user) : false,
            'staff' => $staff,
        ]);
    }

    public function update(Request $request, ClientIncident $incident)
    {
        $this->authorize('update', $incident);

        $user = $request->user();

        // The show page mixes full-form saves with smaller partial updates
        // (for example corrective actions), so preserve the existing core
        // values when those fields are omitted from the request.
        $request->merge([
            'type' => $request->input('type', $incident->type),
            'severity' => $request->input('severity', $incident->severity),
        ]);

        // Audit guardrail: once submitted/reviewed, lock core incident fields.
        // Managers can still add review notes and manage portal visibility.
        $coreLocked = in_array($incident->status, ['submitted', 'reviewed'], true);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'in:low,medium,high'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'requires_followup' => ['sometimes', 'boolean'],
            'immediate_action_taken' => ['nullable', 'string'],
            'witnesses' => ['nullable', 'string'],

            // review fields (manager/admin)
            'review_notes' => ['nullable', 'string'],

            // portal sharing (manager/admin)
            'portal_visible' => ['sometimes', 'boolean'],

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

            // Investigation fields
            'investigation_status' => ['nullable', 'in:not_required,pending,in_progress,completed'],
            'investigation_assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'root_cause_category' => ['nullable', 'string', 'max:255'],
            'root_cause_description' => ['nullable', 'string'],
            'contributing_factors' => ['nullable', 'string'],
            'corrective_actions' => ['nullable', 'array'],
            'corrective_actions.*.description' => ['required_with:corrective_actions', 'string'],
            'corrective_actions.*.assigned_to' => ['nullable', 'string'],
            'corrective_actions.*.due_date' => ['nullable', 'string'],
            'corrective_actions.*.status' => ['nullable', 'string'],
            'corrective_actions.*.completed_at' => ['nullable', 'string'],
            'lessons_learned' => ['nullable', 'string'],
        ]);

        // If reporter is editing, do not allow review fields / portal visibility to be overwritten
        if ($user && $incident->isEditableByReporter($user) && !$user->canDo('incidents.viewAny')) {
            unset($data['review_notes']);
            unset($data['portal_visible']);
        }

        if ($user && !$user->canDo('incidents.portal.manage')) {
            unset($data['portal_visible']);
        }

        if ($coreLocked) {
            // Only allow manager/admin-only fields after submission.
            // Core fields and injury/near-miss details are locked; investigation fields remain editable.
            foreach ([
                'type', 'severity', 'occurred_at', 'description', 'requires_followup', 'immediate_action_taken', 'witnesses',
                'potential_severity', 'potential_consequence',
                'injured_person_name', 'injured_person_role', 'injured_person_age',
                'injury_body_part', 'injury_nature', 'injury_classification', 'medical_treatment_type',
            ] as $field) {
                unset($data[$field]);
            }
        }

        $incident->update([
            ...$data,
            'title' => ($data['type'] ?? $incident->type) . ' incident',
        ]);

        return back()->with('success', 'Incident updated.');
    }

    public function updateAttachment(Request $request, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        $this->authorize('view', $incident);
        abort_unless($request->user()?->canDo('incidents.portal.manage'), 403);

        abort_unless((int)$attachment->incident_id === (int)$incident->id, 404);

        $data = $request->validate([
            'portal_visible' => ['required', 'boolean'],
        ]);

        $attachment->update([
            'portal_visible' => (bool)$data['portal_visible'],
        ]);

        return back()->with('success', 'Attachment sharing updated.');
    }

    public function submit(Request $request, ClientIncident $incident)
    {
        $this->authorize('submit', $incident);

        $incident->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'submitted',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.submitted',
                'severity' => $incident->severity,
                'title' => 'Incident submitted for review',
                'body' => "Client: {$client->first_name} {$client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}",
                'url' => url("/incidents/{$incident->id}"),
                // Submission is for managers to action; avoid pinging the submitter again.
                'include_entity_user' => false,
            ]
        );

        // High severity: extra managers-only alert on submission.
        if ($incident->severity === 'high') {
            app(NotificationService::class)->notifyCrud(
                $request->user(),
                'submitted',
                'incident',
                $incident,
                $client,
                [
                    'event_key' => 'incidents.high_severity_alert',
                    'severity' => $incident->severity,
                    'title' => 'High severity incident submitted',
                    'body' => "Client: {$client->first_name} {$client->last_name}\nType: {$incident->type}\nSeverity: {$incident->severity}",
                    'url' => url("/incidents/{$incident->id}"),
                    'include_assigned_workers' => false,
                    'include_entity_user' => false,
                    'include_managers' => false,
                ]
            );
        }

        return back()->with('success', 'Incident submitted.');
    }

    public function review(Request $request, ClientIncident $incident)
    {
        $this->authorize('review', $incident);

        // Guardrail: review is only valid for submitted incidents.
        abort_unless($incident->status === 'submitted', 403);

        $data = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        $incident->update([
            'status' => 'reviewed',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? $incident->review_notes,
        ]);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter + assigned workers that the incident has been reviewed.
        $targets = [];
        if (!empty($incident->reported_by)) {
            $targets[] = (int) $incident->reported_by;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'reviewed',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.reviewed',
                'severity' => $incident->severity,
                'title' => 'Incident reviewed',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
            ]
        );

        return back()->with('success', 'Incident reviewed.');
    }

    public function close(Request $request, ClientIncident $incident)
    {
        $this->authorize('close', $incident);

        // Guardrail: closing is only valid for reviewed incidents.
        abort_unless($incident->status === 'reviewed', 403);

        // Guardrail: high-severity incidents require a completed investigation before closure.
        if (in_array($incident->severity, ['high', 'critical'], true) && $incident->investigation_status !== 'completed') {
            return back()->with('error', 'High-severity incidents require a completed investigation before closure.');
        }

        // Guardrail: incidents cannot be closed while there are any open follow-ups.
        // This applies if follow-ups were explicitly flagged *or* any follow-up records exist.
        $hasOpenFollowups = $incident->followups()->whereNull('completed_at')->exists();
        if ($hasOpenFollowups) {
            return back()->with('error', 'There are open follow-ups. Please complete them before closing the incident.');
        }

        $data = $request->validate([
            'closed_outcome' => ['required', 'string', 'max:120'],
            'closed_notes' => ['nullable', 'string'],
        ]);

        $incident->update([
            'status' => 'closed',
            'closed_by' => $request->user()?->id,
            'closed_at' => now(),
            'closed_outcome' => $data['closed_outcome'],
            'closed_notes' => $data['closed_notes'] ?? null,
        ]);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter + assigned workers that the incident has been closed.
        $targets = [];
        if (!empty($incident->reported_by)) {
            $targets[] = (int) $incident->reported_by;
        }

        app(NotificationService::class)->notifyCrud(
            $request->user(),
            'closed',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.closed',
                'severity' => $incident->severity,
                'title' => 'Incident closed',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
            ]
        );

        return back()->with('success', 'Incident closed.');
    }

    public function reopen(Request $request, ClientIncident $incident)
    {
        $this->authorize('reopen', $incident);

        // Only closed incidents may be reopened.
        abort_unless($incident->status === 'closed', 403);

        $data = $request->validate([
            'reopened_reason' => ['required', 'string', 'max:2000'],
        ]);

        $incident->update([
            'status' => 'reviewed',
            'reopened_by' => $request->user()?->id,
            'reopened_at' => now(),
            'reopened_reason' => $data['reopened_reason'],

            // clear closure fields so the incident becomes "open" again
            'closed_by' => null,
            'closed_at' => null,
            'closed_outcome' => null,
            'closed_notes' => null,
        ]);

        $incident->load(['client:id,first_name,last_name']);
        $client = $incident->client;

        // Notify reporter that the incident has been reopened.
        $targets = [];
        if (!empty($incident->reported_by)) {
            $targets[] = (int) $incident->reported_by;
        }

        app(\App\Services\NotificationService::class)->notifyCrud(
            $request->user(),
            'reopened',
            'incident',
            $incident,
            $client,
            [
                'event_key' => 'incidents.reopened',
                'severity' => $incident->severity,
                'title' => 'Incident reopened',
                'url' => url("/incidents/{$incident->id}"),
                'target_user_ids' => $targets,
                'include_entity_user' => false,
            ]
        );

        return back()->with('success', 'Incident reopened.');
    }

    public function uploadAttachment(Request $request, ClientIncident $incident)
    {
        $this->authorize('update', $incident);

        // Audit guardrail: attachments are only mutable while the incident is in draft.
        abort_unless($incident->status === 'draft', 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        $disk = 'public';
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

    public function removeAttachment(Request $request, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        $this->authorize('update', $incident);

        // Audit guardrail: attachments are only mutable while the incident is in draft.
        abort_unless($incident->status === 'draft', 403);

        abort_unless((int)$attachment->incident_id === (int)$incident->id, 404);

        // Attachments may be removed only while incident is editable by reporter (admins/managers can also remove if they can update)
        $user = $request->user();
        if ($user && !$user->canDo('incidents.viewAny')) {
            abort_unless($incident->isEditableByReporter($user), 403);
        }

        $disk = $attachment->disk ?: 'public';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }

        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    public function downloadAttachment(Request $request, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        $this->authorize('view', $incident);
        abort_unless((int)$attachment->incident_id === (int)$incident->id, 404);

        $disk = $attachment->disk ?: 'public';
        return Storage::disk($disk)->download($attachment->path, $attachment->original_name);
    }
}
