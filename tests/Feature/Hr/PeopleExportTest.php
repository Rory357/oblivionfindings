<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
});

test('people index export button submits to the existing exporter', function () {
    $source = file_get_contents(resource_path('js/pages/hr/employees/index.tsx'));

    expect($source)->toContain('function submitExport')
        ->and($source)->toContain("form.action = '/hr/import-export/export'")
        ->and($source)->toContain("form.method = 'POST'")
        ->and($source)->toContain('onClick={submitExport}');
});

test('employee export endpoint downloads a csv for people managers', function () {
    $manager = User::factory()->create(['approved_at' => now()]);
    $manager->roles()->sync([Role::where('name', 'hr')->firstOrFail()->id]);

    $employee = User::factory()->create([
        'name' => 'Aroha Worker',
        'email' => 'aroha.worker@example.test',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $employee->id,
        'employee_number' => 'EMP-100',
        'work_email' => 'aroha.worker@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'department' => 'Community',
        'start_date' => '2026-01-12',
        'hours_per_week' => 40,
        'created_by' => $manager->id,
    ]);

    $response = $this->actingAs($manager)->post('/hr/import-export/export');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertDownload();
    $response->streamedContent();

    expect($response->streamedContent())->toContain('employee_number,name,email')
        ->and($response->streamedContent())->toContain('EMP-100,"Aroha Worker",aroha.worker@example.test');
});
