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
                $query->where(function ($sub) use ($q) {
                    $sub->where('description', 'like', "%{$q}%")
                        ->orWhere('type', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%");
                });
            })
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
        abort_unless($request->user()?->canDo('incidents.create'), 403);

        $clients = Client::query()
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        $templates = IncidentTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return inertia('incidents/create', [
            'clients' => $clients,
            'templates' => $templates,
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
        ]);

        // High severity alert -> managers only (drafts can still be high severity)
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
        ]);

        $user = $request->user();

        $staff = null;
        if ($user && ($user->canDo('incidents.followups.manage') || $user->canDo('incidents.viewAny'))) {
            $staff = User::query()
                ->whereIn('role', ['support_worker', 'provider_manager', 'admin'])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']);
        }

        return inertia('incidents/show', [
            'incident' => $incident,
            'can' => [
                'update' => $user ? $user->can('update', $incident) : false,
                'submit' => $user ? $user->can('submit', $incident) : false,
                'review' => $user ? $user->can('review', $incident) : false,
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
        ]);

        // If reporter is editing, do not allow review fields / portal visibility to be overwritten
        $user = $request->user();
        if ($user && $incident->isEditableByReporter($user) && !$user->canDo('incidents.viewAny')) {
            unset($data['review_notes']);
            unset($data['portal_visible']);
        }

        if ($user && !$user->canDo('incidents.portal.manage')) {
            unset($data['portal_visible']);
        }

        $incident->update([
            ...$data,
            'title' => $data['type'] . ' incident',
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

        return back()->with('success', 'Incident submitted.');
    }

    public function review(Request $request, ClientIncident $incident)
    {
        $this->authorize('review', $incident);

        $data = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        $incident->update([
            'status' => 'reviewed',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? $incident->review_notes,
        ]);

        return back()->with('success', 'Incident reviewed.');
    }

    public function uploadAttachment(Request $request, ClientIncident $incident)
    {
        $this->authorize('update', $incident);

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
