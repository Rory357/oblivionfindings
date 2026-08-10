<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->visibleSite = Site::factory()->create();
    $this->hiddenSite = Site::factory()->create();
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->hr->roles()->firstOrFail()->permissions()->syncWithoutDetaching([
        Permission::query()->where('key', 'rostering.viewAny')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'employee_number' => 'CAL-MANAGER-'.$this->hr->id,
        'primary_site_id' => $this->visibleSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
});

test('every HR calendar route carries the view-permission middleware', function () {
    $routeNames = [
        'hr.calendar.index',
        'hr.calendar.feed',
        'hr.calendar.events.store',
        'hr.calendar.events.update',
        'hr.calendar.events.destroy',
        'hr.calendar.events.restore',
        'hr.calendar.events.rsvp',
        'hr.calendar.events.attachments.store',
        'hr.calendar.attachments.destroy',
        'hr.calendar.attachments.download',
    ];

    foreach ($routeNames as $routeName) {
        expect(app('router')->getRoutes()->getByName($routeName)?->gatherMiddleware())
            ->toContain('permission:hr.calendar.view');
    }
});

test('optional calendar layers fail soft when a source table is absent', function (string $missingTable, array $layers) {
    $checkedMissingTable = false;
    Schema::shouldReceive('hasTable')
        ->andReturnUsing(function (string $table) use ($missingTable, &$checkedMissingTable): bool {
            if ($table === $missingTable) {
                $checkedMissingTable = true;

                return false;
            }

            return true;
        });

    $events = app(HrCalendarAggregator::class)->feed(
        now()->startOfMonth()->toDateString(),
        now()->endOfMonth()->toDateString(),
        $layers,
        [],
        $this->hr,
    );

    expect($events)->toBeArray()
        ->and($checkedMissingTable)->toBeTrue();
})->with([
    'events' => ['hr_calendar_events', ['event']],
    'leave and holidays' => ['hr_leave_requests', ['leave', 'holiday']],
    'shifts and coverage' => ['shifts', ['shift']],
    'compliance statuses' => ['hr_staff_compliance_statuses', ['compliance']],
    'background checks' => ['staff_background_checks', ['compliance']],
    'driver eligibility' => ['hr_driver_eligibility', ['compliance']],
    'people milestones' => ['hr_employee_profiles', ['milestone']],
]);

test('a team audience must name a current team visible through Site access', function () {
    $hiddenUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $hiddenUser->id,
        'employee_number' => 'FOREIGN-TEAM',
        'team' => 'Foreign Clinical',
        'primary_site_id' => $this->hiddenSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/calendar/events', [
            'title' => 'Foreign team event',
            'event_type' => 'team',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addHour()->toDateTimeString(),
            'audience_type' => 'team',
            'audience_team' => 'Foreign Clinical',
        ])
        ->assertSessionHasErrors('audience_team');

    expect(HrCalendarEvent::query()->where('title', 'Foreign team event')->exists())->toBeFalse();
});

test('an active team member can see a team event while other and inactive profiles cannot', function () {
    $viewPermission = Permission::query()->where('key', 'hr.calendar.view')->firstOrFail();
    $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $member = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $member->roles()->syncWithoutDetaching([$supportRole->id]);
    $member->permissionOverrides()->syncWithoutDetaching([$viewPermission->id => ['allowed' => true]]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $member->id,
        'employee_number' => 'TEAM-ACTIVE',
        'team' => 'Clinical   Support',
        'primary_site_id' => $this->visibleSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $other->roles()->syncWithoutDetaching([$supportRole->id]);
    $other->permissionOverrides()->syncWithoutDetaching([$viewPermission->id => ['allowed' => true]]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $other->id,
        'employee_number' => 'TEAM-OTHER',
        'team' => 'Operations',
        'primary_site_id' => $this->visibleSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $inactive = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $inactive->roles()->syncWithoutDetaching([$supportRole->id]);
    $inactive->permissionOverrides()->syncWithoutDetaching([$viewPermission->id => ['allowed' => true]]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $inactive->id,
        'employee_number' => 'TEAM-INACTIVE',
        'team' => 'Clinical Support',
        'primary_site_id' => $this->visibleSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => false,
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/calendar/events', [
            'title' => 'Clinical team hui',
            'event_type' => 'team',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addHour()->toDateTimeString(),
            'audience_type' => 'team',
            'audience_team' => ' clinical support ',
            'audience_user_ids' => [],
        ])
        ->assertRedirect();

    $event = HrCalendarEvent::query()->where('title', 'Clinical team hui')->firstOrFail();
    $this->assertDatabaseHas('hr_calendar_event_attendees', [
        'event_id' => $event->id,
        'audience_type' => 'team',
        'audience_ref' => 'Clinical Support',
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/calendar/events/{$event->id}", [
            'audience_type' => 'team',
            'audience_team' => ' CLINICAL   SUPPORT ',
            'audience_user_ids' => [],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_calendar_event_attendees', [
        'event_id' => $event->id,
        'audience_type' => 'team',
        'audience_ref' => 'Clinical Support',
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/calendar/events', [
            'title' => 'Operations team hui',
            'event_type' => 'team',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->addWeek()->addHour()->toDateTimeString(),
            'audience_type' => 'team',
            'audience_team' => 'Operations',
        ])
        ->assertRedirect();

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->addMonth()->toDateString();
    $url = "/hr/calendar/feed?from={$from}&to={$to}&layers=event";

    $memberEvents = collect($this->actingAs($member)->getJson($url)->assertOk()->json('events'));
    $otherEvents = collect($this->actingAs($other)->getJson($url)->assertOk()->json('events'));
    $inactiveEvents = collect($this->actingAs($inactive)->getJson($url)->assertOk()->json('events'));
    $filteredManagerEvents = collect($this->actingAs($this->hr)->getJson(
        $url.'&team='.rawurlencode(' CLINICAL   SUPPORT '),
    )->assertOk()->json('events'));

    expect($memberEvents->pluck('title'))->toContain('Clinical team hui')
        ->and($memberEvents->firstWhere('title', 'Clinical team hui')['extendedProps']['audienceRef'])->toBe('Clinical Support')
        ->and($otherEvents->pluck('title'))->not->toContain('Clinical team hui')
        ->and($inactiveEvents->pluck('title'))->not->toContain('Clinical team hui')
        ->and($filteredManagerEvents->pluck('title'))->toContain('Clinical team hui')
        ->and($filteredManagerEvents->pluck('title'))->not->toContain('Operations team hui');
});
