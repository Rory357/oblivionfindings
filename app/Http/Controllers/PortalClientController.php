<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\TimelineEvent;
use Illuminate\Http\Request;

class PortalClientController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $client->load(['medicalProfile', 'medications', 'conditions', 'emergencyContacts']);

        $documents = ClientDocument::query()
            ->where('client_id', $client->id)
            ->where('portal_visible', true)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'category', 'notes', 'original_name', 'mime_type', 'size_bytes', 'created_at']);

        $events = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->orderByDesc('occurred_at')
            ->limit(60)
            ->with(['actor:id,name', 'site:id,name'])
            ->get();

        return inertia('portal/client', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'profile' => $client->medicalProfile,
            'medications' => $client->medications,
            'conditions' => $client->conditions,
            'emergency_contacts' => $client->emergencyContacts,
            'documents' => $documents,
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
        ]);
    }
}
