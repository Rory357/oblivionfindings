<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Expense bulk visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Expense bulk hidden Site']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'position_role' => 'hr_manager',
        'is_active' => true,
    ]);
});

function expenseBulkEmployeeAtSite(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-OB-'.fake()->unique()->numberBetween(1000, 999999),
        'work_email' => 'ob'.fake()->unique()->numberBetween(1000, 999999).'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    return $user;
}

function submittedExpenseBulkClaim(int $ownerId, array $overrides = []): HrExpenseClaim
{
    return HrExpenseClaim::query()->create(array_merge([
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
    $target = expenseBulkEmployeeAtSite($this->site);

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

test('filing on behalf of an employee at a hidden Site is concealed', function () {
    $hidden = expenseBulkEmployeeAtSite($this->hiddenSite);

    $this->actingAs($this->hr)->post('/hr/compensation/expenses', [
        'title' => 'Hidden on-behalf',
        'on_behalf_user_id' => $hidden->id,
        'items' => [[
            'description' => 'Taxi',
            'category' => 'travel',
            'amount' => 30,
            'expense_date' => '2026-03-01',
        ]],
    ])->assertNotFound();

    expect(HrExpenseClaim::query()->where('title', 'Hidden on-behalf')->exists())->toBeFalse();
});

test('bulk-approve approves the submitted claims and skips the rest', function () {
    Queue::fake(); // don't run the GL posting job inline

    $worker = expenseBulkEmployeeAtSite($this->site);
    $a = submittedExpenseBulkClaim($worker->id);
    $b = submittedExpenseBulkClaim($worker->id);
    $draft = submittedExpenseBulkClaim($worker->id, ['status' => 'draft']);

    $this->actingAs($this->hr)->post('/hr/compensation/expenses/bulk-approve', [
        'claim_ids' => [$a->id, $b->id, $draft->id],
    ])->assertSessionHas('success');

    expect($a->fresh()->status)->toBe('approved');
    expect($b->fresh()->status)->toBe('approved');
    // The draft was not submitted → left untouched.
    expect($draft->fresh()->status)->toBe('draft');
});

test('a user without approve permission cannot bulk-approve', function () {
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $claim = submittedExpenseBulkClaim($worker->id);

    $this->actingAs($worker)->post('/hr/compensation/expenses/bulk-approve', [
        'claim_ids' => [$claim->id],
    ])->assertForbidden();

    expect($claim->fresh()->status)->toBe('submitted');
});
