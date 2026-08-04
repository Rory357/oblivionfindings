<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

function calendarCanonicalStaff(string $role, Site $site, array $profile = []): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'employee_number' => 'CAL-'.$user->id,
        'position_role' => $role,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ], $profile));

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->visibleSite = Site::factory()->create([
        'name' => 'Calendar visible Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Calendar hidden Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->manager = calendarCanonicalStaff('hr', $this->visibleSite);
    $this->viewer = calendarCanonicalStaff('support_worker', $this->visibleSite);
    $this->viewer->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'hr.calendar.view')->firstOrFail()->id => ['allowed' => true],
    ]);
});

test('calendar Site options feed and archived worklist use canonical Site access', function () {
    $legacyColumn = 'ten'.'ant_id';
    $thisWeek = now()->startOfWeek()->addDay()->setTime(10, 0);
    $visible = HrCalendarEvent::query()->create([
        $legacyColumn => 901,
        'created_by' => $this->manager->id,
        'site_id' => $this->visibleSite->id,
        'title' => 'Visible Site event',
        'event_type' => 'company',
        'starts_at' => $thisWeek,
        'ends_at' => $thisWeek->copy()->addHour(),
    ]);
    $hidden = HrCalendarEvent::query()->create([
        $legacyColumn => 1,
        'created_by' => $this->manager->id,
        'site_id' => $this->hiddenSite->id,
        'title' => 'Hidden Site event',
        'event_type' => 'company',
        'starts_at' => $thisWeek,
        'ends_at' => $thisWeek->copy()->addHour(),
        'archived_at' => now(),
        'archived_by' => $this->manager->id,
    ]);
    HrCalendarEvent::query()->create([
        $legacyColumn => 733,
        'created_by' => $this->manager->id,
        'site_id' => $this->hiddenSite->id,
        'title' => 'Hidden active Site event',
        'event_type' => 'company',
        'starts_at' => $thisWeek,
        'ends_at' => $thisWeek->copy()->addHour(),
    ]);

    $index = $this->actingAs($this->manager)->get('/hr/calendar')->assertOk();
    expect(collect($index->inertiaProps('sites'))->pluck('id')->all())
        ->toBe([$this->visibleSite->id])
        ->and(collect($index->inertiaProps('archivedEvents'))->pluck('id'))
        ->not->toContain($hidden->id)
        ->and($index->inertiaProps('stats.eventsThisWeek'))->toBe(1);

    $from = now()->startOfMonth()->toDateString();
    $to = now()->addMonth()->endOfMonth()->toDateString();
    $events = collect($this->actingAs($this->manager)
        ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
        ->assertOk()
        ->json('events'));

    expect($events->pluck('id'))->toContain('event-'.$visible->id)
        ->and($events->pluck('id'))->not->toContain('event-'.$hidden->id);
});

test('legacy storage values cannot partition application-wide calendar events', function () {
    $legacyColumn = 'ten'.'ant_id';
    foreach ([1, 999] as $legacyValue) {
        HrCalendarEvent::query()->create([
            $legacyColumn => $legacyValue,
            'created_by' => $this->manager->id,
            'title' => 'Application event '.$legacyValue,
            'event_type' => 'company',
            'starts_at' => now()->addDays($legacyValue === 1 ? 2 : 3),
            'ends_at' => now()->addDays($legacyValue === 1 ? 2 : 3)->addHour(),
        ]);
    }

    $from = now()->startOfMonth()->toDateString();
    $to = now()->addMonth()->endOfMonth()->toDateString();
    $titles = collect($this->actingAs($this->manager)
        ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
        ->assertOk()
        ->json('events'))
        ->pluck('title');

    expect($titles)->toContain('Application event 1', 'Application event 999');
});

