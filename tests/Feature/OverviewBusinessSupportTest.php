<?php

namespace Tests\Feature;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Overview\OverviewAccess;
use Carbon\CarbonImmutable;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Business support overview: finance, compliance, safety, fleet, risk and audit staff. */
class OverviewBusinessSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, OperationsPermissionsSeeder::class]);
    }

    /** @param array<string, bool> $overrides permission key => allowed */
    private function actor(string $role, array $overrides = []): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role], ['label' => $role])->id);
        foreach ($overrides as $key => $allowed) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
        }

        return $user->fresh();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Pacific/Auckland')->startOfDay();
    }

    public function test_back_office_staff_open_business_support_and_frontline_staff_do_not_see_it(): void
    {
        $access = app(OverviewAccess::class);
        foreach (['finance', 'auditor', 'health_safety_officer', 'compliance_lead', 'fleet_manager'] as $role) {
            $user = $this->actor($role);
            $this->assertContains('office', $access->roles($user), $role);
            $this->assertSame('office', $access->defaultRole($user), $role);
        }
        $this->actingAs($this->actor('finance'))->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('role', 'office')->where('defaultRole', 'office')
            ->where('defaultLayout', ['attention', 'focus', 'office-payroll', 'office-bills', 'trend', 'distribution'])
            ->where('overview.primary.href', '/finance'));
        // Auditors start from the audit trail and compliance.
        $this->assertSame(['attention', 'focus', 'office-audit', 'office-compliance'], array_slice($access->defaultBlockIds($this->actor('auditor'), 'office'), 0, 4));
        // A fleet or hazard permission alone is frontline work, not Business support.
        foreach (['support_worker', 'team_lead'] as $role) {
            $user = $this->actor($role, ['fleet.viewAny' => true, 'hazards.view' => true]);
            $this->assertNotContains('office', $access->roles($user), $role);
            $this->actingAs($user)->getJson('/dashboard/data?role=office')->assertForbidden();
        }
        // HR also reads compliance records: Business support is offered, HR still opens first.
        $hr = $this->actor('hr');
        $this->assertContains('office', $access->roles($hr));
        $this->assertSame('hr', $access->defaultRole($hr));
    }

    public function test_finance_summaries_count_pay_runs_and_bills_and_link_only_to_pages_the_person_can_open(): void
    {
        $today = $this->today();
        HrPayrollRun::create(['tenant_id' => 1, 'period_start' => $today->subDays(13)->toDateString(), 'period_end' => $today->toDateString(), 'status' => 'draft', 'total_gross' => 84312]);
        // Posted to the ledger but not yet filed with Inland Revenue.
        HrPayrollRun::create(['tenant_id' => 1, 'period_start' => $today->subDays(27)->toDateString(), 'period_end' => $today->subDays(14)->toDateString(), 'status' => 'locked', 'journal_id' => 777, 'total_gross' => 4000]);
        $bill = fn (string $status, string $total, string $paid, CarbonImmutable $due) => FinBill::factory()->create([
            'organization_id' => 1, 'status' => $status, 'total_amount' => $total, 'amount_paid' => $paid, 'due_date' => $due->toDateString(),
        ]);
        $bill('approved', '1960.00', '0.00', $today->subDays(3));
        $bill('partially_paid', '400.00', '100.00', $today->addDays(2));
        $bill('approved', '900.00', '0.00', $today->addDays(40));
        $bill('draft', '100.00', '0.00', $today->addDay());
        $bill('paid', '300.00', '300.00', $today->subDays(9));
        $finance = $this->actor('finance', ['hr.payroll.view' => false]);

        $data = $this->actingAs($finance)->getJson('/dashboard/data?role=office&blocks=all')->assertOk()->json();
        $this->assertFalse($data['partial']);
        $attention = collect($data['blocks']['attention']['rows'])->keyBy('label');
        $this->assertSame(1, $attention['Pay runs waiting for approval']['value']);
        $this->assertSame('$84,312 gross', $attention['Pay runs waiting for approval']['detail']);
        $this->assertSame(1, $attention['Payday filings to send']['value']);
        $this->assertSame(1, $attention['Bills overdue']['value']);
        $this->assertSame('$1,960 still to pay', $attention['Bills overdue']['detail']);
        $this->assertSame('Needs attention', $data['metrics'][1]['label']);
        $metrics = collect($data['metrics'])->keyBy('label');
        $this->assertSame(1, $metrics['Payroll to approve']['value']);
        $this->assertSame('$300', $metrics['Bills due in 7 days']['display']);
        $this->assertSame('1 bill · 1 overdue', $metrics['Bills due in 7 days']['caption']);
        // Payroll is an HR page this person cannot open, so its links stay in Finance.
        $this->assertSame('/finance', $data['blocks']['office-payroll']['href']);
        $this->assertSame('/finance', $attention['Pay runs waiting for approval']['href']);
        $bills = $data['blocks']['office-bills'];
        $this->assertSame(2, $bills['total']);
        $this->assertSame(['Overdue', 'Due soon'], array_column($bills['rows'], 'status'));
        $this->assertStringContainsString('$1,960', $bills['rows'][0]['label']);
        $this->assertSame('organisation', $bills['scopeMode']);
        $this->assertSame('NZD', $data['blocks']['trend']['unit']);
        $this->assertSame('month', $data['blocks']['trend']['interval']);
        // Complete months only: this month joins once it is finished.
        $this->assertCount(5, $data['blocks']['trend']['series']);
        $this->assertLessThan($today->startOfMonth()->toDateString(), end($data['blocks']['trend']['series'])['label']);
        // No compliance, safety or risk permission: those blocks say so and hold nothing.
        foreach (['office-compliance', 'office-safety', 'office-risks'] as $id) {
            $this->assertSame('restricted', $data['blocks'][$id]['state'], $id);
            $this->assertSame([], $data['blocks'][$id]['rows'], $id);
        }
    }

    public function test_compliance_and_risk_dates_feed_the_next_deadline_and_upcoming_dates(): void
    {
        $lead = $this->actor('compliance_lead', ['compliance.view' => true, 'governance.risks.view' => true]);
        $today = $this->today();
        foreach ([['Building warrant of fitness', $today->subDays(4), 'due_soon'], ['First aid certificates', $today->addDays(10), 'due_soon'], ['Cancelled obligation', $today->subDays(2), 'cancelled']] as [$title, $date, $status]) {
            ComplianceObligation::query()->create(['description' => 'Test obligation', 'next_due_date' => $date->toDateString(), 'obligation_title' => $title, 'framework' => 'internal',
                'frequency' => 'annual', 'due_date' => $date->toDateString(), 'status' => $status, 'evidence_required' => false, 'evidence_provided' => false, 'owner_id' => $lead->id]);
        }
        foreach ([['Staff fatigue on sleepovers', 'active', 5], ['Voided risk', 'voided', 0]] as [$title, $status, $days]) {
            RiskRegisterEntry::query()->create(['identified_at' => $today->subMonth()->toDateString(), 'identified_by' => $lead->id, 'risk_reference' => 'BS-'.$days, 'title' => $title,
                'description' => 'Test risk', 'category' => 'operational', 'likelihood_score' => 3, 'impact_score' => 3, 'control_effectiveness' => 'none',
                'appetite_threshold' => 10, 'next_review_date' => $today->addDays($days)->toDateString(), 'risk_owner_id' => $lead->id, 'status' => $status]);
        }

        $data = $this->actingAs($lead)->getJson('/dashboard/data?role=office&blocks=all')->assertOk()->json();
        $this->assertFalse($data['partial']);
        $focus = $data['blocks']['focus'];
        $this->assertSame('Overdue', $focus['focusTag']);
        $this->assertSame('Building warrant of fitness', $focus['rows'][0]['label']);
        $this->assertSame('Compliance · 4 days overdue', $focus['rows'][0]['detail']);
        $this->assertSame('Open compliance', $focus['actionLabel']);
        $this->assertSame(['Risk review · Staff fatigue on sleepovers', 'First aid certificates'], array_column($data['blocks']['agenda']['rows'], 'label'));
        $attention = collect($data['blocks']['attention']['rows'])->keyBy('label');
        $this->assertSame(1, $attention['Compliance items overdue']['value']);
        // The risk review is in five days: upcoming, not yet due.
        $this->assertFalse($attention->has('Risk reviews due'));
        $this->assertSame(['Building warrant of fitness', 'First aid certificates'], array_column($data['blocks']['office-compliance']['rows'], 'label'));
        $this->assertSame(['Staff fatigue on sleepovers'], array_column($data['blocks']['office-risks']['rows'], 'label'));
        $this->assertSame('/compliance', $data['primary']['href']);
        // Finance figures need the finance permission.
        $this->assertSame('restricted', $data['blocks']['trend']['state']);
        $this->assertSame([], $data['blocks']['trend']['series']);
    }

    public function test_blocks_off_the_layout_wait_for_the_editor(): void
    {
        $this->actingAs($this->actor('finance'))->get('/dashboard?role=office')->assertOk()->assertInertia(fn ($page) => $page
            ->where('overview.blocks.office-payroll.state', 'ready')
            ->where('overview.blocks.trend.state', 'ready')
            // Figures from the same Finance read (funding, cash) come along at no extra cost.
            ->where('overview.blocks.office-tax.state', 'deferred')
            ->where('overview.blocks.sites.state', 'deferred')
            ->where('overview.blocks.office-compliance.state', 'restricted'));
        // The editor asks for the whole library: nothing is left deferred or "planned".
        $all = $this->getJson('/dashboard/data?role=office&blocks=all')->assertOk()->json('blocks');
        $this->assertSame([], array_keys(array_filter($all, fn ($block) => in_array($block['state'], ['deferred', 'proposed'], true))));
    }
}
