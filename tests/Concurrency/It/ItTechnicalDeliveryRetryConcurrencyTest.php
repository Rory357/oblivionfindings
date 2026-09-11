<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTechnicalDeliveryRecoveryService;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ItTechnicalDeliveryRetryConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || getenv('DB_HOST') !== '127.0.0.1' || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1) {
            throw new RuntimeException('Use the isolated IT test wrapper for retry concurrency.');
        }

        return parent::createApplication();
    }

    public function test_competing_reviewed_retries_and_permission_loss_recheck_under_the_outbox_lock(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('oblivion_it_support_test_'.getenv('TEST_TOKEN'), DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
        foreach (['device', 'fleet'] as $source) {
            foreach ([false, true] as $revoke) {
                [$actor, $role, $row] = $this->fixture($source);
                $review = app(ItTechnicalDeliveryRecoveryService::class)->review($actor, (int) $actor->id, $source, (int) $row->id);
                $prefix = storage_path('framework/testing/'.getenv('TEST_TOKEN').'-technical-retry-'.Str::uuid());
                $ready = [$prefix.'-0.ready', $prefix.'-1.ready'];
                $workers = [];
                $action = 'it.'.($source === 'device' ? 'monitoring' : 'fleet').'.delivery_retry_requested';
                $before = AuditLog::query()->where('action', $action)->count();
                try {
                    DB::beginTransaction();
                    $row->newQuery()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                    foreach ($ready as $path) {
                        $worker = new Process([PHP_BINARY, base_path('tests/Support/It/technical-delivery-retry-worker.php'),
                            $source, (string) $row->id, (string) $actor->id, $review['version'], $path], base_path(), timeout: 60);
                        $worker->start();
                        $workers[] = $worker;
                    }
                    $deadline = microtime(true) + 40;
                    while (count(array_filter($ready, 'is_file')) !== 2) {
                        foreach ($workers as $worker) {
                            if (! $worker->isRunning()) {
                                throw new RuntimeException('Retry worker stopped before its lock barrier: '.$worker->getErrorOutput());
                            }
                        }
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Retry lock barrier timed out.');
                        }
                        usleep(10_000);
                    }
                    // Both processes completed their preliminary review and are
                    // attempting the exact lock still held by this transaction.
                    if ($revoke) {
                        $role->permissions()->detach(Permission::query()->where('key', 'it.manage')->value('id'));
                    }
                    DB::commit();
                    $statuses = [];
                    foreach ($workers as $worker) {
                        $worker->wait();
                        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                        $this->assertTrue($result['waiting']);
                        $this->assertSame(0, $result['transaction_level']);
                        $this->assertSame($result['status'] === 200, $result['retry_requested']);
                        $statuses[] = $result['status'];
                    }
                    sort($statuses);
                    $this->assertSame($revoke ? [403, 403] : [200, 409], $statuses);
                    $this->assertSame($revoke ? 'dead_letter' : 'pending', $row->fresh()->it_status);
                    $this->assertSame(8, $row->fresh()->it_attempts);
                    $this->assertSame($revoke ? 8 : 9, $row->fresh()->it_attempt_limit);
                    $this->assertSame($before + ($revoke ? 0 : 1), AuditLog::query()->where('action', $action)->count());
                } finally {
                    while (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    foreach ($workers as $worker) {
                        if ($worker->isRunning()) {
                            $worker->stop(1);
                        }
                    }
                    foreach ($ready as $path) {
                        if (is_file($path)) {
                            unlink($path);
                        }
                        $this->assertFileDoesNotExist($path);
                    }
                }
            }
        }
    }

    /** @return array{User, Role, Model} */
    private function fixture(string $source): array
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'technical-retry-'.Str::uuid(), 'label' => 'Synthetic retry operator', 'level' => 50, 'type' => 'custom']);
        foreach (['it.manage', 'securityDevices.devices.view', 'assets.viewAny'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
            $role->permissions()->attach($permission->id);
        }
        $actor->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null]);
        $device = Device::factory()->create(['domain' => $source === 'device' ? 'it_infrastructure' : 'tracking']);
        if ($source === 'device') {
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(), 'assigned_by_user_id' => $actor->id]);
            $event = DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create(['device_id' => $device->id, 'event_type' => 'offline',
                'severity' => 'high', 'source' => 'oblivion_monitoring', 'occurred_at' => now(), 'payload' => []]));
            $row = new DeviceEventSignalOutbox(['device_event_id' => $event->id]);
        } else {
            $asset = Asset::factory()->vehicle()->create(['site_id' => $site->id, 'home_site_id' => null, 'client_id' => null]);
            DeviceAssetLink::query()->create(['device_id' => $device->id, 'asset_id' => $asset->id, 'link_type' => LinkType::InstalledIn, 'linked_at' => now()->subHour()]);
            $signal = FleetSignal::query()->create(['device_id' => $device->id, 'asset_id' => $asset->id, 'signal_type' => 'device.offline',
                'severity_hint' => 'medium', 'occurred_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'payload' => []]);
            $row = new FleetSignalOutbox(['fleet_signal_id' => $signal->id]);
        }
        $row->forceFill(['status' => 'sent', 'it_status' => 'dead_letter', 'it_scope' => ['site_id' => (int) $site->id], 'it_attempts' => 8, 'it_attempt_limit' => 8])->save();

        return [$actor->fresh(), $role, $row];
    }
}