test('event audiences and private attachments inherit the canonical Site boundary', function () {
    Storage::fake('private');
    Storage::disk('private')->put('hr/calendar/events/hidden/brief.pdf', 'private');
    $hidden = HrCalendarEvent::query()->create([
        'created_by' => $this->manager->id,
        'site_id' => $this->hiddenSite->id,
        'title' => 'Hidden briefing',
        'event_type' => 'company',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
    ]);
    $hidden->attendees()->create([
        'audience_type' => 'site',
        'audience_ref' => (string) $this->hiddenSite->id,
        'rsvp_status' => 'none',
    ]);
    $attachment = HrCalendarEventAttachment::query()->create([
        'event_id' => $hidden->id,
        'uploaded_by' => $this->manager->id,
        'disk' => 'private',
        'original_name' => 'brief.pdf',
        'path' => 'hr/calendar/events/hidden/brief.pdf',
        'mime' => 'application/pdf',
        'size' => 7,
    ]);

    $from = now()->startOfMonth()->toDateString();
    $to = now()->addMonth()->endOfMonth()->toDateString();
    $titles = collect($this->actingAs($this->viewer)
        ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
        ->assertOk()
        ->json('events'))
        ->pluck('title');

    expect($titles)->not->toContain($hidden->title);
    $this->actingAs($this->viewer)
        ->get("/hr/calendar/attachments/{$attachment->id}/download")
        ->assertNotFound();
});

test('calendar creation rejects inaccessible Sites and non-visible invitees', function () {
    $hiddenStaff = calendarCanonicalStaff('support_worker', $this->hiddenSite);
    $payload = [
        'title' => 'Invalid Site audience',
        'event_type' => 'company',
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHour()->toDateTimeString(),
    ];

    $this->actingAs($this->manager)
        ->post('/hr/calendar/events', [
            ...$payload,
            'site_id' => $this->hiddenSite->id,
            'audience_type' => 'site',
        ])
        ->assertUnprocessable();

    $this->actingAs($this->manager)
        ->post('/hr/calendar/events', [
            ...$payload,
            'title' => 'Invalid invitee',
            'audience_type' => 'people',
            'audience_user_ids' => [$hiddenStaff->id],
        ])
        ->assertUnprocessable();

    expect(HrCalendarEvent::query()->whereIn('title', ['Invalid Site audience', 'Invalid invitee'])->exists())
        ->toBeFalse();
});

test('recurrence exceptions inherit the parent audience and malformed audience graphs fail closed', function () {
    $other = calendarCanonicalStaff('support_worker', $this->visibleSite);
    $other->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'hr.calendar.view')->firstOrFail()->id => ['allowed' => true],
    ]);
    $parent = HrCalendarEvent::query()->create([
        'created_by' => $this->manager->id,
        'site_id' => $this->visibleSite->id,
        'title' => 'Private recurring supervision',
        'event_type' => 'team',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHour(),
        'rrule' => 'FREQ=WEEKLY',
    ]);
    $parent->attendees()->create([
        'user_id' => $this->viewer->id,
        'audience_type' => 'person',
        'rsvp_status' => 'none',
    ]);
    $exception = HrCalendarEvent::query()->create([
        'created_by' => $this->manager->id,
        'site_id' => $this->visibleSite->id,
        'title' => 'Moved private supervision',
        'event_type' => 'team',
        'starts_at' => now()->addWeek()->addHour(),
        'ends_at' => now()->addWeek()->addHours(2),
        'recurrence_parent_id' => $parent->id,
        'is_exception' => true,
        'exception_date' => now()->addWeek()->toDateString(),
    ]);

    $access = app(HrCalendarAccessService::class);
    expect($access->canViewEvent($this->viewer, $exception))->toBeTrue()
        ->and($access->canViewEvent($other, $exception))->toBeFalse();

    $malformed = HrCalendarEvent::query()->create([
        'created_by' => $this->manager->id,
        'site_id' => $this->visibleSite->id,
        'title' => 'Mismatched Site audience',
        'event_type' => 'company',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
    ]);
    $malformed->attendees()->create([
        'audience_type' => 'site',
        'audience_ref' => (string) $this->hiddenSite->id,
        'rsvp_status' => 'none',
    ]);

    expect($access->canViewEvent($this->manager, $malformed))->toBeFalse();
});
