<?php

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptOutputs;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScripts;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

const ENDPOINT_SCRIPT_PERMISSION_MIGRATION = 'database/migrations/2026_10_22_090000_register_endpoint_script_permissions.php';

/** @return list<string> */
function endpointScriptPermissionKeys(): array
{
    return [
        EndpointScripts::UPLOAD,
        EndpointScripts::REVIEW,
        EndpointScriptPolicy::MANAGE,
        EndpointScriptPolicy::RUN,
        EndpointScriptPolicy::UNATTENDED,
        EndpointScriptOutputs::VIEW,
    ];
}

test('migrations register every script permission without granting it to any role', function () {
    // Deploys run migrations but not seeders, so the keys must exist from
    // migrations alone.
    $keys = endpointScriptPermissionKeys();

    expect(Permission::query()->whereIn('key', $keys)->count())->toBe(count($keys))
        ->and(DB::table('role_permission')
            ->whereIn('permission_id', Permission::query()->whereIn('key', $keys)->pluck('id'))
            ->count())->toBe(0)
        ->and(array_values(array_diff($keys, RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY)))->toBe([]);
});

test('the registration migration is repeatable and removes script grants from admin', function () {
    $this->seed(RbacSeeder::class);
    $admin = Role::query()->where('name', 'admin')->firstOrFail();
    $keys = endpointScriptPermissionKeys();

    expect($admin->permissions()->whereIn('key', $keys)->count())->toBe(0);

    // What an older admin backfill could have left behind.
    $admin->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id'));

    $migration = require base_path(ENDPOINT_SCRIPT_PERMISSION_MIGRATION);
    $migration->up();
    $migration->up();

    expect($admin->permissions()->whereIn('key', $keys)->count())->toBe(0)
        ->and(Permission::query()->whereIn('key', $keys)->count())->toBe(count($keys));
});
