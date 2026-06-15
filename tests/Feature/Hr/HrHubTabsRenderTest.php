<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // hr role holds ALL hr.* permissions (SeedHrPermissionsSeeder), so it can
    // reach every page in the Settings / Compensation / Reports hubs.
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

// Each hub page must still render (200) after the section tab-strip was added —
// guards the new import + <HubTabs/> element against route/render breakage.
test('every page in the tab-shell hubs still renders for a permitted user', function (string $url) {
    $this->actingAs($this->hr)->get($url)->assertOk();
})->with([
    // Settings hub
    '/hr/settings/webhooks',
    '/hr/settings/custom-fields',
    '/hr/settings/audit-log',
    // Compensation hub
    '/hr/compensation/bands',
    '/hr/compensation/reviews',
    '/hr/compensation/bonuses',
    // Reports hub
    '/hr/reports',
    '/hr/reports/builder',
    '/hr/reports/saved',
    '/hr/reports/automations',
    '/hr/reports/webhooks',
]);
