<?php

namespace App\Services;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AzureAdGroupService
{
    public function getGroups(Identity $identity): array
    {
        return $this->readGroups($identity, 'https://graph.microsoft.com/v1.0/groups', [
            '$select' => 'id,displayName,securityEnabled',
            '$filter' => 'securityEnabled eq true',
            '$top' => 100,
        ]);
    }

    public function getUserGroups(Identity $identity): array
    {
        return collect($this->readGroups($identity, 'https://graph.microsoft.com/v1.0/me/memberOf', [
            '$select' => 'id,displayName',
            '$top' => 100,
        ]))
            ->filter(fn ($g) => ($g['@odata.type'] ?? '') === '#microsoft.graph.group')
            ->values()
            ->all();
    }

    /** A failed, malformed or truncated response is never an empty membership set. */
    private function readGroups(Identity $identity, string $url, array $query): array
    {
        $all = [];
        $visited = [];
        for ($page = 0; $page < 20; $page++) {
            $parts = parse_url($url);
            if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.microsoft.com'
                || ! in_array($parts['path'] ?? '', ['/v1.0/groups', '/v1.0/me/memberOf'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || isset($visited[$url])) {
                throw new \RuntimeException('Microsoft Graph group pagination is invalid. No roles were changed.');
            }
            $visited[$url] = true;
            $response = Http::withToken($identity->access_token)->connectTimeout(5)->timeout(20)->get($url, $query);
            $body = $response->json();
            if ($response->failed() || ! is_array($body) || ! isset($body['value']) || ! is_array($body['value']) || ! array_is_list($body['value'])) {
                throw new \RuntimeException('Microsoft Graph group fetch failed. No roles were changed.');
            }
            foreach ($body['value'] as $group) {
                if (! is_array($group) || ! is_string($group['id'] ?? null) || $group['id'] === '') {
                    throw new \RuntimeException('Microsoft Graph returned incomplete group evidence. No roles were changed.');
                }
                $all[] = $group;
            }
            $next = $body['@odata.nextLink'] ?? null;
            if ($next === null) {
                return $all;
            }
            if (! is_string($next) || $next === '') {
                throw new \RuntimeException('Microsoft Graph group pagination is invalid. No roles were changed.');
            }
            $url = $next;
            $query = [];
        }

        throw new \RuntimeException('Microsoft Graph group pagination is incomplete. No roles were changed.');
    }

    public function syncUserRoles(User $user): void
    {
        $identity = $user->identities()->where('provider', 'microsoft')->first();
        if (! $identity || $identity->isExpired()) {
            return;
        }

        $userGroups = collect($this->getUserGroups($identity))->pluck('id')->all();
        DB::transaction(function () use ($user, $userGroups, $identity): void {
            $lockedMappings = app(SsoGroupMappingLockService::class)
                ->lockMappingSet()
                ->filter(fn ($mapping): bool => $mapping->provider === 'microsoft');
            $roleIds = $lockedMappings->pluck('role_id')
                ->map(fn ($roleId): int => (int) $roleId)
                ->unique()
                ->sort()
                ->values();
            $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                [$user],
                [],
                $roleIds->all(),
            );
            /** @var User $lockedUser */
            $lockedUser = $lockedUsers->get((int) $user->id);
            if (! $lockedUser || ! $lockedUser->approved_at) {
                return;
            }
            $currentIdentity = Identity::query()->whereKey($identity->id)->lockForUpdate()->first();
            if (! $currentIdentity || (int) $currentIdentity->user_id !== (int) $lockedUser->id
                || $currentIdentity->provider !== 'microsoft' || $currentIdentity->isExpired()
                || ! hash_equals((string) $identity->access_token, (string) $currentIdentity->access_token)) {
                throw new \RuntimeException('The Microsoft identity changed during group verification. No roles were changed.');
            }

            $changes = [];

            foreach ($lockedMappings as $mapping) {
                $inGroup = in_array($mapping->external_group_id, $userGroups, true);

                if ($inGroup && $mapping->auto_assign) {
                    $lockedUser->roles()->syncWithoutDetaching([$mapping->role_id]);
                    $changes[] = ['mapping_id' => (int) $mapping->id, 'role_id' => (int) $mapping->role_id, 'action' => 'assign'];
                } elseif (! $inGroup && $mapping->auto_remove && ! $lockedMappings->contains(fn ($candidate): bool => (int) $candidate->role_id === (int) $mapping->role_id && in_array($candidate->external_group_id, $userGroups, true))) {
                    $lockedUser->roles()->detach($mapping->role_id);
                    $changes[] = ['mapping_id' => (int) $mapping->id, 'role_id' => (int) $mapping->role_id, 'action' => 'remove'];
                }
                $mapping->update(['last_synced_at' => now()]);
            }
            AuditLogger::logOrFail('settings.sso.groups_synced', null, ['subject_user_id' => (int) $lockedUser->id, 'provider' => 'microsoft', 'changes' => $changes]);
        });
    }
}
