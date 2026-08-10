<?php

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPosition;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    // canDo() resolves via the Spatie role relation, not the role string column.
    $adminRole = Role::query()->where('name', 'admin')->first();
    if ($adminRole) {
        $this->actor->roles()->syncWithoutDetaching([$adminRole->id]);
    }
});

function makeDept(string $name, ?int $parentId = null): HrDepartment
{
    return HrDepartment::query()->create([
        'name' => $name,
        'parent_id' => $parentId,
        'is_active' => true,
        'sort_order' => 0,
    ]);
}

function staffInDept(HrDepartment $dept): void
{
    $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'department_id' => $dept->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ]);
}

test('update rejects a parent that would create a cycle', function () {
    $a = makeDept('Care');
    $b = makeDept('Clinical', $a->id); // B is a child of A

    // Setting A's parent to B (B is A's descendant) must be rejected.
    $this->actingAs($this->actor)
        ->put("/hr/departments/{$a->id}", ['name' => 'Care', 'parent_id' => $b->id])
        ->assertSessionHasErrors('parent_id');

    expect($a->fresh()->parent_id)->toBeNull();
});

test('deactivating a department reparents its children to its parent', function () {
    $a = makeDept('Care');
    $b = makeDept('Clinical', $a->id);
    $c = makeDept('Nursing', $b->id);

    $this->actingAs($this->actor)
        ->delete("/hr/departments/{$b->id}")
        ->assertRedirect();

    expect($b->fresh()->is_active)->toBeFalse()
        ->and($c->fresh()->parent_id)->toBe($a->id); // reparented up to A
});

test('deactivation is blocked while a department has active employees', function () {
    $a = makeDept('Care');
    staffInDept($a);

    $this->actingAs($this->actor)
        ->delete("/hr/departments/{$a->id}")
        ->assertSessionHas('error');

    expect($a->fresh()->is_active)->toBeTrue();
});

test('show returns rolled-up headcount, children and linked positions', function () {
    $a = makeDept('Care');
    $b = makeDept('Clinical', $a->id);
    staffInDept($a);
    staffInDept($b); // 1 direct in A, 1 in child B → roll-up = 2

    HrPosition::query()->create([
        'title' => 'Care Lead', 'code' => 'CL-1',
        'department' => 'Care', 'employment_type' => 'full_time', 'fte' => 1.0,
        'headcount_budget' => 1, 'is_active' => true, 'created_by' => $this->actor->id,
    ]);

    $json = $this->actingAs($this->actor)
        ->getJson("/hr/departments/{$a->id}")
        ->assertOk()
        ->json();

    expect($json['direct_employee_count'])->toBe(1)
        ->and($json['rolled_up_employee_count'])->toBe(2)
        ->and(collect($json['children'])->pluck('name'))->toContain('Clinical')
        ->and(collect($json['linked_positions'])->pluck('title'))->toContain('Care Lead');
});

test('store persists a cost centre', function () {
    $this->actingAs($this->actor)
        ->post('/hr/departments', [
            'name' => 'Finance Ops',
            'code' => 'FINOPS',
            'cost_centre' => 'CC-4100',
        ])
        ->assertRedirect();

    expect(HrDepartment::query()->where('name', 'Finance Ops')->value('cost_centre'))->toBe('CC-4100');
});

test('a department can be linked to sites and the View returns them', function () {
    $siteA = Site::factory()->create(['type' => 'house', 'name' => 'Kauri House']);
    $siteB = Site::factory()->create(['type' => 'house', 'name' => 'Rata House']);

    $this->actingAs($this->actor)
        ->post('/hr/departments', [
            'name' => 'Care Services',
            'site_ids' => [$siteA->id, $siteB->id],
        ])
        ->assertRedirect();

    $dept = HrDepartment::query()->where('name', 'Care Services')->firstOrFail();

    expect($dept->sites()->pluck('sites.id')->all())
        ->toEqualCanonicalizing([$siteA->id, $siteB->id]);

    $json = $this->actingAs($this->actor)
        ->getJson("/hr/departments/{$dept->id}")
        ->assertOk()
        ->json();

    expect(collect($json['sites'])->pluck('name'))
        ->toContain('Kauri House')
        ->toContain('Rata House');
});

test('the people page keys the edit dialogs so they re-init per target', function () {
    // Regression: the always-mounted wizard kept its initial (empty) useForm
    // values, so Edit never prefilled. A per-target key forces a remount.
    $src = file_get_contents(resource_path('js/pages/hr/employees/index.tsx'));

    expect($src)->toContain("key={editingDept?.id ?? 'new'}")
        ->and($src)->toContain("key={editingPosition?.id ?? 'new'}");
});

test('updating a department re-syncs its sites', function () {
    $siteA = Site::factory()->create(['type' => 'house']);
    $siteB = Site::factory()->create(['type' => 'house']);
    $dept = makeDept('Clinical');
    $dept->sites()->sync([$siteA->id]);

    $this->actingAs($this->actor)
        ->put("/hr/departments/{$dept->id}", [
            'name' => 'Clinical',
            'site_ids' => [$siteB->id],
        ])
        ->assertRedirect();

    expect($dept->sites()->pluck('sites.id')->all())->toBe([$siteB->id]);
});
