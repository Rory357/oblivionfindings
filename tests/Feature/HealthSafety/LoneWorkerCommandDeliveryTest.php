<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\CheckLoneWorkerOverdueJob;
use App\Jobs\DispatchLoneWorkerEmergency;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerCommandReceipt;
use App\Models\LoneWorkerEmergencyEpisode;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftGpsLog;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\HealthSafety\LoneWorkerEmergencyDeliveryService;
use App\Services\HealthSafety\LoneWorkerSessionAccessService;
use App\Services\HealthSafety\LoneWorkerSessionService;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\UserSiteAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LoneWorkerCommandDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $manager;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Queue::fake();
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->worker = $this->staff();
        $this->manager = $this->staff(['hazards.view', 'hazards.manage', 'controlRoom.alerts.view', 'controlRoom.alerts.manage']);
        $this->actingAs($this->manager);
    }

    private function staff(array $permissions = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null]);
        foreach ($permissions as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function service(): LoneWorkerSessionService
    {
        return app(LoneWorkerSessionService::class);
    }

    private function planning(array $extra = []): array
    {
        return ['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'expected_end_at' => now()->addHours(2)->toISOString(),
            'check_in_interval_minutes' => 30, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function source(): LoneWorkerSession
    {
        return $this->service()->create($this->manager, $this->planning());
    }

    private function guard(LoneWorkerSession $row, array $extra = []): array
    {
        return ['expected_version' => $row->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected '.$field.' validation.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    public function test_actual_http_start_confirmation_replays_once_without_fabricating_a_check_in(): void
    {
        $payload = $this->planning(['return_to' => '/health-safety/lone-workers', 'section' => 'overview']);
        $this->post('/health-safety/lone-workers/sessions', $payload)->assertRedirect();
        $row = LoneWorkerSession::sole();
        $this->post('/health-safety/lone-workers/sessions', [...$payload, 'section' => 'history'])->assertRedirect();
        $this->assertSame(1, LoneWorkerSession::count());
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
        $this->assertNull($row->last_check_in_at);
        $this->assertSame(0, $row->checkIns()->count());
        $this->assertSame(1, $row->lock_version);
        $this->post('/health-safety/lone-workers/sessions', [...$payload, 'location' => 'Changed payload'])->assertSessionHasErrors('idempotency_key');
    }

    public function test_check_in_http_retry_keeps_one_actual_actor_receipt_and_rejects_changed_payload(): void
    {
        $row = $this->source();
        $payload = $this->guard($row, ['status' => 'ok', 'notes' => 'Received check-in.']);
        $this->actingAs($this->worker)->post('/health-safety/lone-workers/sessions/'.$row->id.'/check-in', $payload)->assertRedirect();
        $this->post('/health-safety/lone-workers/sessions/'.$row->id.'/check-in', [...$payload, 'return_to' => '/my-day', 'section' => 'check-ins'])->assertRedirect();
        $check = $row->checkIns()->sole();
        $this->assertSame($this->worker->id, $check->recorded_by_user_id);
        $this->assertTrue($check->checked_in_at->eq($check->received_at));
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->post('/health-safety/lone-workers/sessions/'.$row->id.'/check-in', [...$payload, 'notes' => 'Other observation'])->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, $row->checkIns()->count());
    }

    public function test_actor_and_other_source_cannot_replay_a_confirmed_command_key(): void
    {
        $row = $this->source();
        $other = $this->source();
        $payload = $this->guard($row, ['status' => 'ok']);
        $this->service()->checkIn($row, $this->worker, $payload);
        $this->invalid(fn () => $this->service()->checkIn($row, $this->manager, $payload), 'idempotency_key');
        $this->invalid(fn () => $this->service()->checkIn($other, $this->worker, $payload), 'idempotency_key');
        $this->assertSame(0, $other->checkIns()->count());
    }

    public function test_stale_edit_does_not_replace_a_newer_record_or_receipt(): void
    {
        $row = $this->source();
        $payload = $this->guard($row, ['expected_end_at' => now()->addHours(3)->toISOString()]);
        $this->service()->checkIn($row, $this->worker, $this->guard($row, ['status' => 'concern']));
        $before = $row->fresh()->getAttributes();
        $this->invalid(fn () => $this->service()->update($row, $this->manager, $payload), 'expected_version');
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(2, LoneWorkerCommandReceipt::count());
    }

    public function test_exact_replay_rechecks_current_actor_and_source_authority(): void
    {
        $row = $this->source();
        $payload = $this->guard($row, ['status' => 'ok']);
        $this->service()->checkIn($row, $this->worker, $payload);
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        try {
            $this->service()->checkIn($row, $this->worker, $payload);
            $this->fail('Revoked worker must not replay.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(1, $row->checkIns()->count());
    }

    public function test_self_start_and_end_require_the_exact_owned_actually_started_shift(): void
    {
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id, 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        $data = $this->planning(['shift_id' => $shift->id]);
        $row = $this->service()->create($this->worker, $data, true);
        $this->assertSame($this->worker->id, $row->created_by);
        $this->assertNull($row->last_check_in_at);
        $this->invalid(fn () => $this->service()->create($this->worker, $this->planning(['shift_id' => $shift->id]), true), 'shift_id');
        $this->service()->end($row, $this->worker, $this->guard($row), true);
        $this->assertSame('completed', $row->fresh()->status);
    }

    public static function invalidOwnShifts(): array
    {
        return [['other_owner'], ['not_started'], ['ended'], ['cancelled']];
    }

    #[DataProvider('invalidOwnShifts')]
    public function test_self_start_rejects_invalid_shift_authority(string $kind): void
    {
        $other = $this->staff();
        $shift = Shift::factory()->create(['user_id' => $kind === 'other_owner' ? $other->id : $this->worker->id, 'site_id' => $this->site->id, 'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'actual_starts_at' => $kind === 'not_started' ? null : now()->subHour(), 'actual_ends_at' => $kind === 'ended' ? now() : null, 'status' => $kind === 'cancelled' ? 'cancelled' : 'in_progress']);
        try {
            $this->service()->create($this->worker, $this->planning(['shift_id' => $shift->id]), true);
            $this->fail('Invalid own Shift must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, LoneWorkerSession::count());
    }

    public function test_manager_standalone_source_keeps_existing_worker_check_in_and_panic_authority(): void
    {
        $row = $this->source();
        $this->service()->checkIn($row, $this->worker, $this->guard($row, ['status' => 'ok']));
        $this->service()->panic($row, $this->worker, $this->guard($row, ['emergency_notes' => 'Urgent help requested.']));
        $this->assertSame('emergency', $row->fresh()->status);
        $this->assertSame('pending', $row->emergencyEpisodes()->sole()->status);
        $this->invalid(fn () => $this->service()->end($row, $this->worker, $this->guard($row), true), 'status');
        $this->assertNull($row->fresh()->ended_at);
    }

    public function test_emergency_intent_audit_and_command_confirmation_roll_back_together(): void
    {
        $row = $this->source();
        $before = $row->fresh()->getAttributes();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function ($audit) {
            if ($audit->action === 'healthSafety.loneWorker.session.emergency') {
                throw new RuntimeException('Source audit unavailable');
            }
        });
        try {
            $this->service()->panic($row, $this->worker, $this->guard($row));
            $this->fail('Audit must be strict.');
        } catch (RuntimeException $error) {
            $this->assertSame('Source audit unavailable', $error->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(0, LoneWorkerEmergencyEpisode::count());
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
        Queue::assertNotPushed(DispatchLoneWorkerEmergency::class);
    }

    public function test_failed_processing_retains_the_accepted_request_and_recovers_exactly_once(): void
    {
        $row = $this->source();
        $payload = $this->guard($row, ['emergency_notes' => 'Recorded request.']);
        $this->service()->panic($row, $this->worker, $payload);
        $episode = $row->emergencyEpisodes()->sole();
        $actual = app(SignalProcessingService::class);
        $broken = \Mockery::mock(SignalProcessingService::class);
        $broken->shouldReceive('ingest')->once()->andReturnUsing(fn ($data) => Signal::create([...$data, 'status' => 'pending']));
        $broken->shouldReceive('process')->once()->andThrow(new RuntimeException('Processing unavailable'));
        $delivery = new LoneWorkerEmergencyDeliveryService(app(LoneWorkerSessionAccessService::class), new LoneWorkerSignalService($broken, app(UserSiteAccessService::class)));
        try {
            $delivery->deliver($episode->id);
            $this->fail('Processing failure must be visible.');
        } catch (RuntimeException $error) {
            $this->assertSame('Processing unavailable', $error->getMessage());
        }
        $this->assertSame('emergency', $row->fresh()->status);
        $this->assertSame('failed', $episode->fresh()->status);
        $this->assertSame('signal_delivery_failed', $episode->fresh()->failure_code);
        $this->assertSame(0, Signal::count());
        $this->service()->panic($row, $this->worker, $payload);
        $this->assertSame(1, LoneWorkerEmergencyEpisode::count());
        $this->travelTo($episode->fresh()->next_attempt_at->copy()->addSecond());
        $delivery = new LoneWorkerEmergencyDeliveryService(app(LoneWorkerSessionAccessService::class), new LoneWorkerSignalService($actual, app(UserSiteAccessService::class)));
        $delivery->deliver($episode->id);
        $delivery->deliver($episode->id);
        $this->assertSame('delivered', $episode->fresh()->status);
        $this->assertSame(2, $episode->fresh()->attempts);
        $this->assertSame(1, Signal::count());
        $this->assertSame(1, ControlRoomAlert::count());
        $this->assertSame('open', ControlRoomAlert::sole()->status);
        $this->assertSame(0, $row->checkIns()->count());
    }

    public static function withdrawnSources(): array
    {
        return [['ended'], ['worker_revoked'], ['site_archived']];
    }

    #[DataProvider('withdrawnSources')]
    public function test_retry_skips_freshly_ineligible_source_without_delivering_sensitive_details(string $kind): void
    {
        $row = $this->source();
        $this->service()->panic($row, $this->worker, $this->guard($row));
        $episode = $row->emergencyEpisodes()->sole();
        if ($kind === 'ended') {
            $row->forceFill(['ended_at' => now(), 'status' => 'completed'])->saveQuietly();
        } elseif ($kind === 'worker_revoked') {
            $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        } else {
            $this->site->update(['archived' => true, 'archived_at' => now()]);
        }
        app(LoneWorkerEmergencyDeliveryService::class)->deliver($episode->id);
        $this->assertSame('skipped', $episode->fresh()->status);
        $this->assertNull($episode->fresh()->delivered_at);
        $this->assertSame(0, Signal::count());
        $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_recovery_dispatches_only_due_pending_or_failed_exact_episode_ids(): void
    {
        $row = $this->source();
        $this->service()->panic($row, $this->worker, $this->guard($row));
        $episode = $row->emergencyEpisodes()->sole();
        $episode->update(['status' => 'failed', 'failure_code' => 'queue_unavailable', 'next_attempt_at' => now()->subMinute()]);
        $this->assertSame(1, app(LoneWorkerEmergencyDeliveryService::class)->recoverPending());
        Queue::assertPushed(DispatchLoneWorkerEmergency::class, fn ($job) => $job->episodeId === $episode->id);
        $episode->update(['next_attempt_at' => now()->addHour()]);
        $this->assertSame(0, app(LoneWorkerEmergencyDeliveryService::class)->recoverPending());
    }

    public function test_new_panic_after_resolved_origin_gets_a_new_episode_and_never_reuses_the_old_alert(): void
    {
        $row = $this->source();
        $first = $this->guard($row);
        $this->service()->panic($row, $this->worker, $first);
        $one = $row->emergencyEpisodes()->sole();
        app(LoneWorkerEmergencyDeliveryService::class)->deliver($one->id);
        $this->actingAs($this->manager)->post('/health-safety/lone-workers/alerts/'.$one->fresh()->control_room_alert_id.'/resolve', $this->guard($row, ['resolution_notes' => 'Response completed after direct contact.']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $row->fresh()->status);
        $second = $this->guard($row);
        $this->service()->panic($row, $this->worker, $second);
        $this->service()->panic($row, $this->worker, $second);
        $two = $row->emergencyEpisodes()->latest('episode_number')->firstOrFail();
        app(LoneWorkerEmergencyDeliveryService::class)->deliver($two->id);
        $this->assertSame(2, LoneWorkerEmergencyEpisode::count());
        $this->assertNotSame($one->fresh()->control_room_alert_id, $two->fresh()->control_room_alert_id);
        $this->assertSame(2, Signal::count());
        $this->assertSame(2, ControlRoomAlert::count());
        $this->assertSame('resolved', ControlRoomAlert::findOrFail($one->fresh()->control_room_alert_id)->status);
        $this->assertSame('open', ControlRoomAlert::findOrFail($two->fresh()->control_room_alert_id)->status);
        $this->assertSame(0, $row->checkIns()->count());
    }

    public function test_manager_edit_requires_read_and_manage_even_if_the_manager_owns_the_source(): void
    {
        $managerOnly = $this->staff(['hazards.manage']);
        try {
            $this->service()->create($managerOnly, $this->planning(['user_id' => $managerOnly->id]));
            $this->fail('Read authority is required.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, LoneWorkerSession::count());
    }

    public function test_planning_rejects_naive_time_but_equivalent_explicit_offsets_share_creation_confirmation(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T01:00:00Z'));
        $this->invalid(fn () => $this->service()->create($this->manager, $this->planning(['expected_end_at' => '2026-10-08T15:00'])), 'expected_end_at');
        $payload = $this->planning(['expected_end_at' => '2026-10-08T15:00:00+13:00']);
        $row = $this->service()->create($this->manager, $payload);
        $replay = $this->service()->create($this->manager, [...$payload, 'expected_end_at' => '2026-10-07T22:00:00-04:00']);
        $this->assertSame($row->id, $replay->id);
        $this->assertSame('2026-10-08 02:00:00', $row->expected_end_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_emergency_check_in_retains_an_actual_observation_and_same_atomic_intent(): void
    {
        $row = $this->source();
        $payload = $this->guard($row, ['status' => 'emergency', 'notes' => 'Help requested during the check-in.']);
        $this->service()->checkIn($row, $this->worker, $payload);
        $this->service()->checkIn($row, $this->worker, $payload);
        $this->assertSame(1, $row->checkIns()->count());
        $this->assertSame('emergency', $row->checkIns()->sole()->status);
        $this->assertSame($this->worker->id, $row->checkIns()->sole()->recorded_by_user_id);
        $this->assertSame('emergency', $row->fresh()->status);
        $this->assertSame('pending', $row->emergencyEpisodes()->sole()->status);
        $this->assertSame(2, $row->fresh()->lock_version);
    }

    public function test_valid_legacy_site_fallback_records_the_actual_resolved_site_without_rewriting_history(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $client->id]);
        $row = $this->source();
        $row->forceFill(['site_id' => null, 'client_id' => $client->id, 'shift_id' => $shift->id])->saveQuietly();
        $this->service()->panic($row, $this->worker, $this->guard($row));
        $episode = $row->emergencyEpisodes()->sole();
        $this->assertSame($this->site->id, $episode->site_id);
        app(LoneWorkerEmergencyDeliveryService::class)->deliver($episode->id);
        $this->assertSame('delivered', $episode->fresh()->status);
        $this->assertNull($row->fresh()->site_id);
        $this->assertSame($this->site->id, Signal::sole()->site_id);
        $this->assertSame($this->site->id, ControlRoomAlert::sole()->site_id);
    }

    public function test_conflicting_legacy_fallback_cannot_record_an_emergency_intent(): void
    {
        $foreign = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $foreign->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $client->id]);
        $row = $this->source();
        $row->forceFill(['site_id' => null, 'client_id' => $client->id, 'shift_id' => $shift->id])->saveQuietly();
        try {
            $this->service()->panic($row, $this->worker, $this->guard($row));
            $this->fail('Conflicting source must be denied.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame('active', $row->fresh()->status);
        $this->assertSame(0, LoneWorkerEmergencyEpisode::count());
        $this->assertSame(0, Signal::count());
    }

    public function test_actual_pipeline_suppression_has_a_signal_receipt_without_delivery_or_response_claim(): void
    {
        $row = $this->source();
        $this->service()->panic($row, $this->worker, $this->guard($row));
        $episode = $row->emergencyEpisodes()->sole();
        $processor = \Mockery::mock(SignalProcessingService::class);
        $processor->shouldReceive('ingest')->once()->andReturnUsing(fn ($data) => Signal::create([...$data, 'status' => 'pending']));
        $processor->shouldReceive('process')->once()->andReturnUsing(function (Signal $signal) {
            $signal->markSuppressed('Configured processing suppression.');

            return null;
        });
        $delivery = new LoneWorkerEmergencyDeliveryService(app(LoneWorkerSessionAccessService::class), new LoneWorkerSignalService($processor, app(UserSiteAccessService::class)));
        $delivery->deliver($episode->id);
        $this->assertSame('suppressed', $episode->fresh()->status);
        $this->assertNotNull($episode->fresh()->signal_id);
        $this->assertNull($episode->fresh()->control_room_alert_id);
        $this->assertNull($episode->fresh()->delivered_at);
        $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_poisoned_signal_correlation_is_failed_without_a_response_origin(): void
    {
        $row = $this->source();
        $other = $this->source();
        $this->service()->panic($row, $this->worker, $this->guard($row));
        $episode = $row->emergencyEpisodes()->sole();
        $processor = \Mockery::mock(SignalProcessingService::class);
        $processor->shouldReceive('ingest')->once()->andReturnUsing(fn ($data) => Signal::create([...$data, 'normalized_data' => [...$data['normalized_data'], 'lone_worker_session_id' => $other->id], 'status' => 'pending']));
        $processor->shouldNotReceive('process');
        $delivery = new LoneWorkerEmergencyDeliveryService(app(LoneWorkerSessionAccessService::class), new LoneWorkerSignalService($processor, app(UserSiteAccessService::class)));
        try {
            $delivery->deliver($episode->id);
            $this->fail('Conflicting signal must fail.');
        } catch (\DomainException) {
        }
        $this->assertSame('failed', $episode->fresh()->status);
        $this->assertSame('correlation_conflict', $episode->fresh()->failure_code);
        $this->assertSame(0, Signal::count());
        $this->assertSame(0, ControlRoomAlert::count());
    }

    public function test_missing_command_guards_never_record_a_check_in_or_source_change(): void
    {
        $row = $this->source();
        $this->actingAs($this->worker)->post('/health-safety/lone-workers/sessions/'.$row->id.'/check-in', ['status' => 'ok'])->assertSessionHasErrors(['expected_version', 'idempotency_key']);
        $this->assertSame(0, $row->checkIns()->count());
        $this->assertSame(1, $row->fresh()->lock_version);
    }

    public function test_self_start_normalizes_form_encoded_source_ids_for_exact_retry(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $client->id, 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        $data = $this->planning(['user_id' => (string) $this->worker->id, 'site_id' => (string) $this->site->id, 'client_id' => (string) $client->id, 'shift_id' => (string) $shift->id, 'check_in_interval_minutes' => '30']);
        $row = $this->service()->create($this->worker, $data, true);
        $retry = $this->service()->create($this->worker, [...$data, 'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $client->id, 'shift_id' => $shift->id, 'check_in_interval_minutes' => 30], true);
        $this->assertSame($row->id, $retry->id);
        $this->assertSame($client->id, $row->client_id);
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
    }

    public function test_exact_http_confirmation_is_shared_and_forged_or_revoked_proof_is_withheld(): void
    {
        $row = $this->source();
        $data = $this->guard($row, ['status' => 'ok']);
        $this->actingAs($this->worker)->post('/health-safety/lone-workers/sessions/'.$row->id.'/check-in', $data)->assertRedirect();
        $this->getJson('/health-safety/lone-workers/sessions/'.$row->id)->assertOk()->assertJsonPath('lone_worker_confirmation.id', $row->id)->assertJsonPath('lone_worker_confirmation.lock_version', 2)->assertJsonPath('lone_worker_confirmation.idempotency_key', $data['idempotency_key'])->assertJsonPath('lone_worker_confirmation.command', 'checkIn')->assertJsonPath('lone_worker_confirmation.removed', false);
        $candidate = ['id' => (int) $row->id, 'lock_version' => 2, 'idempotency_key' => $data['idempotency_key']];
        $this->assertNull($this->service()->confirmationForViewer($this->worker, [...$candidate, 'idempotency_key' => (string) Str::uuid()]));
        $this->assertNull($this->service()->confirmationForViewer($this->worker, [...$candidate, 'lock_version' => 3]));
        $this->assertNull($this->service()->confirmationForViewer($this->manager, $candidate));
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertNull($this->service()->confirmationForViewer($this->worker, $candidate));
    }

    public function test_deleted_source_returns_exact_removal_confirmation_without_restoring_the_record(): void
    {
        $row = $this->source();
        $this->service()->end($row, $this->manager, $this->guard($row));
        $data = $this->guard($row);
        $this->delete('/health-safety/lone-workers/sessions/'.$row->id, $data)->assertRedirect('/health-safety/lone-workers');
        $this->getJson('/health-safety/lone-workers')->assertOk()->assertJsonPath('lone_worker_confirmation.id', $row->id)->assertJsonPath('lone_worker_confirmation.idempotency_key', $data['idempotency_key'])->assertJsonPath('lone_worker_confirmation.command', 'delete')->assertJsonPath('lone_worker_confirmation.removed', true);
        $this->assertSoftDeleted($row);
    }

    public function test_hidden_source_is_denied_before_missing_or_malformed_command_validation(): void
    {
        $row = $this->source();
        $foreign = $this->staff(['hazards.view', 'hazards.manage']);
        $foreign->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->actingAs($foreign)->patch('/health-safety/lone-workers/sessions/'.$row->id, ['expected_end_at' => 'not-an-instant'])->assertForbidden();
        $this->assertSame(1, $row->fresh()->lock_version);
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
    }

    public function test_unverified_source_coordinates_do_not_escape_new_legacy_signal_emissions(): void
    {
        $row = $this->source();
        $row->forceFill(['location_lat' => -36.85, 'location_lng' => 174.76])->saveQuietly();
        app(LoneWorkerSignalService::class)->emitOverdueCheckIn($row, 45);
        app(LoneWorkerSignalService::class)->emitSessionOverrun($row, 45);
        $this->assertSame(2, Signal::count());
        foreach (Signal::all() as $signal) {
            $this->assertArrayNotHasKey('location_lat', $signal->normalized_data);
            $this->assertArrayNotHasKey('location_lng', $signal->normalized_data);
        }
        $this->assertEqualsWithDelta(-36.85, (float) $row->fresh()->location_lat, 0.00001);
        foreach (ControlRoomAlert::all() as $alert) {
            $this->assertNull(data_get($alert->context, 'normalized_data.location_lat'));
            $this->assertNull(data_get($alert->context, 'normalized_data.location_lng'));
        }
    }

    public function test_detector_failure_remains_visible_without_fabricating_a_successful_monitor_receipt(): void
    {
        $row = $this->source();
        $row->forceFill(['started_at' => now()->subHours(2), 'expected_end_at' => now()->addHour()])->saveQuietly();
        $broken = \Mockery::mock(LoneWorkerSignalService::class);
        $broken->shouldReceive('emitOverdueCheckIn')->once()->andThrow(new RuntimeException('Detector pipeline unavailable'));
        (new CheckLoneWorkerOverdueJob)->handle($broken);
        $this->assertSame('active', $row->fresh()->status);
        $this->assertNotNull($row->fresh()->monitor_failed_at);
        $this->assertNull($row->fresh()->last_monitor_checked_at);
        $this->assertSame('signal_delivery_failed', $row->fresh()->monitor_failure_code);
        $this->assertSame(1, $row->fresh()->lock_version);
    }

    public function test_actual_self_start_records_a_manual_plan_and_normalizes_equivalent_coordinate_retry(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $client->id,
            'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        $payload = $this->planning(['shift_id' => $shift->id, 'planned_location' => ['method' => 'manual', 'description' => 'Chosen meeting point', 'latitude' => '0.0000000', 'longitude' => '0']]);
        $this->actingAs($this->worker)->post('/health-safety/lone-workers/sessions/start-own', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $row = LoneWorkerSession::sole();
        $plan = $row->planned_location;
        $this->assertSame('planned', $plan['kind']);
        $this->assertSame($this->worker->id, $plan['recorded_by_user_id']);
        $this->assertSame(0.0, (float) $plan['latitude']);
        $this->assertSame(0.0, (float) $plan['longitude']);
        $this->assertNull($row->location_lat);
        $this->assertNull($row->last_check_in_at);
        $this->post('/health-safety/lone-workers/sessions/start-own', [...$payload, 'planned_location' => ['method' => 'manual', 'description' => 'Chosen meeting point', 'latitude' => 0, 'longitude' => 0]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($plan, $row->fresh()->planned_location);
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
        $this->post('/health-safety/lone-workers/sessions/start-own', [...$payload, 'planned_location' => ['method' => 'manual', 'description' => 'Changed choice', 'latitude' => 0, 'longitude' => 0]])->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, LoneWorkerSession::count());
    }

    public function test_plan_omission_preserves_receipt_explicit_null_clears_and_exact_retry_is_immutable(): void
    {
        $row = $this->service()->create($this->manager, $this->planning(['planned_location' => ['method' => 'selected_address', 'description' => 'Chosen address', 'latitude' => -36.85, 'longitude' => 174.76]]));
        $original = $row->planned_location;
        $this->service()->checkIn($row, $this->worker, $this->guard($row, ['status' => 'ok']));
        $this->service()->update($row, $this->manager, $this->guard($row, ['expected_end_at' => now()->addHours(3)->toISOString()]));
        $this->assertSame($original, $row->fresh()->planned_location);
        $payload = $this->guard($row, ['expected_end_at' => now()->addHours(3)->toISOString(), 'planned_location' => null]);
        $this->service()->update($row, $this->manager, $payload);
        $confirmedVersion = $row->fresh()->lock_version;
        $this->service()->update($row, $this->manager, $payload);
        $this->assertNull($row->fresh()->planned_location);
        $this->assertSame($confirmedVersion, $row->fresh()->lock_version);
        $this->assertSame(4, LoneWorkerCommandReceipt::count());
        $snapshot = AuditLog::where('action', 'healthSafety.loneWorker.session.update')->latest('id')->firstOrFail()->meta['snapshot'];
        $this->assertArrayHasKey('planned_location', $snapshot);
        $this->assertNull($snapshot['planned_location']);
    }

    public function test_site_plan_uses_fresh_exact_site_fields_and_keeps_immutable_confirmation_when_site_changes(): void
    {
        $this->site->updateQuietly(['address_line_1' => '17 Synthetic Road', 'suburb' => 'Test suburb', 'city' => 'Test city', 'postcode' => '0000', 'latitude' => 0, 'longitude' => 0]);
        $payload = $this->planning(['planned_location' => ['method' => 'site_address', 'description' => 'Untrusted label', 'latitude' => 44, 'longitude' => 77]]);
        $row = $this->service()->create($this->manager, $payload);
        $plan = $row->planned_location;
        $this->assertSame('17 Synthetic Road, Test suburb, Test city, 0000', $plan['description']);
        $this->assertSame($this->site->id, $plan['source_site_id']);
        $this->assertNull($plan['source_shift_id']);
        $this->assertSame(0.0, (float) $plan['latitude']);
        $this->site->updateQuietly(['address_line_1' => 'Changed source address', 'latitude' => -36.8, 'longitude' => 174.7]);
        $replay = $this->service()->create($this->manager, $payload);
        $this->assertSame($plan, $replay->planned_location);
        $changed = $this->service()->update($row, $this->manager, $this->guard($row, ['expected_end_at' => now()->addHours(3)->toISOString(), 'planned_location' => ['method' => 'site_address']]));
        $this->assertStringStartsWith('Changed source address', $changed->planned_location['description']);
        $this->assertSame(-36.8, $changed->planned_location['latitude']);
    }

    public function test_shift_plan_is_exact_address_only_and_does_not_copy_any_shift_gps_evidence(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $client->id, 'location' => 'Exact rostered meeting address']);
        ShiftGpsLog::create(['shift_id' => $shift->id, 'user_id' => $this->worker->id, 'event_type' => 'ping', 'latitude' => -36.8, 'longitude' => 174.7, 'captured_at' => now()]);
        $row = $this->service()->create($this->manager, $this->planning(['shift_id' => $shift->id, 'client_id' => $client->id, 'planned_location' => ['method' => 'shift_address', 'description' => 'Ignored', 'latitude' => 10, 'longitude' => 20]]));
        $this->assertSame('Exact rostered meeting address', $row->planned_location['description']);
        $this->assertSame($shift->id, $row->planned_location['source_shift_id']);
        $this->assertNull($row->planned_location['latitude']);
        $this->assertNull($row->planned_location['longitude']);
        $this->assertNull($row->location_lat);
        $this->service()->panic($row, $this->worker, $this->guard($row));
        app(LoneWorkerEmergencyDeliveryService::class)->deliver($row->emergencyEpisodes()->sole()->id);
        $this->assertArrayNotHasKey('planned_location', Signal::sole()->normalized_data);
        $this->assertArrayNotHasKey('location_lat', Signal::sole()->normalized_data);
    }

    public static function invalidPlannedLocations(): array
    {
        return [
            'one coordinate' => [['method' => 'manual', 'description' => 'Chosen point', 'latitude' => 0]],
            'manual address without point' => [['method' => 'manual', 'description' => 'Text only']],
            'out of range' => [['method' => 'selected_address', 'latitude' => -91, 'longitude' => 0]],
            'invented actor' => [['method' => 'manual', 'latitude' => 0, 'longitude' => 0, 'recorded_by_user_id' => 999]],
            'unknown method' => [['method' => 'gps', 'latitude' => 0, 'longitude' => 0]],
            'description too long' => [['method' => 'manual', 'latitude' => 0, 'longitude' => 0, 'description' => str_repeat('x', 501)]],
        ];
    }

    #[DataProvider('invalidPlannedLocations')]
    public function test_invalid_plan_does_not_change_source_version_or_command_receipts(array $plan): void
    {
        $row = $this->source();
        $before = $row->fresh()->getAttributes();
        try {
            $this->service()->update($row, $this->manager, $this->guard($row, ['expected_end_at' => now()->addHours(3)->toISOString(), 'planned_location' => $plan]));
            $this->fail('Invalid planned location must be rejected.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty(array_filter(array_keys($error->errors()), fn ($key) => str_starts_with($key, 'planned_location')));
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
    }

    public static function distinctCanonicalStatuses(): array
    {
        return [['ack', 'acknowledged'], ['triaging', 'acknowledged'], ['resolved', 'resolved'], ['closed', 'resolved']];
    }

    #[DataProvider('distinctCanonicalStatuses')]
    public function test_response_rows_preserve_exact_canonical_status_alongside_legacy_display(string $canonical, string $legacy): void
    {
        $row = $this->source();
        $this->service()->panic($row, $this->worker, $this->guard($row));
        app(LoneWorkerEmergencyDeliveryService::class)->deliver($row->emergencyEpisodes()->sole()->id);
        $alert = ControlRoomAlert::sole();
        $alert->updateQuietly(['status' => $canonical]);
        $this->actingAs($this->manager)->getJson('/health-safety/lone-workers?tab=alerts&period=all&status='.$canonical)
            ->assertOk()->assertJsonPath('alerts.data.0.canonical_status', $canonical)->assertJsonPath('alerts.data.0.status', $legacy);
    }

    public function test_invalid_legacy_site_static_point_stays_unknown_in_the_attributed_plan(): void
    {
        $this->site->updateQuietly(['latitude' => 91, 'longitude' => 0]);
        $row = $this->service()->create($this->manager, $this->planning(['planned_location' => ['method' => 'site_address']]));
        $this->assertSame('site_address', $row->planned_location['method']);
        $this->assertSame($this->site->id, $row->planned_location['source_site_id']);
        $this->assertNull($row->planned_location['latitude']);
        $this->assertNull($row->planned_location['longitude']);
    }

    public function test_own_shift_start_derives_hidden_client_for_null_and_omitted_input_with_exact_retry(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $client->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        $payload = ['shift_id' => (string) $shift->id, 'client_id' => null, 'expected_end_at' => now()->addHours(2)->toISOString(), 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($this->worker)->post('/health-safety/lone-workers/sessions/start-own', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $row = LoneWorkerSession::sole();
        unset($payload['client_id']);
        $this->post('/health-safety/lone-workers/sessions/start-own', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($client->id, $row->client_id);
        $this->assertSame($shift->id, $row->shift_id);
        $this->assertSame(1, LoneWorkerSession::count());
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
        $this->assertNull($row->last_check_in_at);
        $this->assertSame(0, $row->checkIns()->count());
        $conflictingClient = Client::factory()->create(['site_id' => $this->site->id]);
        $this->post('/health-safety/lone-workers/sessions/start-own', [...$payload, 'idempotency_key' => (string) Str::uuid(), 'client_id' => $conflictingClient->id])->assertForbidden();
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
    }

    public function test_manager_shift_start_derives_policy_hidden_client_from_the_exact_picker_shift(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Private synthetic', 'last_name' => 'Client']);
        $shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $client->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        $picker = collect(app(LoneWorkerSessionAccessService::class)->pickerOptions($this->manager)['shifts'])->firstWhere('id', $shift->id);
        $this->assertNotNull($picker);
        $this->assertNull($picker['client_id']);
        $payload = $this->planning(['shift_id' => $picker['id'], 'client_id' => $picker['client_id']]);
        $this->post('/health-safety/lone-workers/sessions', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $row = LoneWorkerSession::sole();
        unset($payload['client_id']);
        $this->post('/health-safety/lone-workers/sessions', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($client->id, $row->client_id);
        $this->assertSame($shift->id, $row->shift_id);
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
        $this->getJson('/health-safety/lone-workers/sessions/'.$row->id)->assertOk()->assertJsonPath('detail.client', null);
        $conflicting = Client::factory()->create(['site_id' => $this->site->id]);
        $this->post('/health-safety/lone-workers/sessions', [...$payload, 'idempotency_key' => (string) Str::uuid(), 'client_id' => $conflicting->id])->assertSessionHasErrors('client_id');
        $this->assertSame(1, LoneWorkerCommandReceipt::count());
    }
}
