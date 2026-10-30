<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetKitItem;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Local-only verification fixture. Owns one PID-suffixed database and removes it at exit.
// No live environment file or database is read. Keep this process alive while previewing.
$root = dirname(__DIR__, 5);
chdir($root);
foreach (['.env', '.env.testing', 'bootstrap/cache/config.php'] as $path) {
    if (is_file($path)) {
        throw new RuntimeException('Unexpected environment file: '.$path);
    }
}
$xml = simplexml_load_file('phpunit.pkg06b.xml');
foreach ($xml->php->env as $entry) {
    $name = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
foreach (['TEST_TOKEN', 'PARALLEL_PROCESS', 'PROCESS_TOKEN'] as $name) {
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}
require 'vendor/autoload.php';
class Pkg06bBrowserFixture extends TestCase
{
    public function test_fixture(): void {}

    // Several independent worktrees are bootstrapping at once. Avoid the CLI loader's
    // five-minute timeout; retain the same isolated schema and parent cleanup boundary.
    protected function loadSchemaDumpIntoTestingDatabase(string $host, string $port, string $username, string $password, string $database): bool
    {
        return $this->loadSchemaDumpViaPdo(getcwd().'/database/schema/mysql-schema.sql', $host, $port, $username, $password, $database);
    }
}
$fixture = new Pkg06bBrowserFixture('testFixture');
$app = $fixture->createApplication();
$db = config('database.connections.mysql.database');
if (! preg_match('/^oblivion_findings_pkg06b_8821_test_\d+$/', $db)) {
    throw new RuntimeException('Unexpected database');
}
Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
$site = Site::factory()->create(['name' => 'Kōwhai House · verification', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
$other = Site::factory()->create(['name' => 'Rimu House · verification', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
$user = User::factory()->create(['name' => 'Nia Patel · verification', 'email' => 'pkg06b-verification@example.invalid', 'approved_at' => now(), 'email_verified_at' => now()]);
HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [$other->id], 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
foreach (['assets.viewAny', 'assets.update', 'assets.assignments.manage', 'assets.documents.manage', 'assets.inspections.record', 'assets.scan.record', 'assets.delete', 'staff.viewAny', 'hazards.view', 'fleet.maintenance.report', 'fleet.viewAny'] as $key) {
    $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'assets']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
}
$asset = Asset::factory()->forSite($site)->create(['name' => 'Transfer hoist', 'asset_tag' => 'AS-104', 'category' => 'equipment', 'status' => 'active', 'home_site_id' => $site->id, 'manufacturer' => 'Illustrative equipment', 'model' => 'TH-200', 'serial_number' => 'SAMPLE-104', 'location' => 'Equipment room', 'description' => 'Synthetic equipment record for implementation verification.', 'risk_level' => 'medium', 'requires_inspection' => true, 'inspection_due_at' => today()->addWeek()]);
$asset->forceFill(['condition' => 'worn'])->save();
AssetKitItem::create(['asset_id' => $asset->id, 'name' => 'Sling · sample', 'added_by_user_id' => $user->id]);
AssetKitItem::create(['asset_id' => $asset->id, 'name' => 'Charging cable · sample', 'added_by_user_id' => $user->id]);
$storageRoot = storage_path('app/pkg06b-browser-'.getmypid());
config(['filesystems.disks.private.root' => $storageRoot]);
Storage::forgetDisk('private');
foreach (['transfer-hoist-manual.pdf' => ['Manual', 'Transfer hoist manual'], 'warranty-104.pdf' => ['Warranty', 'Warranty · sample'], 'brake-assessment.jpg' => ['Photo', 'Brake assessment · sample']] as $filename => [$category, $title]) {
    $bytes = file_get_contents('docs/fleet-assets-audit/previews/PKG-06B/v9/files/'.$filename);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    Storage::disk('private')->put('documents/'.$filename, $bytes);
    AssetDocument::create(['asset_id' => $asset->id, 'uploaded_by_user_id' => $user->id, 'title' => $title, 'category' => $category, 'storage_disk' => 'private', 'storage_path' => 'documents/'.$filename, 'original_name' => $filename, 'mime_type' => $mime, 'detected_mime' => $mime, 'size_bytes' => strlen($bytes), 'state' => 'legacy_unverified']);
}
$asset->inspections()->create(['inspected_by_user_id' => $user->id, 'inspected_at' => now()->subDays(2), 'result' => 'needs_followup', 'notes' => 'Synthetic observation: inspect brake assembly before next use.']);
file_put_contents(storage_path('framework/pkg06b-browser.json'), json_encode(['database' => $db, 'user' => $user->id, 'asset' => $asset->id, 'storage' => $storageRoot, 'pid' => getmypid()]));
echo json_encode(['ready' => true, 'database' => $db, 'asset' => $asset->id, 'url' => 'http://127.0.0.1:8905/__pkg06b/sign-in']).PHP_EOL;
// Parent exec session owns lifetime; Ctrl+C ends the preview fixture and triggers DB cleanup.
while (true) {
    sleep(30);
}
