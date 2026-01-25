<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientIncident;
use App\Models\TimelineEvent;
use Illuminate\Http\Request;

class PortalClientController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $user = $request->user();

        $client->load(['medicalProfile', 'medications', 'conditions', 'emergencyContacts']);

        $documents = ClientDocument::query()
            ->where('client_id', $client->id)
            ->where('portal_visible', true)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'category', 'notes', 'original_name', 'mime_type', 'size_bytes', 'created_at']);

        $events = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->orderByDesc('occurred_at')
            ->limit(60)
            ->with(['actor:id,name', 'site:id,name'])
            ->get();

        $incidents = collect();
        if ($user && $user->canDo('incidents.view.portal')) {
            $incidents = ClientIncident::query()
                ->where('client_id', $client->id)
                ->where('portal_visible', true)
                ->whereNotNull('reviewed_at')
                ->orderByDesc('occurred_at')
                ->with(['attachments' => fn($q) => $q->where('portal_visible', true)])
                ->limit(40)
                ->get();
        }

        return inertia('portal/client', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'profile' => $client->medicalProfile,
            'medications' => $client->medications,
            'conditions' => $client->conditions,
            'emergency_contacts' => $client->emergencyContacts,
            'documents' => $documents,
            'incidents' => $incidents->map(fn($i) => [
                'id' => $i->id,
                'type' => $i->type,
                'severity' => $i->severity,
                'occurred_at' => optional($i->occurred_at)->toISOString(),
                'description' => $i->description,
                'immediate_action_taken' => $i->immediate_action_taken,
                'requires_followup' => (bool)$i->requires_followup,
                'attachments' => $i->attachments->map(fn($a) => [
                    'id' => $a->id,
                    'original_name' => $a->original_name,
                    'mime_type' => $a->mime_type ?? $a->mime,
                    'size' => $a->size,
                    'download_url' => $user && $user->canDo('incidents.attachments.view.portal')
                        ? route('portal.clients.incidents.attachments.download', ['client' => $client->id, 'incident' => $i->id, 'attachment' => $a->id])
                        : null,
                ])->values(),
            ])->values(),
            'events' => $events->map(fn($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
            ])->values(),
            'rag_answer' => session('rag_answer'),
            'can' => [
                'viewIncidents' => $user?->canDo('incidents.view.portal') ?? false,
                'downloadIncidentAttachments' => $user?->canDo('incidents.attachments.view.portal') ?? false,
            ],
        ]);
    }
}
