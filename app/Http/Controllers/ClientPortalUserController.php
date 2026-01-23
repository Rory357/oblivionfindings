<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;

class ClientPortalUserController extends Controller
{
    public function edit(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $client->load(['portalUsers:id,name,email']);

        return inertia('clients/portal-users', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'portal_users' => $client->portalUsers->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'relation' => $u->pivot->relation,
            ])->values(),
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'relation' => ['required', 'in:client,next_of_kin'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return back()->withErrors(['email' => 'No user found with this email.']);
        }

        // Ensure role
        if ($data['relation'] === 'client') {
            $role = \App\Models\Role::where('name', 'client')->first();
        } else {
            $role = \App\Models\Role::where('name', 'next_of_kin')->first();
        }
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        $client->portalUsers()->syncWithoutDetaching([
            $user->id => ['relation' => $data['relation']],
        ]);

        return back()->with('status', 'Portal user linked.');
    }

    public function destroy(Request $request, Client $client, User $user)
    {
        $this->authorize('update', $client);

        $client->portalUsers()->detach($user->id);

        return back()->with('status', 'Portal user unlinked.');
    }
}
