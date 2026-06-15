<?php

use App\Domain\Hr\Models\HrBenefitPlan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

function makeBenefitPlan(array $overrides = []): HrBenefitPlan
{
    return HrBenefitPlan::query()->create(array_merge([
        'tenant_id' => 1,
        'name' => 'KiwiSaver 3%',
        'type' => 'kiwisaver',
        'is_active' => true,
    ], $overrides));
}

test('a benefit plan can be deactivated and reactivated', function () {
    $plan = makeBenefitPlan();

    $this->actingAs($this->hr)
        ->put("/hr/compensation/benefits/plans/{$plan->id}", ['is_active' => false])
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($plan->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->hr)
        ->put("/hr/compensation/benefits/plans/{$plan->id}", ['is_active' => true])
        ->assertSessionHas('success');
    expect($plan->fresh()->is_active)->toBeTrue();
});

test('deactivating a plan does not remove it (existing enrollments keep referencing it)', function () {
    $plan = makeBenefitPlan();

    $this->actingAs($this->hr)
        ->put("/hr/compensation/benefits/plans/{$plan->id}", ['is_active' => false]);

    $this->assertDatabaseHas('hr_benefit_plans', [
        'id' => $plan->id,
        'is_active' => false,
    ]);
});

test('a user without hr.benefits.manage cannot toggle a plan', function () {
    $plan = makeBenefitPlan();
    $worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->actingAs($worker)
        ->put("/hr/compensation/benefits/plans/{$plan->id}", ['is_active' => false])
        ->assertForbidden();

    expect($plan->fresh()->is_active)->toBeTrue();
});
