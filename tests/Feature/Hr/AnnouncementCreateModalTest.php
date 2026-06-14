<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // hr.announcements.* are in SeedHrPermissionsSeeder → the hr role gets them.
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
});

test('an announcement can be published from the index modal endpoint', function () {
    $this->actingAs($this->hr)->post('/hr/announcements', [
        'title' => 'Office closed Friday',
        'content' => 'The office will be closed this Friday for maintenance.',
        'priority' => 'high',
        'target_audience' => 'all',
        'is_pinned' => true,
        'requires_acknowledgement' => true,
    ])->assertRedirect(route('hr.announcements.index'));

    $this->assertDatabaseHas('hr_announcements', [
        'tenant_id' => 1,
        'title' => 'Office closed Friday',
        'priority' => 'high',
        'created_by' => $this->hr->id,
    ]);
});

test('the announcements index ships priority and audience options for the modal', function () {
    $response = $this->actingAs($this->hr)->get('/hr/announcements');
    $response->assertOk();

    $priorities = collect($response->inertiaProps('priorities'))->pluck('value')->all();
    $audiences = collect($response->inertiaProps('audiences'))->pluck('value')->all();
    expect($priorities)->toContain('urgent');
    expect($audiences)->toContain('all');
});

test('the retired create page redirects to the index', function () {
    $this->actingAs($this->hr)
        ->get('/hr/announcements/create')
        ->assertRedirect(route('hr.announcements.index'));
});

test('a user without hr.announcements.manage cannot publish', function () {
    $this->actingAs($this->worker)->post('/hr/announcements', [
        'title' => 'Sneaky',
        'content' => 'Should be blocked.',
        'priority' => 'normal',
        'target_audience' => 'all',
    ])->assertForbidden();

    $this->assertDatabaseMissing('hr_announcements', ['title' => 'Sneaky']);
});
