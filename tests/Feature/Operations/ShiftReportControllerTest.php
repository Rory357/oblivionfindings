<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-04-06 12:00:00'));

        $this->manager = User::factory()->create();
        $permission = Permission::firstOrCreate([
            'key' => 'operations.reports.view',
        ], [
            'description' => 'View operations reports',
            'group' => 'operations',
            'module' => 'operations',
        ]);

        $this->manager->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }

    public function test_shift_report_index_returns_expected_component_and_props(): void
    {
        [$site, $client, $serviceContext] = $this->makeSiteContext();
        $this->assignAccessibleSites([$site]);
        $staff = User::factory()->create(['name' => 'Riley Staff']);

        Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'created_by' => $this->manager->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->manager)
            ->get('/operations/reports/shifts?date_from=2026-04-01&date_to=2026-04-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/reports/Shifts')
                ->where('filters.date_from', '2026-04-01')
                ->where('filters.date_to', '2026-04-30')
                ->has('sites')
                ->has('staff')
                ->where('report.staff_utilisation.total_shifts', 1)
            );
    }

    public function test_shift_report_filters_by_date_and_site(): void
    {
        [$siteA, $clientA, $serviceContextA] = $this->makeSiteContext();
        [$siteB, $clientB, $serviceContextB] = $this->makeSiteContext();
        $this->assignAccessibleSites([$siteA]);
        $staff = User::factory()->create();

        Shift::factory()->create([
            'site_id' => $siteA->id,
            'client_id' => $clientA->id,
            'service_context_id' => $serviceContextA->id,
            'user_id' => $staff->id,
            'created_by' => $this->manager->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
        ]);

        Shift::factory()->create([
            'site_id' => $siteB->id,
            'client_id' => $clientB->id,
            'service_context_id' => $serviceContextB->id,
            'user_id' => $staff->id,
            'created_by' => $this->manager->id,
            'starts_at' => Carbon::parse('2026-05-01 09:00:00'),
            'ends_at' => Carbon::parse('2026-05-01 17:00:00'),
        ]);

        $this->actingAs($this->manager)
            ->get("/operations/reports/shifts?date_from=2026-04-01&date_to=2026-04-30&site_id={$siteA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.staff_utilisation.total_shifts', 1)
                ->where('report.attendance_variance_report.shift_rows.0.site_name', $siteA->name)
            );
    }

    public function test_shift_report_export_streams_csv_for_requested_dataset(): void
    {
        [$site, $client, $serviceContext] = $this->makeSiteContext();
        $this->assignAccessibleSites([$site]);
        $staff = User::factory()->create(['name' => 'Jordan Staff']);

        $shift = Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'created_by' => $this->manager->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
        ]);

        Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => '2026-04-06',
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'break_minutes' => 30,
        ]);

        $response = $this->actingAs($this->manager)
            ->get('/operations/reports/shifts/export?dataset=staff-utilisation&date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString('Staff,"Total Shifts","Planned Hours","Worked Hours","Hours Per Week","Overtime Flag"', $content);
        $this->assertStringContainsString('Jordan Staff', $content);
    }

    public function test_legacy_shift_report_url_redirects_to_operations_report(): void
    {
        $this->actingAs($this->manager)
            ->get('/reports/shifts')
            ->assertRedirect('/operations/reports/shifts')
            ->assertStatus(301);
    }

    protected function makeSiteContext(): array
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $serviceContext = ServiceContext::factory()->create();

        return [$site, $client, $serviceContext];
    }

    /**
     * @param  array<int, Site>  $sites
     */
    protected function assignAccessibleSites(array $sites): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $this->manager->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'EMP-REPORT-'.$this->manager->id,
                'work_email' => $this->manager->email,
                'position_title' => 'Manager',
                'position_role' => 'manager',
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $sites[0]->id ?? null,
                'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            ],
        );
    }
}
