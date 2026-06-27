<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

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

function employeeInTenant(int $tenantId): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $user->id,
        'employee_number' => 'EMP-OB-'.fake()->unique()->numberBetween(1000, 999999),
        'work_email' => 'ob'.fake()->unique()->numberBetween(1000, 999999).'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    return $user;
}

function submittedClaim(int $ownerId, array $overrides = []): HrExpenseClaim
{
    return HrExpenseClaim::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $ownerId,
        'claim_number' => 'EXP-BULK-'.fake()->unique()->numberBetween(1000, 999999),
        'title' => 'Travel claim',
        'status' => 'submitted',
        'total_amount' => 100,
        'currency' => 'NZD',
        'submitted_at' => now()->subDays(2),
        'created_by' => $ownerId,
    ], $overrides));
}

test('a manager can file an expense claim on behalf of another employee', function () {
    $target = employeeInTenant(1);

    $this->actingAs($this->hr)->post('/hr/compensation/expenses', [
        'title' => 'On-behalf travel',
        'on_behalf_user_id' => $target->id,
        'items' => [[
            'description' => 'Taxi',
            'category' => 'travel',
            'amount' => 30,
            'expense_date' => '2026-03-01',
        ]],
    ])->assertRedirect();

    $claim = HrExpenseClaim::query()->where('title', 'On-behalf travel')->firstOrFail();
    // Owned by the target employee, but created_by records the manager.
    expect($claim->user_id)->toBe($target->id);
    expect($claim->created_by)->toBe($this->hr->id);
});

test('filing on behalf of an employee outside the tenant is rejected', function () {
    $foreign = employeeInTenant(2); // different tenant

    $this->actingAs($this->hr)->post('/hr/compensation/expenses', [
        'title' => 'Foreign on-behalf',
        'on_behalf_user_id' => $foreign->id,
        'items' => [[
            'description' => 'Taxi',
            'category' => 'travel',
            'amount' => 30,
            'expense_date' => '2026-03-01',
        ]],
    ])->assertStatus(422);

    expect(HrExpenseClaim::query()->where('title', 'Foreign on-behalf')->exists())->toBeFalse();
});

test('bulk-approve approves the submitted claims and skips the rest', function () {
    Queue::fake(); // don't run the GL posting job inline

    $worker = employeeInTenant(1);
    $a = submittedClaim($worker->id);
    $b = submittedClaim($worker->id);
    $draft = submittedClaim($worker->id, ['status' => 'draft']);

    $this->actingAs($this->hr)->post('/hr/compensation/expenses/bulk-approve', [
        'claim_ids' => [$a->id, $b->id, $draft->id],
    ])->assertSessionHas('success');

    expect($a->fresh()->status)->toBe('approved');
    expect($b->fresh()->status)->toBe('approved');
    // The draft was not submitted → left untouched.
    expect($draft->fresh()->status)->toBe('draft');
});

test('a user without approve permission cannot bulk-approve', function () {
    $worker = User::factory()->create(['organization_id' => 1, 'role' => 'support_worker', 'approved_at' => now()]);
    $claim = submittedClaim($worker->id);

    $this->actingAs($worker)->post('/hr/compensation/expenses/bulk-approve', [
        'claim_ids' => [$claim->id],
    ])->assertForbidden();

    expect($claim->fresh()->status)->toBe('submitted');
});
