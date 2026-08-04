<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // HR role is granted hr.recognition.view + hr.recognition.give.
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
    ]);

    // A role with no recognition permission.
    $noRecRole = Role::query()->firstOrCreate(['name' => 'norec'], ['label' => 'No Recognition']);
    $this->outsider = User::factory()->create(['role' => 'norec', 'approved_at' => now()]);
    $this->outsider->roles()->syncWithoutDetaching([$noRecRole->id]);

    $this->recipient = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->recipient->id,
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
    ]);
});

test('a user without hr.recognition.view cannot read the feed', function () {
    $this->actingAs($this->outsider)
        ->get('/hr/feed')
        ->assertForbidden();
});

test('a user without hr.recognition.give cannot send kudos (was previously ungated)', function () {
    $this->actingAs($this->outsider)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $this->recipient->id,
            'category' => 'teamwork',
            'message' => 'Nice work!',
        ])
        ->assertForbidden();

    $this->actingAs($this->outsider)
        ->post('/hr/feed', [
            'content' => 'Hello feed',
            'post_type' => 'update',
        ])
        ->assertForbidden();
});

test('a permitted user can view the feed and send kudos', function () {
    $this->actingAs($this->hr)->get('/hr/feed')->assertOk();

    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $this->recipient->id,
            'category' => 'teamwork',
            'message' => 'Great teamwork on the night shift!',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_kudos', [
        'from_user_id' => $this->hr->id,
        'to_user_id' => $this->recipient->id,
        'category' => 'teamwork',
    ]);
});
