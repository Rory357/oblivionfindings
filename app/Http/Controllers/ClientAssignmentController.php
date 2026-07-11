<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Clients\ClientWorkerEligibility;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ClientAssignmentController extends Controller
{
    public function edit(
        Request $request,
        Client $client,
        ClientWorkerEligibility $eligibility,
    ) {
        $this->authorize('update', $client);

        $workers = $eligibility->query($client)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $assignedIds = $client->supportWorkers()->pluck('users.id')->values();

        $payload = [
            // key_worker_id lets the UI flag the designated key worker with a star.
            'client' => $client->only(['id', 'first_name', 'last_name', 'status', 'key_worker_id']),
            'workers' => $workers,
            'assignedIds' => $assignedIds,
        ];

        // The redesigned Clients index manages assignments in a dialog that
        // fetches this data as JSON. Normal navigations still get the full page.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return response()->json($payload);
        }

        return inertia('operations/clients/assignments', $payload);
    }

    public function update(
        Request $request,
        Client $client,
        ClientWorkerEligibility $eligibility,
    ) {
        $this->authorize('update', $client);

        $validated = $request->validate([
            'user_ids' => ['array'],
            'user_ids.*' => [
                'bail',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($client, $eligibility): void {
                    if (! $eligibility->contains($client, (int) $value)) {
                        $fail('Choose an eligible worker from this organisation.');
                    }
                },
            ],
        ]);

        $allowedWorkerIds = $eligibility->query($client)
            ->whereIn('id', $validated['user_ids'] ?? [])
            ->pluck('id')
            ->all();

        $oldAssignedIds = $client->supportWorkers()->pluck('users.id')->map(fn ($id) => (int) $id);
        $newAssignedIds = collect($allowedWorkerIds)->map(fn ($id) => (int) $id);

        $client->supportWorkers()->sync($allowedWorkerIds);

        $added = $newAssignedIds->diff($oldAssignedIds)->values()->all();
        $removed = $oldAssignedIds->diff($newAssignedIds)->values()->all();

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'client assignments', $client, $client, [
            'title' => "Client assignments updated: {$client->first_name} {$client->last_name}",
            'body' => 'Added worker IDs: '.(count($added) ? implode(', ', $added) : 'none').' | Removed worker IDs: '.(count($removed) ? implode(', ', $removed) : 'none'),
            'url' => url("/operations/clients/{$client->id}/assignments"),
            // Explicitly notify newly assigned workers in addition to managers.
            'target_user_ids' => $added,
        ]);

        // Dialog submissions (from the Clients index popup) stay on the current
        // page so it can close and reload fresh data; the full page keeps its
        // own redirect back to the assignments screen.
        if ($request->boolean('_modal')) {
            return back()->with('success', 'Assignments updated.');
        }

        return redirect()
            ->route('clients.assignments.edit', $client)
            ->with('success', 'Assignments updated.');
    }
}
