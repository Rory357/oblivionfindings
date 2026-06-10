<?php

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    foreach ([$this->hr, $this->staff] as $index => $user) {
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => sprintf('ORPHAN-%03d', $index + 1),
            'work_email' => "orphan-{$user->id}@example.test",
            'position_title' => $user->id === $this->hr->id ? 'HR Manager' : 'Support Worker',
            'position_role' => $user->role,
            'created_by' => $this->hr->id,
            'updated_by' => $this->hr->id,
        ]);
    }

    $permissionIds = Permission::query()
        ->whereIn('key', ['hr.performance.view', 'hr.performance.manage'])
        ->pluck('id')
        ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
        ->all();

    $this->hr->permissionOverrides()->syncWithoutDetaching($permissionIds);
});

test('my expenses page is routed and accepts self-service expense claims', function () {
    $this->actingAs($this->staff)
        ->get('/hr/my/expenses')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/my/expenses')
            ->has('claims.data')
            ->where('categories.0', 'travel'));

    $this->actingAs($this->staff)
        ->post('/hr/my/expenses', [
            'title' => 'Mileage and supplies',
            'items' => [
                [
                    'description' => 'Client visit mileage',
                    'category' => 'mileage',
                    'amount' => 18.50,
                    'expense_date' => '2026-06-15',
                ],
            ],
        ])
        ->assertSessionHas('success');

    $this->assertDatabaseHas('hr_expense_claims', [
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'title' => 'Mileage and supplies',
        'status' => 'draft',
    ]);

    $this->assertDatabaseHas('hr_expense_items', [
        'description' => 'Client visit mileage',
        'category' => 'mileage',
    ]);
});

test('competency assessment page is routed for managers', function () {
    HrCompetency::query()->create([
        'tenant_id' => 1,
        'name' => 'Safe medication support',
        'description' => 'Demonstrates safe medication support practice.',
        'category' => 'Clinical',
        'proficiency_levels' => ['Aware', 'Developing', 'Competent', 'Advanced', 'Expert'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($this->hr)
        ->get('/hr/performance/competencies/assess')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/performance/competencies/assess')
            ->where('competencies.0.name', 'Safe medication support')
            ->where('staff', fn ($staff) => collect($staff)->pluck('id')->contains($this->hr->id)));
});
