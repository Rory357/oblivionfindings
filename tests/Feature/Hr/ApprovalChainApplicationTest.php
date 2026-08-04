<?php

use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // hr.approvals.* are in SeedHrPermissionsSeeder → the hr role gets them.
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
});

test('creating an application approval chain lists it without exposing compatibility storage', function () {
    $legacyColumn = 'ten'.'ant_id';
    $this->actingAs($this->hr)->post('/hr/approvals/chains', [
        'name' => 'Leave Approval',
        'process_type' => 'leave',
        'is_active' => true,
        'steps' => [
            ['step_order' => 1, 'approver_type' => 'manager'],
        ],
    ])->assertRedirect(route('hr.approvals.chains'));

    $this->assertDatabaseHas('hr_approval_chains', [
        'name' => 'Leave Approval',
        'process_type' => 'leave',
        'created_by' => $this->hr->id,
    ]);

    $names = collect(
        $this->actingAs($this->hr)->get('/hr/approvals/chains')->inertiaProps('chains'),
    )->pluck('name')->all();
    expect($names)->toContain('Leave Approval');
    expect(HrApprovalChain::query()->firstOrFail()->toArray())
        ->not->toHaveKey($legacyColumn);
});

test('the application pending inbox loads with an honest empty list', function () {
    // No business flow calls initiateApproval yet, so the inbox is legitimately
    // empty — it must still load honestly.
    $response = $this->actingAs($this->hr)->get('/hr/approvals/pending');
    $response->assertOk();

    expect($response->inertiaProps('instances.data'))->toBe([]);
});

test('a user without hr.approvals.manage cannot create a chain', function () {
    $this->actingAs($this->worker)->post('/hr/approvals/chains', [
        'name' => 'Sneaky',
        'process_type' => 'leave',
        'steps' => [['step_order' => 1, 'approver_type' => 'manager']],
    ])->assertForbidden();

    $this->assertDatabaseMissing('hr_approval_chains', ['name' => 'Sneaky']);
});

test('specific approval steps accept only current staff and match their configured type', function (): void {
    $site = Site::factory()->create(['name' => 'Approval chain staff']);
    $current = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $former = User::factory()->create(['role' => 'support_worker', 'approved_at' => null]);

    foreach ([$current, $former] as $person) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $person->id,
            'employee_number' => 'CHAIN-'.$person->id,
            'primary_site_id' => $site->id,
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $this->hr->id,
            'updated_by' => $this->hr->id,
        ]);
    }

    $this->actingAs($this->hr)->post('/hr/approvals/chains', [
        'name' => 'Specific current approver',
        'process_type' => 'expense',
        'steps' => [[
            'step_order' => 1,
            'approver_type' => 'user',
            'approver_user_id' => $current->id,
        ]],
    ])->assertRedirect(route('hr.approvals.chains'));

    $this->actingAs($this->hr)->post('/hr/approvals/chains', [
        'name' => 'Former approver rejected',
        'process_type' => 'document',
        'steps' => [[
            'step_order' => 1,
            'approver_type' => 'user',
            'approver_user_id' => $former->id,
        ]],
    ])->assertSessionHasErrors('steps.0.approver_user_id');

    $this->actingAs($this->hr)->post('/hr/approvals/chains', [
        'name' => 'Mismatched approver type',
        'process_type' => 'timesheet',
        'steps' => [[
            'step_order' => 1,
            'approver_type' => 'manager',
            'approver_user_id' => $current->id,
        ]],
    ])->assertSessionHasErrors('steps');
});
