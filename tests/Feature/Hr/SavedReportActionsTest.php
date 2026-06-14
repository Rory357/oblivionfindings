<?php

use App\Domain\Hr\Models\HrSavedReport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    // hr.reports.view/export are granted to provider_manager via RbacSeeder.
    $this->manager = User::factory()->create([
        'organization_id' => 1,
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->first()->id,
    ]);

    $this->report = HrSavedReport::query()->create([
        'tenant_id' => null,
        'name' => 'Active Staff',
        'report_type' => 'employee',
        'fields' => ['employee_number', 'name'],
        'sort_direction' => 'asc',
        'created_by' => $this->manager->id,
    ]);
});

test('running a saved report returns JSON data (path was 404)', function () {
    // Regression: the page POSTed /hr/reports/{id}/run, but the route is
    // /hr/reports/saved/{id}/run → 404.
    $response = $this->actingAs($this->manager)
        ->postJson("/hr/reports/saved/{$this->report->id}/run");

    $response->assertOk();
    $response->assertJsonStructure(['data', 'fields']);
});

test('exporting a saved report downloads an honest CSV (not a corrupt .xlsx)', function () {
    $response = $this->actingAs($this->manager)
        ->get("/hr/reports/saved/{$this->report->id}/export");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    $disposition = (string) $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('.csv');
    expect($disposition)->not->toContain('.xlsx');
});

test('deleting a saved report works (path was 404)', function () {
    $this->actingAs($this->manager)
        ->delete("/hr/reports/saved/{$this->report->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('hr_saved_reports', ['id' => $this->report->id]);
});

test('the saved-report action routes resolve under /reports/saved', function () {
    expect(route('hr.reports.saved.run', $this->report, false))
        ->toBe("/hr/reports/saved/{$this->report->id}/run");
    expect(route('hr.reports.saved.export', $this->report, false))
        ->toBe("/hr/reports/saved/{$this->report->id}/export");
});
