<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;

class ClientAssignmentController extends Controller
{
    public function edit(Client $client)
    {
        $this->authorize('update', $client);

        $workers = User::query()
            ->whereHas('roles', fn($q) => $q->where('name', 'support_worker'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']); // role no longer needed here

        $assignedIds = $client->supportWorkers()->pluck('users.id')->values();

        return inertia('clients/assignments', [
            'client' => $client->only(['id', 'first_name', 'last_name', 'status']),
            'workers' => $workers,
            'assignedIds' => $assignedIds,
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $validated = $request->validate([
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $allowedWorkerIds = User::query()
            ->whereHas('roles', fn($q) => $q->where('name', 'support_worker'))
            ->whereIn('id', $validated['user_ids'] ?? [])
            ->pluck('id')
            ->all();

        $client->supportWorkers()->sync($allowedWorkerIds);

        return redirect()
            ->route('clients.assignments.edit', $client)
            ->with('success', 'Assignments updated.');
    }
}
