<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteCalendarEventException;
use App\Models\User;
use App\Services\Sites\SiteCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function calendarManager(Site $site): User
{
    $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $role = Role::query()->where('name', 'team_lead')->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-CALWF-'.$user->id,
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

test('an approver can approve a pending event', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = calendarManager($site);

    $event = SiteCalendarEvent::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'event_type' => 'maintenance',
        'title' => 'Boiler service',
        'start_at' => Carbon::parse('2026-05-20 10:00'),
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'approval_status' => 'pending',
    ]);

    $this->actingAs($user)
        ->post("/sites/{$site->id}/calendar/events/{$event->id}/approve")
        ->assertRedirect();

    $event->refresh();
    expect($event->approval_status)->toBe('approved');
    expect($event->status)->toBe('approved');
    expect($event->approved_by_user_id)->toBe($user->id);
});

test('a partial update reschedules without touching other fields', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = calendarManager($site);

    $event = SiteCalendarEvent::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'event_type' => 'general',
        'title' => 'House meeting',
        'start_at' => Carbon::parse('2026-05-10 09:00'),
        'end_at' => Carbon::parse('2026-05-10 10:00'),
        'created_by_user_id' => $user->id,
        'status' => 'approved',
        'approval_status' => 'not_required',
    ]);

    $this->actingAs($user)
        ->put("/sites/{$site->id}/calendar/events/{$event->id}", [
            'start_at' => '2026-05-12T14:00:00',
            'end_at' => '2026-05-12T15:00:00',
        ])
        ->assertRedirect();

    $event->refresh();
    expect($event->start_at->format('Y-m-d H:i'))->toBe('2026-05-12 14:00');
    expect($event->end_at->format('Y-m-d H:i'))->toBe('2026-05-12 15:00');
    expect($event->title)->toBe('House meeting');
});

test('a single-occurrence override moves only that occurrence', function () {
    $site = Site::factory()->create(['type' => 'house']);

    $event = SiteCalendarEvent::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'event_type' => 'general',
        'title' => 'Weekly meeting',
        'start_at' => Carbon::parse('2026-05-04 10:00'),
        'end_at' => Carbon::parse('2026-05-04 11:00'),
        'recurrence_rule' => 'FREQ=WEEKLY',
        'created_by_user_id' => User::factory()->create()->id,
        'status' => 'approved',
        'approval_status' => 'not_required',
    ]);

    SiteCalendarEventException::create([
        'parent_event_id' => $event->id,
        'exception_date' => '2026-05-11',
        'is_cancelled' => false,
        'overridden_fields' => [
            'start_at' => '2026-05-11T14:00:00',
            'end_at' => '2026-05-11T15:00:00',
        ],
    ]);

    $occurrences = app(SiteCalendarService::class)->getEventsForRange(
        [$site->id],
        null,
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
    );

    $moved = collect($occurrences)->first(fn ($o) => str_starts_with((string) $o['start_at'], '2026-05-11T14:00'));
    expect($moved)->not->toBeNull();

    // The un-overridden occurrences keep the series' 10:00 start.
    $normal = collect($occurrences)->first(fn ($o) => str_starts_with((string) $o['start_at'], '2026-05-18T10:00'));
    expect($normal)->not->toBeNull();
});
