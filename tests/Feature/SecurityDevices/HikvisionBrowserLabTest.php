<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryAuthority;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionLiveContract;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Explicit isolated laboratory owner only; no rollback transaction hides seeds
 * from the real HTTP processes. TestCase owns this unique schema and cleanup. */
#[Group('hikvision-browser-lab')]
final class HikvisionBrowserLabTest extends TestCase
{
    public function test_original_browser_central_collector_native_laboratory(): void
    {
        $root = getenv('OBLI_HIKVISION_BROWSER_LAB');
        if (! is_string($root) || $root === '') {
            self::markTestSkipped('Explicit isolated original-browser laboratory only.');
        }
        self::assertMatchesRegularExpression('/\Aoblivion_findings_codex_test_hiklab[0-9]{6}_[0-9]+\z/', config('database.connections.mysql.database'));
        file_put_contents($root.'/database-ready.json', json_encode(['owner_pid' => getmypid(), 'database' => config('database.connections.mysql.database')], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 2400;
        while (! is_file($root.'/native-metadata.json') && microtime(true) < $deadline) {
            usleep(200000);
        }
        self::assertFileExists($root.'/native-metadata.json');
        $native = json_decode(file_get_contents($root.'/native-metadata.json'), true, 16, JSON_THROW_ON_ERROR);
        $private = json_decode(file_get_contents($root.'/native-private.json'), true, 8, JSON_THROW_ON_ERROR);
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $site = Site::factory()->create(['name' => 'Synthetic camera laboratory']);
        $camera = Device::factory()->security()->create(['name' => 'Synthetic entry camera', 'category' => 'cctv', 'subcategory' => 'dome_camera', 'provider' => 'hikvision', 'ip_address' => $native['camera']['allowed_ip'], 'serial_number' => 'fixture-camera-serial', 'model' => 'DS-2CD2347G3-LIS2UY/SL']);
        $computer = Device::factory()->itInfrastructure()->create(['name' => 'Synthetic Site collector']);
        foreach ([$camera, $computer] as $device) {
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
        }
        $serverKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $collectorKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $collector = MonitoringCollector::factory()->create(['name' => 'Synthetic Site collector', 'site_id' => $site->id, 'collector_device_id' => $computer->id, 'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($collectorKey)), 'client_certificate_fingerprint' => $native['client_fingerprint'], 'enrolled_at' => now()]);
        $scope = DiscoveryScope::factory()->create(['name' => 'Approved synthetic TLS camera', 'site_id' => $site->id, 'collector_id' => $collector->id, 'cidrs' => ['127.0.0.1/32'], 'port_bounds' => ['tcp' => [$native['camera']['allowed_port']]], 'protocols' => ['tls']]);
        $reference = CredentialReference::query()->create(['reference_key' => 'vault:synthetic/hikvision', 'site_id' => $site->id, 'provider' => 'hikvision', 'purpose' => 'cctv_read_only', 'capabilities' => [HikvisionDiscoveryAuthority::CAPABILITY, HikvisionLiveContract::CAPABILITY], 'secret_manager_reference' => 'synthetic-camera-only', 'secret_manager_reference_hash' => hash('sha256', 'synthetic-camera-only'), 'status' => 'active', 'rotation_status' => 'current', 'test_status' => 'passed', 'version' => 1]);
        $users = [];
        foreach (['manager', 'viewer'] as $kind) {
            $user = User::factory()->create(['name' => 'Synthetic camera '.$kind, 'email' => 'hik-lab-'.$kind.'@example.test', 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
            HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'is_active' => true]);
            $role = Role::query()->create(['name' => 'synthetic_hik_browser_'.$kind, 'label' => 'Synthetic camera '.$kind, 'level' => 20, 'type' => 'custom']);
            $permissions = ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.cctv.media.view'];
            if ($kind === 'manager') {
                $permissions[] = 'securityDevices.integrations.manage';
                $permissions[] = 'securityDevices.devices.update';
            }
            $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id')->all());
            $user->roles()->attach($role);
            $users[$kind] = ['id' => (int) $user->id, 'email' => $user->email];
        }
        $runtime = ['database' => config('database.connections.mysql.database'), 'app_key' => config('app.key'), 'server_secret_key' => base64_encode($serverKey), 'server_public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($serverKey)), 'collector_secret_key' => base64_encode($collectorKey), 'collector_id' => $collector->collector_uuid, 'site_id' => (int) $site->id, 'native' => $native, 'private' => $private, 'users' => $users, 'camera_id' => (int) $camera->id, 'scope_id' => (int) $scope->id, 'reference_id' => (int) $reference->id];
        file_put_contents($root.'/runtime.json', json_encode($runtime, JSON_THROW_ON_ERROR));
        file_put_contents($root.'/public.json', json_encode(['database_owner' => getmypid(), 'backend_origin' => $native['backend_origin'], 'camera' => $camera->id, 'users' => $users, 'camera_transport' => $native['camera'], 'collector_name' => $collector->name, 'scope_name' => $scope->name, 'reference_name' => $reference->reference_key], JSON_THROW_ON_ERROR));
        while (! is_file($root.'/complete.json') && microtime(true) < $deadline) {
            usleep(200000);
        }
        self::assertFileExists($root.'/complete.json', 'Owned children must be closed before schema shutdown.');
        $done = json_decode(file_get_contents($root.'/complete.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($done['children_closed'] ?? false);
        self::assertTrue($done['browser_passed'] ?? false);
        self::assertFileExists($root.'/native-closed.json');
        $nativeClosed = json_decode(file_get_contents($root.'/native-closed.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($nativeClosed['native_closed'] ?? false);
        self::assertGreaterThanOrEqual(2, $nativeClosed['rtsp_digest_requests'] ?? 0);
        self::assertSame('verified', CctvSource::query()->where('camera_id', $camera->id)->firstOrFail()->stream_state);
        $sessions = CctvLiveSession::query()->where('camera_id', $camera->id)->get();
        self::assertGreaterThanOrEqual(1, $sessions->count());
        foreach ($sessions as $session) {
            self::assertContains($session->status, ['stopped', 'expired', 'revoked']);
            self::assertNull($session->material);
            self::assertNull($session->worker_grant);
            $receipt = CctvRequestReceipt::query()->where('owner', 'hikvision_live_close:'.$collector->id)->where('request_key', $session->uuid)->first();
            self::assertNotNull($receipt);
            self::assertIsArray($receipt->response);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $receipt->response['result_sha256'] ?? '');
            self::assertContains($receipt->response['status'] ?? null, ['stopped', 'expired']);
            self::assertNotEmpty($receipt->response['acknowledged_at'] ?? null);
        }
        self::assertSame(0, DB::transactionLevel());
    }
}
