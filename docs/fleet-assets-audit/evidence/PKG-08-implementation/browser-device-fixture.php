<?php
// Opt-in synthetic fixture extension; never boot TestCase against the already-running preview database.
$root = dirname(__DIR__, 4);
$environment = json_decode(file_get_contents(__DIR__.'/browser-environment.json'), true, 512, JSON_THROW_ON_ERROR);
if (($environment['APP_ENV'] ?? '') !== 'testing' || ! str_starts_with($environment['DB_DATABASE'] ?? '', 'of_pkg08_browser_20260928_')) throw new RuntimeException('Unexpected fixture database');
foreach ($environment as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Notification::fake();
$actor = \App\Models\User::where('email', 'pkg08.coordinator@example.test')->sole();
$site = \App\Models\Site::where('name', 'Settings test site')->sole();
$foreign = \App\Models\Site::firstOrCreate(['name' => 'Settings inaccessible fixture site'], ['is_active' => true, 'archived' => false]);
foreach ([['pkg08-visible', 'Synthetic site tracker', $site], ['pkg08-foreign', 'Synthetic inaccessible tracker', $foreign]] as [$uid, $name, $owner]) {
    $device = \App\Domain\SecurityDevices\Models\Device::firstOrCreate(['device_uid' => $uid], ['name' => $name, 'domain' => 'tracking', 'category' => 'vehicle_tracker', 'model' => 'Fixture tracker model', 'firmware_version' => 'test-1.0', 'status' => 'active', 'health_status' => 'healthy', 'provider' => 'manual', 'last_seen_at' => now()]);
    \App\Domain\SecurityDevices\Models\DeviceAssignment::firstOrCreate(['device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $owner->id], ['custody_site_id' => $owner->id, 'assignment_type' => 'permanent', 'assigned_at' => now(), 'assigned_by_user_id' => $actor->id]);
}
$request = \Illuminate\Http\Request::create('/fleet-assets/settings/tracking-devices');
$request->setUserResolver(fn () => $actor);
$data = app(\App\Http\Controllers\FleetAssets\WorkspaceSettingsController::class)->devices($request, app(\App\Domain\SecurityDevices\Services\SecurityDevicesAccessService::class))->toArray();
if ($data['total'] !== 1 || $data['data'][0]['name'] !== 'Synthetic site tracker') throw new RuntimeException('Canonical device visibility mismatch');
if (array_diff(array_keys($data['data'][0]), ['id', 'name', 'model', 'firmware', 'lastContact', 'href'])) throw new RuntimeException('Unexpected device projection');
file_put_contents(__DIR__.'/device-scope-check.json', json_encode(['permitted' => 1, 'foreignSiteExcluded' => true, 'fields' => array_keys($data['data'][0]), 'syntheticOnly' => true], JSON_PRETTY_PRINT));
echo 'Synthetic device visibility passed; foreign Site excluded and no position/assignment projection.';
