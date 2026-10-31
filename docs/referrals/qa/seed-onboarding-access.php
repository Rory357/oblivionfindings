<?php

// Exact disposable schema, fictional provider and approved-site guards are inherited.
require __DIR__.'/seed-handover-access.php';

use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\User;

foreach (['author', 'reviewer'] as $name) {
    $user = User::where('email', $name.'@referrals-preview.test')->sole();
    $keys = ['service_agreements.viewAny', 'service_agreements.update', 'care_plans.viewAny'];
    if ($name === 'author') {
        $keys[] = 'service_agreements.create';
        $keys[] = 'care_plans.create';
        $keys[] = 'care_plans.update';
        $keys[] = 'clients.assignments.update';
        $keys[] = 'consents.viewAny';
        $keys[] = 'consents.record';
        $keys[] = 'consents.request';
    }
    foreach ($keys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'clients', 'module' => 'Operations']);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
}
echo "Fictional canonical preparation access added; approved-site scope retained.\n";
echo json_encode(['mandatory_consent_types' => ConsentType::active()->mandatory()->get(['id', 'name'])]).PHP_EOL;
