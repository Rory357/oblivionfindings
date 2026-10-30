<?php

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetCustodyMovement;
use App\Models\AssetProfileEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Assets\AssetProfilePresenter;
use App\Services\Assets\AssetProfileService;
use App\Services\Assets\AssetQrLabelService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Further verification against the live synthetic fixture, rolled back after each run.
$root = dirname(__DIR__, 5);
chdir($root);
if (PHP_SAPI !== 'cli' || is_file('.env') || is_file('.env.testing') || is_file('bootstrap/cache/config.php')) {
    throw new RuntimeException('Unsafe verification environment');
}
$fixture = json_decode(file_get_contents('storage/framework/pkg06b-browser.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^oblivion_findings_pkg06b_8821_test_\d+$/', $fixture['database'])) {
    throw new RuntimeException('Unexpected fixture database');
}
$xml = simplexml_load_file('phpunit.pkg06b.xml');
foreach ($xml->php->env as $entry) {
    $name = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
foreach (['DB_DATABASE' => $fixture['database'], 'APP_URL' => 'http://127.0.0.1:8905'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.private.root' => $fixture['storage']]);
Storage::forgetDisk('private');
$actor = User::findOrFail($fixture['user']);
Auth::login($actor);
$checks = [];
$check = function (bool $condition, string $label) use (&$checks) {
    if (! $condition) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
DB::beginTransaction();
try {
    $asset = Asset::findOrFail($fixture['asset']);
    $origin = $asset->site_id;
    $sites = app(SecurityDevicesAccessService::class)->accessibleSiteIds($actor);
    $destination = collect($sites)->first(fn ($id) => $id !== $origin);
    $component = Asset::factory()->forSite(Site::findOrFail($origin))->create(['category' => 'equipment', 'status' => 'active', 'home_site_id' => $origin, 'client_id' => $asset->client_id]);
    $service = app(AssetProfileService::class);
    $command = function (string $action, array $values = [], ?Asset $target = null) use ($actor, $asset, $service) {
        $target ??= $asset;

        return $service->command($actor, $target, $values + ['action' => $action, 'reason' => 'Synthetic regression verification', 'request_key' => (string) Str::uuid(), 'expected_version' => $target->fresh()->asset_profile_version]);
    };
    $command('kit_add', ['component_asset_id' => $component->id, 'name' => 'Linked component']);
    $dispatch = $command('dispatch', ['kind' => 'transfer', 'destination_site_id' => $destination, 'recipient_user_id' => $actor->id]);
    $check($asset->fresh()->site_id === $origin && $component->fresh()->site_id === $origin, 'Dispatch preserves canonical parent and component placement');
    try {
        $command('dispatch', ['kind' => 'transfer', 'destination_site_id' => $destination, 'recipient_user_id' => $actor->id], $component);
        throw new RuntimeException('Component moved independently');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 409, 'A linked component cannot dispatch independently');
    }
    $movement = AssetCustodyMovement::findOrFail($dispatch['movement_id']);
    $command('receive', ['movement_id' => $movement->id, 'outcome' => 'acknowledged', 'received_kit' => array_column($movement->kit_snapshot, 'id')]);
    $check($asset->fresh()->site_id === $destination && $component->fresh()->site_id === $destination, 'Acknowledged kit receipt moves parent and component atomically');
    $check(AssetProfileEvent::where('asset_id', $component->id)->where('action', 'kit_receipt')->count() === 1, 'Component history retains the kit receipt');
    $check(in_array('Remove or reassign the current kit items.', $service->retirementBlockers($asset->fresh()), true), 'Retirement is blocked by current kit contents');
    $legacyPhoto = $asset->documents()->where('mime_type', 'image/jpeg')->firstOrFail();
    try {
        $command('set_photo', ['document_id' => $legacyPhoto->id]);
        throw new RuntimeException('Unverified image selected');
    } catch (ModelNotFoundException) {
        $check(true, 'Legacy unverified images cannot become profile photos');
    }
    $check(app(AssetProfilePresenter::class)->present($actor, $asset->fresh())['documents']->contains(fn ($file) => $file['state'] === 'legacy_unverified'), 'Presenter retains explicit legacy scan state');
    $artifact = $root.'/docs/fleet-assets-audit/evidence/PKG-06B/implementation';
    config(['filesystems.disks.public.root' => $fixture['storage'].'/branding-verification']);
    Storage::forgetDisk('public');
    $brandingSource = file_get_contents('docs/fleet-assets-audit/previews/PKG-06B/v9/asset-branding.ts');
    if (! preg_match('/data:image\/png;base64,([A-Za-z0-9+\/=]+)/', $brandingSource, $logoMatch)) {
        throw new RuntimeException('Synthetic branding fixture missing');
    }
    Storage::disk('public')->put('sample-logo.png', base64_decode($logoMatch[1], true));
    AppSetting::updateOrCreate(['key' => 'branding.logo_path'], ['value' => 'sample-logo.png']);
    AppSetting::updateOrCreate(['key' => 'branding.name'], ['value' => 'Synthetic branding verification']);
    $labels = app(AssetQrLabelService::class);
    foreach ([['format' => 'a4', 'copies' => 18, 'offset' => 0], ['format' => 'a4', 'copies' => 2, 'offset' => 17], ['format' => 'custom', 'copies' => 2, 'width' => 60, 'height' => 50]] as $index => $options) {
        $pdf = $labels->pdf($asset->fresh(), $options);
        $check(str_starts_with($pdf, '%PDF-') && strlen($pdf) > 1000, 'Real QR label PDF variant '.($index + 1));
        file_put_contents($artifact.'/labels-'.($index + 1).'.pdf', $pdf);
    }
    file_put_contents($artifact.'/fixture-regression.json', json_encode(['checks' => $checks, 'count' => count($checks)], JSON_PRETTY_PRINT));
    echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    DB::rollBack();
}
