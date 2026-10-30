<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinVendor;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetScanEvent;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assets\AssetOwnershipService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Only the already-owned synthetic browser database may be extended here.
$root = dirname(__DIR__, 5);
chdir($root);
if (PHP_SAPI !== 'cli' || is_file('.env') || is_file('.env.testing') || is_file('bootstrap/cache/config.php')) {
    throw new RuntimeException('Unexpected environment');
}
$fixture = json_decode(file_get_contents('storage/framework/pkg06b-browser.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^oblivion_findings_pkg06b_8821_test_\d+$/', $fixture['database']) || $fixture['database'] !== 'oblivion_findings_pkg06b_8821_test_'.$fixture['pid']) {
    throw new RuntimeException('Unexpected database');
}
$xml = simplexml_load_file('phpunit.pkg06b.xml');
foreach ($xml->php->env as $entry) {
    $name = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
putenv('DB_DATABASE='.$fixture['database']);
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $fixture['database'];
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$user = User::whereKey($fixture['user'])->where('email', 'pkg06b-verification@example.invalid')->firstOrFail();
$asset = Asset::findOrFail($fixture['asset']);
Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_27_130000_create_asset_label_batches.php', '--force' => true]);
foreach (['finance.assets.view', 'finance.ledger.view', 'finance.ap.view', 'fleet.maintenance.manage', 'assets.ownership.manage'] as $key) {
    $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'assets']);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
}
config(['filesystems.disks.private.root' => $fixture['storage']]);
Storage::forgetDisk('private');
if (! $asset->ownerships()->whereNull('effective_to')->exists()) {
    app(AssetOwnershipService::class)->change($user, $asset, ['owner_type' => 'site', 'owner_id' => $asset->site_id, 'notes' => 'Synthetic ownership for verification']);
}
$template = FleetChecklistTemplate::firstOrCreate(['name' => 'Hoist visual check · verification'], ['type' => 'inspection', 'items' => [['id' => 'brake', 'label' => 'Brake condition']], 'is_active' => true]);
$run = FleetChecklistRun::firstOrCreate(['asset_id' => $asset->id, 'template_id' => $template->id], ['user_id' => $user->id, 'passed' => false, 'outcome' => 'needs_assessment', 'check_kind' => 'inspection', 'completed_at' => now()->subDay(), 'responses' => ['brake' => ['result' => 'concern', 'note' => 'Synthetic brake observation', 'evidence_file' => ['path' => 'documents/brake-assessment.jpg', 'original_name' => 'brake-assessment.jpg', 'mime_type' => 'image/jpeg']]], 'presented_template_json' => ['name' => $template->name, 'items' => $template->items]]);
$work = FleetWorkOrder::firstOrCreate(['asset_id' => $asset->id, 'title' => 'Brake assessment · verification'], ['reported_by_user_id' => $user->id, 'category' => 'mechanical', 'status' => 'open', 'priority' => 'high', 'description' => 'Synthetic linked work record', 'assigned_to_user_id' => $user->id, 'next_action' => 'Inspect brake assembly']);
if (! DB::table('fleet_maintenance_attachments')->where('work_order_id', $work->id)->exists()) {
    $bytes = Storage::disk('private')->get('documents/transfer-hoist-manual.pdf');
    DB::table('fleet_maintenance_attachments')->insert(['work_order_id' => $work->id, 'uploaded_by_user_id' => $user->id, 'disk' => 'private', 'path' => 'documents/transfer-hoist-manual.pdf', 'original_name' => 'Service instructions · verification.pdf', 'mime_type' => 'application/pdf', 'category' => 'evidence', 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'request_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'fixture-work-evidence'), 'created_at' => now()]);
}
AssetScanEvent::firstOrCreate(['asset_id' => $asset->id, 'qr_token' => $asset->qr_token, 'scanned_by_type' => 'user', 'scanned_by_id' => $user->id], ['scanned_at' => now()->subHours(2), 'site_id' => $asset->site_id, 'created_at' => now()->subHour()]);
$journal = FinJournal::firstOrCreate(['journal_number' => 'JNL-PKG06B-VERIFY'], ['organization_id' => 1, 'journal_date' => today(), 'type' => 'standard', 'status' => 'posted', 'description' => 'Synthetic service allocation · verification', 'posted_at' => now()]);
if (! $journal->lines()->exists()) {
    $account = FinAccount::where('code', '6300')->first() ?? FinAccount::factory()->create(['type' => 'expense']);
    $line = $journal->lines()->create(['account_id' => $account->id, 'site_id' => $asset->site_id, 'description' => 'Synthetic servicing', 'debit' => '185.00', 'credit' => '0.00']);
    FinCostAllocation::create(['asset_id' => $asset->id, 'site_id' => $asset->site_id, 'journal_id' => $journal->id, 'journal_line_id' => $line->id, 'event_type' => 'asset_maintenance_expense', 'event_date' => today(), 'amount' => '185.00']);
}
$vendor = FinVendor::firstOrCreate(['name' => 'Harbour Equipment · verification'], ['organization_id' => 1, 'is_active' => true]);
FinBill::firstOrCreate(['bill_number' => 'BILL-PKG06B-VERIFY'], ['organization_id' => 1, 'vendor_id' => $vendor->id, 'asset_id' => $asset->id, 'site_id' => $asset->site_id, 'status' => 'draft', 'bill_date' => today(), 'due_date' => today()->addMonth(), 'subtotal' => '185.00', 'gst_amount' => '0.00', 'total_amount' => '185.00', 'amount_paid' => '0.00']);
foreach (['AS-105' => 'Shower chair', 'AS-106' => 'Standing aid'] as $tag => $name) {
    Asset::firstOrCreate(['asset_tag' => $tag], ['name' => $name, 'category' => 'equipment', 'status' => 'active', 'site_id' => $asset->site_id, 'home_site_id' => $asset->site_id]);
}
config(['filesystems.disks.public.root' => $fixture['storage'].'/branding-verification']);
Storage::forgetDisk('public');
$brandingSource = file_get_contents('docs/fleet-assets-audit/previews/PKG-06B/v9/asset-branding.ts');
if (! preg_match('/data:image\/png;base64,([A-Za-z0-9+\/=]+)/', $brandingSource, $logoMatch)) {
    throw new RuntimeException('Synthetic branding fixture missing');
}
Storage::disk('public')->put('sample-logo.png', base64_decode($logoMatch[1], true));
AppSetting::updateOrCreate(['key' => 'branding.logo_path'], ['value' => 'sample-logo.png']);
AppSetting::updateOrCreate(['key' => 'branding.name'], ['value' => 'Oblivion Care · verification']);
echo "Synthetic completion fixture prepared: owned label migration, original evidence, scan, ownership and Finance sources.\n";
