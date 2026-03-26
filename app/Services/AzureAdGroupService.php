<?php

namespace App\Services;

use App\Models\Identity;
use App\Models\SsoGroupMapping;
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

        if ($response->failed()) return [];
        return $response->json('value', []);
    }

    public function getUserGroups(Identity $identity): array
    {
        $response = Http::withToken($identity->access_token)
            ->get('https://graph.microsoft.com/v1.0/me/memberOf', [
                '$select' => 'id,displayName',
                '$top' => 100,
            ]);

        if ($response->failed()) return [];
        return collect($response->json('value', []))
            ->filter(fn ($g) => ($g['@odata.type'] ?? '') === '#microsoft.graph.group')
            ->values()
            ->all();
    }

    public function syncUserRoles(\App\Models\User $user): void
    {
        $identity = $user->identities()->where('provider', 'microsoft')->first();
        if (!$identity || $identity->isExpired()) return;

        $userGroups = collect($this->getUserGroups($identity))->pluck('id')->all();
        $mappings = SsoGroupMapping::where('provider', 'microsoft')->with('role')->get();

        foreach ($mappings as $mapping) {
            $inGroup = in_array($mapping->external_group_id, $userGroups);

            if ($inGroup && $mapping->auto_assign) {
                $user->roles()->syncWithoutDetaching([$mapping->role_id]);
            } elseif (!$inGroup && $mapping->auto_remove) {
                $user->roles()->detach($mapping->role_id);
            }
        }
    }
}
