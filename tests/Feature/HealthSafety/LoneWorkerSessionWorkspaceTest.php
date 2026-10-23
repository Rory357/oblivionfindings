<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\LoneWorkerCheckIn;
use App\Models\LoneWorkerEmergencyEpisode;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\TaskAggregator;
use App\Support\HealthSafety\LoneWorkerSessionPresenter;
use App\Support\HealthSafety\LoneWorkerSessionReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LoneWorkerSessionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->owner = $this->worker();
        $this->manager = $this->worker(true);
        $tasks = Mockery::mock(TaskAggregator::class);
        $tasks->shouldReceive('navigationBadgeFor')->andReturn(['view' => true, 'badge' => 0, 'degraded' => false]);
        $tasks->shouldReceive('findItemFor')->andReturnNull();
        $this->app->instance(TaskAggregator::class, $tasks);
    }

    public function test_permanent_html_json_and_scoped_legacy_navigation_preserve_bounded_origin(): void
    {
        $record = $this->sessionSource();
        $origin = '/health-safety/lone-workers?tab=sessions&site_id='.$this->site->id.'&status=active&period=all';
        $href = LoneWorkerSessionReturn::href($record->id, $origin, 'check-ins');
        $this->actingAs($this->manager)->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/lone-workers/show')->where('detail.id', $record->id)->where('return_to', $origin)->where('section', 'check-ins'));
        $this->getJson('/health-safety/lone-workers/sessions/'.$record->id)->assertOk()->assertJsonPath('detail.id', $record->id);
        $this->get($origin.'&session='.$record->id)->assertRedirect(LoneWorkerSessionReturn::href($record->id, $origin));
        $this->get('/health-safety/lone-workers?session[]=1')->assertNotFound();
        $this->get('/health-safety/lone-workers?session=1x')->assertNotFound();
    }

    public function test_owner_can_read_without_module_grant_but_unrelated_worker_cannot(): void
    {
        $record = $this->sessionSource();
        $this->actingAs($this->owner)->get('/health-safety/lone-workers/sessions/'.$record->id)->assertOk();
        $detail = $this->detail($record, $this->owner);
        $this->assertTrue($detail['can']['check_in']);
        $this->assertFalse($detail['can']['manage']);
        $this->assertFalse($detail['can']['delete']);
        $this->actingAs($this->worker())->get('/health-safety/lone-workers/sessions/'.$record->id)->assertForbidden();
        $this->assertSame([], $detail['connections']['control_room']);
    }

    public function test_source_remains_readable_but_client_identity_requires_current_independent_exact_read_authority(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'PrivateFirstHS15', 'last_name' => 'PrivateSurnameHS15']);
        $record = $this->sessionSource(['client_id' => $client->id]);
        foreach ([$this->manager, $this->owner] as $viewer) {
            foreach (['clients.viewAny', 'clients.viewAssigned'] as $key) {
                $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => false]]);
            }
        }
        $presenter = app(LoneWorkerSessionPresenter::class);
        $projections = [$this->detail($record), $presenter->row($record, $this->manager), $presenter->selfSummary($record, $this->owner)];
        foreach ($projections as $projection) {
            $this->assertSame($record->id, $projection['id']);
            $this->assertNull($projection['client']);
            $this->assertNull($projection['client_id']);
            $this->assertStringNotContainsString('PrivateSurnameHS15', json_encode($projection));
        }
        $this->assertNull($projections[1]['client_name']);
        $this->assertTrue($projections[2]['can']['check_in']);
        $this->assertSame($client->id, $record->fresh()->client_id);
        $this->actingAs($this->owner)->getJson('/health-safety/lone-workers/sessions/'.$record->id)->assertOk()
            ->assertJsonPath('detail.client', null)->assertJsonPath('detail.client_id', null)->assertJsonPath('detail.can.check_in', true);
        $client->supportWorkers()->syncWithoutDetaching([$this->manager->id, $this->owner->id]);
        foreach ([$this->manager, $this->owner] as $viewer) {
            $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'clients.viewAssigned')->firstOrFail()->id => ['allowed' => true]]);
        }
        $this->assertSame($client->id, $this->detail($record)['client']['id']);
        $this->assertSame('PrivateFirstHS15 PrivateSurnameHS15', $presenter->row($record, $this->manager)['client_name']);
        $this->assertSame($client->id, $presenter->selfSummary($record, $this->owner)['client_id']);
        $client->supportWorkers()->detach($this->manager->id);
        $this->assertNull($this->detail($record)['client']);
        $this->assertNull($presenter->row($record, $this->manager)['client_name']);
    }

    public function test_unbacked_last_checkin_is_only_scheduling_baseline_never_actual_received_observation(): void
    {
        $record = $this->sessionSource(['last_check_in_at' => now()]);
        $detail = $this->detail($record);
        $this->assertNull($detail['last_check_in_at']);
        $this->assertSame('no_check_in', $detail['observation']['state']);
        $this->assertNull($detail['observation']['received_at']);
        $this->assertSame([], $detail['check_ins']);
        $this->assertNotNull($detail['next_check_in_at']);
        $this->assertSame('unknown', $detail['observation']['monitor']['state']);
        $this->assertNull($detail['source_delivery']);
        $this->assertArrayNotHasKey('safe', $detail);
    }

    public function test_checkin_occurrence_receipt_and_operator_are_distinct_and_coordinates_are_independently_restricted(): void
    {
        $record = $this->sessionSource(['location' => 'Recorded meeting point', 'location_lat' => -36.85, 'location_lng' => 174.75]);
        $check = LoneWorkerCheckIn::create(['lone_worker_session_id' => $record->id, 'checked_in_at' => now()->subMinutes(5),
            'received_at' => now()->subMinutes(2), 'recorded_by_user_id' => $this->manager->id, 'status' => 'concern', 'notes' => 'Worker requested follow-up', 'location_lat' => -36.8, 'location_lng' => 174.7]);
        $detail = $this->detail($record, $this->owner);
        $this->assertSame($check->checked_in_at->toIso8601String(), $detail['check_ins'][0]['checked_in_at']);
        $this->assertSame($check->received_at->toIso8601String(), $detail['check_ins'][0]['received_at']);
        $this->assertSame($this->manager->id, $detail['check_ins'][0]['recorded_by']['id']);
        $this->assertSame('concern', $detail['check_ins'][0]['status']);
        $this->assertNull($detail['check_ins'][0]['location_lat']);
        $this->assertNull($detail['location_lat']);
        $this->assertSame('restricted', $detail['tracker_state']);
        $this->assertNull($detail['tracker']);
        $this->manager->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'assets.telemetry.view')->firstOrFail()->id => ['allowed' => true]]);
        $permitted = $this->detail($record);
        $this->assertNull($permitted['location_lat']);
        $this->assertNull($permitted['location_lng']);
        $this->assertSame('unverified', $permitted['location_state']);
        $this->assertSame('unverified_source_coordinates', $permitted['location_provenance']);
        $this->assertSame('Recorded meeting point', $permitted['location']);
        $this->assertEqualsWithDelta(-36.8, (float) $permitted['check_ins'][0]['location_lat'], 0.00001);
        $this->assertSame($check->received_at->toIso8601String(), $permitted['check_ins'][0]['received_at']);
        $this->assertEqualsWithDelta(-36.85, (float) $record->fresh()->location_lat, 0.00001);
    }

    public function test_attributed_planned_place_is_readable_without_claiming_legacy_coordinates_or_current_observation(): void
    {
        $record = $this->sessionSource(['location' => 'Independent location description', 'location_lat' => -36.8, 'location_lng' => 174.7]);
        $this->assertNull($this->detail($record, $this->owner)['planned_location']);
        $plan = ['kind' => 'planned', 'method' => 'manual', 'description' => 'Intended meeting point', 'latitude' => 0, 'longitude' => 0,
            'recorded_at' => now()->toIso8601String(), 'recorded_by_user_id' => $this->manager->id, 'source_site_id' => null, 'source_shift_id' => null,
            'private_path' => 'private/planned-secret'];
        $record->forceFill(['planned_location' => $plan])->saveQuietly();
        $detail = $this->detail($record, $this->owner);
        $this->assertSame('planned', $detail['planned_location']['kind']);
        $this->assertSame('manual', $detail['planned_location']['method']);
        $this->assertSame(0.0, $detail['planned_location']['lat']);
        $this->assertSame(0.0, $detail['planned_location']['lng']);
        $this->assertSame($plan['recorded_at'], $detail['planned_location']['recorded_at']);
        $this->assertSame($this->manager->id, $detail['planned_location']['recorded_by']['id']);
        $this->assertSame('Independent location description', $detail['location']);
        $this->assertNull($detail['location_lat']);
        $this->assertNull($detail['location_lng']);
        $this->assertNull($detail['tracker']);
        $this->assertStringNotContainsString('private/planned-secret', json_encode($detail));
        $this->assertEqualsWithDelta(-36.8, (float) $record->fresh()->location_lat, 0.00001);
        $record->forceFill(['planned_location' => [...$plan, 'source_site_id' => Site::factory()->create()->id]])->saveQuietly();
        $this->assertNull($this->detail($record, $this->owner)['planned_location']);
        $record->forceFill(['planned_location' => [...$plan, 'recorded_at' => null]])->saveQuietly();
        $this->assertNull($this->detail($record, $this->owner)['planned_location']);
    }

    public function test_history_preserves_the_recorded_plan_and_explicit_clear_without_mutable_substitution(): void
    {
        $record = $this->sessionSource();
        $past = ['kind' => 'planned', 'method' => 'site_address', 'description' => 'Previously chosen premises', 'latitude' => -36.8, 'longitude' => 174.7,
            'recorded_at' => now()->subHour()->toIso8601String(), 'recorded_by_user_id' => $this->manager->id, 'source_site_id' => $this->site->id,
            'source_shift_id' => null, 'transport' => 'private planned transport'];
        AuditLog::create(['user_id' => $this->manager->id, 'action' => 'healthSafety.loneWorker.session.update', 'auditable_type' => LoneWorkerSession::class,
            'auditable_id' => $record->id, 'meta' => ['snapshot' => ['planned_location' => $past]]]);
        AuditLog::create(['user_id' => $this->owner->id, 'action' => 'healthSafety.loneWorker.session.update', 'auditable_type' => LoneWorkerSession::class,
            'auditable_id' => $record->id, 'meta' => ['snapshot' => ['planned_location' => null]]]);
        $record->forceFill(['planned_location' => [...$past, 'description' => 'Later working plan']])->saveQuietly();
        $detail = $this->detail($record, $this->owner);
        $history = collect($detail['history'])->where('action', 'healthSafety.loneWorker.session.update')->values();
        $this->assertSame('Previously chosen premises', $history[0]['snapshot']['planned_location']['description']);
        $this->assertSame($past['recorded_at'], $history[0]['snapshot']['planned_location']['recorded_at']);
        $this->assertSame($this->manager->id, $history[0]['snapshot']['planned_location']['recorded_by']['id']);
        $this->assertSame($this->site->id, $history[0]['snapshot']['planned_location']['source_site_id']);
        $this->assertArrayHasKey('planned_location', $history[1]['snapshot']);
        $this->assertNull($history[1]['snapshot']['planned_location']);
        $this->assertSame('Later working plan', $detail['planned_location']['description']);
        $this->assertStringNotContainsString('private planned transport', json_encode($detail));
        $this->assertArrayNotHasKey('latitude', $history[0]['snapshot']['planned_location']);
    }

    public function test_legacy_checkin_retains_receipt_provenance_and_unknown_actor_without_inferring_worker(): void
    {
        $record = $this->sessionSource();
        LoneWorkerCheckIn::create(['lone_worker_session_id' => $record->id, 'checked_in_at' => now()->subMinute(), 'status' => 'ok']);
        $row = $this->detail($record)['check_ins'][0];
        $this->assertNull($row['recorded_by']);
        $this->assertSame('legacy_created_at', $row['receipt_source']);
        $this->assertNotNull($row['received_at']);
    }

    public function test_history_uses_immutable_allowlisted_snapshot_and_labels_legacy_incomplete(): void
    {
        $record = $this->sessionSource(['activity_description' => 'Later changed activity']);
        AuditLog::create(['user_id' => $this->manager->id, 'action' => 'healthSafety.loneWorker.session.update', 'auditable_type' => LoneWorkerSession::class, 'auditable_id' => $record->id,
            'meta' => ['snapshot' => ['status' => 'active', 'activity_description' => 'Earlier exact activity', 'location_lat' => -36.8, 'private_path' => 'private/secret'], 'note' => 'Recorded update', 'transport' => 'secret transport', 'idempotency_key' => 'private-key']]);
        AuditLog::create(['user_id' => null, 'action' => 'healthSafety.loneWorker.session.start', 'auditable_type' => LoneWorkerSession::class, 'auditable_id' => $record->id, 'meta' => ['old_private_note' => 'Do not claim complete legacy history']]);
        AuditLog::create(['user_id' => null, 'action' => 'legacy.session.imported', 'auditable_type' => LoneWorkerSession::class, 'auditable_id' => $record->id, 'meta' => []]);
        $history = collect($this->detail($record)['history']);
        $this->assertSame('Legacy session activity - details incomplete', $history->firstWhere('action', 'legacy.session.imported')['title']);
        $update = $history->firstWhere('action', 'healthSafety.loneWorker.session.update');
        $this->assertSame('Earlier exact activity', $update['snapshot']['activity_description']);
        $this->assertSame($this->manager->id, $update['actor']['id']);
        $this->assertFalse($update['legacy']);
        $legacy = $history->firstWhere('action', 'healthSafety.loneWorker.session.start');
        $this->assertTrue($legacy['legacy']);
        $this->assertSame([], $legacy['snapshot']);
        $this->assertNull($legacy['actor']);
        $this->assertStringNotContainsString('private/secret', json_encode($history));
        $this->assertStringNotContainsString('secret transport', json_encode($history));
        $this->assertStringNotContainsString('private-key', json_encode($history));
    }

    public function test_current_episode_delivery_is_neutral_and_never_reveals_private_pipeline_ids_or_errors(): void
    {
        $record = $this->sessionSource(['status' => 'emergency', 'emergency_episode_number' => 2]);
        LoneWorkerEmergencyEpisode::create(['lone_worker_session_id' => $record->id, 'site_id' => $record->site_id, 'episode_number' => 1, 'initiated_by_user_id' => $this->owner->id,
            'occurred_at' => now()->subHour(), 'status' => 'delivered', 'signal_key' => 'earlier-private-key', 'attempts' => 1, 'delivered_at' => now()->subHour()]);
        $episode = LoneWorkerEmergencyEpisode::create(['lone_worker_session_id' => $record->id, 'site_id' => $record->site_id, 'episode_number' => 2, 'initiated_by_user_id' => $this->owner->id,
            'occurred_at' => now(), 'status' => 'failed', 'signal_key' => 'current-private-key', 'attempts' => 2, 'failure_code' => 'queue_unavailable', 'next_attempt_at' => now()->addMinute()]);
        $detail = $this->detail($record);
        $this->assertSame(2, $detail['source_delivery']['episode_number']);
        $this->assertSame('failed', $detail['source_delivery']['status']);
        $this->assertNull($detail['source_delivery']['delivered_at']);
        $this->assertSame('queue_unavailable', $detail['source_delivery']['failure_code']);
        $this->assertFalse($detail['can']['panic']);
        $this->assertStringNotContainsString('current-private-key', json_encode($detail));
        $this->assertArrayNotHasKey('control_room_alert_id', $detail['source_delivery']);
        $episode->update(['failure_code' => 'Raw private provider exception']);
        $this->assertNull($this->detail($record)['source_delivery']['failure_code']);
    }

    public function test_legacy_alert_history_cannot_be_presented_as_current_canonical_response(): void
    {
        $record = $this->sessionSource();
        $record->alerts()->create(['alert_type' => 'emergency', 'triggered_at' => now()->subHour(), 'status' => 'active']);
        $detail = $this->detail($record);
        $this->assertSame('historical', $detail['legacy_alert_history'][0]['status']);
        $this->assertSame('Historical record - read only', $detail['legacy_alert_history'][0]['label']);
        $this->assertSame('active', $detail['legacy_alert_history'][0]['recorded_status']);
        $this->assertSame([], $detail['alerts']);
        $this->assertSame([], $detail['connections']['control_room']);
    }

    public function test_stale_record_and_viewer_cannot_bypass_current_source_site_or_hr_revocation(): void
    {
        $record = $this->sessionSource();
        $this->detail($record);
        HrEmployeeProfile::where('user_id', $this->owner->id)->update(['is_active' => false]);
        try {
            $this->detail($record);
            $this->fail('Expected current worker eligibility denial.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->actingAs($this->owner)->get('/health-safety/lone-workers/sessions/'.$record->id)->assertForbidden();
    }

    public function test_completed_history_remains_readable_but_never_offers_worker_checkin_or_help_commands(): void
    {
        $record = $this->sessionSource(['status' => 'completed', 'ended_at' => now()]);
        $can = $this->detail($record)['can'];
        $this->assertFalse($can['check_in']);
        $this->assertFalse($can['panic']);
        $this->assertFalse($can['end']);
        $this->assertTrue($can['delete']);
    }

    public function test_self_start_pickers_only_disclose_owned_eligible_shift_scalar_fields(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $own = Shift::factory()->create(['user_id' => $this->owner->id, 'site_id' => $this->site->id, 'client_id' => $client->id,
            'status' => 'in_progress', 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'location' => 'Recorded Shift address', 'notes' => 'Private shift notes']);
        Shift::factory()->create(['user_id' => $this->manager->id, 'site_id' => $this->site->id, 'client_id' => $client->id,
            'status' => 'in_progress', 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null]);
        $pickers = app(LoneWorkerSessionPresenter::class)->pickers($this->owner);
        $this->assertSame([$this->owner->id], array_column($pickers['workers'], 'id'));
        $this->assertSame([$own->id], array_column($pickers['shifts'], 'id'));
        $this->assertSame($this->owner->id, $pickers['shifts'][0]['user_id']);
        $this->assertSame('Recorded Shift address', $pickers['shifts'][0]['address']);
        $this->assertNull($pickers['shifts'][0]['latitude']);
        $this->assertNull($pickers['shifts'][0]['longitude']);
        $this->assertFalse($pickers['can_geocode']);
        $this->assertStringNotContainsString('Private shift notes', json_encode($pickers));
        $this->assertArrayNotHasKey('created_by', $pickers['shifts'][0]);
    }

    public function test_response_history_does_not_copy_denied_canonical_alert_note_or_identifier(): void
    {
        $record = $this->sessionSource();
        AuditLog::create(['user_id' => $this->manager->id, 'action' => 'healthSafety.loneWorker.session.responseResolved', 'auditable_type' => LoneWorkerSession::class, 'auditable_id' => $record->id,
            'meta' => ['snapshot' => ['status' => 'active'], 'alert_id' => 999999, 'note' => 'Private response notes']]);
        $history = $this->detail($record, $this->owner)['history'];
        $this->assertFalse(collect($history)->contains('action', 'healthSafety.loneWorker.session.responseResolved'));
        $this->assertStringNotContainsString('Private response notes', json_encode($history));
        $this->assertStringNotContainsString('999999', json_encode($history));
    }

    private function worker(bool $manage = false): User
    {
        $user = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        foreach (['hazards.view' => $manage, 'hazards.manage' => $manage, 'healthSafety.viewAllSites' => false, 'assets.telemetry.view' => false, 'controlRoom.viewAny' => false, 'controlRoom.alerts.view' => false, 'controlRoom.alerts.manage' => false] as $key => $allowed) {
            $id = Permission::where('key', $key)->firstOrFail()->id;
            $user->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => $allowed]]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $user;
    }

    private function sessionSource(array $values = []): LoneWorkerSession
    {
        $record = LoneWorkerSession::create(['user_id' => $this->owner->id, 'site_id' => $this->site->id, 'started_at' => now()->subMinutes(10),
            'expected_end_at' => now()->addHours(2), 'check_in_interval_minutes' => 30, 'status' => 'active', 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, ...$values]);
        $record->forceFill(['lock_version' => 1, 'emergency_episode_number' => $values['emergency_episode_number'] ?? 0])->saveQuietly();

        return $record;
    }

    private function detail(LoneWorkerSession $record, ?User $viewer = null): array
    {
        return app(LoneWorkerSessionPresenter::class)->detail($record, $viewer ?? $this->manager, '/health-safety/lone-workers');
    }
}
