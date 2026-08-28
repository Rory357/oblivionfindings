<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\Clients\ClientPortalMembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientPortalUserController extends Controller
{
    public function __construct(
        private readonly ClientPortalMembershipService $portalMembership,
    ) {}

    public function edit(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $client->load(['portalUsers:id,name,email']);

        return inertia('operations/clients/portal-users', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'portal_users' => $client->portalUsers->map(fn ($u) => [
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
        if ($action === 'contact_only') {
            if ($data['portal_role'] !== 'next_of_kin') {
                return back()->withErrors(['portal_role' => 'Contact-only mode is only available for next of kin.']);
            }
            if (empty($data['name'])) {
                return back()->withErrors(['name' => 'Name is required to save a contact.']);
            }

            $actor = $request->user();
            abort_unless($actor, 403);
            DB::transaction(function () use ($actor, $client, $data): void {
                $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
                $locks = app(PeopleMutationLockService::class)->lock([(int) $actor->id]);
                $lockedActor = $locks['users']->get((int) $actor->id);
                abort_unless($lockedActor instanceof User, 403);
                Gate::forUser($lockedActor)->authorize('update', $lockedClient);
                $lockedClient->emergencyContacts()->updateOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => trim((string) $data['name']),
                        'relationship' => $data['relation'],
                    ],
                );
            }, 3);

            return back()->with('status', 'Next-of-kin saved for display/contact purposes.');
        }

        if ($action === 'create_user' && empty($data['name'])) {
            return back()->withErrors(['name' => 'Name is required to create a user.']);
        }

        $actor = $request->user();
        abort_unless($actor, 403);
        $email = strtolower(trim((string) $data['email']));
        [$user] = DB::transaction(function () use ($actor, $client, $data, $action, $email): array {
            app(EmployeeIntakeService::class)->acquireIntakeLock('email:'.$email);

            $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $roleId = (int) Role::query()->where('name', $data['portal_role'])->value('id');
            abort_unless($roleId > 0, 404);
            $targetUserId = User::query()->where('email', $email)->value('id');
            $locks = app(PeopleMutationLockService::class)->lock(
                [(int) $actor->id, $targetUserId],
                [],
                [$roleId],
            );
            $lockedPortalRole = Role::query()->whereKey($roleId)->lockForUpdate()->first();
            abort_unless(
                $lockedPortalRole instanceof Role
                    && (string) $lockedPortalRole->name === (string) $data['portal_role'],
                409,
                'The requested portal role changed. Please retry.',
            );
            /** @var User|null $lockedActor */
            $lockedActor = $locks['users']->get((int) $actor->id);
            abort_unless($lockedActor instanceof User, 403);
            Gate::forUser($lockedActor)->authorize('update', $lockedClient);

            /** @var User|null $user */
            $user = $targetUserId ? $locks['users']->get((int) $targetUserId) : null;
            if ($user && strtolower(trim((string) $user->email)) !== $email) {
                throw ValidationException::withMessages([
                    'email' => 'The matching account changed while this request was waiting. Please retry.',
                ]);
            }
            $created = false;
            if (! $user) {
                if ($action !== 'create_user') {
                    throw ValidationException::withMessages([
                        'email' => 'No user found with this email.',
                    ]);
                }
                $user = User::query()->create([
                    'name' => trim((string) ($data['name'] ?? $email)),
                    'email' => $email,
                    'password' => Str::password(32),
                    'role' => $data['portal_role'],
                    'approved_at' => now(),
                ]);
                $created = true;
            }

            $this->portalMembership->assertLinkable($user);
            $user->roles()->syncWithoutDetaching([$roleId]);
            $this->portalMembership->link($lockedClient, $user, $data['relation']);

            return [$user, $created];
        }, 3);

        if ($action === 'create_user') {
            Password::sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', 'Portal user linked.');
    }

    public function destroy(Request $request, Client $client, User $user)
    {
        $this->authorize('update', $client);

        abort_unless($this->portalMembership->unlink($client, $user), 404);

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
