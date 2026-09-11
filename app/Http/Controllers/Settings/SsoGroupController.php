<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\SsoGroupMapping;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\AzureAdGroupService;
use App\Services\SsoGroupMappingLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SsoGroupController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        $mappings = SsoGroupMapping::with('role')->orderBy('provider')->orderBy('external_group_name')->get();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['mappings' => $mappings])->header('Cache-Control', 'no-store, private');
        }

        return redirect('/settings/sso?view=groups');
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
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Connect a Microsoft identity in your profile before fetching directory groups.'], 422);
            }

            return back()->with('error', 'No Microsoft identity found for your account. Please connect a Microsoft account first.');
        }

        if ($identity->isExpired()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Reconnect your Microsoft identity in your profile before fetching directory groups.'], 422);
            }

            return back()->with('error', 'Microsoft token has expired. Please reconnect your Microsoft account.');
        }

        try {
            $groups = $service->getGroups($identity);
        } catch (Throwable) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The directory request failed. No group result or role change was recorded. Reconnect or retry after provider access is available.'], 502);
            }

            return back()->with('error', 'Could not fetch Microsoft groups. Please try again or reconnect your Microsoft account.');
        }

        DB::transaction(function () use ($request, $identity): void {
            app(SsoGroupMappingLockService::class)->lockMappingSet();
            $actor = $this->lockMappingMutationActor((int) $request->user()->id, []);
            $currentIdentity = $actor->identities()->whereKey($identity->id)->lockForUpdate()->first();
            abort_unless($currentIdentity && ! $currentIdentity->isExpired()
                && hash_equals((string) $identity->access_token, (string) $currentIdentity->access_token), 409, 'The Microsoft identity changed during the request. Reconnect before fetching groups again.');
        }, 3);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'fetched', 'groups' => $groups])->header('Cache-Control', 'no-store, private');
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
            'confirm_role_assignment' => 'sometimes|boolean',
            'assignment_reason' => 'nullable|string|max:1000',
        ]);

        $mapping = DB::transaction(function () use ($actorId, $data): SsoGroupMapping {
            $lockedMappings = app(SsoGroupMappingLockService::class)->lockMappingSet();
            $this->lockMappingMutationActor($actorId, [(int) $data['role_id']]);
            if ($lockedMappings->contains(fn ($mapping): bool => $mapping->provider === $data['provider'] && $mapping->external_group_id === $data['external_group_id'])) {
                throw ValidationException::withMessages(['external_group_id' => 'This provider group already has a mapping. Review the existing rule.']);
            }
            $reason = $this->assignmentReview($data);
            unset($data['confirm_role_assignment'], $data['assignment_reason']);
            $mapping = SsoGroupMapping::create($data);
            AuditLogger::logOrFail('settings.sso.group_mapping_created', $mapping, ['actor_id' => $actorId, 'role_id' => (int) $data['role_id'], 'assignment_reason' => $reason]);

            return $mapping;
        });

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => 'saved', 'mapping' => $mapping->load('role')]);
        }

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
            'confirm_role_assignment' => 'sometimes|boolean',
            'assignment_reason' => 'nullable|string|max:1000',
            'expected_version' => 'required|string|size:64',
        ]);

        $savedMapping = DB::transaction(function () use ($actorId, $data, $mappingId): SsoGroupMapping {
            $lockedMappings = app(SsoGroupMappingLockService::class)->lockMappingSet();
            /** @var SsoGroupMapping|null $lockedMapping */
            $lockedMapping = $lockedMappings->get($mappingId);
            abort_unless($lockedMapping, 404);

            $this->lockMappingMutationActor($actorId, [
                (int) $lockedMapping->role_id,
                (int) $data['role_id'],
            ]);
            abort_unless(hash_equals($lockedMapping->version, $data['expected_version']), 409, 'The mapping changed. Reload and review its current role and flags.');
            $reason = $this->assignmentReview($data, $lockedMapping);
            unset($data['confirm_role_assignment'], $data['assignment_reason'], $data['expected_version']);
            $before = $lockedMapping->only(['role_id', 'auto_assign', 'auto_remove']);
            $lockedMapping->update($data);
            AuditLogger::logOrFail('settings.sso.group_mapping_updated', $lockedMapping, ['actor_id' => $actorId, 'before' => $before, 'after' => $lockedMapping->only(['role_id', 'auto_assign', 'auto_remove']), 'assignment_reason' => $reason]);

            return $lockedMapping;
        });

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => 'saved', 'mapping' => $savedMapping->load('role')]);
        }

        return back()->with('success', 'Group mapping updated.');
    }

    public function destroy(Request $request, SsoGroupMapping $mapping)
    {
        $this->authorizeAccess($request);
        $actorId = (int) $request->user()->id;
        $mappingId = (int) $mapping->id;
        $expectedVersion = $request->validate(['expected_version' => 'required|string|size:64'])['expected_version'];

        DB::transaction(function () use ($actorId, $mappingId, $expectedVersion): void {
            $lockedMappings = app(SsoGroupMappingLockService::class)->lockMappingSet();
            /** @var SsoGroupMapping|null $lockedMapping */
            $lockedMapping = $lockedMappings->get($mappingId);
            abort_unless($lockedMapping, 404);

            $this->lockMappingMutationActor($actorId, [(int) $lockedMapping->role_id]);
            abort_unless(hash_equals($lockedMapping->version, $expectedVersion), 409, 'The mapping changed. Reload before removing it.');
            AuditLogger::logOrFail('settings.sso.group_mapping_deleted', $lockedMapping, ['actor_id' => $actorId, 'role_id' => (int) $lockedMapping->role_id]);
            $lockedMapping->delete();
        });

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => 'removed', 'mapping_id' => $mappingId]);
        }

        return back()->with('success', 'Group mapping deleted.');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
    }

    private function assignmentReview(array $data, ?SsoGroupMapping $current = null): ?string
    {
        $startsGranting = ($data['auto_assign'] ?? true) && (! $current || ! $current->auto_assign || (int) $current->role_id !== (int) $data['role_id']);
        if (! $startsGranting) {
            return null;
        }
        if (! ($data['confirm_role_assignment'] ?? false) || trim((string) ($data['assignment_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['assignment_reason' => 'Confirm the role this group may grant and record the reason before enabling assignment.']);
        }

        return trim($data['assignment_reason']);
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
        abort_unless($actor?->isApproved(), 403);
        abort_unless($actor?->canDo('settings.access.manage'), 403);

        return $actor;
    }
}
