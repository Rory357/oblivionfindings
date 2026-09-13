<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = 'oblivion_findings_codex_test_myday_browser_md_ui_01a094e0';
$identity = json_decode(file_get_contents(__DIR__.'/desktop-browser-identity.json'), true, flags: JSON_THROW_ON_ERROR);
if (! app()->environment('local')
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') === $database
    || ($identity['database'] ?? null) !== $database
    || ($identity['url'] ?? null) !== 'http://127.0.0.1:8766/my-day'
) {
    throw new RuntimeException('Fixture cleanup identity guard failed.');
}
$socket = @fsockopen('127.0.0.1', 8766, $errno, $error, 1);
if ($socket) {
    fclose($socket);
    throw new RuntimeException('Stop the fixture server before cleanup.');
}
$db = Illuminate\Support\Facades\DB::connection();
$worker = $db->selectOne('SELECT id FROM `oblivion_findings_codex_test_myday_browser_md_ui_01a094e0`.users WHERE email = ? AND name = ?', ['myday-worker@demo.test', 'Taylor Demo']);
if ((int) ($worker->id ?? 0) !== (int) $identity['worker_id']) {
    throw new RuntimeException('Synthetic fixture owner does not match.');
}
$db->statement('DROP DATABASE `oblivion_findings_codex_test_myday_browser_md_ui_01a094e0`');
echo "Owned disposable My Day browser database removed.\n";
