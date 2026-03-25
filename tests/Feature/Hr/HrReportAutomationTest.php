<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrReportExport;
use App\Domain\Hr\Models\HrReportSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');

    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', 'hr')->first();
    if ($role) {
        $this->hr->roles()->syncWithoutDetaching([$role->id]);
    }

    $employeeUser = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $employeeUser->id,
        'employee_number' => 'EMP95001',
        'work_email' => "worker-{$employeeUser->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('hr user can create report subscriptions and download exports', function () {
    $this->actingAs($this->hr)
        ->post('/hr/reports/subscriptions', [
            'report_type' => 'headcount',
            'cadence' => 'weekly',
            'day_of_week' => 1,
            'run_at' => '09:30',
            'timezone' => 'Pacific/Auckland',
            'recipient_user_ids' => [$this->hr->id],
        ])
        ->assertSessionHas('success');

    $subscription = HrReportSubscription::query()->latest('id')->first();
    expect($subscription)->not->toBeNull();
    expect($subscription->report_type)->toBe('headcount');
    expect($subscription->next_run_at)->not->toBeNull();

    $this->actingAs($this->hr)
        ->get('/hr/reports/export?report_type=headcount')
        ->assertDownload();

    $export = HrReportExport::query()->latest('id')->first();
    expect($export)->not->toBeNull();
    expect(Storage::disk('private')->exists($export->storage_path))->toBeTrue();

    $this->actingAs($this->hr)
        ->get("/hr/reports/exports/{$export->id}")
        ->assertOk();

    $this->actingAs($this->hr)
        ->get("/hr/reports/exports/{$export->id}/download")
        ->assertDownload();
});
