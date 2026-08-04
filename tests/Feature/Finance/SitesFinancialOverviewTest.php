<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\SiteBudgetLine;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\LegacyStorageContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitesFinancialOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_sites_financial_overview_returns_sites_and_kpis(): void
    {
        $house = Site::factory()->create([
            'type' => 'house',
            'name' => 'Aroha House',
        ]);
        $facility = Site::factory()->create([
            'type' => 'facility',
            'name' => 'Kauri Facility',
        ]);

        $this->createAllocation($house, 'payroll_cost', 1000.00, '2026-05-03');
        $this->createAllocation($facility, 'site_utilities_expense', 300.00, '2026-05-05');

        SiteBudgetLine::create([
            ...LegacyStorageContext::attributes(),
            'site_id' => $house->id,
            'period' => '2026-05',
            'category' => 'payroll',
            'planned_amount' => 800.00,
            'created_by' => $this->admin->id,
        ]);
        SiteBudgetLine::create([
            ...LegacyStorageContext::attributes(),
            'site_id' => $facility->id,
            'period' => '2026-05',
            'category' => 'utilities',
            'planned_amount' => 500.00,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/finance/sites?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('finance/sites-overview/Show')
                ->has('sites', 2)
                ->where('kpis.total_cost', '1300.00')
                ->where('kpis.sites_over_budget', 1)
                ->where('kpis.avg_cost_per_site', '650.00')
            );
    }

    public function test_sites_financial_overview_requires_finance_dashboard_permission(): void
    {
        $supportWorker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($supportWorker)
            ->get('/finance/sites')
            ->assertForbidden();
    }

    public function test_sites_financial_overview_only_includes_sites_accessible_to_current_employee(): void
    {
        $visibleSite = Site::factory()->create([
            'type' => 'house',
            'name' => 'Visible House',
        ]);
        $hiddenSite = Site::factory()->create([
            'type' => 'house',
            'name' => 'Hidden House',
        ]);

        $viewer = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::where('name', 'support_worker')->first());
        $permission = Permission::query()->where('key', 'finance.dashboard')->firstOrFail();
        $viewer->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $visibleSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        $this->createAllocation($visibleSite, 'payroll_cost', 250.00, '2026-05-03');
        $this->createAllocation($hiddenSite, 'payroll_cost', 900.00, '2026-05-03');

        $this->actingAs($viewer)
            ->get('/finance/sites?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('finance/sites-overview/Show')
                ->has('sites', 1)
                ->where('sites.0.site.name', 'Visible House')
                ->where('kpis.total_cost', '250.00')
            );
    }

    private function createAllocation(Site $site, string $eventType, float $amount, string $date): void
    {
        $account = FinAccount::factory()->create([
            'type' => 'expense',
        ]);
        $journal = FinJournal::factory()->create([
            'journal_date' => $date,
            'status' => 'posted',
        ]);
        $line = FinJournalLine::create([
            'journal_id' => $journal->id,
            'account_id' => $account->id,
            'description' => $eventType,
            'debit' => $amount,
            'credit' => 0,
        ]);

        FinCostAllocation::create([
            'journal_id' => $journal->id,
            'journal_line_id' => $line->id,
            'site_id' => $site->id,
            'amount' => $amount,
            'event_type' => $eventType,
            'event_date' => $date,
        ]);
    }
}
