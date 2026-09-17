<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 'oblivion_findings_codex_test') {
    throw new RuntimeException('This activation is restricted to the verified local application database.');
}
foreach (['2026_09_12_000060_add_my_day_shift_task_context', '2026_09_12_000061_create_shift_task_drafts', '2026_09_12_000062_add_shift_task_follow_through'] as $migration) {
    if (! Illuminate\Support\Facades\DB::table('migrations')->where('migration', $migration)->exists()) {
        throw new RuntimeException('Required My Day migration is missing: '.$migration);
    }
}
(new Database\Seeders\MyDayTaskPermissionSeeder)->run();
$role = App\Models\Role::where('name', 'support_worker')->firstOrFail();
if (! $role->permissions()->where('key', 'shifts.tasks.createSelf')->exists()) {
    throw new RuntimeException('Worker task creation was not enabled.');
}
echo "Verified local application: all three My Day migrations are present; support_worker has shifts.tasks.createSelf. Existing permissions and user overrides were retained.\n";
