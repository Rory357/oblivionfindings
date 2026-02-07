<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'relation_options' => $this->relationOptions(),
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'relation' => ['required', 'string', 'max:100', Rule::in($this->relationOptions())],
            'portal_role' => ['required', 'in:client,next_of_kin'],
            'action' => ['nullable', 'in:link,create_user,contact_only'],
        ]);

        $action = $data['action'] ?? 'link';
        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            if ($action === 'create_user') {
                if (empty($data['name'])) {
                    return back()->withErrors(['name' => 'Name is required to create a user.']);
                }

                $user = User::create([
                    'name' => trim((string) ($data['name'] ?? $data['email'])),
                    'email' => $data['email'],
                    'password' => Str::password(32),
                    'role' => $data['portal_role'],
                    'approved_at' => now(),
                ]);
                Password::sendResetLink(['email' => $user->email]);
            } elseif ($action === 'contact_only') {
                if ($data['portal_role'] !== 'next_of_kin') {
                    return back()->withErrors(['portal_role' => 'Contact-only mode is only available for next of kin.']);
                }
                if (empty($data['name'])) {
                    return back()->withErrors(['name' => 'Name is required to save a contact.']);
                }

                $client->emergencyContacts()->updateOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => trim((string) $data['name']),
                        'relationship' => $data['relation'],
                    ]
                );

                return back()->with('status', 'Next-of-kin saved for display/contact purposes.');
            } else {
                return back()->withErrors(['email' => 'No user found with this email.']);
            }
        } elseif ($action === 'create_user') {
            Password::sendResetLink(['email' => $user->email]);
        }

        // Ensure role
        if ($data['portal_role'] === 'client') {
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

    private function relationOptions(): array
    {
        return [
            'client',
            'mother',
            'father',
            'brother',
            'sister',
            'aunt',
            'uncle',
            'grandmother',
            'grandfather',
            'daughter',
            'son',
            'spouse',
            'partner',
            'guardian',
            'carer',
            'friend',
            'other',
        ];
    }
}
