<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientRisk;
use Illuminate\Http\Request;

class ClientRiskController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $risks = ClientRisk::query()
            ->where('client_id', $client->id)
            ->orderByDesc('active')
            ->orderBy('severity')
            ->orderBy('label')
            ->get();

        return inertia('clients/risks', [
            'client' => $client->only(['id', 'first_name', 'last_name', 'status']),
            'risks' => $risks,
            'can' => [
                'update' => $request->user()?->canDo('risks.update') ?? false,
                'create' => $request->user()?->canDo('risks.create') ?? false,
            ],
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('risks.create'), 403);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'string', 'max:40'],
            'controls' => ['nullable', 'string'],
            'review_date' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ]);

        ClientRisk::create([
            ...$data,
            'client_id' => $client->id,
            'active' => (bool)($data['active'] ?? true),
        ]);

        return back();
    }

    public function update(Request $request, Client $client, ClientRisk $risk)
    {
        $this->authorize('view', $client);
        abort_unless($risk->client_id === $client->id, 404);
        abort_unless($request->user()?->canDo('risks.update'), 403);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'string', 'max:40'],
            'controls' => ['nullable', 'string'],
            'review_date' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $risk->update([
            ...$data,
            'active' => (bool)($data['active'] ?? $risk->active),
        ]);

        return back();
    }

    public function destroy(Request $request, Client $client, ClientRisk $risk)
    {
        $this->authorize('view', $client);
        abort_unless($risk->client_id === $client->id, 404);
        abort_unless($request->user()?->canDo('risks.delete'), 403);

        $risk->delete();
        return back();
    }
}
