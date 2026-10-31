<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\HealthSafety\CommitteeMeetingScheduled;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Whole mounted scheduling workflows; real persistence/read routes, fake delivery only. Native UNRUN. */
final class WorkerParticipationSchedulingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Site $site;
    private HsCommittee $committee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now(), 'external_clinical_account' => false]);
        $this->actor->roles()->attach(Role::where('name', 'health_safety_officer')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null]);
        $this->committee = HsCommittee::create(['name' => 'Existing scheduling committee', 'site_id' => $this->site->id,
            'established_at' => '2025-01-01', 'meeting_frequency' => 'monthly', 'members' => [$this->actor->id], 'status' => 'active', 'created_by' => $this->actor->id]);
        Notification::fake();
        $this->actingAs($this->actor);
    }

    private function create(string $path, array $schedule)
    {
        if ($path === 'inline') {
            return $this->postJson('/health-safety/worker-participation/committees', [...$schedule,
                'name' => 'New scheduling committee', 'site_id' => $this->site->id, 'meeting_frequency' => 'monthly',
                'established_at' => '2026-01-01', 'members' => [$this->actor->id], 'schedule_meeting' => true]);
        }

        return $this->postJson('/health-safety/worker-participation/committees/'.$this->committee->id.'/meetings', [...$schedule, 'attendees' => [$this->actor->id]]);
    }

    public static function validCreates(): array
    {
        $cases = [];
        foreach (['inline', 'existing'] as $path) {
            $cases[] = [$path, '2026-01-15T12:30', null, '2026-01-14 23:30:00'];
            $cases[] = [$path, '2026-07-15T12:30', null, '2026-07-15 00:30:00'];
            $cases[] = [$path, '2026-04-05T02:30', '+13:00', '2026-04-04 13:30:00'];
            $cases[] = [$path, '2026-04-05T02:30', '+12:00', '2026-04-04 14:30:00'];
        }
        return $cases;
    }

    #[DataProvider('validCreates')]
    public function test_both_create_paths_store_unique_and_explicit_fold_instants_as_utc(string $path, string $wall, ?string $offset, string $utc): void
    {
        $before = HsCommittee::count();
        $this->create($path, ['scheduled_at' => $wall, 'scheduled_timezone' => 'Pacific/Auckland', 'scheduled_offset' => $offset])->assertRedirect();
        $meeting = HsCommitteeMeeting::sole();
        $this->assertSame($utc, $meeting->getRawOriginal('scheduled_at'));
        $this->assertSame($path === 'inline' ? $before + 1 : $before, HsCommittee::count());
        $this->assertSame([$this->actor->id], $meeting->attendeeUsers()->pluck('users.id')->all());
        $this->assertArrayNotHasKey('scheduled_timezone', $meeting->getAttributes());
        $this->assertArrayNotHasKey('scheduled_offset', $meeting->getAttributes());
        Notification::assertSentTo($this->actor, CommitteeMeetingScheduled::class);
    }

    public static function invalidCreates(): array
    {
        $cases = [];
        foreach (['inline', 'existing'] as $path) {
            $cases[] = [$path, '2026-09-27T02:30', 'Pacific/Auckland', null, 'scheduled_at'];
            $cases[] = [$path, '2026-04-05T02:30', 'Pacific/Auckland', null, 'scheduled_offset'];
            $cases[] = [$path, '2026-07-15T12:30', 'Pacific/Auckland', '+13:00', 'scheduled_offset'];
            $cases[] = [$path, '2026-01-15T12:30', 'UTC', null, 'scheduled_at'];
            $cases[] = [$path, '2026-02-30T12:30', 'Pacific/Auckland', null, 'scheduled_at'];
        }
        return $cases;
    }

    #[DataProvider('invalidCreates')]
    public function test_invalid_marked_schedules_return_field_error_before_any_partial_creation(string $path, string $wall, string $zone, ?string $offset, string $field): void
    {
        $before = HsCommittee::count();
        $this->create($path, ['scheduled_at' => $wall, 'scheduled_timezone' => $zone, 'scheduled_offset' => $offset])
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->assertSame($before, HsCommittee::count()); $this->assertSame(0, HsCommitteeMeeting::count());
        $this->assertSame(0, DB::table('hs_meeting_attendees')->count());
        Notification::assertNothingSent();
    }

    public function test_native_register_and_calendar_read_same_utc_instant_as_nz_wall_time(): void
    {
        $this->create('existing', ['scheduled_at' => '2026-01-15T12:30', 'scheduled_timezone' => 'Pacific/Auckland'])->assertRedirect();
        $meeting = HsCommitteeMeeting::sole();
        $response = $this->getJson('/health-safety/worker-participation?tab=meetings&site_id='.$this->site->id.'&meeting='.$meeting->id, ['X-Inertia' => 'true']);
        $response->assertOk()->assertJsonPath('props.detail.data.id', $meeting->id);
        $readAt = Carbon::parse($response->json('props.detail.data.scheduled_at'));
        $this->assertSame('2026-01-14T23:30:00+00:00', $readAt->utc()->toIso8601String());
        $this->assertSame('2026-01-15T12:30+13:00', $readAt->setTimezone('Pacific/Auckland')->format('Y-m-d\TH:iP'));
        $calendar = $this->getJson('/sites/'.$this->site->id.'/calendar/events?start=2026-01-14&end=2026-01-15T23:59:59&sources[]=participation');
        $calendar->assertOk()->assertJsonPath('availability.participation', 'available');
        $row = collect($calendar->json('events'))->sole('id', 'participation-meeting-'.$meeting->id);
        $this->assertFalse($row['allDay']);
        $this->assertSame('2026-01-15T12:30+13:00', Carbon::parse($row['start'])->setTimezone('Pacific/Auckland')->format('Y-m-d\TH:iP'));
    }

    public function test_update_without_scheduled_at_preserves_exact_stored_precision_and_sends_no_reschedule(): void
    {
        $meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-01-14 23:30:37', 'status' => 'scheduled']);
        $meeting->attendeeUsers()->attach($this->actor->id);
        $original = $meeting->getRawOriginal('scheduled_at');
        $armed = true; $updates = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$updates): void {
            if ($armed && preg_match('/^update `hs_committee_meetings`/i', ltrim($sql))) { $updates[] = $sql; }
        });
        try { $this->putJson('/health-safety/worker-participation/meetings/'.$meeting->id, ['location' => 'Changed room'])->assertRedirect(); }
        finally { $armed = false; }
        $this->assertNotEmpty($updates);
        foreach ($updates as $sql) { $this->assertStringNotContainsString('`scheduled_at`', $sql); }
        $this->assertSame($original, $meeting->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame('Changed room', $meeting->fresh()->location);
        Notification::assertNothingSent();
    }

    public function test_changed_marked_time_renotifies_existing_attendee_with_fake_delivery_only(): void
    {
        $meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-01-14 23:30:00', 'status' => 'scheduled']);
        $meeting->attendeeUsers()->attach($this->actor->id);
        $this->putJson('/health-safety/worker-participation/meetings/'.$meeting->id,
            ['scheduled_at' => '2026-01-15T13:45', 'scheduled_timezone' => 'Pacific/Auckland'])->assertRedirect();
        $this->assertSame('2026-01-15 00:45:00', $meeting->fresh()->getRawOriginal('scheduled_at'));
        Notification::assertSentTo($this->actor, CommitteeMeetingScheduled::class,
            fn ($notice) => $notice->meeting->id === $meeting->id && $notice->meeting->scheduled_at->utc()->format('Y-m-d H:i:s') === '2026-01-15 00:45:00');
    }

    public function test_same_marked_instant_does_not_send_reschedule_notification(): void
    {
        $meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-04-04 14:30:00', 'status' => 'scheduled']);
        $meeting->attendeeUsers()->attach($this->actor->id);
        $this->putJson('/health-safety/worker-participation/meetings/'.$meeting->id,
            ['scheduled_at' => '2026-04-05T02:30', 'scheduled_timezone' => 'Pacific/Auckland', 'scheduled_offset' => '+12:00'])->assertRedirect();
        $this->assertSame('2026-04-04 14:30:00', $meeting->fresh()->getRawOriginal('scheduled_at'));
        Notification::assertNothingSent();
    }

    public function test_invalid_update_preserves_existing_body_and_timestamp_without_notice(): void
    {
        $meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-01-14 23:30:37', 'status' => 'scheduled', 'location' => 'Original room']);
        $this->putJson('/health-safety/worker-participation/meetings/'.$meeting->id,
            ['scheduled_at' => '2026-09-27T02:30', 'scheduled_timezone' => 'Pacific/Auckland', 'location' => 'Invalid replacement'])
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame('2026-01-14 23:30:37', $meeting->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame('Original room', $meeting->fresh()->location); Notification::assertNothingSent();
    }

    public function test_unmarked_plain_utc_adapter_keeps_existing_timestamp_meaning(): void
    {
        $this->create('existing', ['scheduled_at' => '2026-01-15 12:30:37'])->assertRedirect();
        $meeting = HsCommitteeMeeting::sole();
        $this->assertSame('2026-01-15 12:30:37', $meeting->getRawOriginal('scheduled_at'));
        $this->putJson('/health-safety/worker-participation/meetings/'.$meeting->id, ['scheduled_at' => '2026-01-16 12:30:37'])->assertRedirect();
        $this->assertSame('2026-01-16 12:30:37', $meeting->fresh()->getRawOriginal('scheduled_at'));
    }
}
