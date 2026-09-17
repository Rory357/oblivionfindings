<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.mysql.host') !== '127.0.0.1') {
    throw new RuntimeException('Only the local verification database may be checked.');
}
$name = 'oblivion_findings_codex_test_myday_browser_md_ui_01a094e0';
$remaining = Illuminate\Support\Facades\DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]);
echo json_encode(['owned_fixture_database_remaining' => (int) $remaining->n]).PHP_EOL;
exit((int) $remaining->n === 0 ? 0 : 1);
