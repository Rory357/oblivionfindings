<?php

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
});

test('payroll run creation falls back to a default tenant when user has no tenant_id attribute', function () {
    $response = $this->actingAs($this->hr)->post('/hr/payroll/runs', [
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->startOfMonth()->addDays(13)->toDateString(),
    ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_payroll_runs', [
        'created_by' => $this->hr->id,
        'tenant_id' => 1,
        'status' => 'draft',
    ]);
});
