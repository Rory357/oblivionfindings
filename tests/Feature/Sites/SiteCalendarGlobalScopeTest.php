<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function siteCalendarUser(string $roleName): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

test('global site calendar only returns sites within the users assigned site scope', function () {
    $user = siteCalendarUser('team_lead');

    $allowedSite = Site::factory()->create([
        'name' => 'Alpha House',
        'type' => 'house',
    ]);
    $blockedSite = Site::factory()->create([
        'name' => 'Bravo House',
        'type' => 'house',
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-CALENDAR-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Team Lead',
        'position_role' => 'team_lead',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $allowedSite->id,
        'secondary_site_ids' => [],
    ]);

    $this->actingAs($user)
        ->get('/calendar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('calendar/global')
            ->has('sites', 1)
            ->where('sites.0.id', $allowedSite->id)
            ->where('sites.0.name', 'Alpha House')
        );
});
