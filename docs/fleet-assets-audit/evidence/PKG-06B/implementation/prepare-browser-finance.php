<?php

use App\Models\AssetDocument;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Extend only the named synthetic verification actor, never a real user/database.
$root = dirname(__DIR__, 5);
chdir($root);
if (PHP_SAPI !== 'cli' || is_file('.env') || is_file('.env.testing') || is_file('bootstrap/cache/config.php')) {
    throw new RuntimeException('Unexpected environment');
}
$fixture = json_decode(file_get_contents('storage/framework/pkg06b-browser.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^oblivion_findings_pkg06b_8821_test_\d+$/', $fixture['database'])) {
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
$permission = Permission::where('key', 'finance.assets.view')->firstOrFail();
$user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
AssetDocument::where('asset_id', $fixture['asset'])->where('original_name', 'transfer-hoist-manual.pdf')->update(['version' => '3']);
echo "Synthetic Finance viewer fixture ready; no approval or decision permission granted.\n";
