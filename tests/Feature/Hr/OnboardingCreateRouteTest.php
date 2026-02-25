<?php

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
});

test('onboarding create route resolves to create page instead of checklist show binding', function () {
    $response = $this->actingAs($this->hr)->get('/hr/onboarding/create');

    $response->assertOk();
    $response->assertSee('hr\/onboarding\/create');
});
