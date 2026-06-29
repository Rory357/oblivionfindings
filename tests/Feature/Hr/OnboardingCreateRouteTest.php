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

test('onboarding create route redirects to the hub wizard instead of binding as a checklist', function () {
    // The legacy single-field create page is retired; /create now redirects to
    // the hub (and must still resolve literally, not as a {checklist} binding).
    $response = $this->actingAs($this->hr)->get('/hr/onboarding/create');

    $response->assertRedirect('/hr/onboarding');
});
