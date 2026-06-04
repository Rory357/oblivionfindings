<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteCertification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

/** A team_lead (has calendar.* + sites.update) assigned to the given site. */
function heroCountManager(Site $site): User
{
    $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $role = Role::query()->where('name', 'team_lead')->first();
    $user->roles()->syncWithoutDetaching([$role->id]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-HEROCNT-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Team Lead',
        'position_role' => 'team_lead',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $user;
}

test('the single-site hero exposes authoritative pending, mine and overdue counts', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $manager = heroCountManager($site);
    $other = User::factory()->create(['approved_at' => now()]);

    // "Mine" via owner.
    SiteCalendarEvent::create([
        'site_id' => $site->id, 'tenant_id' => $site->tenant_id,
        'event_type' => 'general', 'title' => 'My meeting',
        'start_at' => Carbon::parse('2026-06-10 09:00'),
        'created_by_user_id' => $manager->id, 'owner_user_id' => $manager->id,
        'status' => 'approved', 'approval_status' => 'not_required',
    ]);

    // "Mine" via attendee (owned by someone else) — the leg the in-view derivation misses.
    SiteCalendarEvent::create([
        'site_id' => $site->id, 'tenant_id' => $site->tenant_id,
        'event_type' => 'general', 'title' => 'Invited',
        'start_at' => Carbon::parse('2026-06-11 09:00'),
        'created_by_user_id' => $other->id, 'owner_user_id' => $other->id,
        'attendee_user_ids' => [$manager->id],
        'status' => 'approved', 'approval_status' => 'not_required',
    ]);

    // Two pending approvals owned by someone else (counted as pending, not "mine").
    foreach (['Pending A', 'Pending B'] as $i => $title) {
        SiteCalendarEvent::create([
            'site_id' => $site->id, 'tenant_id' => $site->tenant_id,
            'event_type' => 'maintenance', 'title' => $title,
            'start_at' => Carbon::parse('2026-06-1'.($i + 2).' 09:00'),
            'created_by_user_id' => $other->id, 'owner_user_id' => $other->id,
            'status' => 'draft', 'approval_status' => 'pending',
        ]);
    }

    // An overdue obligation: a compliance certificate that expired two months ago.
    SiteCertification::create([
        'site_id' => $site->id,
        'name' => 'Fire safety certificate',
        'certification_type' => 'fire_safety',
        'status' => 'active',
        'expiry_date' => now()->subMonths(2)->toDateString(),
    ]);

    $this->actingAs($manager)
        ->get("/sites/{$site->id}/calendar")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/calendar/index')
            ->where('pendingApprovalCount', 2)
            ->where('mineCount', 2)
            ->where('overdueCount', 1)
            ->where('canApprove', true)
        );
});

test('hero counts ignore events on sites outside the users scope', function () {
    $mySite = Site::factory()->create(['type' => 'house']);
    $otherSite = Site::factory()->create(['type' => 'house']);
    $manager = heroCountManager($mySite);

    // A pending event on a site the manager cannot see must not inflate the count.
    SiteCalendarEvent::create([
        'site_id' => $otherSite->id, 'tenant_id' => $otherSite->tenant_id,
        'event_type' => 'maintenance', 'title' => 'Elsewhere',
        'start_at' => Carbon::parse('2026-06-12 09:00'),
        'created_by_user_id' => $manager->id, 'owner_user_id' => $manager->id,
        'status' => 'draft', 'approval_status' => 'pending',
    ]);

    $this->actingAs($manager)
        ->get("/sites/{$mySite->id}/calendar")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pendingApprovalCount', 0)
            ->where('mineCount', 0)
        );
});

test('canApprove is false when the user can view but not update the site', function () {
    $site = Site::factory()->create(['type' => 'house']);

    // A bespoke role that can browse sites and approve calendar entries, but cannot
    // update sites — so approve()/reject() would 403 and the button must stay hidden.
    $role = Role::create([
        'name' => 'calendar_approver_readonly',
        'label' => 'Calendar Approver (read-only site)',
        'level' => 50,
        'type' => 'system',
        'description' => 'Test role: approve calendars without site update.',
    ]);
    $role->permissions()->sync(
        Permission::query()->whereIn('key', [
            'sites.viewAny', 'sites.type.house.view', 'calendar.view', 'calendar.approve',
        ])->pluck('id')
    );

    $user = User::factory()->create(['role' => 'calendar_approver_readonly', 'approved_at' => now()]);
    $user->roles()->sync([$role->id]);

    $this->actingAs($user)
        ->get("/sites/{$site->id}/calendar")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/calendar/index')
            ->where('canApprove', false)
            ->where('canCreate', false)
        );
});
