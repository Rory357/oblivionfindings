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
        $response = Http::withToken($identity->access_token)
            ->get('https://graph.microsoft.com/v1.0/groups', [
                '$select' => 'id,displayName,securityEnabled',
                '$filter' => 'securityEnabled eq true',
                '$top' => 100,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Microsoft Graph group fetch failed.');
        }

        return $response->json('value', []);
    }

    public function getUserGroups(Identity $identity): array
    {
        $response = Http::withToken($identity->access_token)
            ->get('https://graph.microsoft.com/v1.0/me/memberOf', [
                '$select' => 'id,displayName',
                '$top' => 100,
            ]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('value', []))
            ->filter(fn ($g) => ($g['@odata.type'] ?? '') === '#microsoft.graph.group')
            ->values()
            ->all();
    }

    public function syncUserRoles(User $user): void
    {
        $identity = $user->identities()->where('provider', 'microsoft')->first();
        if (! $identity || $identity->isExpired()) {
            return;
        }

        $userGroups = collect($this->getUserGroups($identity))->pluck('id')->all();
        DB::transaction(function () use ($user, $userGroups): void {
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

            foreach ($lockedMappings as $mapping) {
                $inGroup = in_array($mapping->external_group_id, $userGroups, true);

                if ($inGroup && $mapping->auto_assign) {
                    $lockedUser->roles()->syncWithoutDetaching([$mapping->role_id]);
                } elseif (! $inGroup && $mapping->auto_remove) {
                    $lockedUser->roles()->detach($mapping->role_id);
                }
            }
        });
    }
}
