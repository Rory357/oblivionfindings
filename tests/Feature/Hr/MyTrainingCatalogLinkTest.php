<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
});

test('the my/training page exposes the catalog link to users who can view the LMS', function () {
    // provider_manager holds hr.training.view (RbacSeeder).
    $manager = User::factory()->create([
        'organization_id' => 1,
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->first()->id,
    ]);

    $response = $this->actingAs($manager)->get('/hr/my/training');
    $response->assertOk();
    expect($response->inertiaProps('can.viewCatalog'))->toBeTrue();
});

test('a plain employee without training-view does not get the catalog link', function () {
    $worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $response = $this->actingAs($worker)->get('/hr/my/training');
    $response->assertOk();
    expect($response->inertiaProps('can.viewCatalog'))->toBeFalse();
});
