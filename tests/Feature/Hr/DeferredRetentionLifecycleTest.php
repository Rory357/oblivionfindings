<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Domain\Hr\Services\CompensationService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
});

test('calendar removal archives and restores the event without deleting retained evidence', function () {
    $invitee = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $event = HrCalendarEvent::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'title' => 'Retained evidence hui',
        'event_type' => 'company',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHour(),
        'is_all_day' => false,
    ]);
    $attendee = $event->attendees()->create([
        'user_id' => $invitee->id,
        'audience_type' => 'person',
        'rsvp_status' => 'yes',
    ]);
    $reminder = $event->reminders()->create([
        'offset_minutes' => 60,
        'channel' => 'notification',
    ]);
    $path = "hr/calendar/1/retained-{$event->id}.pdf";
    Storage::disk('private')->put($path, '%PDF-1.4 retained');
    $attachment = $event->attachments()->create([
        'tenant_id' => 1,
        'uploaded_by' => $this->hr->id,
        'disk' => 'private',
        'original_name' => 'retained.pdf',
        'path' => $path,
        'mime' => 'application/pdf',
        'size' => 17,
    ]);

    $this->actingAs($this->hr)
        ->delete("/hr/calendar/events/{$event->id}", ['archive_reason' => 'Created in error'])
        ->assertSessionHas('success');

    $archived = $event->fresh();
    expect($archived)->not->toBeNull()
        ->and($archived->archived_at)->not->toBeNull()
        ->and((int) $archived->archived_by)->toBe($this->hr->id)
        ->and($archived->archive_reason)->toBe('Created in error')
        ->and($attendee->fresh())->not->toBeNull()
        ->and($reminder->fresh())->not->toBeNull()
        ->and($attachment->fresh())->not->toBeNull();
    Storage::disk('private')->assertExists($path);

    $this->actingAs($this->hr)
        ->get('/hr/calendar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('archivedEvents', 1)
            ->where('archivedEvents.0.id', $event->id)
            ->where('archivedEvents.0.archive_reason', 'Created in error'));

    $this->actingAs($this->hr)
        ->put("/hr/calendar/events/{$event->id}", ['title' => 'Must restore first'])
        ->assertStatus(409);

    $from = now()->startOfMonth()->toDateString();
    $to = now()->addMonths(2)->endOfMonth()->toDateString();
    $archivedFeed = collect($this->actingAs($this->hr)
        ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
        ->assertOk()
        ->json('events'));
    expect($archivedFeed->pluck('id'))->not->toContain('event-'.$event->id);

    $this->actingAs($this->hr)
        ->post("/hr/calendar/events/{$event->id}/restore")
        ->assertSessionHas('success');

    expect($event->fresh()->archived_at)->toBeNull()
        ->and($event->fresh()->archived_by)->toBeNull()
        ->and($event->fresh()->archive_reason)->toBeNull();

    $restoredFeed = collect($this->actingAs($this->hr)
        ->getJson("/hr/calendar/feed?from={$from}&to={$to}&layers=event")
        ->assertOk()
        ->json('events'));
    expect($restoredFeed->pluck('id'))->toContain('event-'.$event->id);
});

test('salary band deactivation preserves historical placement and active selector identity', function () {
    $worker = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $profile = HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $worker->id,
        'position_role' => 'support_worker',
        'annual_salary' => 60000,
        'is_active' => true,
    ]);
    $band = HrSalaryBand::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'position_role' => 'support_worker',
        'band_name' => 'Retained Band',
        'min_salary' => 50000,
        'mid_salary' => 60000,
        'max_salary' => 70000,
        'min_hourly' => 25,
        'max_hourly' => 35,
        'currency' => 'NZD',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);
    $service = app(CompensationService::class);

    expect($service->getSalaryBandForRole(1, 'support_worker')?->id)->toBe($band->id)
        ->and($service->bandPlacement($profile, $band)['position'])->toBe('in');

    $this->actingAs($this->hr)
        ->post("/hr/compensation/bands/{$band->id}/deactivate")
        ->assertSessionHas('success');

    $inactive = $band->fresh();
    expect($inactive)->not->toBeNull()
        ->and($inactive->is_active)->toBeFalse()
        ->and($inactive->deactivated_at)->not->toBeNull()
        ->and((int) $inactive->deactivated_by)->toBe($this->hr->id)
        ->and($service->getSalaryBandForRole(1, 'support_worker'))->toBeNull()
        ->and($service->bandPlacement($profile, $inactive)['position'])->toBe('in');

    $this->actingAs($this->hr)
        ->get('/hr/compensation/reviews')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('bands', 0));

    $this->actingAs($this->hr)
        ->post("/hr/compensation/bands/{$band->id}/reactivate")
        ->assertSessionHas('success');

    expect($band->fresh()->is_active)->toBeTrue()
        ->and($band->fresh()->deactivated_at)->toBeNull()
        ->and($band->fresh()->deactivated_by)->toBeNull()
        ->and($service->getSalaryBandForRole(1, 'support_worker')?->id)->toBe($band->id);
});
