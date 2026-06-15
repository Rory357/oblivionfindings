<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // hr role holds ALL hr.* permissions (SeedHrPermissionsSeeder), so it can
    // reach every page in every hub.
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    // Some ESS (my/*) pages resolve the viewer's own employee profile (e.g.
    // my/documents firstOrFail()s on it), so the self-service user needs one —
    // the realistic case for an employee viewing their own My HR pages.
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-HUB-'.$this->hr->id,
        'work_email' => 'hub'.$this->hr->id.'@example.test',
        'position_title' => 'HR Administrator',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
});

// Each hub page must still render (200) after the section tab-strip was added —
// guards the new import + <HubTabs/> element against route/render breakage.
test('every page in the tab-shell hubs still renders for a permitted user', function (string $url) {
    $this->actingAs($this->hr)->get($url)->assertOk();
})->with([
    // Settings hub
    '/hr/settings/webhooks',
    '/hr/settings/custom-fields',
    '/hr/settings/audit-log',
    // Compensation hub
    '/hr/compensation/bands',
    '/hr/compensation/reviews',
    '/hr/compensation/bonuses',
    // Reports hub
    '/hr/reports',
    '/hr/reports/builder',
    '/hr/reports/saved',
    '/hr/reports/automations',
    '/hr/reports/webhooks',
    // Documents hub
    '/hr/documents',
    '/hr/documents/templates',
    // Payroll hub
    '/hr/payroll',
    '/hr/payroll/payslips',
    // Performance hub
    '/hr/performance',
    '/hr/performance/reviews',
    '/hr/goals',
    '/hr/performance/competencies',
    '/hr/feedback',
    '/hr/performance/pips',
    '/hr/succession',
    // My HR / ESS hub
    '/hr/my',
    '/hr/my/profile',
    '/hr/my/leave',
    '/hr/my/time',
    '/hr/my/expenses',
    '/hr/my/payslips',
    '/hr/my/reviews',
    '/hr/my/goals',
    '/hr/my/training',
    '/hr/my/documents',
    '/hr/my/policies',
    '/hr/my/surveys',
    // Compliance hub
    '/hr/compliance',
    '/hr/compliance/matrix',
    '/hr/compliance/calendar',
    '/hr/compliance/training',
    '/hr/compliance/vetting',
    '/hr/compliance/drivers',
]);
