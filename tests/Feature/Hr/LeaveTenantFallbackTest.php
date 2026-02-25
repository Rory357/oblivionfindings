<?php

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
});

test('leave request creation falls back to a default tenant when user has no tenant_id attribute', function () {
    $response = $this->actingAs($this->hr)->post('/hr/leave', [
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(2)->toDateString(),
        'ends_at' => now()->addDays(2)->toDateString(),
        'hours_requested' => 8,
        'reason' => 'Family appointment.',
    ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_leave_requests', [
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('hr_leave_balances', [
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'year' => now()->year,
    ]);
});
