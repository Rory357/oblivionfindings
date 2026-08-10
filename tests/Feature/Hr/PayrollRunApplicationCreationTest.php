<?php

use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
});

test('payroll run creation uses one application period without user partition context', function () {
    $response = $this->actingAs($this->hr)->post('/hr/payroll/runs', [
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->startOfMonth()->addDays(13)->toDateString(),
    ]);

    $response->assertSessionHas('success');

    $run = HrPayrollRun::query()->where('created_by', $this->hr->id)->firstOrFail();
    expect($run->status)->toBe('draft')
        ->and($run->period_start->toDateString())->toBe(now()->startOfMonth()->toDateString())
        ->and($run->period_end->toDateString())->toBe(now()->startOfMonth()->addDays(13)->toDateString());
});
