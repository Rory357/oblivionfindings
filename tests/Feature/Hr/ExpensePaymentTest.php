<?php

use App\Domain\Finance\Jobs\PostExpenseJournalJob;
use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
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
    $this->site = Site::factory()->create(['name' => 'Expense payment Site']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'position_role' => 'hr_manager',
        'is_active' => true,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->worker->id,
        'primary_site_id' => $this->site->id,
        'position_role' => 'support_worker',
        'is_active' => true,
    ]);
});

function makeExpenseClaimForPayment(int $ownerId, array $overrides = []): HrExpenseClaim
{
    return HrExpenseClaim::query()->create(array_merge([
        'user_id' => $ownerId,
        'claim_number' => 'EXP-PAY-'.fake()->unique()->numberBetween(1000, 999999),
        'title' => 'Travel claim',
        'status' => 'approved',
        'total_amount' => 100,
        'currency' => 'NZD',
        'submitted_at' => now()->subDays(2),
        'approved_at' => now()->subDay(),
        'journal_id' => 9999,
        'gl_posted_at' => now()->subDay(),
        'created_by' => $ownerId,
    ], $overrides));
}

test('an approved, GL-posted claim can be marked paid', function () {
    $claim = makeExpenseClaimForPayment($this->worker->id);

    $this->actingAs($this->hr)
        ->post("/hr/compensation/expenses/{$claim->id}/pay")
        ->assertSessionHas('success');

    $claim->refresh();
    expect($claim->status)->toBe('paid');
    expect($claim->paid_at)->not->toBeNull();
});

test('a claim not yet posted to the GL cannot be marked paid', function () {
    $claim = makeExpenseClaimForPayment($this->worker->id, [
        'journal_id' => null,
        'gl_posted_at' => null,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/compensation/expenses/{$claim->id}/pay")
        ->assertSessionHas('error');

    expect($claim->fresh()->status)->toBe('approved');
});

test('a user without hr.expenses.approve cannot mark a claim paid', function () {
    $claim = makeExpenseClaimForPayment($this->worker->id);

    $this->actingAs($this->worker)
        ->post("/hr/compensation/expenses/{$claim->id}/pay")
        ->assertForbidden();

    expect($claim->fresh()->status)->toBe('approved');
});

test('the claim detail surfaces GL posting state and the pay action', function () {
    $claim = makeExpenseClaimForPayment($this->worker->id);

    $response = $this->actingAs($this->hr)->get("/hr/compensation/expenses/{$claim->id}");
    $response->assertOk();

    expect($response->inertiaProps('claim.gl_posted_at'))->not->toBeNull();
    expect($response->inertiaProps('claim.journal_id'))->toBe(9999);
    expect($response->inertiaProps('can.pay'))->toBeTrue();
});

test('approving a claim posts exactly one GL journal (no observer double-post)', function () {
    Queue::fake();

    // A submitted claim with no journal yet.
    $claim = makeExpenseClaimForPayment($this->worker->id, [
        'status' => 'submitted',
        'journal_id' => null,
        'gl_posted_at' => null,
        'approved_at' => null,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/compensation/expenses/{$claim->id}/approve")
        ->assertSessionHas('success');

    // Path A (the purpose-built expense journal) fires exactly once…
    Queue::assertPushed(PostExpenseJournalJob::class, 1);
    // …and the retired observer's second, conflicting posting never fires.
    Queue::assertNotPushed(ProcessFinancialEventJob::class);
});

test('the expenses index lists Site-visible claims', function () {
    $claim = makeExpenseClaimForPayment($this->worker->id, ['status' => 'submitted']);

    $response = $this->actingAs($this->hr)->get('/hr/compensation/expenses');
    $response->assertOk();

    $ids = collect($response->inertiaProps('claims.data'))->pluck('id')->all();
    expect($ids)->toContain($claim->id);
});
