<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrSavedReport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->visibleSite = Site::factory()->create(['name' => 'Report Builder visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Report Builder hidden Site']);

    $this->manager = reportBuilderUser('Report Builder HR manager', $this->visibleSite, 'HR-RPT-MANAGER');
    $this->manager->roles()->sync([Role::query()->where('name', 'hr')->firstOrFail()->id]);

    $this->visibleWorker = reportBuilderUser('Visible report worker', $this->visibleSite, 'HR-RPT-VISIBLE');
    $this->hiddenWorker = reportBuilderUser('Hidden report worker', $this->hiddenSite, 'HR-RPT-HIDDEN');

    reportBuilderLeave($this->visibleWorker, 'Visible medical detail');
    reportBuilderLeave($this->hiddenWorker, 'Hidden medical detail');

    $observerRole = Role::query()->create([
        'name' => 'report-builder-observer',
        'label' => 'Report builder observer',
        'type' => 'custom',
        'level' => 20,
    ]);
    $observerRole->permissions()->sync(Permission::query()
        ->whereIn('key', [
            'hr.reports.view',
            'hr.reports.export',
            'hr.employees.viewAny',
            'hr.leave.viewAny',
        ])
        ->pluck('id')
        ->all());

    $this->observer = reportBuilderUser('Report Builder observer', $this->visibleSite, 'HR-RPT-OBSERVER');
    $this->observer->roles()->sync([$observerRole->id]);
});

function reportBuilderUser(string $name, Site $site, string $employeeNumber): User
{
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => $employeeNumber,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => 'support_worker',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'hours_per_week' => 40,
        'pay_frequency' => 'fortnightly',
        'gender' => 'prefer_not_to_say',
        'ethnicity' => 'Not stated',
    ]);

    return $user;
}

function reportBuilderLeave(User $user, string $reason): HrLeaveRequest
{
    return HrLeaveRequest::query()->create([
        'user_id' => $user->id,
        'leave_type' => 'sick',
        'period' => 'full_day',
        'starts_at' => today()->addWeek(),
        'ends_at' => today()->addWeek(),
        'hours_requested' => 8,
        'status' => 'approved',
        'submitted_at' => now(),
        'escalation_level' => 1,
        'reason' => $reason,
    ]);
}

test('report previews and saved runs include only staff from Sites visible to the viewer', function (): void {
    $preview = $this->actingAs($this->manager)->postJson('/hr/reports/builder/preview', [
        'report_type' => 'leave',
        'fields' => ['employee_name', 'reason'],
        'sort_by' => 'employee_name',
        'sort_direction' => 'asc',
    ])->assertOk();

    expect(collect($preview->json('data'))->pluck('employee_name')->all())
        ->toBe(['Visible report worker'])
        ->and($preview->json('total'))->toBe(1)
        ->and($preview->json('data.0.reason'))->toBe('Visible medical detail');

    $report = HrSavedReport::query()->create([
        'name' => 'Visible Site leave register',
        'report_type' => 'leave',
        'fields' => ['employee_name', 'reason'],
        'sort_by' => 'employee_name',
        'sort_direction' => 'asc',
        'created_by' => $this->manager->id,
    ]);

    $run = $this->actingAs($this->manager)
        ->postJson("/hr/reports/saved/{$report->id}/run")
        ->assertOk();

    expect(collect($run->json('data'))->pluck('employee_name')->all())
        ->toBe(['Visible report worker']);

    $csvResponse = $this->actingAs($this->manager)
        ->get("/hr/reports/saved/{$report->id}/export")
        ->assertOk();
    $csv = $csvResponse->getContent();

    expect($csv)->toContain('Visible report worker')
        ->not->toContain('Hidden report worker')
        ->not->toContain('Hidden medical detail');
});

test('report definitions reject fields and operators the viewer is not allowed to use', function (): void {
    $builder = $this->actingAs($this->observer)
        ->get('/hr/reports/builder')
        ->assertOk();

    $employeeFields = collect($builder->inertiaProps('sources')['employee']['fields']);
    expect($employeeFields)
        ->not->toContain('hours_per_week')
        ->not->toContain('pay_frequency')
        ->not->toContain('gender')
        ->not->toContain('ethnicity')
        ->and(collect($builder->inertiaProps('sources')['leave']['fields']))
        ->not->toContain('reason');

    $this->actingAs($this->observer)
        ->postJson('/hr/reports/builder/preview', [
            'report_type' => 'leave',
            'fields' => ['employee_name', 'reason'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fields');

    $this->actingAs($this->observer)
        ->postJson('/hr/reports/builder/preview', [
            'report_type' => 'employee',
            'fields' => ['name'],
            'filters' => [[
                'field' => 'users.password',
                'operator' => '=',
                'value' => 'x',
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('filters.0.field');

    $this->actingAs($this->observer)
        ->postJson('/hr/reports/builder/preview', [
            'report_type' => 'employee',
            'fields' => ['name'],
            'filters' => [[
                'field' => 'name',
                'operator' => 'or 1 = 1',
                'value' => 'x',
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('filters.0.operator');
});

test('saved definitions are creator-owned and concealed from other report viewers', function (): void {
    $report = HrSavedReport::query()->create([
        'name' => 'Manager private definition',
        'description' => 'A creator-owned report definition',
        'report_type' => 'employee',
        'fields' => ['employee_number', 'name'],
        'sort_direction' => 'asc',
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->observer)
        ->get('/hr/reports/saved')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/reports/saved')
            ->has('reports.data', 0));

    $this->actingAs($this->observer)
        ->postJson("/hr/reports/saved/{$report->id}/run")
        ->assertNotFound();
    $this->actingAs($this->observer)
        ->get("/hr/reports/saved/{$report->id}/export")
        ->assertNotFound();
    $this->actingAs($this->observer)
        ->delete("/hr/reports/saved/{$report->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('hr_saved_reports', ['id' => $report->id]);
});

test('saved reports do not expose a second inert scheduling API', function (): void {
    expect(Route::has('hr.reports.saved.schedule'))->toBeFalse()
        ->and(file_get_contents(resource_path('js/pages/hr/reports/saved.tsx')))
        ->not->toContain('is_scheduled');
});
