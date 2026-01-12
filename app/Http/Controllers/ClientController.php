<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;

class ClientController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Client::class);

        $user = auth()->user();

        $clients = Client::query()
            ->when(
                $user->hasRole('support_worker'),
                fn($q) => $q->whereHas('supportWorkers', fn($q) => $q->whereKey($user->id))
            )
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'status']);

        return inertia('clients/index', [
            'clients' => $clients,
        ]);
    }

    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $client->load([
            'supportWorkers:id,name,email',
        ]);

        // For modal / async detail views, return JSON.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return response()->json([
                'client' => [
                    'id' => $client->id,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                    'status' => $client->status,
                    'support_workers' => $client->supportWorkers->map(fn($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                    ])->values(),
                ],
            ]);
        }

        // Fallback (optional future full page)
        return inertia('clients/show', [
            'client' => $client,
        ]);
    }


    // public function show(Client $client)
    // {
    //     $this->authorize('view', $client);

    //     return inertia('clients/show', [
    //         'client' => $client->load('supportWorkers'),
    //     ]);
    // }

    public function create()
    {
        $this->authorize('create', Client::class);

        return inertia('clients/create');
    }

    public function store(StoreClientRequest $request)
    {
        $this->authorize('create', Client::class);

        $client = Client::create($request->validated());

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client created.');
    }

    public function edit(Client $client)
    {
        $this->authorize('update', $client);

        return inertia('clients/edit', [
            'client' => $client->only(['id', 'first_name', 'last_name', 'status']),
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $this->authorize('update', $client);

        $client->update($request->validated());

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client updated.');
    }
}
