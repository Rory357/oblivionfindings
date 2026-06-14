<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    // hr.vetting.* is granted to provider_manager via RbacSeeder.
    $this->manager = User::factory()->create([
        'organization_id' => 1,
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    // assertStaffCheckTenantAccess requires the check's user to have a
    // tenant-1 profile.
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $this->check = StaffBackgroundCheck::query()->create([
        'user_id' => $this->worker->id,
        'check_type' => 'police_check',
        'status' => 'clear',
    ]);
});

test('a manager can record consent against a vetting check', function () {
    $this->actingAs($this->manager)
        ->post("/hr/compliance/vetting/{$this->check->id}/consent", [
            'consent_given' => true,
            'consent_notes' => 'Consent form signed and filed.',
        ])
        ->assertSessionHas('success');

    $notes = $this->check->fresh()->notes;
    expect($notes)->toContain('[Consent recorded');
    expect($notes)->toContain('Consent form signed and filed.');
});

test('consent must be affirmatively given (accepted rule)', function () {
    $this->actingAs($this->manager)
        ->post("/hr/compliance/vetting/{$this->check->id}/consent", [
            'consent_given' => false,
        ])
        ->assertSessionHasErrors('consent_given');

    expect((string) $this->check->fresh()->notes)->not->toContain('[Consent recorded');
});

test('a user without hr.vetting.manage cannot record consent', function () {
    $this->actingAs($this->worker)
        ->post("/hr/compliance/vetting/{$this->check->id}/consent", [
            'consent_given' => true,
        ])
        ->assertForbidden();

    expect((string) $this->check->fresh()->notes)->not->toContain('[Consent recorded');
});
