<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Notification::fake();
    Queue::fake();

    $this->site = Site::factory()->create(['name' => 'Expense canonical visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Expense canonical hidden Site']);
    $this->manager = User::factory()->create([
        'name' => 'Expense HR manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->worker = User::factory()->create([
        'name' => 'Expense visible worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->otherWorker = User::factory()->create([
        'name' => 'Expense other visible worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenWorker = User::factory()->create([
        'name' => 'Expense hidden worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->formerWorker = User::factory()->create([
        'name' => 'Expense former worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->managerProfile = expenseCanonicalProfile($this->manager, $this->site, [
        'position_role' => 'hr_manager',
    ]);
    $this->workerProfile = expenseCanonicalProfile($this->worker, $this->site);
    $this->otherProfile = expenseCanonicalProfile($this->otherWorker, $this->site);
    $this->hiddenProfile = expenseCanonicalProfile($this->hiddenWorker, $this->hiddenSite);
    $this->formerProfile = expenseCanonicalProfile($this->formerWorker, $this->site, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    $view = Permission::query()->where('key', 'hr.expenses.view')->firstOrFail();
    $this->worker->permissionOverrides()->syncWithoutDetaching([
        $view->id => ['allowed' => true],
    ]);
});

function expenseCanonicalProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => 'support_worker',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$overrides,
    ]);
}

function expenseCanonicalClaim(
    User $owner,
    User $creator,
    string $number,
    array $overrides = [],
): HrExpenseClaim {
    $claim = HrExpenseClaim::query()->create([
        'user_id' => $owner->id,
        'claim_number' => $number,
        'title' => "Claim {$number}",
        'status' => 'draft',
        'total_amount' => 40,
        'currency' => 'NZD',
        'created_by' => $creator->id,
        ...$overrides,
    ]);
    $claim->items()->create([
        'description' => 'Site visit travel',
        'category' => 'travel',
        'amount' => 40,
        'expense_date' => today()->subDay(),
        'receipt_path' => 'hr/expense-receipts/test/receipt.pdf',
    ]);

    return $claim;
}

test('expense register summaries and on-behalf picker use canonical Site access', function (): void {
    $visible = expenseCanonicalClaim($this->worker, $this->manager, 'EXP-CAN-001');
    $former = expenseCanonicalClaim($this->formerWorker, $this->manager, 'EXP-CAN-002');
    $hidden = expenseCanonicalClaim($this->hiddenWorker, $this->manager, 'EXP-CAN-003');

    $response = $this->actingAs($this->manager)
        ->get('/hr/compensation/expenses')
        ->assertOk();

    expect(collect($response->inertiaProps('claims.data'))->pluck('id'))
        ->toContain($visible->id, $former->id)
        ->not->toContain($hidden->id)
        ->and(collect($response->inertiaProps('employees'))->pluck('id'))
        ->toContain($this->manager->id, $this->worker->id, $this->otherWorker->id)
        ->not->toContain($this->hiddenWorker->id, $this->formerWorker->id)
        ->and($response->inertiaProps('tabCounts.expenses'))->toBe(2);
});

test('hidden claims and their lifecycle actions are concealed', function (): void {
    $hidden = expenseCanonicalClaim($this->hiddenWorker, $this->manager, 'EXP-CAN-004', [
        'status' => 'submitted',
        'submitted_at' => now()->subDay(),
    ]);
    $item = $hidden->items()->firstOrFail();

    $this->actingAs($this->manager)
        ->get("/hr/compensation/expenses/{$hidden->id}")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->get("/hr/compensation/expenses/{$hidden->id}/items/{$item->id}/receipt")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compensation/expenses/{$hidden->id}/submit")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compensation/expenses/{$hidden->id}/approve")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compensation/expenses/{$hidden->id}/reject", [
            'rejection_reason' => 'Hidden claim',
        ])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/compensation/expenses/{$hidden->id}/pay")
        ->assertNotFound();

    expect($hidden->fresh()->status)->toBe('submitted');
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('on-behalf filing accepts visible current staff and conceals hidden or former staff', function (): void {
    $payload = [
        'title' => 'Visible on-behalf claim',
        'items' => [[
            'description' => 'Taxi',
            'category' => 'travel',
            'amount' => 30,
            'expense_date' => today()->toDateString(),
        ]],
    ];

    $this->actingAs($this->manager)
        ->post('/hr/compensation/expenses', [
            ...$payload,
            'on_behalf_user_id' => $this->worker->id,
        ])
        ->assertRedirect();
    expect(HrExpenseClaim::query()
        ->where('title', $payload['title'])
        ->where('user_id', $this->worker->id)
        ->exists())->toBeTrue();

    foreach ([$this->hiddenWorker, $this->formerWorker] as $target) {
        $this->actingAs($this->manager)
            ->post('/hr/compensation/expenses', [
                ...$payload,
                'title' => "Blocked {$target->id}",
                'on_behalf_user_id' => $target->id,
            ])
            ->assertNotFound();
        expect(HrExpenseClaim::query()->where('title', "Blocked {$target->id}")->exists())
            ->toBeFalse();
    }
});

test('bulk approval mutates only visible submitted claims', function (): void {
    $visible = expenseCanonicalClaim($this->worker, $this->manager, 'EXP-CAN-005', [
        'status' => 'submitted',
        'submitted_at' => now()->subDay(),
    ]);
    $hidden = expenseCanonicalClaim($this->hiddenWorker, $this->manager, 'EXP-CAN-006', [
        'status' => 'submitted',
        'submitted_at' => now()->subDay(),
    ]);
    $draft = expenseCanonicalClaim($this->otherWorker, $this->manager, 'EXP-CAN-007');

    $this->actingAs($this->manager)
        ->post('/hr/compensation/expenses/bulk-approve', [
            'claim_ids' => [$visible->id, $hidden->id, $draft->id],
        ])
        ->assertSessionHas('success');

    expect($visible->fresh()->status)->toBe('approved')
        ->and($hidden->fresh()->status)->toBe('submitted')
        ->and($draft->fresh()->status)->toBe('draft');
});

test('a worker can open and submit only their own visible claim', function (): void {
    $own = expenseCanonicalClaim($this->worker, $this->manager, 'EXP-CAN-008');
    $other = expenseCanonicalClaim($this->otherWorker, $this->manager, 'EXP-CAN-009');

    $this->actingAs($this->worker)
        ->get("/hr/compensation/expenses/{$own->id}")
        ->assertOk();
    $this->actingAs($this->worker)
        ->get("/hr/compensation/expenses/{$other->id}")
        ->assertNotFound();
    $this->actingAs($this->worker)
        ->post("/hr/compensation/expenses/{$own->id}/submit")
        ->assertSessionHas('success');
    $this->actingAs($this->worker)
        ->post("/hr/compensation/expenses/{$other->id}/submit")
        ->assertNotFound();

    expect($own->fresh()->status)->toBe('submitted')
        ->and($other->fresh()->status)->toBe('draft');
});
