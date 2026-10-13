<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceCommandReceipt;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Services\MaintenanceEvaluator;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\Monitoring\Services\MonitoringPolicyAuthoringService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MonitoringMaintenanceOverlapTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Site $site;

    private Monitor $monitor;

    private string $base = '/security-devices/settings/monitoring/maintenance';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->actingAs($this->actor);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $device = Device::factory()->itInfrastructure()->create();
        DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
        $this->monitor = Monitor::factory()->create(['device_id' => $device->id, 'current_state' => MonitorState::Healthy, 'effective_state' => MonitorState::Healthy]);
        $this->monitor->profile->forceFill(['failure_confirmations' => 1, 'failure_duration_seconds' => 0])->save();
    }

    private function input(array $changes = []): array
    {
        return [...['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => (int) $this->actor->id, 'site_id' => (int) $this->site->id, 'monitor_id' => (int) $this->monitor->id, 'device_id' => null, 'name' => 'Synthetic overlap review', 'reason' => 'Review synthetic technical maintenance scope and timing.', 'starts_at' => '2026-10-20T09:00:00+13:00', 'ends_at' => '2026-10-20T10:00:00+13:00', 'timezone' => 'Pacific/Auckland', 'dst_overlap' => 'reject', 'dst_gap' => 'reject', 'recurrence' => null, 'recurrence_until' => null], ...$changes];
    }

    public function test_same_scope_overlap_rolls_back_the_reserved_receipt_and_keeps_original_replay(): void
    {
        $first = $this->input();
        $original = $this->postJson($this->base, $first)->assertCreated()->getContent();
        $second = $this->input(['starts_at' => '2026-10-20T09:30:00+13:00', 'ends_at' => '2026-10-20T11:00:00+13:00']);
        $reply = $this->postJson($this->base, $second)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertFalse(MonitoringMaintenanceCommandReceipt::query()->where('request_uuid', $second['request_uuid'])->exists());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.created')->count());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.command_committed')->count());
        self::assertSame($original, $this->postJson($this->base, $first)->assertCreated()->getContent());
        $path = getenv('MAINTENANCE_OVERLAP_EXPORT_PATH');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['synthetic' => true, 'hardware_acceptance' => false, 'status' => 422, 'response_body' => $reply->getContent(), 'response_sha256' => hash('sha256', $reply->getContent()), 'existing_reply_body' => $original, 'body' => $second], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }

    public function test_conflicting_update_does_not_change_version_schedule_receipt_or_audit(): void
    {
        $first = $this->postJson($this->base, $this->input())->assertCreated()->json('window.id');
        $second = $this->postJson($this->base, $this->input(['starts_at' => '2026-10-20T10:00:00+13:00', 'ends_at' => '2026-10-20T11:00:00+13:00']))->assertCreated()->json('window.id');
        $body = $this->input(['version' => 1, 'starts_at' => '2026-10-20T09:45:00+13:00']);
        $this->patchJson($this->base.'/'.$second, $body)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        self::assertSame(1, MonitoringMaintenanceWindow::query()->findOrFail($second)->version);
        self::assertSame('2026-10-19T21:00:00+00:00', MonitoringMaintenanceWindow::query()->findOrFail($second)->starts_at->utc()->toIso8601String());
        self::assertSame(2, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertSame(0, AuditLog::query()->where('action', 'monitoring.maintenance.updated')->count());
        $this->patchJson($this->base.'/'.$first, ['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $this->actor->id, 'version' => 1, 'reason' => 'A reason-only correction excludes the same canonical window.'])->assertOk()->assertJsonPath('window.version', 2);
    }

    public function test_more_specific_scope_overrides_and_cancellation_reveals_the_documented_fallback(): void
    {
        $site = $this->postJson($this->base, $this->input(['monitor_id' => null]))->assertCreated()->json('window.id');
        $device = $this->postJson($this->base, $this->input(['monitor_id' => null, 'device_id' => $this->monitor->device_id]))->assertCreated()->json('window.id');
        $monitor = $this->postJson($this->base, $this->input())->assertCreated()->json('window.id');
        $at = CarbonImmutable::parse('2026-10-20T09:30:00+13:00');
        $resolved = app(MaintenanceEvaluator::class)->resolve($this->monitor, $at);
        self::assertSame('applied', $resolved['state']);
        self::assertSame('monitor', $resolved['specificity']);
        self::assertSame($monitor, $resolved['window']->id);
        self::assertCount(2, $resolved['overridden']);
        foreach ([$monitor, $device] as $id) {
            $this->postJson($this->base.'/'.$id.'/cancel', ['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $this->actor->id, 'version' => 1, 'reason' => 'The synthetic scoped maintenance rule was withdrawn.'])->assertOk();
            $next = app(MaintenanceEvaluator::class)->resolve($this->monitor, $at);
            self::assertSame($id === $monitor ? $device : $site, $next['window']->id);
            self::assertSame($id === $monitor ? 'device' : 'site', $next['specificity']);
        }
        self::assertNull(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, CarbonImmutable::parse('2026-10-20T10:00:00+13:00')));
    }

    public function test_legacy_equal_priority_rules_are_an_explicit_conflict_and_cannot_suppress_a_failure(): void
    {
        $service = app(MonitoringPolicyAuthoringService::class);
        $body = $this->input();
        unset($body['request_uuid'], $body['expected_actor_id']);
        $first = $service->createMaintenanceWindow($this->actor, $body);
        $second = MonitoringMaintenanceWindow::query()->create([...$first->only($first->getFillable()), 'name' => 'Synthetic pre-existing conflicting rule']);
        $at = CarbonImmutable::parse('2026-10-20T09:30:00+13:00');
        $resolved = app(MaintenanceEvaluator::class)->resolve($this->monitor, $at);
        self::assertSame('conflict', $resolved['state']);
        self::assertSame('monitor', $resolved['specificity']);
        self::assertNull($resolved['window']);
        self::assertCount(2, $resolved['matching']);
        self::assertNull(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, $at));
        $result = app(MonitoringObservationIngestor::class)->ingest($this->monitor, new ObservationInput('synthetic:overlap:failure', MonitorState::Failed, $at), (int) $this->site->id, (int) $this->monitor->device_id, null);
        self::assertSame(MonitorState::Failed, $result->to);
        self::assertSame(MonitorState::Failed, $this->monitor->fresh()->effective_state);
        self::assertNull($this->monitor->fresh()->suppression_reason);
        self::assertSame(1, DeviceEvent::query()->where('device_id', $this->monitor->device_id)->where('event_type', 'offline')->count());
        $second->forceFill(['status' => 'cancelled'])->save();
        self::assertSame($first->id, app(MaintenanceEvaluator::class)->activeWindow($this->monitor, $at)->id);
    }

    public function test_recurring_later_overlap_is_denied_while_different_scopes_adjacent_occurrences_and_cancelled_windows_are_allowed(): void
    {
        $body = $this->input(['starts_at' => '2026-09-26T09:00:00+12:00', 'ends_at' => '2026-09-26T10:00:00+12:00', 'recurrence' => 'daily', 'recurrence_until' => '2026-10-03T09:30:00+13:00']);
        $id = $this->postJson($this->base, $body)->assertCreated()->json('window.id');
        $later = $this->input(['starts_at' => '2026-10-02T09:30:00+13:00', 'ends_at' => '2026-10-02T10:30:00+13:00']);
        $this->postJson($this->base, $later)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson($this->base, $this->input(['starts_at' => '2026-10-03T09:30:00+13:00', 'ends_at' => '2026-10-03T10:30:00+13:00']))->assertCreated();
        $this->postJson($this->base, $this->input(['monitor_id' => null, 'starts_at' => '2026-10-02T09:30:00+13:00', 'ends_at' => '2026-10-02T10:30:00+13:00']))->assertCreated();
        $this->postJson($this->base.'/'.$id.'/cancel', ['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $this->actor->id, 'version' => 1, 'reason' => 'Release this synthetic recurring slot after cancellation.'])->assertOk();
        $this->postJson($this->base, $later)->assertCreated();
    }

    public function test_internal_owner_callers_cannot_bypass_conflict_validation_and_unresolved_legacy_series_blocks_saving(): void
    {
        $body = $this->input(['recurrence' => 'daily', 'recurrence_until' => '2026-10-25T11:00:00+13:00']);
        unset($body['request_uuid'], $body['expected_actor_id']);
        $service = app(MonitoringPolicyAuthoringService::class);
        $window = $service->createMaintenanceWindow($this->actor, $body);
        DB::table('monitoring_maintenance_windows')->where('id', $window->id)->update(['recurrence_until' => null]);
        try {
            $service->createMaintenanceWindow($this->actor, [...$body, 'starts_at' => '2026-10-21T09:00:00+13:00', 'ends_at' => '2026-10-21T10:00:00+13:00']);
            self::fail('An unresolved legacy rule must not be accepted as disjoint.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('unresolved timing', $error->errors()['starts_at'][0]);
        }
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.created')->count());
    }
}
