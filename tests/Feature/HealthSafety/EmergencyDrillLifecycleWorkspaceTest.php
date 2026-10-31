<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoom\Signal;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillCommandReceipt;
use App\Models\EmergencyDrillFailureDelivery;
use App\Models\EmergencyDrillFinding;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\HealthSafety\DrillComplianceService;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\EmergencyDrillFailureDeliveryService;
use App\Services\HealthSafety\EmergencyDrillService;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsEventService;
use App\Support\HealthSafety\EmergencyDrillPresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmergencyDrillLifecycleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $hiddenSite;

    private User $manager;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        Queue::fake();
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->hiddenSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->manager = $this->worker($this->site);
        $this->actingAs($this->manager);
    }

    private function worker(Site $site): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null]);
        foreach (['hazards.view', 'hazards.create', 'hazards.manage'] as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function service(): EmergencyDrillService
    {
        return app(EmergencyDrillService::class);
    }

    private function planning(array $extra = []): array
    {
        return ['site_id' => $this->site->id, 'drill_type' => 'fire_evacuation', 'title' => 'Emergency readiness drill '.(++$this->sequence),
            'scheduled_at' => now()->addDay()->toISOString(), 'scenario_description' => 'Test the agreed evacuation route.',
            'assembly_point' => 'Designated assembly point', 'conducted_by' => $this->manager->id, 'warden_ids' => [],
            'is_unannounced' => false, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function create(array $extra = []): EmergencyDrill
    {
        return $this->service()->create($this->manager, $this->planning($extra));
    }

    private function guard(EmergencyDrill $row, array $extra = []): array
    {
        return ['expected_version' => $row->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function finding(EmergencyDrill $row): EmergencyDrillFinding
    {
        $this->service()->addFinding($row, $this->manager, $this->guard($row, ['finding_type' => 'non_conformance', 'description' => 'The exit was obstructed.', 'severity' => 'high', 'assigned_to' => $this->manager->id, 'due_date' => now()->addWeek()->toDateString()]));

        return $row->findings()->latest('id')->firstOrFail();
    }

    private function evidence(EmergencyDrill $row): int
    {
        $this->service()->addEvidence($row, $this->manager, $this->guard($row, ['kind' => 'inspection']), UploadedFile::fake()->create('exit-inspection.pdf', 2, 'application/pdf'));

        return (int) $row->attachments()->latest('id')->firstOrFail()->id;
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_create_replay_seeds_actual_staff_once_and_denies_other_drill_payload(): void
    {
        $warden = $this->worker($this->site);
        $data = $this->planning(['warden_ids' => [$warden->id, $this->manager->id]]);
        $row = $this->service()->create($this->manager, $data);
        $confirmed = $this->service()->create($this->manager, $data);
        $this->assertSame($row->id, $confirmed->id);
        $this->assertSame(1, EmergencyDrill::count());
        $this->assertSame(2, $row->participants()->count());
        $this->assertSame(1, EmergencyDrillCommandReceipt::count());
        $this->assertSame(1, AuditLog::where('action', 'drill.scheduled')->count());
        $this->invalid(fn () => $this->service()->create($this->manager, [...$data, 'title' => 'Another drill']), 'idempotency_key');
    }

    public function test_child_writes_increment_parent_revision_and_preserve_staff_support_notes(): void
    {
        $row = $this->create();
        $this->service()->upsertParticipant($row, $this->manager, $this->guard($row, ['user_id' => $this->manager->id, 'role' => 'coordinator', 'attended' => true, 'notes' => 'Briefing attended.', 'accommodations' => 'Allow extra time to reach the assembly point.']));
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertSame('Allow extra time to reach the assembly point.', $row->participants()->first()->accommodations);
        $this->invalid(fn () => $this->service()->start($row, $this->manager, ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()]), 'expected_version');
        $this->assertSame('scheduled', $row->fresh()->status);
    }

    public function test_completion_requires_real_start_and_ordered_time_but_records_failed_checks(): void
    {
        $row = $this->create();
        $completion = ['completed_at' => now()->toISOString(), 'outcome' => 'failed', 'all_areas_checked' => false, 'assembly_point_reached' => false, 'roll_call_completed' => false];
        try {
            $this->service()->complete($row, $this->manager, $this->guard($row, $completion));
            $this->fail('A scheduled drill must not invent a start.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->service()->start($row, $this->manager, $this->guard($row));
        $this->invalid(fn () => $this->service()->complete($row, $this->manager, $this->guard($row, [...$completion, 'completed_at' => now()->subDay()->toISOString()])), 'completed_at');
        $finding = $this->finding($row);
        $payload = $this->guard($row, [...$completion, 'completed_at' => now()->toISOString()]);
        $this->service()->complete($row, $this->manager, $payload);
        $this->service()->complete($row, $this->manager, $payload);
        $this->assertSame('completed', $row->fresh()->status);
        $this->assertFalse($row->fresh()->roll_call_completed);
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertSame(1, EmergencyDrillFailureDelivery::count());
        $this->assertSame('pending', EmergencyDrillFailureDelivery::first()->event_status);
        $this->assertSame(1, AuditLog::where('action', 'drill.completed')->count());
    }

    public function test_future_actual_completion_is_rejected_without_source_receipt_or_delivery_changes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T01:00:00Z'));
        $row = $this->create();
        $this->service()->start($row, $this->manager, $this->guard($row));
        $before = $row->fresh()->getAttributes();
        $receipts = EmergencyDrillCommandReceipt::count();
        $audits = AuditLog::count();
        $payload = $this->guard($row, ['completed_at' => '2026-10-08T14:01:00+13:00', 'outcome' => 'failed']);
        $this->invalid(fn () => $this->service()->complete($row, $this->manager, $payload), 'completed_at');
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame($receipts, EmergencyDrillCommandReceipt::count());
        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(0, EmergencyDrillFailureDelivery::count());
        $this->travelTo(Carbon::parse('2026-10-08T01:02:00Z'));
        $confirmed = $this->service()->complete($row, $this->manager, $payload);
        $this->travelTo(Carbon::parse('2026-10-08T01:00:00Z'));
        $replay = $this->service()->complete($row, $this->manager, $payload);
        $confirmedAttributes = $confirmed->getAttributes();
        $replayedAttributes = $replay->getAttributes();
        ksort($confirmedAttributes);
        ksort($replayedAttributes);
        $this->assertSame($confirmedAttributes, $replayedAttributes);
        $this->assertSame($receipts + 1, EmergencyDrillCommandReceipt::count());
        $this->assertSame(1, EmergencyDrillFailureDelivery::count());
    }

    public static function invalidInstants(): array
    {
        return [['2026-10-08T09:30'], ['2026-10-08 09:30:00'], ['2026-10-08'], ['2026-09-27T02:30:00'], ['2026-04-05T02:30:00'], ['2026-10-08T25:30:00+13:00']];
    }

    #[DataProvider('invalidInstants')]
    public function test_schedule_and_completion_reject_naive_or_invalid_instants(string $instant): void
    {
        $this->invalid(fn () => $this->create(['scheduled_at' => $instant]), 'scheduled_at');
        $row = $this->create();
        $this->service()->start($row, $this->manager, $this->guard($row));
        $this->invalid(fn () => $this->service()->complete($row, $this->manager, $this->guard($row, ['completed_at' => $instant, 'outcome' => 'passed'])), 'completed_at');
        $this->assertSame('in_progress', $row->fresh()->status);
    }

    public function test_explicit_nz_and_non_nz_offsets_save_the_same_actual_instant_and_order_completion(): void
    {
        $nz = $this->create(['scheduled_at' => '2026-10-09T09:30:00+13:00']);
        $overseas = $this->create(['scheduled_at' => '2026-10-08T16:30:00-04:00']);
        $this->assertSame('2026-10-08 20:30:00', $nz->fresh()->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($nz->fresh()->scheduled_at->eq($overseas->fresh()->scheduled_at));
        $firstRepeatedHour = $this->create(['scheduled_at' => '2026-04-05T02:30:00+13:00']);
        $secondRepeatedHour = $this->create(['scheduled_at' => '2026-04-05T02:30:00+12:00']);
        $this->assertSame(3600, (int) $firstRepeatedHour->fresh()->scheduled_at->diffInSeconds($secondRepeatedHour->fresh()->scheduled_at));
        $this->travelTo(Carbon::parse('2026-10-08T20:30:00Z'));
        $this->service()->start($nz, $this->manager, $this->guard($nz));
        $this->invalid(fn () => $this->service()->complete($nz, $this->manager, $this->guard($nz, ['completed_at' => '2026-10-09T09:29:59+13:00', 'outcome' => 'passed'])), 'completed_at');
        $this->travelTo(Carbon::parse('2026-10-08T20:32:00Z'));
        $this->service()->complete($nz, $this->manager, $this->guard($nz, ['completed_at' => '2026-10-08T16:31:00-04:00', 'outcome' => 'passed']));
        $this->assertSame('2026-10-08 20:31:00', $nz->fresh()->completed_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_cancellation_keeps_attributable_history_and_explicit_next_schedule_link(): void
    {
        $row = $this->create();
        $cancel = $this->guard($row, ['reason' => 'The site is unavailable.']);
        $this->service()->cancel($row, $this->manager, $cancel);
        $this->assertSame('The site is unavailable.', $row->fresh()->cancellation_reason);
        $this->assertSame($this->manager->id, (int) $row->fresh()->cancelled_by);
        $cancelledAt = $row->fresh()->cancelled_at;
        $next = $this->guard($row, $this->planning(['title' => 'Explicit next readiness drill']));
        $this->service()->scheduleNext($row, $this->manager, $next);
        $this->service()->scheduleNext($row, $this->manager, $next);
        $this->assertSame(2, EmergencyDrill::count());
        $this->assertDatabaseHas('emergency_drills', ['previous_drill_id' => $row->id, 'title' => 'Explicit next readiness drill', 'status' => 'scheduled']);
        $this->assertEquals($cancelledAt, $row->fresh()->cancelled_at);
        $this->assertSame(0, EmergencyDrillFailureDelivery::count());
    }

    public function test_replay_is_bound_to_actor_exact_parent_child_and_payload(): void
    {
        $row = $this->create();
        $other = $this->create();
        $first = $this->finding($row);
        $second = $this->finding($row);
        $data = $this->guard($row, ['description' => 'Record the actual changed obstruction.']);
        $this->service()->updateFinding($row, $first, $this->manager, $data);
        $this->service()->updateFinding($row, $first, $this->manager, $data);
        $this->invalid(fn () => $this->service()->updateFinding($row, $second, $this->manager, $data), 'idempotency_key');
        $this->invalid(fn () => $this->service()->updateFinding($row, $first, $this->worker($this->site), $data), 'idempotency_key');
        $this->invalid(fn () => $this->service()->update($other, $this->manager, $data), 'idempotency_key');
    }

    public static function revokedAuthority(): array
    {
        return [['inactive'], ['future'], ['ended'], ['unapproved'], ['legacy_client'], ['rbac_client'], ['legacy_next_of_kin'], ['rbac_next_of_kin'], ['external_clinician'], ['site'], ['manage_permission']];
    }

    #[DataProvider('revokedAuthority')]
    public function test_every_command_rechecks_canonical_current_staff_and_site(string $revocation): void
    {
        $row = $this->create();
        if ($revocation === 'inactive') {
            $this->manager->hrEmployeeProfile()->update(['is_active' => false]);
        } elseif ($revocation === 'future') {
            $this->manager->hrEmployeeProfile()->update(['start_date' => now()->addMonth()->toDateString()]);
        } elseif ($revocation === 'ended') {
            $this->manager->hrEmployeeProfile()->update(['end_date' => now()->subDay()->toDateString()]);
        } elseif ($revocation === 'unapproved') {
            $this->manager->update(['approved_at' => null]);
        } elseif (str_starts_with($revocation, 'legacy_')) {
            $this->manager->update(['role' => substr($revocation, 7)]);
        } elseif (str_starts_with($revocation, 'rbac_')) {
            $role = Role::where('name', substr($revocation, 5))->firstOrFail();
            $this->manager->roles()->attach($role);
        } elseif ($revocation === 'external_clinician') {
            $this->manager->update(['role' => 'external_clinician']);
        } elseif ($revocation === 'site') {
            $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->hiddenSite->id]);
        } else {
            $this->manager->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->firstOrFail()->id => ['allowed' => false]]);
        }
        try {
            $this->service()->start($row, $this->manager, $this->guard($row));
            $this->fail('Revoked authority must be denied.');
        } catch (HttpException $exception) {
            $this->assertContains($exception->getStatusCode(), [403, 404]);
        }
        $this->assertSame('scheduled', $row->fresh()->status);
        $this->assertSame(1, $row->fresh()->lock_version);
    }

    public function test_picker_and_direct_staff_ids_reject_foreign_site_and_inactive_people(): void
    {
        $row = $this->create();
        $hidden = $this->worker($this->hiddenSite);
        $inactive = $this->worker($this->site);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $options = app(EmergencyDrillAccessService::class)->pickerOptions($this->manager, $row);
        $this->assertNotContains($hidden->id, $options['staff']->pluck('id')->all());
        $this->assertNotContains($inactive->id, $options['staff']->pluck('id')->all());
        foreach ([$hidden, $inactive] as $candidate) {
            try {
                $this->service()->upsertParticipant($row, $this->manager, $this->guard($row, ['user_id' => $candidate->id, 'attended' => false]));
                $this->fail('Forged staff must be denied.');
            } catch (HttpException $exception) {
                $this->assertContains($exception->getStatusCode(), [403, 404]);
            }
        }
        $this->assertSame(1, $row->participants()->count());
    }

    public function test_real_failed_finding_links_once_to_canonical_action_before_writeup_and_resolves_only_after_independent_positive_verification(): void
    {
        $row = $this->create();
        $finding = $this->finding($row);
        $evidenceId = $this->evidence($row);
        $data = $this->guard($row, ['mode' => 'create', 'title' => 'Restore the exit route', 'description' => 'Remove the obstruction and inspect the exit.', 'assigned_to_user_id' => $this->manager->id, 'due_date' => now()->addWeek()->toDateString(), 'priority' => 'high']);
        $this->service()->createOrLinkCorrectiveAction($row, $finding, $this->manager, $data);
        $this->service()->createOrLinkCorrectiveAction($row, $finding, $this->manager, $data);
        $this->assertSame(1, HsCorrectiveAction::count());
        $action = HsCorrectiveAction::findOrFail($finding->fresh()->hs_corrective_action_id);
        $this->assertSame(EmergencyDrill::class, $action->hsEvent->source_type);
        $this->assertSame($row->id, (int) $action->hsEvent->source_id);
        $this->assertSame('scheduled', $row->fresh()->status);
        $this->invalid(fn () => $this->service()->resolveFinding($row, $finding, $this->manager, $this->guard($row, ['resolution_notes' => 'The exit is now clear.', 'resolution_evidence_ids' => [$evidenceId]])), 'hs_corrective_action_id');
        $central = app(HsCorrectiveActionService::class);
        $central->start($action, $this->manager->id);
        $central->complete($action, ['completion_notes' => 'The obstruction was removed and the exit inspected.', 'completed_by_user_id' => $this->manager->id]);
        $this->assertSame('open', $finding->fresh()->status);
        $verifier = $this->worker($this->site);
        $this->actingAs($verifier);
        $central->verify($action, ['verified_by_user_id' => $verifier->id, 'verification_notes' => 'Independently inspected the clear exit.', 'evidence_reviewed' => true, 'effectiveness_confirmed' => true]);
        $this->assertSame('open', $finding->fresh()->status);
        $this->actingAs($this->manager);
        $resolve = $this->guard($row, ['resolution_notes' => 'The exit is clear and the remedy was independently verified.', 'resolution_evidence_ids' => [$evidenceId]]);
        $this->service()->resolveFinding($row, $finding, $this->manager, $resolve);
        $this->service()->resolveFinding($row, $finding, $this->manager, $resolve);
        $this->assertSame('resolved', $finding->fresh()->status);
        $this->assertSame([$evidenceId], $finding->fresh()->resolution_evidence_ids);
        $this->assertSame(1, AuditLog::where('action', 'drill.finding_resolved')->count());
    }

    public function test_unlinked_observation_requires_note_and_local_available_supporting_evidence(): void
    {
        $row = $this->create();
        $finding = $this->finding($row);
        $this->invalid(fn () => $this->service()->resolveFinding($row, $finding, $this->manager, $this->guard($row, ['resolution_notes' => '   ', 'resolution_evidence_ids' => [123]])), 'resolution_notes');
        $other = $this->create();
        $wrong = $this->evidence($other);
        $this->invalid(fn () => $this->service()->resolveFinding($row, $finding, $this->manager, $this->guard($row, ['resolution_notes' => 'Exit condition inspected.', 'resolution_evidence_ids' => [$wrong]])), 'resolution_evidence_ids');
        $right = $this->evidence($row);
        $this->service()->resolveFinding($row, $finding, $this->manager, $this->guard($row, ['resolution_notes' => 'Exit condition inspected.', 'resolution_evidence_ids' => [$right]]));
        $this->assertSame('resolved', $finding->fresh()->status);
        $this->assertSame(0, HsCorrectiveAction::count());
    }

    public function test_strict_audit_failure_rolls_back_lifecycle_and_delivery_intent(): void
    {
        $row = $this->create();
        $this->service()->start($row, $this->manager, $this->guard($row));
        $before = $row->fresh()->getAttributes();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'drill.completed') {
                throw new RuntimeException('Strict completion audit unavailable');
            }
        });
        try {
            $this->service()->complete($row, $this->manager, $this->guard($row, ['completed_at' => now()->toISOString(), 'outcome' => 'failed']));
            $this->fail('Expected strict audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Strict completion audit unavailable', $exception->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(0, EmergencyDrillFailureDelivery::count());
    }

    public function test_upload_database_failure_cleans_new_blob_without_altering_source(): void
    {
        $row = $this->create();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'drill.evidence_added') {
                throw new RuntimeException('Evidence audit unavailable');
            }
        });
        try {
            $this->evidence($row);
            $this->fail('Expected evidence rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Evidence audit unavailable', $exception->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(0, $row->attachments()->count());
        $this->assertSame(1, $row->fresh()->lock_version);
    }

    public function test_finished_drill_evidence_is_retained_and_passed_outcome_does_not_create_failure_delivery(): void
    {
        $row = $this->create();
        $evidence = $this->evidence($row);
        $attachment = $row->attachments()->findOrFail($evidence);
        $this->service()->start($row, $this->manager, $this->guard($row));
        $this->service()->complete($row, $this->manager, $this->guard($row, ['completed_at' => now()->toISOString(), 'outcome' => 'passed']));
        try {
            $this->service()->removeEvidence($row, $attachment, $this->manager, $this->guard($row));
            $this->fail('Completed evidence cannot be removed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Storage::disk('private')->assertExists($attachment->path);
        $this->assertSame(0, EmergencyDrillFailureDelivery::count());
    }

    public function test_failure_delivery_keeps_event_and_signal_receipts_separate_and_recovers_without_duplicates(): void
    {
        $row = $this->create();
        $this->service()->start($row, $this->manager, $this->guard($row));
        $this->service()->complete($row, $this->manager, $this->guard($row, ['completed_at' => now()->toISOString(), 'outcome' => 'failed']));
        $intent = EmergencyDrillFailureDelivery::firstOrFail();
        $events = app(HsEventService::class);
        $signals = app(SignalProcessingService::class);
        $broken = \Mockery::mock(SignalProcessingService::class);
        $broken->shouldReceive('ingest')->once()->andThrow(new RuntimeException('Signal destination unavailable'));
        $delivery = new EmergencyDrillFailureDeliveryService($events, $broken);
        try {
            $delivery->deliver($intent->id);
            $this->fail('Expected signal delivery failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Signal destination unavailable', $exception->getMessage());
        }
        $this->assertSame('delivered', $intent->fresh()->event_status);
        $this->assertSame('failed', $intent->fresh()->signal_status);
        $this->assertSame('signal_delivery_failed', $intent->fresh()->failure_code);
        $this->assertNotNull($intent->fresh()->hs_event_id);
        $this->assertNull($intent->fresh()->signal_id);
        $this->assertSame('completed', $row->fresh()->status);
        $this->travel(31)->seconds();
        $delivery = new EmergencyDrillFailureDeliveryService($events, $signals);
        $delivery->recoverPending();
        $delivery->deliver($intent->id);
        $this->assertSame('delivered', $intent->fresh()->event_status);
        $this->assertSame('delivered', $intent->fresh()->signal_status);
        $this->assertSame(1, HsEvent::where('source_type', EmergencyDrill::class)->where('source_id', $row->id)->count());
        $this->assertSame(1, Signal::where('idempotency_key', $intent->signal_key)->count());
        $this->assertSame('open', HsEvent::findOrFail($intent->fresh()->hs_event_id)->status);
    }

    public function test_register_counts_and_csv_use_exact_authorized_sites_and_full_filter_population(): void
    {
        $shown = $this->create(['title' => 'Visible fire readiness']);
        $this->create(['title' => 'Other safe site drill', 'drill_type' => 'earthquake']);
        $hidden = EmergencyDrill::factory()->create(['site_id' => $this->hiddenSite->id, 'title' => 'Private foreign site drill', 'scheduled_at' => now()->addDay()]);
        $query = '?period=all&tab=scheduled&drill_type=fire&q=Visible';
        $this->get('/health-safety/drills'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('drills.data', 1)->where('drills.data.0.id', $shown->id)->where('tabCounts.all', 2)
            ->where('hero.live.scheduled', 2)->has('sites', 1)->where('can.schedule', true));
        $csv = $this->get('/health-safety/drills/export'.$query)->assertOk()->streamedContent();
        $this->assertStringContainsString('Visible fire readiness', $csv);
        $this->assertStringNotContainsString('Other safe site drill', $csv);
        $this->assertStringNotContainsString('Private foreign site drill', $csv);
        $this->get('/health-safety/drills?site_id='.$hidden->site_id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('drills.data', 0)->where('tabCounts.all', 0));
    }

    public function test_foreign_drill_and_child_ids_deny_page_commands_options_exports_and_private_download(): void
    {
        $shown = $this->create();
        $foreign = EmergencyDrill::factory()->create(['site_id' => $this->hiddenSite->id]);
        $foreignFinding = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $foreign->id]);
        $this->get('/health-safety/drills/'.$foreign->id)->assertNotFound();
        $this->get('/health-safety/drills?drill='.$foreign->id)->assertNotFound();
        $this->get('/health-safety/drills/'.$foreign->id.'/participants/export')->assertNotFound();
        $this->get('/health-safety/drills/'.$foreign->id.'/evidence/export')->assertNotFound();
        $this->getJson('/health-safety/drills/options?site_id='.$this->hiddenSite->id)->assertForbidden();
        $this->post('/health-safety/drills/'.$foreign->id.'/start', $this->guard($foreign))->assertNotFound();
        $this->put('/health-safety/drills/'.$shown->id.'/findings/'.$foreignFinding->id, $this->guard($shown, ['description' => 'Forged source']))->assertNotFound();
        $this->getJson('/health-safety/drills/'.$shown->id.'/findings/'.$foreignFinding->id.'/corrective-action-options')->assertNotFound();
        $owner = $this->worker($this->hiddenSite);
        $this->service()->addEvidence($foreign, $owner, $this->guard($foreign), UploadedFile::fake()->create('foreign-sensitive.pdf', 2, 'application/pdf'));
        $attachment = $foreign->attachments()->firstOrFail();
        $this->get('/health-safety/drills/'.$foreign->id.'/attachments/'.$attachment->id.'/download')->assertNotFound();
        $this->get('/health-safety/drills/'.$shown->id.'/attachments/'.$attachment->id.'/download')->assertNotFound();
        $this->assertSame('scheduled', $foreign->fresh()->status);
    }

    public function test_command_redirect_confirms_current_detail_and_exports_preserve_actual_participation_and_safe_download_links(): void
    {
        $shown = $this->create(['title' => '=source drill']);
        $this->post('/health-safety/drills/'.$shown->id.'/participants', $this->guard($shown, ['user_id' => $this->manager->id, 'attended' => true, 'role' => 'warden', 'notes' => '=formula text', 'accommodations' => 'Visual instruction support', 'section' => 'participants', 'action' => 'participant']))
            ->assertRedirect('/health-safety/drills/'.$shown->id.'?section=participants&action=add-participant');
        $this->getJson('/health-safety/drills/'.$shown->id)->assertOk()->assertJsonPath('detail.lock_version', 2)->assertJsonPath('detail.counts.attended', 1);
        $id = $this->evidence($shown);
        $attachment = $shown->attachments()->findOrFail($id);
        $participants = $this->get('/health-safety/drills/'.$shown->id.'/participants/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Attended', $participants);
        $this->assertStringContainsString('Visual instruction support', $participants);
        $this->assertStringContainsString("'=formula text", $participants);
        $evidence = $this->get('/health-safety/drills/'.$shown->id.'/evidence/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('/health-safety/drills/'.$shown->id.'/attachments/'.$id.'/download', $evidence);
        $this->assertStringNotContainsString($attachment->path, $evidence);
        $this->get('/health-safety/drills/'.$shown->id.'/attachments/'.$id.'/download')->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");
        $this->manager->hrEmployeeProfile()->update(['is_active' => false]);
        $this->get('/health-safety/drills/'.$shown->id.'/evidence/export')->assertForbidden();
        $this->get('/health-safety/drills/'.$shown->id.'/attachments/'.$id.'/download')->assertForbidden();
    }

    public function test_navigation_changes_do_not_reexecute_or_conflict_with_confirmed_business_payload(): void
    {
        $row = $this->create();
        $data = $this->guard($row, ['user_id' => $this->manager->id, 'attended' => true, 'notes' => 'Actual attendance', 'section' => 'participants', 'action' => 'add-participant']);
        $this->service()->upsertParticipant($row, $this->manager, $data);
        $replay = $this->service()->upsertParticipant($row, $this->manager, [...$data, 'section' => 'history', 'action' => 'edit-participant', 'participant' => $row->participants()->first()->id, 'return_to' => '/my-day']);
        $this->assertSame(2, $replay->lock_version);
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertSame(1, AuditLog::where('action', 'drill.participant_recorded')->count());
    }

    public function test_removed_or_missing_private_support_does_not_enable_or_confirm_finding_resolution(): void
    {
        $row = $this->create();
        $finding = $this->finding($row);
        $id = $this->evidence($row);
        $attachment = $row->attachments()->findOrFail($id);
        Storage::disk('private')->delete($attachment->path);
        $this->assertFalse(app(EmergencyDrillAccessService::class)->findingCan($row, $finding, $this->manager)['resolve']);
        $this->invalid(fn () => $this->service()->resolveFinding($row, $finding, $this->manager, $this->guard($row, ['resolution_notes' => 'Inspected', 'resolution_evidence_ids' => [$id]])), 'resolution_evidence_ids');
        $this->assertSame('open', $finding->fresh()->status);
    }

    public function test_finding_due_date_uses_nz_calendar_day_and_preserves_terminal_legacy_outcomes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T11:30:00Z'));
        $row = $this->create();
        $dueToday = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $row->id, 'due_date' => '2026-10-09']);
        $dueYesterday = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $row->id, 'due_date' => '2026-10-08']);
        $closed = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $row->id, 'due_date' => '2026-10-01', 'status' => 'closed']);
        $accepted = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $row->id, 'due_date' => '2026-10-01', 'status' => 'accepted']);
        $ids = EmergencyDrillFinding::overdue()->pluck('id')->all();
        $this->assertContains($dueYesterday->id, $ids);
        foreach ([$dueToday, $closed, $accepted] as $finding) {
            $this->assertNotContains($finding->id, $ids);
        }
        $this->assertSame(2, app(DrillComplianceService::class)->siteSummary($this->site->id)['open_findings']);
    }

    public function test_completed_failure_intent_retains_its_actual_actor_after_later_child_writes(): void
    {
        $row = $this->create();
        $this->service()->start($row, $this->manager, $this->guard($row));
        $this->service()->complete($row, $this->manager, $this->guard($row, ['completed_at' => now()->toISOString(), 'outcome' => 'failed']));
        $intent = EmergencyDrillFailureDelivery::firstOrFail();
        $other = $this->worker($this->site);
        $this->service()->addFinding($row, $other, $this->guard($row, ['finding_type' => 'observation', 'description' => 'Later actual observation', 'severity' => 'low']));
        $this->assertSame($other->id, (int) $row->fresh()->updated_by);
        $this->assertSame($this->manager->id, (int) $intent->fresh()->initiated_by_user_id);
    }

    public function test_http_evidence_retries_bind_exact_bytes_and_name_without_serializing_uploaded_file_objects(): void
    {
        $row = $this->create();
        $payload = $this->guard($row, ['kind' => 'inspection', 'notes' => 'Actual source inspection', 'section' => 'evidence']);
        $path = '/health-safety/drills/'.$row->id.'/attachments';
        $this->post($path, [...$payload, 'file' => UploadedFile::fake()->createWithContent('inspection.pdf', 'Actual inspection evidence')])->assertRedirect();
        $this->post($path, [...$payload, 'file' => UploadedFile::fake()->createWithContent('inspection.pdf', 'Actual inspection evidence')])->assertRedirect();
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertSame(1, $row->attachments()->count());
        $this->assertSame(1, AuditLog::where('action', 'drill.evidence_added')->count());
        $attachment = $row->attachments()->firstOrFail();
        $this->assertSame('Actual inspection evidence', Storage::disk('private')->get($attachment->path));
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->post($path, [...$payload, 'file' => UploadedFile::fake()->createWithContent('inspection.pdf', 'Different inspection evidence')])->assertSessionHasErrors('idempotency_key');
        $this->post($path, [...$payload, 'file' => UploadedFile::fake()->createWithContent('other-name.pdf', 'Actual inspection evidence')])->assertSessionHasErrors('idempotency_key');
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertSame(1, $row->attachments()->count());
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_command_history_preserves_typed_finding_calendar_days_after_the_source_changes(): void
    {
        config()->set('app.worker_timezone', 'Pacific/Auckland');
        $this->travelTo(Carbon::parse('2026-10-07T12:30:00Z'));
        $this->assertSame('2026-10-07', now()->utc()->toDateString());
        $this->assertSame('2026-10-08', now(config('app.worker_timezone'))->toDateString());
        $row = $this->create();
        $this->service()->addFinding($row, $this->manager, $this->guard($row, ['finding_type' => 'observation', 'description' => 'Actual initial observation', 'severity' => 'medium', 'due_date' => '2026-10-20']));
        $finding = $row->findings()->firstOrFail();
        $this->service()->updateFinding($row, $finding, $this->manager, $this->guard($row, ['description' => 'Actual updated observation', 'due_date' => '2026-10-21']));
        $id = $this->evidence($row);
        $resolution = $this->guard($row, ['resolution_notes' => 'Actual source inspection supports resolution.', 'resolution_evidence_ids' => [$id]]);
        $this->service()->resolveFinding($row, $finding, $this->manager, $resolution);
        $this->assertSame('2026-10-08', $finding->fresh()->resolved_at->toDateString());
        $confirmedVersion = $row->fresh()->lock_version;
        $this->travelTo(Carbon::parse('2026-10-08T12:30:00Z'));
        $this->service()->resolveFinding($row, $finding, $this->manager, $resolution);
        $this->assertSame($confirmedVersion, $row->fresh()->lock_version);
        $this->assertSame('2026-10-08', $finding->fresh()->resolved_at->toDateString());
        $finding->forceFill(['description' => 'Later legacy source text', 'due_date' => '2026-12-01'])->saveQuietly();
        $history = collect(app(EmergencyDrillPresenter::class)->detail($row, $this->manager)['history']);
        $added = $history->firstWhere('action', 'drill.finding_added');
        $updated = $history->firstWhere('action', 'drill.finding_updated');
        $resolved = $history->firstWhere('action', 'drill.finding_resolved');
        $this->assertSame('2026-10-20', $added['snapshot']['finding']['due_date']);
        $this->assertSame('Actual initial observation', $added['snapshot']['finding']['description']);
        $this->assertSame('2026-10-21', $updated['snapshot']['finding']['due_date']);
        $this->assertSame('2026-10-08', $resolved['snapshot']['finding']['resolved_at']);
        $this->assertSame([$id], $resolved['snapshot']['finding']['resolution_evidence_ids']);
        $this->assertSame($this->manager->id, $resolved['actor']['id']);
    }

    public function test_failed_event_delivery_never_claims_review_or_response_delivery(): void
    {
        $row = $this->create();
        $this->service()->start($row, $this->manager, $this->guard($row));
        $this->service()->complete($row, $this->manager, $this->guard($row, ['completed_at' => now()->toISOString(), 'outcome' => 'failed']));
        $intent = EmergencyDrillFailureDelivery::firstOrFail();
        $events = \Mockery::mock(HsEventService::class);
        $events->shouldReceive('recordEvent')->once()->andReturnNull();
        $signals = \Mockery::mock(SignalProcessingService::class);
        $signals->shouldNotReceive('ingest');
        $delivery = new EmergencyDrillFailureDeliveryService($events, $signals);
        try {
            $delivery->deliver($intent->id);
            $this->fail('Expected review delivery failure.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $intent->fresh()->event_status);
        }
        $this->assertSame('pending', $intent->fresh()->signal_status);
        $this->assertNull($intent->fresh()->event_delivered_at);
        $this->assertNull($intent->fresh()->signal_delivered_at);
        $this->assertNull($intent->fresh()->hs_event_id);
        $this->assertSame('completed', $row->fresh()->status);
    }
}
