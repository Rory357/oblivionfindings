<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function userWithPermissionAlias(string $permissionKey): User
{
    $permission = Permission::query()->firstOrCreate(
        ['key' => $permissionKey],
        [
            'description' => $permissionKey,
            'group' => explode('.', $permissionKey)[0],
            'module' => 'test',
        ],
    );

    $role = Role::query()->create([
        'name' => 'alias-test-'.str_replace('.', '-', $permissionKey),
        'label' => $permissionKey,
        'type' => 'custom',
        'level' => 1,
    ]);

    $role->permissions()->sync([$permission->id]);

    $user = User::factory()->create(['approved_at' => now()]);
    $user->roles()->sync([$role->id]);

    return $user;
}

test('legacy time and vetting permission keys are policy aliases only', function () {
    expect(userWithPermissionAlias('hr.time.viewAny')->canDo('timesheets.viewAny'))->toBeTrue()
        ->and(userWithPermissionAlias('timesheets.approve')->canDo('hr.time.approveTeam'))->toBeTrue()
        ->and(userWithPermissionAlias('vetting.verify')->canDo('hr.vetting.manage'))->toBeTrue()
        ->and(userWithPermissionAlias('hr.vetting.view')->canDo('vetting.viewAny'))->toBeTrue();
});

test('retired training course route names are removed', function (string $routeName) {
    expect(Route::getRoutes()->getByName($routeName))->toBeNull();
})->with([
    'training.courses.index',
    'training.courses.create',
    'training.courses.store',
    'training.courses.edit',
    'training.courses.update',
    'training.courses.destroy',
    'training.courses.show',
]);

test('retired training course urls redirect to the hr catalog surface', function (string $method, string $uri, string $target, int $status) {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($user)
        ->call($method, $uri)
        ->assertStatus($status)
        ->assertRedirect(url($target));
})->with([
    ['GET', '/training/courses', '/hr/training/catalog', 301],
    ['GET', '/training/courses/create', '/hr/training/catalog?open=create', 301],
    ['GET', '/training/courses/123', '/hr/training/courses/123', 301],
    ['GET', '/training/courses/123/edit', '/hr/training/courses/123', 301],
    ['POST', '/training/courses', '/hr/training/courses', 308],
    ['PUT', '/training/courses/123', '/hr/training/courses/123', 303],
    ['DELETE', '/training/courses/123', '/hr/training/courses/123', 303],
]);

test('training stub controllers have been removed', function () {
    expect(file_exists(app_path('Http/Controllers/Training/StaffTrainingRecordController.php')))->toBeFalse()
        ->and(file_exists(app_path('Http/Controllers/Training/StaffCompetencyController.php')))->toBeFalse()
        ->and(file_exists(app_path('Http/Controllers/Training/TrainingCourseController.php')))->toBeFalse();
});
