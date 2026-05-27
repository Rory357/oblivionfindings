<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Pest.php applies Tests\TestCase + RefreshDatabase to the whole Feature folder.

function makeRosteringManager(): User
{
    $manager = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $role = Role::query()->create([
        'name' => 'rostering-open-shift-eligibility-test',
        'label' => 'Rostering open shift eligibility test',
        'level' => 10,
        'type' => 'custom',
    ]);

    $permissions = collect([
        'rostering.viewAny',
        'shifts.manageAny',
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

it('emits openShiftEligibility blocked entry when a candidate has overlapping time off', function () {
    $manager = makeRosteringManager();

    $site = Site::factory()->create();
    $serviceContext = ServiceContext::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
    ]);

    $candidate = User::factory()->create([
        'organization_id' => 1,
        'name' => 'Aroha Blocked',
        'approved_at' => now(),
    ]);

    $shift = Shift::factory()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => null,
        'starts_at' => '2026-05-27 09:00:00',
        'ends_at' => '2026-05-27 17:00:00',
        'status' => 'scheduled',
    ]);

    StaffTimeOff::create([
        'user_id' => $candidate->id,
        'starts_at' => '2026-05-27 08:00:00',
        'ends_at' => '2026-05-27 18:00:00',
        'type' => 'sick',
        'label' => 'Sick day',
        'created_by' => $candidate->id,
    ]);

    $this->actingAs($manager)
        ->get(route('operations.rostering.index', ['week' => '2026-05-25']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/rostering/index')
            ->has('openShiftEligibility.'.$shift->id.'.'.$candidate->id, fn (Assert $entry) => $entry
                ->where('status', 'blocked')
                ->has('reasons', fn (Assert $reasons) => $reasons->etc())
            ));
});

it('includes the openShiftEligibility payload on the rostering page', function () {
    $manager = makeRosteringManager();

    $this->actingAs($manager)
        ->get(route('operations.rostering.index', ['week' => '2026-05-25']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/rostering/index')
            ->has('openShiftEligibility'));
});
