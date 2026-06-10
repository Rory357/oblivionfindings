<?php

use App\Domain\Hr\Models\HrSavedReport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
});

test('report builder posts actions to registered builder routes', function () {
    $builderSource = file_get_contents(resource_path('js/pages/hr/reports/builder.tsx'));

    expect($builderSource)->toContain("router.post('/hr/reports/builder'")
        ->and($builderSource)->toContain("fetch('/hr/reports/builder/preview'")
        ->and($builderSource)->not()->toContain("router.post('/hr/reports/save'")
        ->and($builderSource)->not()->toContain("fetch('/hr/reports/preview'");
});

test('report builder store payload creates a saved report', function () {
    $user = User::factory()->create(['approved_at' => now()]);
    $user->roles()->sync([Role::where('name', 'hr')->firstOrFail()->id]);

    $response = $this->actingAs($user)->post('/hr/reports/builder', [
        'name' => 'Active employee list',
        'description' => 'Reusable people report',
        'report_type' => 'employee',
        'fields' => ['employee_number', 'name', 'email'],
        'filters' => [
            [
                'field' => 'is_active',
                'operator' => '=',
                'value' => '1',
            ],
        ],
        'group_by' => null,
        'sort_by' => 'name',
        'sort_direction' => 'asc',
    ]);

    $response->assertRedirect(route('hr.reports.saved'));

    $report = HrSavedReport::query()->where('name', 'Active employee list')->first();

    expect($report)->not()->toBeNull()
        ->and($report->created_by)->toBe($user->id)
        ->and($report->report_type)->toBe('employee')
        ->and($report->fields)->toBe(['employee_number', 'name', 'email'])
        ->and($report->filters)->toHaveCount(1);
});
