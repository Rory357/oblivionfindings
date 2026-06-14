<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // hr.approvals.* are in SeedHrPermissionsSeeder → the hr role gets them.
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
});

test('creating an approval chain resolves the tenant and lists it', function () {
    $this->actingAs($this->hr)->post('/hr/approvals/chains', [
        'name' => 'Leave Approval',
        'process_type' => 'leave',
        'is_active' => true,
        'steps' => [
            ['step_order' => 1, 'approver_type' => 'manager'],
        ],
    ])->assertRedirect(route('hr.approvals.chains'));

    $this->assertDatabaseHas('hr_approval_chains', [
        'tenant_id' => 1,
        'name' => 'Leave Approval',
        'process_type' => 'leave',
        'created_by' => $this->hr->id,
    ]);

    $names = collect(
        $this->actingAs($this->hr)->get('/hr/approvals/chains')->inertiaProps('chains'),
    )->pluck('name')->all();
    expect($names)->toContain('Leave Approval');
});

test('the pending inbox loads with an honest (empty) list', function () {
    // No business flow calls initiateApproval yet, so the inbox is legitimately
    // empty — it must still load (was forTenant(null) → whereNull before).
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
