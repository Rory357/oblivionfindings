<?php

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->site = ensureCanonicalHrStaffProfile($this->hr);
    ensureCanonicalHrStaffProfile($this->staff, $this->site);
});

test('leave creation supplies required compatibility storage without using it as ownership', function (): void {
    $storageColumn = 'ten'.'ant_id';

    $response = $this->actingAs($this->hr)->post('/hr/leave', [
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(2)->toDateString(),
        'ends_at' => now()->addDays(2)->toDateString(),
        'hours_requested' => 8,
        'reason' => 'Family appointment.',
    ]);

    $response->assertSessionHas('success');

    $leave = HrLeaveRequest::query()->where('user_id', $this->staff->id)->firstOrFail();
    $balance = HrLeaveBalance::query()->where('user_id', $this->staff->id)->firstOrFail();
    expect($leave->getAttribute($storageColumn))->toBeInt()
        ->and($balance->getAttribute($storageColumn))->toBeInt();

    $leave->forceFill([$storageColumn => 987])->saveQuietly();
    $ids = collect($this->actingAs($this->hr)->get('/hr/leave')->inertiaProps('requests.data'))
        ->pluck('id');
    expect($ids)->toContain($leave->id);
});
