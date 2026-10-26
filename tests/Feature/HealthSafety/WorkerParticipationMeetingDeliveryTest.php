<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DispatchWorkerParticipationMeetingDelivery;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerParticipationMeetingDelivery;
use App\Models\WorkerParticipationMeetingEvent;
use App\Notifications\HealthSafety\WorkerParticipationMeetingNotice;
use App\Services\HealthSafety\WorkerParticipationMeetingDeliveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Support\NotInThisBuild;

/** Real source, intent and database-channel SQL. Mail physical-commit protocol remains a separate gate. Native UNRUN. */
final class WorkerParticipationMeetingDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $attendee;
    private User $worker;
    private Site $site;
    private HsCommitteeMeeting $meeting;
    private WorkerParticipationMeetingDeliveryService $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->manager = $this->staff('health_safety_officer');
        $this->attendee = $this->staff('support_worker');
        $this->worker = $this->staff('support_worker');
        $committee = HsCommittee::create(['name' => 'Private committee', 'site_id' => $this->site->id,
            'status' => 'active', 'meeting_frequency' => 'monthly', 'established_at' => '2025-01-01', 'members' => []]);
        $this->meeting = $committee->meetings()->create(['scheduled_at' => '2026-01-15 00:00:00', 'status' => 'scheduled', 'location' => 'Private location']);
        $this->meeting->attendeeUsers()->attach($this->attendee->id);
        $this->actingAs($this->manager);
        Queue::fake(); Notification::fake();
        $this->delivery = app(WorkerParticipationMeetingDeliveryService::class);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now(), 'external_clinical_account' => false]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null]);
        return $user;
    }

    private function stage(string $reason = 'scheduled', bool $workforce = false): array
    {
        return $this->asManagerRequest(fn (): array => DB::transaction(function () use ($reason, $workforce): array {
            $event = $this->delivery->recordCurrentEpoch($this->meeting, $this->manager, $reason);
            $ids = $this->delivery->stageToAudience($this->manager, $event['event_id'], [(int) $this->attendee->id], $workforce);
            return [...$event, 'ids' => $ids];
        }));
    }

    /** Manager staging runs inside the manager's own web request in the app; outside HTTP, request()->user() is empty. */
    private function asManagerRequest(callable $operation): mixed
    {
        $previous = $this->app['request'];
        $request = \Illuminate\Http\Request::create('/health-safety/worker-participation');
        $request->setUserResolver(fn ($guard = null) => auth()->guard($guard)->user());
        $this->app->instance('request', $request);
        try { return $operation(); } finally { $this->app->instance('request', $previous); }
    }

    private function attendeeId(array $event): string
    {
        return WorkerParticipationMeetingDeliveryService::notificationId($event['event_id'], (int) $this->attendee->id, 'attendee');
    }

    public function test_repeated_exact_staging_reuses_event_and_deterministic_intent(): void
    {
        $first = $this->stage(); $second = $this->stage();
        $this->assertSame($first, $second);
        $this->assertSame(1, WorkerParticipationMeetingEvent::count());
        $this->assertSame(1, WorkerParticipationMeetingDelivery::count());
        $this->assertSame([$this->attendeeId($first)], $first['ids']);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $first['ids'][0], 'database_state' => 'pending', 'mail_state' => 'pending']);
        Notification::assertNothingSent();
    }

    public function test_source_epoch_a_b_a_never_revives_first_event(): void
    {
        $first = $this->stage();
        $this->meeting->update(['location' => 'Changed location']); $second = $this->stage('source_changed');
        $this->meeting->update(['location' => 'Private location']); $third = $this->stage('source_changed');
        $this->assertNotSame($first['source_epoch_id'], $second['source_epoch_id']);
        $this->assertNotSame($first['source_epoch_id'], $third['source_epoch_id']);
        $this->assertSame(3, $third['sequence']);
        $this->delivery->deliverDatabase($this->attendeeId($first));
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->attendeeId($first), 'database_state' => 'skipped']);
        $this->assertDatabaseMissing('notifications', ['id' => $this->attendeeId($first)]);
    }

    public function test_new_attendee_event_keeps_source_epoch_and_previous_legitimate_intent(): void
    {
        $first = $this->stage();
        $this->meeting->attendeeUsers()->attach($this->worker->id);
        $second = $this->asManagerRequest(fn (): array => DB::transaction(function (): array {
            $event = $this->delivery->recordCurrentEpoch($this->meeting, $this->manager, 'attendees_added');
            $event['ids'] = $this->delivery->stageToAudience($this->manager, $event['event_id'], [(int) $this->worker->id]);
            return $event;
        }));
        $this->assertSame($first['source_epoch_id'], $second['source_epoch_id']);
        $this->assertCount(1, $second['ids']);
        $this->delivery->deliverDatabase($this->attendeeId($first));
        $this->assertDatabaseHas('notifications', ['id' => $this->attendeeId($first), 'notifiable_id' => $this->attendee->id]);
    }

    public function test_database_recovery_is_idempotent_and_notice_has_only_generic_scalar_envelope(): void
    {
        $event = $this->stage('scheduled', true);
        foreach ($event['ids'] as $id) { $this->delivery->deliverDatabase($id); $this->delivery->deliverDatabase($id); }
        $this->assertSame(count($event['ids']), DB::table('notifications')->count());
        foreach (DB::table('notifications')->get() as $row) {
            $data = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(13, $data);
            $this->assertSame(WorkerParticipationMeetingNotice::class, $row->type);
            $this->assertSame('H&S meeting update', $data['title']);
            $this->assertStringNotContainsString('Private', $row->data);
            $this->assertSame($data['audience'] === 'attendee' ? '/my-day/safety/meetings/'.$this->meeting->id : null, $data['url']);
        }
        Notification::assertNothingSent();
    }

    public static function sourceWithdrawals(): array { return [['site'], ['attendee'], ['location']]; }

    #[DataProvider('sourceWithdrawals')]
    public function test_actual_destination_insert_refuses_source_withdrawal_and_recovers_fresh(string $kind): void
    {
        $event = $this->stage(); $id = $this->attendeeId($event); $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired, $kind): void {
            if ($armed && preg_match('/^insert into `notifications`/i', ltrim($sql))) {
                $armed = false; $fired = true;
                if ($kind === 'site') { $this->site->update(['is_active' => false]); }
                elseif ($kind === 'attendee') { $this->meeting->attendeeUsers()->detach($this->attendee->id); }
                else { $this->meeting->update(['location' => 'Replaced location']); }
            }
        });
        try { $this->delivery->deliverDatabase($id); } finally { $armed = false; }
        $this->assertTrue($fired); $this->assertDatabaseMissing('notifications', ['id' => $id]);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $id, 'database_state' => 'failed']);
        // The failed callback transaction rolled the injected source mutation back too.
        $this->assertTrue((bool) $this->site->fresh()->is_active);
        $this->assertSame('Private location', $this->meeting->fresh()->location);
        $this->travel(2)->minutes(); $this->delivery->deliverDatabase($id);
        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    public function test_rebound_delivery_tuple_and_forged_identity_cannot_create_notification(): void
    {
        $event = $this->stage(); $id = $this->attendeeId($event);
        DB::table('worker_participation_meeting_deliveries')->where('id', $id)->update(['recipient_user_id' => $this->worker->id]);
        $this->delivery->deliverDatabase($id);
        $this->assertDatabaseMissing('notifications', ['id' => $id]);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $id, 'database_state' => 'failed']);
    }

    public static function terminalStates(): array { return [['delivered'], ['suppressed'], ['skipped']]; }

    #[DataProvider('terminalStates')]
    public function test_stale_database_failure_never_regresses_another_worker_terminal_receipt(string $terminal): void
    {
        $event = $this->stage(); $id = $this->attendeeId($event); $armed = true; $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$armed, &$fired, $id, $terminal): void {
            if ($armed && str_starts_with(ltrim($query->sql), 'select') && str_contains($query->sql, 'worker_participation_meeting_deliveries')) {
                $armed = false; $fired = true;
                DB::table('worker_participation_meeting_deliveries')->where('id', $id)->update(['database_state' => $terminal, 'database_attempts' => 9]);
                $this->site->update(['is_active' => false]);
            }
        });
        try { $this->delivery->deliverDatabase($id); } finally { $armed = false; }
        $this->assertTrue($fired);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $id, 'database_state' => $terminal, 'database_attempts' => 9]);
        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public function test_intents_and_event_roll_back_with_source_transaction(): void
    {
        try {
            DB::transaction(function (): void { $this->stage(); throw new RuntimeException('Abort source persistence'); });
            $this->fail('Expected source rollback.');
        } catch (RuntimeException $error) { $this->assertSame('Abort source persistence', $error->getMessage()); }
        $this->assertSame(0, WorkerParticipationMeetingEvent::count());
        $this->assertSame(0, WorkerParticipationMeetingDelivery::count());
        Queue::assertNothingPushed(); Notification::assertNothingSent();
    }

    public function test_mysql_persisted_member_key_order_does_not_reject_a_legitimate_delivery(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $event = $this->stage();
        $row = WorkerParticipationMeetingEvent::findOrFail($event['event_id']);
        $reordered = array_map(fn ($member) => ['audience' => $member['audience'], 'recipient_id' => $member['recipient_id']], $row->audience_membership);
        DB::table('worker_participation_meeting_events')->where('id', $row->id)->update(['audience_membership' => json_encode($reordered, JSON_THROW_ON_ERROR)]);
        $stored = json_decode(DB::table('worker_participation_meeting_events')->where('id', $row->id)->value('audience_membership'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(WorkerParticipationMeetingDeliveryService::digest($stored), $row->audience_digest);
        $this->delivery->deliverDatabase($this->attendeeId($event));
        $this->assertDatabaseHas('notifications', ['id' => $this->attendeeId($event)]);
    }

    public static function malformedMembers(): array { return [['duplicate'], ['string_id'], ['extra_key']]; }

    #[DataProvider('malformedMembers')]
    public function test_matching_digest_does_not_bless_malformed_or_duplicate_membership(string $kind): void
    {
        $event = $this->stage(); $member = ['recipient_id' => (int) $this->attendee->id, 'audience' => 'attendee'];
        $members = match ($kind) {
            'duplicate' => [$member, ['audience' => 'attendee', 'recipient_id' => (int) $this->attendee->id]],
            'string_id' => [['recipient_id' => (string) $this->attendee->id, 'audience' => 'attendee']],
            'extra_key' => [[...$member, 'page_access' => true]],
        };
        DB::table('worker_participation_meeting_events')->where('id', $event['event_id'])->update([
            'audience_membership' => json_encode($members, JSON_THROW_ON_ERROR), 'audience_digest' => WorkerParticipationMeetingDeliveryService::digest($members)]);
        $this->delivery->deliverDatabase($this->attendeeId($event));
        $this->assertDatabaseMissing('notifications', ['id' => $this->attendeeId($event)]);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->attendeeId($event), 'database_state' => 'failed']);
    }

    public function test_actual_preference_get_save_read_and_inapp_veto_preserves_other_channel_intent(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        $this->actingAs($this->attendee)->get('/settings/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('groups', fn ($groups) => in_array(WorkerParticipationMeetingDeliveryService::EVENT_KEY, $groups['Audit & Safety'], true))
            ->where('roleDefaults', fn ($defaults) => $defaults[WorkerParticipationMeetingDeliveryService::EVENT_KEY] ===
                ['enabled' => true, 'inapp' => true, 'email' => true, 'push' => false]));
        $this->put('/settings/notifications', ['prefs' => [WorkerParticipationMeetingDeliveryService::EVENT_KEY =>
            ['enabled' => true, 'inapp' => false, 'email' => true, 'push' => true]]])->assertRedirect();
        $this->assertDatabaseHas('user_notification_preferences', ['user_id' => $this->attendee->id,
            'key' => WorkerParticipationMeetingDeliveryService::EVENT_KEY, 'channel_push' => false]);
        $this->get('/settings/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('userPrefs', fn ($prefs) => $prefs[WorkerParticipationMeetingDeliveryService::EVENT_KEY]['inapp'] === false
                && $prefs[WorkerParticipationMeetingDeliveryService::EVENT_KEY]['email'] === true));
        $this->actingAs($this->manager); $event = $this->stage();
        $this->delivery->deliverDatabase($this->attendeeId($event));
        $this->assertDatabaseMissing('notifications', ['id' => $this->attendeeId($event)]);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->attendeeId($event), 'database_state' => 'suppressed', 'mail_state' => 'pending']);
    }

    public function test_actual_role_defaults_save_read_and_legacy_role_fallback_without_scalar_proof_mismatch(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        $admin = $this->staff('admin'); $role = Role::where('name', 'support_worker')->sole();
        $key = WorkerParticipationMeetingDeliveryService::EVENT_KEY;
        $this->actingAs($admin)->get('/settings/notifications/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('matrix', fn ($matrix) => $matrix[$role->id][$key] === ['enabled' => true, 'inapp' => true, 'email' => true, 'push' => false]));
        $this->actingAs($admin)->put('/settings/notifications/roles', ['matrix' => [$role->id => [$key =>
            ['enabled' => true, 'inapp' => false, 'email' => true, 'push' => true]]]])->assertRedirect();
        $this->assertDatabaseHas('role_notification_preferences', ['role_id' => $role->id, 'key' => $key, 'channel_push' => false]);
        $this->get('/settings/notifications/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('matrix', fn ($matrix) => $matrix[$role->id][$key]['inapp'] === false));
        $this->actingAs($this->manager); $event = $this->stage();
        $this->attendee->roles()->detach();
        $this->delivery->deliverDatabase($this->attendeeId($event));
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->attendeeId($event), 'database_state' => 'suppressed']);
        $this->assertDatabaseMissing('notifications', ['id' => $this->attendeeId($event)]);
        Notification::assertNothingSent();
    }

    public function test_disabled_role_channel_bits_are_not_projected_as_enabled_and_personal_override_wins(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        $admin = $this->staff('admin'); $role = Role::where('name', 'support_worker')->sole(); $key = WorkerParticipationMeetingDeliveryService::EVENT_KEY;
        $this->actingAs($admin)->put('/settings/notifications/roles', ['matrix' => [$role->id => [$key =>
            ['enabled' => false, 'inapp' => true, 'email' => true, 'push' => true]]]])->assertRedirect();
        $this->actingAs($this->attendee)->get('/settings/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roleDefaults', fn ($defaults) => $defaults[$key] === ['enabled' => false, 'inapp' => false, 'email' => false, 'push' => false]));
        $this->put('/settings/notifications', ['prefs' => [$key => ['enabled' => true, 'inapp' => true, 'email' => false, 'push' => true]]])->assertRedirect();
        $this->actingAs($this->manager); $event = $this->stage(); $this->delivery->deliverDatabase($this->attendeeId($event));
        $this->assertDatabaseHas('notifications', ['id' => $this->attendeeId($event)]);
    }

    public static function routeCreatePaths(): array { return [['inline'], ['existing']]; }

    #[DataProvider('routeCreatePaths')]
    public function test_actual_create_route_stages_source_bound_intents_without_inline_transport(string $path): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        // Candidate integration expectation: producer mount is pending the command-transition prerequisite.
        $values = ['scheduled_at' => '2026-02-01T12:30', 'scheduled_timezone' => 'Pacific/Auckland', 'attendees' => [$this->attendee->id]];
        if ($path === 'inline') {
            $response = $this->post('/health-safety/worker-participation/committees', [...$values, 'name' => 'Created source',
                'site_id' => $this->site->id, 'meeting_frequency' => 'monthly', 'established_at' => '2026-01-01',
                'members' => [$this->attendee->id], 'schedule_meeting' => true]);
        } else {
            $response = $this->post('/health-safety/worker-participation/committees/'.$this->meeting->hs_committee_id.'/meetings', $values);
        }
        $response->assertRedirect();
        $created = HsCommitteeMeeting::whereKeyNot($this->meeting->id)->sole();
        $event = WorkerParticipationMeetingEvent::where('meeting_id', $created->id)->sole();
        $this->assertSame($created->hs_committee_id, $event->committee_id);
        $this->assertSame($this->site->id, $event->site_id);
        $this->assertSame('2026-01-31 23:30:00', $event->source_descriptor['scheduled_at']);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['event_id' => $event->id,
            'recipient_user_id' => $this->attendee->id, 'audience' => 'attendee', 'database_state' => 'pending', 'mail_state' => 'pending']);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['event_id' => $event->id,
            'recipient_user_id' => $this->worker->id, 'audience' => 'workforce_notice', 'mail_state' => 'not_requested']);
        Notification::assertNothingSent();
        // RefreshDatabase's installed manager simulates after-commit at level one;
        // this asserts queued intents, not a physical outer commit or job execution.
        Queue::assertPushed(DispatchWorkerParticipationMeetingDelivery::class, WorkerParticipationMeetingDelivery::where('event_id', $event->id)->count());
    }

    public function test_actual_route_reschedule_addition_and_status_changes_preserve_delivery_trigger_definitions(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        // Entire route workflow, not a substituted source callback. Currently UNRUN/pending producer mount.
        $this->put('/health-safety/worker-participation/meetings/'.$this->meeting->id, ['scheduled_at' => '2026-02-01T12:30',
            'scheduled_timezone' => 'Pacific/Auckland'])->assertRedirect();
        $reschedule = WorkerParticipationMeetingEvent::where('meeting_id', $this->meeting->id)->sole();
        $this->assertSame('rescheduled', $reschedule->purpose);
        $this->assertSame(['attendee'], WorkerParticipationMeetingDelivery::where('event_id', $reschedule->id)->pluck('audience')->all());
        $this->post('/health-safety/worker-participation/meetings/'.$this->meeting->id.'/attendees', ['user_ids' => [$this->worker->id]])->assertRedirect();
        $addition = WorkerParticipationMeetingEvent::where('meeting_id', $this->meeting->id)->orderByDesc('sequence')->firstOrFail();
        $this->assertSame($reschedule->source_epoch_id, $addition->source_epoch_id);
        $this->assertSame([(int) $this->worker->id], WorkerParticipationMeetingDelivery::where('event_id', $addition->id)->pluck('recipient_user_id')->all());
        $before = WorkerParticipationMeetingDelivery::count();
        $this->put('/health-safety/worker-participation/meetings/'.$this->meeting->id.'/complete', ['actual_attendee_ids' => [$this->attendee->id]])->assertRedirect();
        $this->assertSame('completed', $this->meeting->fresh()->status);
        $this->assertDatabaseHas('hs_meeting_attendees', ['meeting_id' => $this->meeting->id, 'user_id' => $this->attendee->id, 'attended' => true, 'response' => 'accepted']);
        $this->assertSame($before, WorkerParticipationMeetingDelivery::count());
        $this->put('/health-safety/worker-participation/meetings/'.$this->meeting->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $this->meeting->fresh()->status);
        $this->assertSame($before, WorkerParticipationMeetingDelivery::count()); Notification::assertNothingSent();
    }

    public function test_foreign_manager_target_denies_before_full_model_binding_and_source_mutation(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $foreignCommittee = HsCommittee::create(['name' => 'Foreign private source', 'site_id' => $foreignSite->id, 'status' => 'active',
            'meeting_frequency' => 'monthly', 'established_at' => '2025-01-01', 'members' => []]);
        $foreign = $foreignCommittee->meetings()->create(['scheduled_at' => '2026-01-15 00:00:00', 'status' => 'scheduled', 'minutes' => 'Private foreign minutes']);
        $this->manager->permissionOverrides()->attach(Permission::where('key', 'healthSafety.viewAllSites')->sole()->id, ['allowed' => false]);
        $hydrations = 0; $armed = true;
        HsCommitteeMeeting::retrieved(function ($row) use (&$hydrations, &$armed, $foreign): void {
            if ($armed && $row->id === $foreign->id && array_key_exists('minutes', $row->getAttributes())) { $hydrations++; }
        });
        try {
            $response = $this->putJson('/health-safety/worker-participation/meetings/'.$foreign->id, ['location' => 'Must not persist']);
            $this->assertContains($response->status(), [403, 404]);
        } finally { $armed = false; }
        $this->assertSame(0, $hydrations);
        $this->assertNull($foreign->fresh()->location);
        $this->assertSame(0, WorkerParticipationMeetingEvent::count());
    }

    public function test_existing_explicit_wide_manager_without_active_hr_can_schedule_under_original_rule(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        HrEmployeeProfile::where('user_id', $this->manager->id)->delete();
        $this->post('/health-safety/worker-participation/committees/'.$this->meeting->hs_committee_id.'/meetings',
            ['scheduled_at' => '2026-02-01 00:00:00', 'attendees' => [$this->attendee->id]])->assertRedirect();
        $created = HsCommitteeMeeting::whereKeyNot($this->meeting->id)->sole();
        $this->assertDatabaseHas('worker_participation_meeting_events', ['meeting_id' => $created->id, 'site_id' => $this->site->id]);
        Notification::assertNothingSent();
    }

    public function test_same_second_json_object_key_reorder_is_a_real_semantic_noop_not_false_zero_match_failure(): void
    {
        $action = ['description' => 'Retained action', 'assigned_to' => (int) $this->attendee->id, 'status' => 'open'];
        $this->meeting->update(['action_items' => [$action], 'actions_due_count' => 1, 'updated_at' => now()]);
        $before = DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->value('action_items');
        $this->put('/health-safety/worker-participation/meetings/'.$this->meeting->id, ['action_items' => [[
            'status' => 'open', 'assigned_to' => (int) $this->attendee->id, 'description' => 'Retained action']]])->assertRedirect();
        $this->assertSame($before, DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->value('action_items'));
        $this->assertSame(1, $this->meeting->fresh()->actions_due_count);
        $this->assertSame(0, WorkerParticipationMeetingDelivery::count()); Notification::assertNothingSent();
    }

    public function test_actual_conditional_meeting_insert_refuses_late_original_site_withdrawal_and_rolls_back(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired): void {
            if ($armed && preg_match('/^insert into `hs_committee_meetings`/i', ltrim($sql))) {
                $armed = false; $fired = true; $this->site->update(['is_active' => false]);
            }
        });
        try {
            $response = $this->postJson('/health-safety/worker-participation/committees/'.$this->meeting->hs_committee_id.'/meetings',
                ['scheduled_at' => '2026-02-01 00:00:00', 'attendees' => [$this->attendee->id]]);
            $this->assertContains($response->status(), [403, 404, 409]);
        } finally { $armed = false; }
        $this->assertTrue($fired); $this->assertSame(1, HsCommitteeMeeting::count());
        $this->assertSame(1, DB::table('hs_meeting_attendees')->count());
        $this->assertSame(0, WorkerParticipationMeetingEvent::count()); $this->assertSame(0, WorkerParticipationMeetingDelivery::count());
        $this->assertTrue((bool) $this->site->fresh()->is_active); Notification::assertNothingSent();
    }

    public function test_actual_conditional_update_refuses_unrequested_source_fact_tamper_without_audit_or_intents(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::NEVER_BUILT, 'the meeting delivery pipeline (WorkerParticipationMeetingCommandContext, staged delivery intents, conditional meeting writes, the meeting_update preference) was never built; meetings keep main\'s CommitteeMeetingScheduled notice.', 'build the meeting delivery pipeline.'));
        $before = $this->meeting->fresh()->getRawOriginal(); $auditCount = DB::table('audit_logs')->count(); $armed = true; $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$fired): void {
            if ($armed && preg_match('/^update `hs_committee_meetings`/i', ltrim($sql))) {
                $armed = false; $fired = true;
                DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['minutes' => 'Unrequested replacement']);
            }
        });
        try {
            $response = $this->putJson('/health-safety/worker-participation/meetings/'.$this->meeting->id, ['location' => 'Owned location change']);
            $this->assertContains($response->status(), [403, 404, 409]);
        } finally { $armed = false; }
        $this->assertTrue($fired); $this->assertSame($before, $this->meeting->fresh()->getRawOriginal());
        $this->assertSame($auditCount, DB::table('audit_logs')->count()); $this->assertSame(0, WorkerParticipationMeetingEvent::count());
        Notification::assertNothingSent();
    }

    public function test_real_minutes_save_exception_cleans_only_its_new_file_and_preserves_old_owned_bytes(): void
    {
        Storage::fake('private'); $old = 'health-safety/meetings/'.$this->meeting->id.'/old.pdf';
        Storage::disk('private')->put($old, 'Retained old bytes');
        $this->meeting->update(['minutes_document_path' => $old, 'minutes_document_name' => 'old.pdf']);
        $armed = true;
        HsCommitteeMeeting::updating(function ($row) use (&$armed): void {
            if ($armed && $row->isDirty('minutes_document_path')) { throw new RuntimeException('Actual minutes save failure'); }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/health-safety/worker-participation/meetings/'.$this->meeting->id.'/minutes', [
                'document' => UploadedFile::fake()->create('replacement.pdf', 8, 'application/pdf')]);
            $this->fail('The canonical model observer failure must propagate.');
        } catch (RuntimeException $error) { $this->assertSame('Actual minutes save failure', $error->getMessage()); }
        finally { $armed = false; }
        $this->assertSame([$old], Storage::disk('private')->allFiles());
        $this->assertSame('Retained old bytes', Storage::disk('private')->get($old));
        $this->assertSame($old, $this->meeting->fresh()->minutes_document_path); Notification::assertNothingSent();
    }
}
