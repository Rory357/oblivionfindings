<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceCommandReceipt;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Services\MonitoringPolicyAuditWriter;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use UnexpectedValueException;

final class MonitoringMaintenanceReceiptsTest extends TestCase
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
        $this->actor = $this->administrator();
        $this->actingAs($this->actor);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->monitor = Monitor::factory()->create(['device_id' => $this->device()->id]);
    }

    private function administrator(): User
    {
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $actor;
    }

    private function device(): Device
    {
        $device = Device::factory()->itInfrastructure()->create();
        DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);

        return $device;
    }

    private function input(array $changes = []): array
    {
        return [...[
            'expected_actor_id' => (int) $this->actor->id, 'request_uuid' => (string) Str::uuid(),
            'site_id' => (int) $this->site->id, 'monitor_id' => (int) $this->monitor->id, 'device_id' => null,
            'name' => 'Synthetic immutable maintenance', 'starts_at' => '2026-09-26T09:00:00+12:00', 'ends_at' => '2026-09-26T10:00:00+12:00',
            'recurrence' => null, 'recurrence_until' => null, 'timezone' => 'Pacific/Auckland', 'dst_overlap' => 'reject', 'dst_gap' => 'reject',
            'reason' => 'Review a synthetic technical maintenance action.',
        ], ...$changes];
    }

    private function reader(string $uuid, ?int $actor = null): string
    {
        return $this->base.'/requests/'.$uuid.'?expected_actor_id='.($actor ?? $this->actor->id).'&site_id='.$this->site->id;
    }

    public function test_original_create_reply_loss_replays_one_receipt_and_current_primary_reader(): void
    {
        $body = $this->input();
        $first = $this->postJson($this->base, $body)->assertCreated();
        $bytes = $first->getContent();
        $this->postJson($this->base, array_reverse($body, true))->assertCreated()->assertContent($bytes);
        $lookup = $this->getJson($this->reader($body['request_uuid']))->assertOk()->assertContent($bytes);
        self::assertTrue($lookup->headers->hasCacheControlDirective('private'));
        self::assertTrue($lookup->headers->hasCacheControlDirective('no-store'));
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.created')->count());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.command_committed')->count());
        $receipt = MonitoringMaintenanceCommandReceipt::query()->firstOrFail();
        self::assertSame('2026-09-25T21:00:00+00:00', $receipt->after_snapshot['starts_at']);
        self::assertSame((int) $this->monitor->device_id, $receipt->after_scope['monitor_device_id']);
        $path = getenv('MAINTENANCE_RECEIPT_EXPORT_PATH');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['schema_version' => 1, 'synthetic' => true, 'hardware_acceptance' => false,
                'operation' => 'create', 'target_window_id' => null, 'body' => $body, 'response_body' => $bytes,
                'response_sha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }

    public function test_exact_update_and_cancel_replay_precede_stale_version_and_preserve_original_snapshot(): void
    {
        $create = $this->input();
        $first = $this->postJson($this->base, $create)->assertCreated()->json();
        $id = $first['window']['id'];
        $update = ['expected_actor_id' => $this->actor->id, 'request_uuid' => (string) Str::uuid(), 'version' => 1, 'reason' => 'The approved technical plan was corrected.'];
        $saved = $this->patchJson($this->base.'/'.$id, $update)->assertOk()->assertJsonPath('window.version', 2)->getContent();
        $this->patchJson($this->base.'/'.$id, $update)->assertOk()->assertContent($saved);
        $cancel = ['expected_actor_id' => $this->actor->id, 'request_uuid' => (string) Str::uuid(), 'version' => 2, 'reason' => 'The technical work was cancelled by its owner.'];
        $cancelled = $this->postJson($this->base.'/'.$id.'/cancel', $cancel)->assertOk()->assertJsonPath('window.state', 'cancelled')->getContent();
        $this->postJson($this->base.'/'.$id.'/cancel', $cancel)->assertOk()->assertContent($cancelled);
        $this->getJson($this->reader($create['request_uuid']))->assertOk()->assertJsonPath('window.version', 1)->assertJsonPath('window.state', 'active')->assertJsonPath('current_window.version', 3)->assertJsonPath('current_window.state', 'cancelled');
        $receipt = MonitoringMaintenanceCommandReceipt::query()->where('request_uuid', $create['request_uuid'])->firstOrFail();
        self::assertSame($create['reason'], $receipt->after_snapshot['reason']);
        self::assertSame(3, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.updated')->count());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.cancelled')->count());
        $this->patchJson($this->base.'/'.$id, [...$update, 'request_uuid' => (string) Str::uuid()])->assertConflict();
        self::assertSame(3, MonitoringMaintenanceCommandReceipt::query()->count());
    }

    public function test_a_request_identity_cannot_change_actor_input_action_or_target(): void
    {
        $body = $this->input();
        $id = $this->postJson($this->base, $body)->assertCreated()->json('window.id');
        $this->postJson($this->base, [...$body, 'reason' => 'Different intent must use a new reviewed identity.'])->assertConflict();
        $this->postJson($this->base, [...$body, 'expected_actor_id' => $this->actor->id + 1])->assertNotFound();
        $this->postJson($this->base.'/'.$id.'/cancel', ['request_uuid' => $body['request_uuid'], 'expected_actor_id' => $this->actor->id, 'version' => 1, 'reason' => 'A create identity cannot cancel the window.'])->assertConflict();
        $other = $this->administrator();
        $this->actingAs($other)->postJson($this->base, [...$body, 'expected_actor_id' => $other->id])->assertNotFound();
        $this->getJson($this->reader($body['request_uuid'], (int) $other->id))->assertNotFound();
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
    }

    public function test_missing_identity_extra_fields_and_queries_do_not_create_receipts(): void
    {
        $body = $this->input();
        $missing = $body;
        unset($missing['request_uuid']);
        $this->postJson($this->base, $missing)->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $missing = $body;
        unset($missing['expected_actor_id']);
        $this->postJson($this->base, $missing)->assertUnprocessable()->assertJsonValidationErrors('expected_actor_id');
        $this->postJson($this->base, [...$body, 'transport_url' => 'https://unapproved.invalid'])->assertUnprocessable();
        $this->postJson($this->base.'?foreign=1', $body)->assertStatus(400);
        $this->postJson($this->base.'/preview', $body)->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $this->getJson($this->reader((string) Str::uuid()))->assertNotFound();
        $this->getJson($this->reader((string) Str::uuid()).'&foreign=1')->assertStatus(400);
        self::assertSame(0, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertSame(0, MonitoringMaintenanceWindow::query()->count());
    }

    public function test_current_role_loss_denies_original_replay_and_recovery(): void
    {
        $body = $this->input();
        $this->postJson($this->base, $body)->assertCreated();
        self::assertTrue($this->actor->canDo('securityDevices.monitoring.manage'));
        $this->actor->roles()->detach();
        self::assertContains($this->postJson($this->base, $body)->status(), [403, 404]);
        self::assertContains($this->getJson($this->reader($body['request_uuid']))->status(), [403, 404]);
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
    }

    public function test_a_changed_monitor_device_binding_cannot_reuse_original_receipt(): void
    {
        $body = $this->input();
        $this->postJson($this->base, $body)->assertCreated();
        $replacement = $this->device();
        DB::table('monitors')->where('id', $this->monitor->id)->update(['device_id' => $replacement->id]);
        $this->postJson($this->base, $body)->assertNotFound();
        $this->getJson($this->reader($body['request_uuid']))->assertNotFound();
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
    }

    public function test_command_audit_failure_rolls_back_window_and_receipt_then_original_retry_commits_once(): void
    {
        $writer = new class extends MonitoringPolicyAuditWriter
        {
            public bool $unavailable = true;

            public function write(string $action, Model $policy, User $actor, array $safeContext): void
            {
                if ($this->unavailable && $action === 'monitoring.maintenance.command_committed') {
                    throw new \RuntimeException('synthetic command audit unavailable');
                }
                parent::write($action, $policy, $actor, $safeContext);
            }
        };
        $this->app->instance(MonitoringPolicyAuditWriter::class, $writer);
        $body = $this->input();
        $this->postJson($this->base, $body)->assertStatus(500);
        self::assertSame(0, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(0, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertSame(0, AuditLog::query()->where('action', 'like', 'monitoring.maintenance.%')->count());
        $writer->unavailable = false;
        $this->postJson($this->base, $body)->assertCreated();
        $this->postJson($this->base, $body)->assertCreated();
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.command_committed')->count());
    }

    public function test_saved_receipt_is_immutable_and_downgrade_refuses_to_erase_bindings(): void
    {
        $body = $this->input();
        $this->postJson($this->base, $body)->assertCreated();
        $receipt = MonitoringMaintenanceCommandReceipt::query()->firstOrFail();
        foreach (['change', 'delete'] as $action) {
            try {
                if ($action === 'change') {
                    $receipt->forceFill(['request_hash' => str_repeat('0', 64)])->save();
                } else {
                    $receipt->delete();
                }
                self::fail('Saved receipt mutation must be denied.');
            } catch (UnexpectedValueException) {
                $receipt->refresh();
            }
        }
        $migration = require base_path('database/migrations/2026_10_12_130000_add_monitoring_maintenance_command_receipts.php');
        try {
            $migration->down();
            self::fail('A downgrade must retain committed command bindings.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Retain original maintenance', $error->getMessage());
        }
        self::assertTrue(Schema::hasTable('monitoring_maintenance_command_receipts'));
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
        $this->getJson($this->reader($body['request_uuid']))->assertOk();
    }

    public function test_approved_site_withdrawal_and_wrong_recovery_site_deny_saved_receipt(): void
    {
        $body = $this->input();
        $this->postJson($this->base, $body)->assertCreated();
        $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->getJson($this->base.'/requests/'.$body['request_uuid'].'?expected_actor_id='.$this->actor->id.'&site_id='.$other->id)->assertNotFound();
        $this->site->forceFill(['is_active' => false, 'archived' => true, 'archived_at' => now()])->save();
        self::assertContains($this->postJson($this->base, $body)->status(), [403, 404]);
        self::assertContains($this->getJson($this->reader($body['request_uuid']))->status(), [403, 404]);
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
    }

    public function test_an_update_identity_cannot_move_to_another_window_target(): void
    {
        $first = $this->postJson($this->base, $this->input())->assertCreated()->json('window.id');
        $second = $this->postJson($this->base, $this->input(['starts_at' => '2026-09-26T11:00:00+12:00', 'ends_at' => '2026-09-26T12:00:00+12:00']))->assertCreated()->json('window.id');
        $update = ['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $this->actor->id, 'version' => 1, 'reason' => 'Apply the reviewed correction to its original window.'];
        $this->patchJson($this->base.'/'.$first, $update)->assertOk();
        $this->patchJson($this->base.'/'.$second, $update)->assertConflict();
        self::assertSame(1, MonitoringMaintenanceWindow::query()->findOrFail($second)->version);
        self::assertSame(3, MonitoringMaintenanceCommandReceipt::query()->count());
    }

    public function test_a_site_manager_can_save_and_recover_until_the_current_site_grant_is_withdrawn(): void
    {
        $manager = User::factory()->create(['approved_at' => now()]);
        $profile = HrEmployeeProfile::factory()->create(['user_id' => $manager->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.monitoring.manage'] as $key) {
            $permission = Permission::query()->where('key', $key)->firstOrFail();
            $manager->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }
        $body = $this->input(['expected_actor_id' => (int) $manager->id]);
        $this->actingAs($manager)->postJson($this->base, $body)->assertCreated();
        $this->getJson($this->reader($body['request_uuid'], (int) $manager->id))->assertOk();
        self::assertTrue($manager->canDo('securityDevices.monitoring.manage'));
        $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $profile->forceFill(['primary_site_id' => $other->id])->save();
        self::assertContains($this->postJson($this->base, $body)->status(), [403, 404]);
        self::assertContains($this->getJson($this->reader($body['request_uuid'], (int) $manager->id))->status(), [403, 404]);
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->count());
    }

    public function test_middleware_padded_create_edit_and_cancel_keep_one_canonical_recoverable_intent(): void
    {
        $cases = [];
        $create = $this->input(['name' => "\u{200B} \t Synthetic padded maintenance \u{00A0}", 'reason' => " \n Review a padded technical plan. \u{200B}", 'device_id' => " \t", 'recurrence' => ' ', 'recurrence_until' => "\r\n", 'site_id' => ' '.$this->site->id.' ', 'monitor_id' => ' '.$this->monitor->id.' ']);
        $first = $this->postJson($this->base, $create)->assertCreated();
        $id = $first->json('window.id');
        $this->postJson($this->base, $create)->assertCreated()->assertContent($first->getContent());
        $this->getJson($this->reader($create['request_uuid']))->assertOk()->assertContent($first->getContent());
        self::assertSame('Synthetic padded maintenance', MonitoringMaintenanceWindow::query()->findOrFail($id)->name);
        $cases[] = ['operation' => 'create', 'target_window_id' => null, 'body' => $create, 'response_body' => $first->getContent(), 'response_sha256' => hash('sha256', $first->getContent())];
        $update = ['expected_actor_id' => $this->actor->id, 'request_uuid' => (string) Str::uuid(), 'version' => 1, 'name' => "\u{2003} Corrected padded maintenance \u{FEFF}", 'reason' => " \t Apply the reviewed correction. \u{200B}", 'device_id' => ' ', 'recurrence' => "\t", 'recurrence_until' => ' '];
        $edited = $this->patchJson($this->base.'/'.$id, $update)->assertOk()->assertJsonPath('window.version', 2);
        $this->patchJson($this->base.'/'.$id, $update)->assertOk()->assertContent($edited->getContent());
        $this->getJson($this->reader($update['request_uuid']))->assertOk()->assertContent($edited->getContent());
        $cases[] = ['operation' => 'update', 'target_window_id' => $id, 'body' => $update, 'response_body' => $edited->getContent(), 'response_sha256' => hash('sha256', $edited->getContent())];
        $cancel = ['expected_actor_id' => $this->actor->id, 'request_uuid' => (string) Str::uuid(), 'version' => 2, 'reason' => "\u{00A0} Cancel the approved technical activity. \u{200B}"];
        $cancelled = $this->postJson($this->base.'/'.$id.'/cancel', $cancel)->assertOk()->assertJsonPath('window.state', 'cancelled');
        $this->postJson($this->base.'/'.$id.'/cancel', $cancel)->assertOk()->assertContent($cancelled->getContent());
        $this->getJson($this->reader($cancel['request_uuid']))->assertOk()->assertContent($cancelled->getContent());
        $cases[] = ['operation' => 'cancel', 'target_window_id' => $id, 'body' => $cancel, 'response_body' => $cancelled->getContent(), 'response_sha256' => hash('sha256', $cancelled->getContent())];
        $this->postJson($this->base.'/'.$id.'/cancel', [...$cancel, 'reason' => 'A different cancellation must not reuse the identity.'])->assertConflict();
        self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
        self::assertSame(3, MonitoringMaintenanceCommandReceipt::query()->count());
        self::assertSame(3, AuditLog::query()->where('action', 'monitoring.maintenance.command_committed')->count());
        $path = getenv('MAINTENANCE_PADDED_EXPORT_PATH');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['schema_version' => 1, 'synthetic' => true, 'hardware_acceptance' => false, 'cases' => $cases], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }
}
