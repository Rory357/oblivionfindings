<?php

use App\Models\Permission;
use App\Models\User;

function referralSettingsActor(array $actions): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    ensureCanonicalHrStaffProfile($user);
    foreach (['settings.view', ...$actions] as $action) {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'referrals.'.$action)->firstOrFail()->id => ['allowed' => true]]);
    }

    return $user;
}
