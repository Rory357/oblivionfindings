<?php

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->staff = User::factory()->create(['approved_at' => now()]);
    HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'leave_type' => 'sick',
        'period' => 'full_day',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek(),
        'hours_requested' => 8,
        'status' => 'approved',
        'submitted_at' => now(),
        'escalation_level' => 1,
        'reason' => 'Specialist appointment',
    ]);
});

test('a non-HR report viewer cannot include the leave reason column', function () {
    // hr.reports.view + hr.leave.viewAny, but NOT hr.leave.manage.
    $role = Role::query()->create([
        'name' => 'reports-only-no-manage',
        'label' => 'Reports only',
        'type' => 'custom',
        'level' => 20,
    ]);
    $role->permissions()->sync(
        Permission::query()->whereIn('key', ['hr.reports.view', 'hr.leave.viewAny'])->pluck('id')->all()
    );
    $viewer = User::factory()->create(['approved_at' => now()]);
    $viewer->roles()->sync([$role->id]);

    // The column picker must not offer "reason" for leave.
    $builder = $this->actingAs($viewer)->get('/hr/reports/builder')->assertOk();
    expect(collect($builder->inertiaProps('sources')['leave']['fields']))->not->toContain('reason');

    // Even when "reason" is requested directly, the preview rows must omit it
    // (and must NOT fall through to SELECT * and leak it instead).
    $preview = $this->actingAs($viewer)->postJson('/hr/reports/builder/preview', [
        'report_type' => 'leave',
        'fields' => ['employee_name', 'leave_type', 'reason'],
    ])->assertOk();

    $rows = $preview->json('data');
    expect($rows)->not->toBeEmpty();
    expect($rows[0])->not->toHaveKey('reason');
});

test('an HR (manage) report viewer keeps the leave reason column', function () {
    $hr = User::factory()->create(['approved_at' => now()]);
    $hr->roles()->sync([Role::where('name', 'hr')->firstOrFail()->id]);

    $builder = $this->actingAs($hr)->get('/hr/reports/builder')->assertOk();
    expect(collect($builder->inertiaProps('sources')['leave']['fields']))->toContain('reason');

    $preview = $this->actingAs($hr)->postJson('/hr/reports/builder/preview', [
        'report_type' => 'leave',
        'fields' => ['employee_name', 'leave_type', 'reason'],
    ])->assertOk();

    expect($preview->json('data.0.reason'))->toBe('Specialist appointment');
});
