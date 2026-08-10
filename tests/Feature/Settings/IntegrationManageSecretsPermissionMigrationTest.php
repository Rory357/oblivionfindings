<?php

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function integrationManageSecretsPermissionMigration(): object
{
    return require database_path('migrations/2026_07_22_121000_rename_integration_manage_secrets_permission.php');
}

it('refuses a permission collision without deleting an explicit user denial', function (bool $forward): void {
    $from = $forward ? 'integrations.manage_tenant_secrets' : 'integrations.manage_secrets';
    $to = $forward ? 'integrations.manage_secrets' : 'integrations.manage_tenant_secrets';
    $migration = integrationManageSecretsPermissionMigration();
    $user = User::factory()->create();
    $source = Permission::query()->updateOrCreate(['key' => $from], ['description' => 'Source']);
    $target = Permission::query()->updateOrCreate(['key' => $to], ['description' => 'Target']);

    DB::table('permission_user')->insert([
        ['permission_id' => $source->id, 'user_id' => $user->id, 'allowed' => false],
        ['permission_id' => $target->id, 'user_id' => $user->id, 'allowed' => true],
    ]);

    expect(fn () => $forward ? $migration->up() : $migration->down())
        ->toThrow(LogicException::class, 'both keys exist')
        ->and(Permission::query()->where('key', $from)->value('id'))->toBe($source->id)
        ->and(Permission::query()->where('key', $to)->value('id'))->toBe($target->id)
        ->and(DB::table('permission_user')
            ->where('permission_id', $source->id)
            ->where('user_id', $user->id)
            ->value('allowed'))
        ->toBe(0)
        ->and(DB::table('permission_user')
            ->where('permission_id', $target->id)
            ->where('user_id', $user->id)
            ->value('allowed'))
        ->toBe(1);
})->with([
    'forward migration' => true,
    'rollback migration' => false,
]);

it('renames the permission in place so grants and explicit overrides survive rollback', function (): void {
    $migration = integrationManageSecretsPermissionMigration();
    $user = User::factory()->create();
    $source = Permission::query()->updateOrCreate(
        ['key' => 'integrations.manage_tenant_secrets'],
        ['description' => 'Source'],
    );
    Permission::query()->where('key', 'integrations.manage_secrets')->delete();

    DB::table('permission_user')->insert([
        'permission_id' => $source->id,
        'user_id' => $user->id,
        'allowed' => false,
    ]);

    $migration->up();

    expect(Permission::query()->where('key', 'integrations.manage_secrets')->value('id'))->toBe($source->id)
        ->and(DB::table('permission_user')
            ->where('permission_id', $source->id)
            ->where('user_id', $user->id)
            ->value('allowed'))
        ->toBe(0);

    $migration->down();

    expect(Permission::query()->where('key', 'integrations.manage_tenant_secrets')->value('id'))->toBe($source->id)
        ->and(DB::table('permission_user')
            ->where('permission_id', $source->id)
            ->where('user_id', $user->id)
            ->value('allowed'))
        ->toBe(0);
});
