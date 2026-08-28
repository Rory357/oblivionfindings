<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\Role;
use App\Models\SsoGroupMapping;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\AzureAdGroupService;
use App\Services\SsoGroupMappingLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Throwable;

class SsoGroupController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        $mappings = SsoGroupMapping::with('role')->orderBy('provider')->orderBy('external_group_name')->get();
        $roles = Role::orderBy('name')->get(['id', 'name', 'label']);

        return Inertia::render('settings/sso-groups', [
            'mappings' => $mappings,
            'roles' => $roles,
            'stats' => [
                'total' => $mappings->count(),
                'microsoft' => $mappings->where('provider', 'microsoft')->count(),
                'google' => $mappings->where('provider', 'google')->count(),
            ],
        ]);
    }

    public function fetchGroups(Request $request, AzureAdGroupService $service)
    {
        $this->authorizeAccess($request);

        $identity = Identity::query()
            ->where('user_id', $request->user()?->id)
            ->where('provider', 'microsoft')
            ->whereNotNull('access_token')
            ->first();

        if (! $identity) {
            return back()->with('error', 'No Microsoft identity found for your account. Please connect a Microsoft account first.');
        }

        if ($identity->isExpired()) {
            return back()->with('error', 'Microsoft token has expired. Please reconnect your Microsoft account.');
        }

        try {
            $groups = $service->getGroups($identity);
        } catch (Throwable) {
            return back()->with('error', 'Could not fetch Microsoft groups. Please try again or reconnect your Microsoft account.');
        }

        return back()->with('groups', $groups);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess($request);
        $actorId = (int) $request->user()->id;

        $data = $request->validate([
            'provider' => 'required|in:microsoft,google',
            'external_group_id' => 'required|string|max:255',
            'external_group_name' => 'required|string|max:255',
            'role_id' => 'required|exists:roles,id',
            'auto_assign' => 'boolean',
            'auto_remove' => 'boolean',
        ]);

        DB::transaction(function () use ($actorId, $data): void {
            app(SsoGroupMappingLockService::class)->lockMappingSet();
            $this->lockMappingMutationActor($actorId, [(int) $data['role_id']]);

            SsoGroupMapping::create($data);
        });

        return back()->with('success', 'Group mapping created.');
    }

    public function update(Request $request, SsoGroupMapping $mapping)
    {
        $this->authorizeAccess($request);
        $actorId = (int) $request->user()->id;
        $mappingId = (int) $mapping->id;

        $data = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'auto_assign' => 'boolean',
            'auto_remove' => 'boolean',
        ]);

        DB::transaction(function () use ($actorId, $data, $mappingId): void {
            $lockedMappings = app(SsoGroupMappingLockService::class)->lockMappingSet();
            /** @var SsoGroupMapping|null $lockedMapping */
            $lockedMapping = $lockedMappings->get($mappingId);
            abort_unless($lockedMapping, 404);

            $this->lockMappingMutationActor($actorId, [
                (int) $lockedMapping->role_id,
                (int) $data['role_id'],
            ]);
            $lockedMapping->update($data);
        });

        return back()->with('success', 'Group mapping updated.');
    }

    public function destroy(Request $request, SsoGroupMapping $mapping)
    {
        $this->authorizeAccess($request);
        $actorId = (int) $request->user()->id;
        $mappingId = (int) $mapping->id;

        DB::transaction(function () use ($actorId, $mappingId): void {
            $lockedMappings = app(SsoGroupMappingLockService::class)->lockMappingSet();
            /** @var SsoGroupMapping|null $lockedMapping */
            $lockedMapping = $lockedMappings->get($mappingId);
            abort_unless($lockedMapping, 404);

            $this->lockMappingMutationActor($actorId, [(int) $lockedMapping->role_id]);
            $lockedMapping->delete();
        });

        return back()->with('success', 'Group mapping deleted.');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
    }

    /** @param list<int> $additionalRoleIds */
    private function lockMappingMutationActor(int $actorId, array $additionalRoleIds): User
    {
        $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
            [$actorId],
            ['settings.access.manage'],
            $additionalRoleIds,
        );
        /** @var User|null $actor */
        $actor = $lockedUsers->get($actorId);
        abort_unless($actor?->canDo('settings.access.manage'), 403);

        return $actor;
    }
}
