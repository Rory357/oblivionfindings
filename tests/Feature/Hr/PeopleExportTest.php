<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'People export Site']);
});

function peopleExportProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        ...$overrides,
    ]);
}

test('people index export button submits to the existing exporter', function () {
    $source = file_get_contents(resource_path('js/pages/hr/employees/index.tsx'));

    expect($source)->toContain('function submitExport')
        ->and($source)->toContain("form.action = '/hr/import-export/export'")
        ->and($source)->toContain("form.method = 'POST'")
        // Phase-1 redesign: the export action moved into PeopleHero's quick-action
        // (passed as onExport) rather than a bare onClick button.
        ->and($source)->toContain('onExport: can.manage ? submitExport');
});

test('employee export endpoint downloads a csv for people managers', function () {
    $manager = User::factory()->create(['approved_at' => now()]);
    $manager->roles()->sync([Role::where('name', 'hr')->firstOrFail()->id]);
    peopleExportProfile($manager, $this->site, [
        'employee_number' => 'EMP-EXPORT-MANAGER',
    ]);

    $employee = User::factory()->create([
        'name' => 'Aroha Worker',
        'email' => 'aroha.worker@example.test',
        'approved_at' => now(),
    ]);

    peopleExportProfile($employee, $this->site, [
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

test('the people table bulk bar wires an export-selected action', function () {
    $source = file_get_contents(resource_path('js/components/hr/people-pane.tsx'));

    expect($source)->toContain('const exportSelected')
        ->and($source)->toContain("form.action = '/hr/import-export/export'")
        ->and($source)->toContain("addField('ids[]', String(id))")
        ->and($source)->toContain('onClick={exportSelected}');
});

test('export with selected ids returns only those people (incl. inactive)', function () {
    $manager = User::factory()->create(['approved_at' => now()]);
    $manager->roles()->sync([Role::where('name', 'hr')->firstOrFail()->id]);
    peopleExportProfile($manager, $this->site, [
        'employee_number' => 'EMP-SELECT-MANAGER',
    ]);

    $selected = User::factory()->create([
        'name' => 'Selected One',
        'email' => 'sel.one@example.test',
        'approved_at' => now(),
    ]);
    peopleExportProfile($selected, $this->site, [
        'employee_number' => 'EMP-SEL',
        'work_email' => 'sel.one@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'start_date' => '2026-01-01',
        'created_by' => $manager->id,
        'is_active' => false, // inactive — must still export when explicitly selected
    ]);

    $other = User::factory()->create([
        'name' => 'Other Two',
        'email' => 'other.two@example.test',
        'approved_at' => now(),
    ]);
    peopleExportProfile($other, $this->site, [
        'employee_number' => 'EMP-OTH',
        'work_email' => 'other.two@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'start_date' => '2026-01-01',
        'created_by' => $manager->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($manager)
        ->post('/hr/import-export/export', ['ids' => [$selected->id]]);

    $response->assertOk();
    $content = $response->streamedContent();

    expect($content)->toContain('EMP-SEL')      // selected (despite inactive)
        ->and($content)->not->toContain('EMP-OTH'); // not selected
});
