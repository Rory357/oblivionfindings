<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function calendarFeedViewer(string $token, array $userOverrides = []): User
{
    $user = User::factory()->create([
        'role' => 'team_lead',
        'approved_at' => now(),
        'calendar_feed_token' => $token,
        ...$userOverrides,
    ]);
    $user->roles()->sync([Role::query()->where('name', 'team_lead')->value('id')]);

    return $user;
}

function attachCalendarFeedProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-CALFEED-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        ...$overrides,
    ]);
}

test('the subscribe feed returns a VCALENDAR for a valid token', function () {
    $site = Site::factory()->create(['type' => 'house']);

    SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'plan_date' => now()->addDays(3)->toDateString(),
        'meal_slot' => 'lunch',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'Feed Roast',
        'servings' => 2,
    ]);

    $user = calendarFeedViewer('feedtoken123');
    attachCalendarFeedProfile($user, $site);

    $response = $this->get('/calendar/feed/feedtoken123.ics');

    $response->assertOk();
    expect($response->getContent())->toContain('BEGIN:VCALENDAR');
    expect($response->getContent())->toContain('Feed Roast');
    expect($response->getContent())->toContain('END:VCALENDAR');
});

test('the subscribe feed 404s for an unknown token', function () {
    $this->get('/calendar/feed/unknowntoken.ics')->assertNotFound();
});

test('the subscribe feed does not expose any site when the token owner has no current site access', function (string $profileState) {
    $site = Site::factory()->create(['type' => 'house']);
    SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'plan_date' => now()->addDays(3)->toDateString(),
        'meal_slot' => 'lunch',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'Private Site Meal',
        'servings' => 2,
    ]);

    $user = calendarFeedViewer('noaccess'.$profileState.'token');
    if ($profileState === 'ended') {
        attachCalendarFeedProfile($user, $site, [
            'end_date' => now()->subDay()->toDateString(),
            'is_active' => false,
        ]);
    }

    $response = $this->get('/calendar/feed/noaccess'.$profileState.'token.ics');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('BEGIN:VCALENDAR')
        ->not->toContain('Private Site Meal')
        ->toContain('END:VCALENDAR');
})->with(['missing', 'ended']);

test('the subscribe feed conceals tokens whose account approval or calendar permission was revoked', function (string $revocation) {
    $site = Site::factory()->create(['type' => 'house']);
    $token = 'revoked'.$revocation.'token';
    $user = $revocation === 'approval'
        ? calendarFeedViewer($token, ['approved_at' => null])
        : User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'calendar_feed_token' => $token,
        ]);
    attachCalendarFeedProfile($user, $site);

    $this->get('/calendar/feed/'.$token.'.ics')->assertNotFound();
})->with(['approval', 'permission']);

test('the subscribe feed excludes a site carrying a stale archive timestamp', function () {
    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
        'archived_at' => now(),
    ]);
    SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'plan_date' => now()->addDays(3)->toDateString(),
        'meal_slot' => 'lunch',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'Stale Archive Meal',
        'servings' => 2,
    ]);
    $user = calendarFeedViewer('stalearchivesitetoken');
    attachCalendarFeedProfile($user, $site);

    $response = $this->get('/calendar/feed/stalearchivesitetoken.ics');

    $response->assertOk();
    expect($response->getContent())->not->toContain('Stale Archive Meal');
});
