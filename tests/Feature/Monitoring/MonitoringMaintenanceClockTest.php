<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Services\MaintenanceEvaluator;
use App\Domain\Monitoring\Services\MonitoringPolicyAuditWriter;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MonitoringMaintenanceClockTest extends TestCase
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
        $this->monitor = Monitor::factory()->create(['device_id' => $device->id]);
    }

    private function input(array $changes = []): array
    {
        return [...[
            'expected_actor_id' => (int) $this->actor->id, 'site_id' => (int) $this->site->id, 'device_id' => null, 'monitor_id' => (int) $this->monitor->id,
            'name' => 'Synthetic network maintenance', 'starts_at' => '2026-09-26T09:00', 'ends_at' => '2026-09-26T10:00', 'recurrence' => 'daily', 'recurrence_until' => '2026-10-03T10:00', 'timezone' => 'Pacific/Auckland', 'dst_overlap' => 'reject', 'dst_gap' => 'reject', 'reason' => 'Review a synthetic technical maintenance schedule.',
        ], ...$changes];
    }

    private function commandBody(array $input): array
    {
        return [...$input, 'expected_actor_id' => (int) $this->actor->id, 'request_uuid' => (string) Str::uuid()];
    }

    public function test_maintenance_clock_preview_is_read_only_and_real_save_retains_the_reviewed_timezone(): void
    {
        $preview = $this->postJson($this->base.'/preview', $this->input())->assertOk();
        self::assertTrue($preview->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($preview->headers->hasCacheControlDirective('private'));
        $data = $preview->json('preview');
        self::assertSame((int) $this->actor->id, $data['actor_id']);
        self::assertFalse($data['commits_change']);
        self::assertSame('seconds', $data['precision']);
        self::assertSame('2026-09-25T21:00:00+00:00', $data['occurrences'][0]['starts_at']);
        self::assertSame('2026-09-26T20:00:00+00:00', $data['occurrences'][1]['starts_at']);
        self::assertSame(0, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(0, AuditLog::query()->where('action', 'like', 'monitoring.maintenance.%')->count());
        $path = getenv('MAINTENANCE_CLOCK_EXPORT_PATH');
        if (is_string($path) && $path !== '') {
            $originalBody = $this->input(['starts_at' => '2026-09-26T09:00:00+12:00', 'ends_at' => '2026-09-26T10:00:00+12:00', 'recurrence_until' => '2026-10-03T10:00:00+13:00']);
            $originalReply = $this->postJson($this->base.'/preview', $originalBody)->assertOk()->getContent();
            file_put_contents($path, json_encode(['schema_version' => 1, 'synthetic' => true, 'hardware_acceptance' => false, 'body' => $originalBody, 'response_body' => $originalReply, 'response_sha256' => hash('sha256', $originalReply)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }

        $created = $this->postJson($this->base, $this->commandBody($this->input()))->assertCreated()->json('window');
        $window = MonitoringMaintenanceWindow::query()->findOrFail($created['id']);
        self::assertSame('2026-09-25T21:00:00+00:00', $window->starts_at->utc()->toIso8601String());
        self::assertSame(1, $created['version']);
        self::assertSame('reject', $window->dst_overlap);
        self::assertTrue(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, CarbonImmutable::parse('2026-09-27T09:30:00+13:00'))?->is($window) ?? false);
        self::assertNull(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, CarbonImmutable::parse('2026-10-03T10:00:00+13:00')));
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.created')->count());
    }

    public function test_maintenance_clock_gap_requires_an_explicit_skip_and_does_not_suppress_at_a_shifted_time(): void
    {
        $body = $this->input(['starts_at' => '2026-09-26T02:15', 'ends_at' => '2026-09-26T03:45']);
        $this->postJson($this->base.'/preview', $body)->assertUnprocessable()->assertJsonValidationErrors('dst_gap');
        $this->postJson($this->base, $this->commandBody($body))->assertUnprocessable();
        self::assertSame(0, MonitoringMaintenanceWindow::query()->count());
        $body['dst_gap'] = 'skip';
        $preview = $this->postJson($this->base.'/preview', $body)->assertOk()->json('preview');
        self::assertSame(1, $preview['skipped_occurrences']);
        self::assertSame('skipped', $preview['occurrences'][1]['state']);
        $id = $this->postJson($this->base, $this->commandBody($body))->assertCreated()->json('window.id');
        self::assertSame('skip', MonitoringMaintenanceWindow::query()->findOrFail($id)->dst_gap);
        self::assertNull(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, CarbonImmutable::parse('2026-09-27T03:30:00+13:00')));
        self::assertNotNull(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, CarbonImmutable::parse('2026-09-28T02:30:00+13:00')));
    }

    public function test_maintenance_clock_repeated_hour_selects_one_occurrence_and_keeps_the_versioned_choice_on_partial_edit(): void
    {
        $body = $this->input(['starts_at' => '2026-04-04T02:15', 'ends_at' => '2026-04-04T02:45', 'recurrence_until' => '2026-04-07T03:00']);
        $this->postJson($this->base.'/preview', $body)->assertUnprocessable()->assertJsonValidationErrors('dst_overlap');
        $body['dst_overlap'] = 'later';
        $id = $this->postJson($this->base, $this->commandBody($body))->assertCreated()->json('window.id');
        self::assertNull(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, CarbonImmutable::parse('2026-04-05T02:30:00+13:00')));
        self::assertNotNull(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, CarbonImmutable::parse('2026-04-05T02:30:00+12:00')));
        $this->patchJson($this->base.'/'.$id, $this->commandBody(['version' => 1, 'reason' => 'Retain the reviewed clock choice during a reason correction.']))->assertOk()->assertJsonPath('window.version', 2);
        self::assertSame('later', MonitoringMaintenanceWindow::query()->findOrFail($id)->dst_overlap);
        $this->patchJson($this->base.'/'.$id, $this->commandBody(['version' => 1, 'reason' => 'A stale edit must not replace the reviewed schedule.']))->assertConflict();
        self::assertSame(2, MonitoringMaintenanceWindow::query()->findOrFail($id)->version);
        $this->postJson($this->base.'/'.$id.'/cancel', $this->commandBody(['version' => 2, 'reason' => 'The approved technical activity was cancelled.']))->assertOk()->assertJsonPath('window.state', 'cancelled');
        self::assertNull(app(MaintenanceEvaluator::class)->activeWindow($this->monitor, CarbonImmutable::parse('2026-04-05T02:30:00+12:00')));
    }

    public function test_maintenance_clock_preview_and_write_have_actor_scope_current_permission_and_precision_denials(): void
    {
        $this->postJson($this->base.'/preview', $this->input(['expected_actor_id' => $this->actor->id + 1]))->assertNotFound();
        $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->postJson($this->base.'/preview', $this->input(['site_id' => (int) $other->id]))->assertNotFound();
        $this->postJson($this->base.'/preview', $this->input(['starts_at' => '2026-09-26T09:00:00.123+12:00']))->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson($this->base.'/preview?foreign=1', $this->input())->assertStatus(400);
        self::assertTrue($this->actor->canDo('securityDevices.monitoring.manage'));
        $this->actor->roles()->detach();
        self::assertContains($this->postJson($this->base.'/preview', $this->input())->status(), [403, 404]);
        self::assertContains($this->postJson($this->base, $this->commandBody($this->input()))->status(), [403, 404]);
        self::assertSame(0, MonitoringMaintenanceWindow::query()->count());
    }

    public function test_maintenance_clock_strict_audit_failure_rolls_back_and_original_retry_commits_once(): void
    {
        $writer = new class extends MonitoringPolicyAuditWriter
        {
            public bool $unavailable = true;

            public function write(string $action, Model $policy, User $actor, array $safeContext): void
            {
                if ($this->unavailable) {
                    throw new \RuntimeException('synthetic audit unavailable');
                }
                parent::write($action, $policy, $actor, $safeContext);
            }
        };
        $this->app->instance(MonitoringPolicyAuditWriter::class, $writer);
        $originalBody = $this->commandBody($this->input());
        $this->postJson($this->base, $originalBody)->assertStatus(500);
        self::assertSame(0, MonitoringMaintenanceWindow::query()->count());
        $writer->unavailable = false;
        $this->postJson($this->base, $originalBody)->assertCreated();
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.created')->count());
    }
}
