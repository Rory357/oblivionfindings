<?php

// Existing guard requires the exact disposable schema and fictional provider.
require __DIR__.'/seed-staff.php';

use App\Models\Permission;
use App\Models\User;

foreach (['author', 'reviewer'] as $name) {
    $user = User::where('email', $name.'@referrals-preview.test')->sole();
    $keys = ['clients.viewAny', 'clients.update', 'onboarding.view'];
    if ($name === 'reviewer') {
        $keys[] = 'clients.create';
        $keys[] = 'care_plans.viewAny';
        $keys[] = 'service_agreements.viewAny';
    }
    foreach ($keys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'clients', 'module' => 'Operations']);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
}
echo "Fictional handover accounts prepared; existing approved-site scope retained.\n";
