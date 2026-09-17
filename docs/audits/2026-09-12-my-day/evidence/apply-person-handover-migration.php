<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 'oblivion_findings_codex_test') {
    throw new RuntimeException('This migration helper only applies to the verified local application.');
}
$migration = 'database/migrations/2026_09_13_160000_add_worker_notes_to_shift_handovers.php';
foreach (['oblivion_findings_codex_test', 'oblivion_findings_codex_test_myday_browser_md_ui_01a094e0'] as $database) {
    config(['database.connections.mysql.database' => $database]);
    Illuminate\Support\Facades\DB::purge('mysql');
    $code = Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
    echo json_encode(['database' => $database, 'exit_code' => $code,
        'worker_notes_column' => Illuminate\Support\Facades\Schema::hasColumn('shift_handovers', 'worker_notes')]).PHP_EOL;
    if ($code !== 0) throw new RuntimeException('The named migration failed.');
}
