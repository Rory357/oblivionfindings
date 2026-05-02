<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportControllerShowTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        $this->manager = User::factory()->create([
            'organization_id' => 1,
            'name' => 'Reports Manager',
        ]);

        $permission = Permission::firstOrCreate([
            'key' => 'operations.reports.view',
        ], [
            'description' => 'View operations reports',
            'group' => 'operations',
            'module' => 'operations',
        ]);

        $this->manager->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }

    public function test_each_secondary_report_renders_show_component(): void
    {
        [$site, $client, $staff] = $this->seedReportData();
        $this->assignAccessibleSites([$site]);

        foreach (['client-summary', 'staff-utilisation', 'shift-analytics', 'billing', 'compliance', 'service-hours'] as $type) {
            $this->actingAs($this->manager)
                ->get("/operations/reports/{$type}?date_from=2026-04-01&date_to=2026-04-30&client_id={$client->id}&staff_id={$staff->id}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('operations/reports/Show')
                    ->where('report_type', $type)
                    ->has('filters')
                );
        }
    }

    public function test_client_filter_limits_client_summary_and_service_hours(): void
    {
        [$site, $client, $staff] = $this->seedReportData();
        $otherClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'first_name' => 'Other',
            'last_name' => 'Client',
            'status' => 'active',
        ]);
        BillingEntry::create([
            'organization_id' => 1,
            'client_id' => $otherClient->id,
            'site_id' => $site->id,
            'staff_id' => $staff->id,
            'service_date' => '2026-04-12',
            'hours' => 2,
            'rate' => 100,
            'amount' => 200,
            'rate_type' => 'standard',
            'status' => 'draft',
        ]);
        $this->assignAccessibleSites([$site]);

        $this->actingAs($this->manager)
            ->get("/operations/reports/client-summary?date_from=2026-04-01&date_to=2026-04-30&client_id={$client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.client_id', (string) $client->id)
                ->where('data.total_clients', 1)
                ->where('data.by_client.0.client_id', $client->id)
                ->has('clients')
            );
    }

    public function test_staff_filter_limits_staff_utilisation(): void
    {
        [$site, , $staff] = $this->seedReportData();
        $this->assignAccessibleSites([$site]);

        $this->actingAs($this->manager)
            ->get("/operations/reports/staff-utilisation?date_from=2026-04-01&date_to=2026-04-30&staff_id={$staff->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.staff_id', (string) $staff->id)
                ->where('data.total_staff', 1)
                ->where('data.by_staff.0.user_id', $staff->id)
                ->has('staff')
            );
    }

    public function test_compliance_report_honours_client_filter(): void
    {
        [$site, $client, $staff] = $this->seedReportData();
        $otherClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'first_name' => 'Other',
            'last_name' => 'Compliance Client',
            'status' => 'active',
        ]);
        $otherStaff = User::factory()->create([
            'organization_id' => 1,
            'name' => 'Other Compliance Staff',
        ]);
        HrEmployeeProfile::query()->create([
            'user_id' => $otherStaff->id,
            'tenant_id' => 1,
            'employee_number' => 'EMP-OTHER-'.$otherStaff->id,
            'work_email' => $otherStaff->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $otherClient->id,
            'user_id' => $otherStaff->id,
            'created_by' => $this->manager->id,
            'starts_at' => Carbon::parse('2026-04-11 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-11 13:00:00'),
            'status' => 'scheduled',
        ]);
        StaffCredential::query()->create([
            'user_id' => $staff->id,
            'type' => 'first_aid',
            'issuer' => 'Red Cross NZ',
            'issued_at' => now()->subYear(),
            'expires_at' => now()->addYear(),
        ]);
        StaffCredential::query()->create([
            'user_id' => $otherStaff->id,
            'type' => 'first_aid',
            'issuer' => 'Red Cross NZ',
            'issued_at' => now()->subYear(),
            'expires_at' => now()->addYear(),
        ]);
        $this->assignAccessibleSites([$site]);

        $this->actingAs($this->manager)
            ->get("/operations/reports/compliance?date_from=2026-04-01&date_to=2026-04-30&client_id={$client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.client_id', (string) $client->id)
                ->where('data.total_staff', 1)
                ->where('data.total_credentials', 1)
                ->has('clients')
            );
    }

    public function test_unauthorised_and_unknown_report_types_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/operations/reports/billing')
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->get('/operations/reports/not-real')
            ->assertNotFound();
    }

    protected function seedReportData(): array
    {
        $site = Site::factory()->create(['name' => 'North House']);
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'first_name' => 'Aroha',
            'last_name' => 'Client',
            'status' => 'active',
        ]);
        $staff = User::factory()->create([
            'organization_id' => 1,
            'name' => 'Riley Staff',
        ]);
        HrEmployeeProfile::query()->create([
            'user_id' => $staff->id,
            'tenant_id' => 1,
            'employee_number' => 'EMP-STAFF-'.$staff->id,
            'work_email' => $staff->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        $serviceContext = ServiceContext::factory()->create();

        $shift = Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'created_by' => $this->manager->id,
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 13:00:00'),
            'status' => 'scheduled',
        ]);

        Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => '2026-04-10',
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 13:00:00'),
            'break_minutes' => 0,
            'status' => 'approved',
        ]);

        BillingEntry::create([
            'organization_id' => 1,
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'staff_id' => $staff->id,
            'service_date' => '2026-04-10',
            'hours' => 4,
            'rate' => 100,
            'amount' => 400,
            'rate_type' => 'standard',
            'site_name_snapshot' => $site->name,
            'client_name_snapshot' => 'Aroha Client',
            'staff_name_snapshot' => 'Riley Staff',
            'status' => 'approved',
        ]);

        return [$site, $client, $staff];
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
                'employee_number' => 'EMP-REPORT-SHOW-'.$this->manager->id,
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
