<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceRevision;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\MaintenanceHistory;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\Monitoring\Services\MonitoringPolicyAuditWriter;
use App\Domain\Monitoring\Services\MonitoringPolicyAuthoringService;
use App\Domain\Monitoring\Services\MonitoringPolicyRules;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class MonitoringMaintenanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Site $site;

    private Monitor $monitor;

    private DeviceAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-20T08:00:00Z');
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $device = Device::factory()->itInfrastructure()->create();
        $this->assignment = DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subHour()]);
        $this->monitor = Monitor::factory()->create(['device_id' => $device->id, 'current_state' => MonitorState::Healthy, 'effective_state' => MonitorState::Healthy]);
        $this->monitor->profile->forceFill(['failure_confirmations' => 1, 'failure_duration_seconds' => 0])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $instant): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instant)->setTimezone(date_default_timezone_get()));
    }

    private function values(array $patch = []): array
    {
        return [...['site_id' => (int) $this->site->id, 'monitor_id' => (int) $this->monitor->id, 'name' => 'Synthetic historical maintenance',
            'starts_at' => '2026-10-20T09:00:00Z', 'ends_at' => '2026-10-20T12:00:00Z', 'timezone' => 'UTC',
            'reason' => 'Preserve synthetic occurrence-effective maintenance history.'], ...$patch];
    }

    private function create(): MonitoringMaintenanceWindow
    {
        $this->at('2026-10-20T09:00:00Z');

        return app(MonitoringPolicyAuthoringService::class)->createMaintenanceWindow($this->actor, $this->values());
    }

    private function resolve(string $instant): array
    {
        return app(MaintenanceHistory::class)->resolve($this->monitor->fresh(), CarbonImmutable::parse($instant));
    }

    private function ingest(string $key, string $instant): mixed
    {
        return app(MonitoringObservationIngestor::class)->ingest($this->monitor->fresh(), new ObservationInput($key, MonitorState::Failed, CarbonImmutable::parse($instant)), (int) $this->site->id, (int) $this->monitor->device_id, null);
    }

    public function test_edits_and_cancellation_preserve_prior_versions_and_exact_effective_boundaries(): void
    {
        $window = $this->create();
        $first = MonitoringMaintenanceRevision::query()->sole();
        $original = $first->getRawOriginal('snapshot');
        self::assertSame($this->actor->id, $first->actor_user_id);
        self::assertSame('none', $this->resolve('2026-10-20T08:59:59.999999Z')['state']);
        $this->at('2026-10-20T10:00:00Z');
        $window = app(MonitoringPolicyAuthoringService::class)->updateMaintenanceWindow($this->actor, $window, 1, ['starts_at' => '2026-10-20T10:30:00Z', 'reason' => 'Move the remaining synthetic window without changing earlier facts.']);
        self::assertSame(1, $this->resolve('2026-10-20T09:59:59.999999Z')['winner']['version']);
        self::assertSame('none', $this->resolve('2026-10-20T10:00:00Z')['state']);
        self::assertSame(2, $this->resolve('2026-10-20T10:30:00Z')['winner']['version']);
        $this->at('2026-10-20T11:00:00Z');
        app(MonitoringPolicyAuthoringService::class)->cancelMaintenanceWindow($this->actor, $window, 2, 'Cancel the remaining maintenance while retaining its earlier application.');
        self::assertSame(2, $this->resolve('2026-10-20T10:59:59.999999Z')['winner']['version']);
        self::assertSame('none', $this->resolve('2026-10-20T11:00:00Z')['state']);
        self::assertSame($original, $first->fresh()->getRawOriginal('snapshot'));
        $chain = MonitoringMaintenanceRevision::query()->orderBy('version')->get();
        self::assertSame(['create', 'update', 'cancel'], $chain->pluck('operation')->all());
        self::assertSame([null, $chain[0]->id, $chain[1]->id], $chain->pluck('supersedes_id')->all());
        self::assertSame('cancelled', $chain[2]->snapshot['state']);
        self::assertSame((int) $this->monitor->device_id, $chain[0]->snapshot['monitor_device_id']);
    }

    public function test_buffered_failure_uses_the_occurrence_revision_after_cancellation_and_preserves_accepted_evidence(): void
    {
        $window = $this->create();
        $this->at('2026-10-20T11:00:00Z');
        app(MonitoringPolicyAuthoringService::class)->cancelMaintenanceWindow($this->actor, $window, 1, 'The technical maintenance activity ended after this buffered observation.');
        $this->at('2026-10-20T11:30:00Z');
        $result = $this->ingest('synthetic:buffered:failure', '2026-10-20T09:30:00Z');
        self::assertSame(MonitorState::Suppressed, $result->to);
        $observation = $result->observation->fresh();
        self::assertSame(1, $observation->maintenance_policy_evidence['winner']['version']);
        self::assertSame('applied', $observation->maintenance_policy_evidence['state']);
        self::assertSame('2026-10-20T09:30:00.000000Z', $observation->maintenance_policy_evidence['observed_at']);
        self::assertNull($observation->maintenance_policy_evidence['clock_uncertainty_seconds']);
        self::assertArrayNotHasKey('maintenance_policy_evidence', $observation->toArray());
        self::assertSame(0, DeviceEvent::query()->where('device_id', $this->monitor->device_id)->count());
        $raw = $observation->getRawOriginal('maintenance_policy_evidence');
        $this->at('2026-10-20T11:31:00Z');
        $next = $this->ingest('synthetic:current:failure', '2026-10-20T11:31:00Z');
        self::assertSame(MonitorState::Failed, $next->to);
        self::assertSame(1, DeviceEvent::query()->where('device_id', $this->monitor->device_id)->where('event_type', 'offline')->count());
        self::assertSame($raw, $observation->fresh()->getRawOriginal('maintenance_policy_evidence'));
        $path = getenv('MAINTENANCE_HISTORY_EXPORT_PATH');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['synthetic' => true, 'hardware_acceptance' => false, 'original_database_evidence' => $raw, 'evidence_sha256' => hash('sha256', $raw), 'observation_id' => $observation->id, 'current_window_version' => 2, 'saved_occurrence_version' => 1], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }

    public function test_duplicates_and_out_of_order_observations_keep_original_attribution_without_rewinding_state(): void
    {
        $window = $this->create();
        $first = $this->ingest('synthetic:original', '2026-10-20T09:30:00Z');
        $raw = $first->observation->fresh()->getRawOriginal('maintenance_policy_evidence');
        $this->at('2026-10-20T10:00:00Z');
        app(MonitoringPolicyAuthoringService::class)->cancelMaintenanceWindow($this->actor, $window, 1, 'Close the synthetic window after the accepted source observation.');
        $duplicate = $this->ingest('synthetic:original', '2026-10-20T09:30:00Z');
        self::assertTrue($duplicate->duplicate);
        self::assertSame($raw, $duplicate->observation->fresh()->getRawOriginal('maintenance_policy_evidence'));
        $late = $this->ingest('synthetic:earlier', '2026-10-20T09:20:00Z');
        self::assertFalse($late->stateChanged);
        self::assertSame(1, $late->observation->maintenance_policy_evidence['winner']['version']);
        self::assertSame('2026-10-20T09:30:00+00:00', $this->monitor->fresh()->last_observation_at->utc()->toIso8601String());
    }

    public function test_legacy_baseline_is_known_only_from_review_and_unrecorded_changes_are_unavailable(): void
    {
        $window = MonitoringMaintenanceWindow::query()->create([...$this->values(), 'device_id' => null, 'recurrence' => null, 'recurrence_until' => null, 'policy' => 'suppress_notifications_and_ticketing', 'status' => 'active', 'version' => 7]);
        self::assertSame('legacy_rule_history_unavailable', $this->resolve('2026-10-20T09:30:00Z')['reason_code']);
        $this->at('2026-10-20T10:00:00Z');
        $window = app(MonitoringPolicyAuthoringService::class)->updateMaintenanceWindow($this->actor, $window, 7, ['reason' => 'Review the legacy baseline from this instant without backdating it.']);
        self::assertSame(['baseline', 'update'], MonitoringMaintenanceRevision::query()->orderBy('version')->pluck('operation')->all());
        self::assertSame('observation_before_reviewed_baseline', $this->resolve('2026-10-20T09:30:00Z')['reason_code']);
        self::assertSame(8, $this->resolve('2026-10-20T10:30:00Z')['winner']['version']);
        $window->forceFill(['name' => 'Unrecorded synthetic edit'])->save();
        self::assertSame('current_rule_history_diverged', $this->resolve('2026-10-20T10:30:00Z')['reason_code']);
        $this->expectException(ValidationException::class);
        app(MonitoringPolicyAuthoringService::class)->updateMaintenanceWindow($this->actor, $window, 8, ['reason' => 'An unrecorded source cannot silently become a trusted earlier revision.']);
    }

    public function test_missing_history_and_unverified_assignment_never_suppress_a_failure(): void
    {
        $window = $this->create();
        DB::table('monitoring_policy_history_epochs')->where('owner', 'maintenance')->update(['started_at' => '2026-10-20 10:00:00']);
        self::assertSame('observation_before_history_tracking', $this->resolve('2026-10-20T09:30:00Z')['reason_code']);
        self::assertSame(MonitorState::Failed, $this->ingest('synthetic:before:epoch', '2026-10-20T09:30:00Z')->to);
        DB::table('monitoring_policy_history_epochs')->where('owner', 'maintenance')->update(['started_at' => '2026-10-12 00:00:00']);
        $this->assignment->forceFill(['created_at' => CarbonImmutable::parse('2026-10-20T10:00:00Z')])->save();
        self::assertSame('occurrence_assignment_unverified', $this->resolve('2026-10-20T09:30:00Z')['reason_code']);
        self::assertNull($this->resolve('2026-10-20T09:30:00Z')['winner']);
    }

    public function test_current_source_reassignment_denies_replay_without_rewriting_old_observation_evidence(): void
    {
        $this->create();
        $original = $this->ingest('synthetic:site:original', '2026-10-20T09:30:00Z')->observation->fresh();
        $raw = $original->getRawOriginal('maintenance_policy_evidence');
        $this->at('2026-10-20T10:00:00Z');
        $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->assignment->forceFill(['released_at' => now()])->save();
        DeviceAssignment::query()->create(['device_id' => $this->monitor->device_id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $other->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
        try {
            $this->ingest('synthetic:site:original', '2026-10-20T09:30:00Z');
            self::fail('Historical maintenance must not restore a withdrawn canonical Site binding.');
        } catch (RuntimeSiteScopeViolation) {
            self::assertSame($raw, $original->fresh()->getRawOriginal('maintenance_policy_evidence'));
            self::assertSame(1, MonitorObservation::query()->where('monitor_id', $this->monitor->id)->count());
        }
    }

    public function test_audit_failure_and_backwards_clock_roll_back_revisions_with_the_window(): void
    {
        $audit = Mockery::mock(MonitoringPolicyAuditWriter::class);
        $audit->shouldReceive('write')->once()->andThrow(new RuntimeException('synthetic audit unavailable'));
        $service = new MonitoringPolicyAuthoringService(app(MonitoringPolicyRules::class), app(CanonicalDeviceSiteResolver::class), $audit);
        try {
            $service->createMaintenanceWindow($this->actor, $this->values());
            self::fail('Audit failure must roll back history.');
        } catch (RuntimeException $error) {
            self::assertSame('synthetic audit unavailable', $error->getMessage());
        }
        self::assertSame(0, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(0, MonitoringMaintenanceRevision::query()->count());
        $window = $this->create();
        $this->at('2026-10-20T08:59:59Z');
        try {
            app(MonitoringPolicyAuthoringService::class)->cancelMaintenanceWindow($this->actor, $window, 1, 'A backwards application clock cannot reorder accepted revisions.');
            self::fail('Reject a backwards revision clock.');
        } catch (ValidationException) {
            self::assertSame(1, $window->fresh()->version);
        }
        self::assertSame(1, MonitoringMaintenanceRevision::query()->count());
        self::assertSame(0, AuditLog::query()->where('action', 'monitoring.maintenance.cancelled')->count());
    }

    public function test_revision_and_observation_evidence_are_immutable_through_model_and_bulk_writes(): void
    {
        $this->create();
        $revision = MonitoringMaintenanceRevision::query()->sole();
        $observation = $this->ingest('synthetic:immutable', '2026-10-20T09:30:00Z')->observation;
        foreach ([fn () => $revision->forceFill(['change_reason' => 'Changed reason'])->saveQuietly(), fn () => $revision->deleteQuietly(),
            fn () => MonitoringMaintenanceRevision::query()->whereKey($revision->id)->update(['snapshot_hash' => str_repeat('0', 64)]),
            fn () => MonitoringMaintenanceRevision::query()->whereKey($revision->id)->delete(), fn () => MonitoringMaintenanceRevision::query()->truncate(),
            fn () => $observation->forceFill(['maintenance_policy_evidence' => []])->saveQuietly(),
            fn () => MonitorObservation::query()->whereKey($observation->id)->update(['maintenance_policy_evidence' => null])] as $action) {
            try {
                $action();
                self::fail('Immutable evidence must reject this ORM mutation.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1, MonitoringMaintenanceRevision::query()->count());
        self::assertSame('applied', $observation->fresh()->maintenance_policy_evidence['state']);
        $migration = require base_path('database/migrations/2026_10_12_150000_add_monitoring_maintenance_history.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_same_instant_revisions_use_the_version_chain_and_detect_corrupted_snapshots(): void
    {
        $window = $this->create();
        app(MonitoringPolicyAuthoringService::class)->updateMaintenanceWindow($this->actor, $window, 1, ['reason' => 'A same-instant correction retains one explicit superseding order.']);
        self::assertSame(2, $this->resolve('2026-10-20T09:00:00Z')['winner']['version']);
        DB::table('monitoring_maintenance_revisions')->where('window_id', $window->id)->where('version', 1)->update(['snapshot_hash' => str_repeat('0', 64)]);
        self::assertSame('revision_chain_unverified', $this->resolve('2026-10-20T09:30:00Z')['reason_code']);
        self::assertNull($this->resolve('2026-10-20T09:30:00Z')['winner']);
    }
}
