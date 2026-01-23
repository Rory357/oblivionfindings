<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class StaffAssignmentController extends Controller
{
    public function edit(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('staff.assignments.update'), 403);

        $user->load(['assignedClients:id,first_name,last_name,status']);

        $clients = Client::query()
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'status']);

        return inertia('staff/assignments', [
            'user' => $user->only(['id', 'name', 'email']),
            'clients' => $clients,
            'assignedIds' => $user->assignedClients()->pluck('clients.id')->values(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('staff.assignments.update'), 403);

        $data = $request->validate([
            'client_ids' => ['array'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
        ]);

        $oldAssignedIds = $user->assignedClients()->pluck('clients.id')->map(fn($id) => (int) $id);
        $newAssignedIds = collect($data['client_ids'] ?? [])->map(fn($id) => (int) $id);

        $user->assignedClients()->sync($data['client_ids'] ?? []);

        $added = $newAssignedIds->diff($oldAssignedIds)->values()->all();
        $removed = $oldAssignedIds->diff($newAssignedIds)->values()->all();

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'staff assignments', $user, null, [
            'title' => "Staff assignments updated: {$user->name}",
            'body' => 'Added client IDs: ' . (count($added) ? implode(', ', $added) : 'none') . ' | Removed client IDs: ' . (count($removed) ? implode(', ', $removed) : 'none'),
            'url' => url("/staff/{$user->id}/assignments"),
            'target_user_ids' => [$user->id],
        ]);

        return redirect()->back()->with('success', 'Assignments updated.');
    }
}
