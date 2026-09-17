<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local') || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 'oblivion_findings_codex_test') {
    throw new RuntimeException('This read-only check is restricted to the verified local application database.');
}
$role = App\Models\Role::where('name', 'support_worker')->first();
echo json_encode([
    'role_exists' => $role !== null,
    'task_creation_enabled' => $role?->permissions()->where('key', 'shifts.tasks.createSelf')->exists() ?? false,
    'other_role_permissions_hash' => hash('sha256', Illuminate\Support\Facades\DB::table('role_permission')
        ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
        ->where(fn ($query) => $query->where('role_id', '!=', $role?->id ?? 0)
            ->orWhere('permissions.key', '!=', 'shifts.tasks.createSelf'))
        ->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])->toJson()),
    'individual_overrides_hash' => hash('sha256', Illuminate\Support\Facades\DB::table('permission_user')
        ->orderBy('user_id')->orderBy('permission_id')->get()->toJson()),
    'required_migrations_present' => Illuminate\Support\Facades\DB::table('migrations')->whereIn('migration', [
        '2026_09_12_000060_add_my_day_shift_task_context',
        '2026_09_12_000061_create_shift_task_drafts',
        '2026_09_12_000062_add_shift_task_follow_through',
    ])->count() === 3,
]).PHP_EOL;
