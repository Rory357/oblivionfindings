<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Local database only.');
}
$database = 'oblivion_findings_codex_test_md_review_01a094e0_b1';
$rows = Illuminate\Support\Facades\DB::select('SELECT ID, COMMAND, TIME, STATE FROM information_schema.PROCESSLIST WHERE DB = ?', [$database]);
$tables = Illuminate\Support\Facades\DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$database]);
echo json_encode(['database' => $database, 'tables' => $tables->total, 'connections' => $rows], JSON_PRETTY_PRINT).PHP_EOL;
