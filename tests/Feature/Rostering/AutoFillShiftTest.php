<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;

// Pest.php applies Tests\TestCase + RefreshDatabase to the whole Feature folder.

function autoFillManager(): User
{
    $manager = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $role = Role::query()->create([
        'name' => 'auto-fill-test',
        'label' => 'Auto-fill test',
        'level' => 10,
        'type' => 'custom',
    ]);

    $permissions = collect([
        'rostering.viewAny',
        'shifts.manageAny',
        // Bypass site-access scoping in tests.
        'reports.viewAny',
    ])->map(
        fn (string $key) => Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => $key,
                'group' => 'Rostering',
                'module' => 'operations',
            ],
        ),
    );

    $role->permissions()->sync($permissions->pluck('id'));
    $manager->roles()->attach($role);

    return $manager;
}

it('rejects auto-fill on already-assigned shifts', function () {
    $manager = autoFillManager();

    $site = Site::factory()->create();
    $context = ServiceContext::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'service_context_id' => $context->id,
    ]);
    $assigned = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $shift = Shift::factory()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => $context->id,
        'user_id' => $assigned->id,
        'status' => 'scheduled',
    ]);

    $this->actingAs($manager)
        ->from('/operations/rostering')
        ->post(route('operations.shifts.autoFill', $shift))
        ->assertRedirect('/operations/rostering')
        ->assertSessionHas('error');

    expect($shift->fresh()->user_id)->toBe($assigned->id);
});
