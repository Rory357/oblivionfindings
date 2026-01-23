<?php

namespace App\Http\Controllers;

use App\Models\ClientIncident;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned')), 403);

        $q = trim((string)$request->get('q', ''));
        $status = $request->get('status');
        $severity = $request->get('severity');

        $incidents = ClientIncident::query()
            ->with(['client:id,first_name,last_name', 'reporter:id,name'])
            // Row-level access: support workers see incidents only for assigned clients
            ->when($user->canDo('incidents.viewAssigned') && !$user->canDo('incidents.viewAny'), function ($query) use ($user) {
                $query->whereHas('client.supportWorkers', fn ($q) => $q->whereKey($user->id));
            })
            ->when($q, function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('title', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%")
                        ->orWhere('type', 'like', "%{$q}%");
                });
            })
            ->when($status, fn($query) => $query->where('status', $status))
            ->when($severity, fn($query) => $query->where('severity', $severity))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return inertia('incidents/index', [
            'filters' => [
                'q' => $q,
                'status' => $status,
                'severity' => $severity,
            ],
            'incidents' => $incidents,
        ]);
    }

    public function show(Request $request, ClientIncident $incident)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned')), 403);

        // Row-level access: if assigned-only, ensure the incident belongs to an assigned client
        if ($user->canDo('incidents.viewAssigned') && !$user->canDo('incidents.viewAny')) {
            $allowed = $incident->client()
                ->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id))
                ->exists();
            abort_unless($allowed, 403);
        }
        $incident->load(['client:id,first_name,last_name', 'reporter:id,name,email', 'attachments']);

        return inertia('incidents/show', [
            'incident' => $incident,
            'can' => [
                'update' => $request->user()?->canDo('incidents.update') ?? false,
                'approve' => $request->user()?->canDo('incidents.approve') ?? false,
            ],
        ]);
    }

    public function update(Request $request, ClientIncident $incident)
    {
        abort_unless($request->user()?->canDo('incidents.update'), 403);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'string', 'max:40'],
            'status' => ['required', 'string', 'max:40'],
            'occurred_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'immediate_action' => ['nullable', 'string'],
            'follow_up_required' => ['nullable', 'string'],
        ]);

        $incident->update($data);
        return back();
    }

    public function submit(Request $request, ClientIncident $incident)
    {
        abort_unless($request->user()?->canDo('incidents.update'), 403);
        $incident->update(['status' => 'submitted']);
        return back();
    }

    public function review(Request $request, ClientIncident $incident)
    {
        abort_unless($request->user()?->canDo('incidents.approve'), 403);
        $incident->update([
            'status' => 'reviewed',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);
        return back();
    }

    public function close(Request $request, ClientIncident $incident)
    {
        abort_unless($request->user()?->canDo('incidents.approve'), 403);
        $incident->update([
            'status' => 'closed',
            'closed_by' => $request->user()?->id,
            'closed_at' => now(),
        ]);
        return back();
    }
}
