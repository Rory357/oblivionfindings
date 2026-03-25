<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ClientAssignmentController extends Controller
{
    public function edit(Client $client)
    {
        $this->authorize('update', $client);

        $workers = User::staff()
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $assignedIds = $client->supportWorkers()->pluck('users.id')->values();

        return inertia('operations/clients/assignments', [
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

        $allowedWorkerIds = User::staff()
            ->whereIn('id', $validated['user_ids'] ?? [])
            ->pluck('id')
            ->all();

        $oldAssignedIds = $client->supportWorkers()->pluck('users.id')->map(fn($id) => (int) $id);
        $newAssignedIds = collect($allowedWorkerIds)->map(fn($id) => (int) $id);

        $client->supportWorkers()->sync($allowedWorkerIds);

        $added = $newAssignedIds->diff($oldAssignedIds)->values()->all();
        $removed = $oldAssignedIds->diff($newAssignedIds)->values()->all();

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'client assignments', $client, $client, [
            'title' => "Client assignments updated: {$client->first_name} {$client->last_name}",
            'body' => 'Added worker IDs: ' . (count($added) ? implode(', ', $added) : 'none') . ' | Removed worker IDs: ' . (count($removed) ? implode(', ', $removed) : 'none'),
            'url' => url("/clients/{$client->id}/assignments"),
            // Explicitly notify newly assigned workers in addition to managers.
            'target_user_ids' => $added,
        ]);

        return redirect()
            ->route('clients.assignments.edit', $client)
            ->with('success', 'Assignments updated.');
    }
}
