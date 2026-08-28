<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftStaffEligibilityService;

// Pest.php applies Tests\TestCase + RefreshDatabase to the whole Feature folder.

function autoFillManager(Site $site): User
{
    $manager = User::factory()->create([
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
    HrEmployeeProfile::factory()->create([
        'user_id' => $manager->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $manager;
}

it('rejects auto-fill on already-assigned shifts', function () {
    $site = Site::factory()->create();
    $manager = autoFillManager($site);
    $context = ServiceContext::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'service_context_id' => $context->id,
    ]);
    $assigned = User::factory()->create([
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $assigned->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $shift = Shift::factory()->create([
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

it('does not silently override a warning-only auto-fill candidate', function () {
    $site = Site::factory()->create();
    $manager = autoFillManager($site);
    $context = ServiceContext::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'service_context_id' => $context->id,
    ]);
    $candidate = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $candidate->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $shift = Shift::factory()->unassigned()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => $context->id,
        'status' => 'scheduled',
    ]);
    $warning = EligibilityResult::fromChecks([[
        'rule' => 'turnaround',
        'passed' => false,
        'severity' => 'warning',
        'overrideable' => true,
        'message' => 'Tight turnaround.',
    ]]);
    $this->mock(ShiftStaffEligibilityService::class, function ($mock) use ($candidate, $shift, $warning): void {
        $mock->shouldReceive('candidatesFor')->once()->withArgs(
            fn (Shift $candidateShift): bool => (int) $candidateShift->id === (int) $shift->id,
        )->andReturn(collect([$candidate]));
        $mock->shouldReceive('evaluate')->once()->andReturn($warning);
    });

    $this->actingAs($manager)
        ->from('/operations/rostering')
        ->post(route('operations.shifts.autoFill', $shift))
        ->assertRedirect('/operations/rostering')
        ->assertSessionHas('warning', 'Only candidates with eligibility warnings were found. Review and assign one manually.');

    expect($shift->fresh()->user_id)->toBeNull();
});
