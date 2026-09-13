<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$target = $argv[1] ?? '';
$allowed = ['oblivion_findings_codex_test', 'oblivion_findings_codex_test_myday_browser_md_ui_01a094e0'];
if (! in_array($target, $allowed, true) || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
    || ! in_array(app()->environment(), ['local', 'testing'], true)) {
    throw new RuntimeException('Only the verified local My Day databases may be migrated.');
}
config(['database.connections.mysql.database' => $target]);
Illuminate\Support\Facades\DB::purge('mysql');
Illuminate\Support\Facades\Artisan::call('migrate', [
    '--path' => 'database/migrations/2026_09_12_000062_add_shift_task_follow_through.php', '--force' => true,
]);
echo $target.PHP_EOL.Illuminate\Support\Facades\Artisan::output();
