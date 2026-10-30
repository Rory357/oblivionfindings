<?php

// Fictional existing-person UI fixture; the imported guard refuses persistent databases.
require __DIR__.'/seed-staff.php';

use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    $site = Site::where('name', 'Referrals browser preview')->sole();
    $actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
    foreach (['clients.viewAny', 'clients.update', 'onboarding.view'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'clients', 'module' => 'Operations']);
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $context = ServiceContext::firstOrCreate(['site_id' => $site->id, 'name' => 'Fictional internal support setting'], ['type' => 'home_support', 'is_active' => true]);
    $person = Client::where('first_name', 'Fictional')->where('last_name', 'Internal preparation')->first();
    $person ??= Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'first_name' => 'Fictional', 'last_name' => 'Internal preparation', 'status' => 'onboarding']);
    echo json_encode(['person_url' => '/operations/clients/'.$person->id.'?tab=onboarding', 'person_id' => $person->id, 'context' => $context->name]).PHP_EOL;
});
