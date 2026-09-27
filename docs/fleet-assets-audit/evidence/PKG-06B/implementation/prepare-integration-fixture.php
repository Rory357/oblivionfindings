<?php

use App\Models\Asset;
use App\Models\AssetLabelBatch;
use App\Models\User;
use App\Services\Assets\AssetAssignmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Apply only approved-main additions to the already identified synthetic fixture.
// No application environment, normal database or operational deployment is used.
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
foreach (['2026_09_26_000100_pkg02b_appointment_command_receipts.php', '2026_09_27_000100_add_receipt_confirmation_to_asset_assignments.php', '2026_09_27_120000_add_transport_workspace_contracts.php'] as $file) {
    Artisan::call('migrate', ['--path' => 'database/migrations/'.$file, '--force' => true]);
}
$assignment = $asset->assignments()->whereNull('released_at')->first();
if (! $assignment) {
    $assignment = app(AssetAssignmentService::class)->assign($user, $asset, ['assignee_type' => 'staff', 'assignee_id' => $user->id, 'purpose' => 'Synthetic Main integration receipt verification']);
}
echo json_encode(['database' => $fixture['database'], 'asset' => $asset->id, 'assignment' => $assignment->id,
    'labelTable' => Schema::hasTable('asset_label_batches'),
    'labelMigrations' => DB::table('migrations')->where('migration', 'like', '%asset_label%')->get(['migration', 'batch']),
    'labelHistoryCount' => AssetLabelBatch::count(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
