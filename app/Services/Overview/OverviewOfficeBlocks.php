<?php

namespace App\Services\Overview;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinIrdFiling;
use App\Domain\Finance\Services\BudgetVarianceService;
use App\Domain\Finance\Services\Calendar\FinanceCalendarAggregator;
use App\Domain\Finance\Services\DashboardAggregatorService as FinanceDashboard;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\Audit\AuditLogViewService;
use App\Services\Compliance\ComplianceMetricsService;
use App\Services\Fleet\ComplianceQueueProjection;
use App\Services\Fleet\MaintenanceAccessService;
use App\Services\Fleet\VehicleFinanceReviewQueue;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsKpiService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business support overview: finance, compliance, safety, fleet, risk and
 * audit summaries for back-office staff. Each area reads its own module's
 * service under that module's permission; records and changes stay there.
 *
 * Every area always works out its attention items, hero figures and dated
 * deadlines (so the header and "Needs attention" are complete); its library
 * blocks are worked out only when they are on the layout or the editor asks.
 */
final class OverviewOfficeBlocks
{
    /** Library blocks each area fills, most useful first (the default layout's order). */
    public const AREAS = [
        'finance' => ['office-payroll', 'office-bills', 'trend', 'distribution', 'sites', 'office-tax', 'office-funding', 'office-cash'],
        'vehicle-reviews' => ['office-vehicle-reviews'],
        'compliance' => ['office-compliance'],
        'safety' => ['office-safety'],
        'fleet' => ['office-fleet'],
        'maintenance' => ['office-maintenance'],
        'risks' => ['office-risks'],
        'audit' => ['office-audit'],
    ];

    /** Roles whose everyday work is back-office: Business support opens first for them. */
    public const ROLES = [
        'finance', 'cfo', 'auditor', 'compliance_lead', 'risk_lead', 'health_safety_officer',
        'facilities_manager', 'fleet_manager', 'maintenance_coordinator',
    ];

    /** Each area's workspace: [quick link label, href, main action label]. */
    public const WORKSPACES = [
        'finance' => ['Finance', '/finance', 'Open finance'],
        'vehicle-reviews' => ['Vehicle reviews', '/finance/vehicle-reviews', 'Review vehicle costs'],
        'compliance' => ['Compliance', '/compliance', 'Open compliance'],
        'safety' => ['Health & safety', '/health-safety', 'Open health & safety'],
        'fleet' => ['Vehicle compliance', '/fleet-assets/compliance', 'Open vehicle compliance'],
        'maintenance' => ['Maintenance', '/fleet-assets/maintenance/dashboard', 'Open maintenance'],
        'risks' => ['Risk register', '/governance/risks', 'Open risk register'],
        'audit' => ['Audit trail', '/audit-logs', 'Open audit trail'],
    ];

    private const FLEET_CHECKS = ['wof' => 'WOF', 'registration' => 'Registration', 'cof' => 'COF', 'ruc' => 'Road user charges', 'insurance' => 'Insurance'];

    private const AUDIT_AREAS = [
        'operations' => 'Care and rosters', 'hr' => 'People and HR', 'finance' => 'Finance', 'fleet' => 'Fleet and assets',
        'settings' => 'Access and settings', 'it' => 'IT', 'security_devices' => 'Security devices', 'monitoring' => 'Monitoring',
        'default' => 'Other records',
    ];

    public function __construct(private readonly UserSiteAccessService $sites) {}

    /** The areas this person can see, in the order their summaries are shown. */
    public function areas(User $user): array
    {
        $fleet = $user->canDo('fleet.viewAny') || $user->canDo('assets.viewAny');

        return array_keys(array_filter([
            'finance' => $user->canDo('finance.dashboard'),
            'vehicle-reviews' => app(VehicleFinanceReviewQueue::class)->canView($user),
            'compliance' => $user->canDo('compliance.view'),
            'safety' => $user->canDo('hazards.view'),
            'fleet' => $fleet || $user->canDo('assets.viewAssigned'),
            'maintenance' => $fleet && app(MaintenanceAccessService::class)->canRead($user),
            'risks' => $user->canDo('governance.risks.view'),
            'audit' => $user->canDo('audit.viewAny'),
        ]));
    }

    /** What each back-office role reaches for first (blocks it cannot see are skipped). */
    private const ROLE_DEFAULTS = [
        'finance' => ['office-payroll', 'office-bills', 'trend', 'distribution'],
        'cfo' => ['trend', 'sites', 'distribution', 'office-cash'],
        'auditor' => ['office-audit', 'office-compliance', 'office-risks', 'distribution'],
        'compliance_lead' => ['office-compliance', 'office-safety', 'office-risks', 'office-fleet'],
        'risk_lead' => ['office-risks', 'office-compliance', 'office-safety', 'office-audit'],
        'health_safety_officer' => ['office-safety', 'office-compliance', 'office-maintenance', 'office-fleet'],
        'fleet_manager' => ['office-fleet', 'office-maintenance', 'office-safety', 'office-vehicle-reviews'],
        'maintenance_coordinator' => ['office-maintenance', 'office-safety', 'office-fleet', 'office-compliance'],
        'facilities_manager' => ['office-maintenance', 'office-safety', 'office-compliance', 'office-fleet'],
    ];

    /**
     * Opening layout: attention and the next deadline, then the blocks the
     * person's back-office role reaches for first, then one block from each
     * other area they can see in turn.
     *
     * @return list<string>
     */
    public function defaultBlockIds(User $user): array
    {
        $areas = $this->areas($user);
        $visible = array_merge([], ...array_map(fn ($area) => self::AREAS[$area], $areas));
        $role = collect(self::ROLES)->first(fn ($name) => $user->hasRole($name));
        $ids = ['attention', 'focus', ...array_values(array_intersect(self::ROLE_DEFAULTS[$role] ?? [], $visible))];
        $queues = array_map(fn ($area) => array_values(array_diff(self::AREAS[$area], $ids)), $areas);
        while (count($ids) < 6 && array_filter($queues)) {
            foreach ($queues as &$queue) {
                if ($queue && count($ids) < 6) {
                    $ids[] = array_shift($queue);
                }
            }
            unset($queue);
        }

        return array_slice(array_values(array_unique([...$ids, 'agenda', 'updates', 'shortcuts'])), 0, 6);
    }

    /**
     * @param  list<int>  $siteIds  The overview's selected sites.
     * @param  list<string>|null  $only  Blocks to work out in full; null works out all of them.
     * @return array{blocks: array<string, array>, attention: list<array>, metrics: list<array>, deadlines: list<array>}
     */
    public function read(string $area, User $user, array $siteIds, bool $oneSite, ?array $only, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $wants = fn (string $id) => $only === null || in_array($id, $only, true);

        return match ($area) {
            'finance' => $this->finance($user, $wants),
            'vehicle-reviews' => $this->vehicleReviews($user),
            'compliance' => $this->compliance($user),
            'safety' => $this->safety($user, $siteIds),
            'fleet' => $this->fleet($user, $siteIds, $oneSite),
            'maintenance' => ['blocks' => ['office-maintenance' => app(OverviewOptionalBlocks::class)->maintenance($user, $siteIds)], 'attention' => [], 'metrics' => [], 'deadlines' => []],
            'risks' => $this->risks($user),
            'audit' => $this->audit($user, $wants, $start, $end),
        };
    }

    private function finance(User $user, callable $wants): array
    {
        // The Finance dashboard's own scope: the person's organisation (single tenant).
        $org = $user->organization_id;
        $today = $this->today();
        $link = fn (string $permission, string $href) => $user->canDo($permission) ? $href : '/finance';
        $payrollHref = $link('hr.payroll.view', '/hr/payroll');
        $billsHref = $link('finance.ap.view', '/finance/bills');
        $runs = HrPayrollRun::query()->when($org, fn ($q) => $q->where('tenant_id', $org));
        // Pay runs not yet posted to the ledger are waiting for approval or processing.
        $waiting = (clone $runs)->whereIn('status', ['draft', 'locked'])->whereNull('journal_id')
            ->orderBy('period_end')->get(['id', 'period_start', 'period_end', 'status', 'total_gross', 'total_staff']);
        // Posted pay runs with no payday filing to Inland Revenue yet.
        $filed = FinIrdFiling::query()->ofType('payday')->whereNotNull('payroll_run_id')->pluck('payroll_run_id');
        $unfiled = (clone $runs)->whereNotNull('journal_id')->whereNotIn('id', $filed);
        $unfiledCount = (clone $unfiled)->count();
        $bills = FinBill::query()->when($org, fn ($q) => $q->where('organization_id', $org))
            ->whereIn('status', ['approved', 'partially_paid'])->whereColumn('amount_paid', '<', 'total_amount');
        $owing = fn ($query) => round((float) (clone $query)->sum(DB::raw('total_amount - amount_paid')), 2);
        $overdueBills = (clone $bills)->whereDate('due_date', '<', $today->toDateString());
        $dueBills = (clone $bills)->whereBetween('due_date', [$today->toDateString(), $today->addDays(7)->toDateString()]);
        [$overdueCount, $overdueTotal, $dueCount, $dueTotal] = [(clone $overdueBills)->count(), $owing($overdueBills), (clone $dueBills)->count(), $owing($dueBills)];
        // GST returns and period closes from the finance calendar (computed IRD dates).
        $calendar = collect(app(FinanceCalendarAggregator::class)->itemsForRange($org, Carbon::parse($today)->subDays(30), Carbon::parse($today)->addDays(45), ['sources' => ['gst_due', 'period_close']]))
            ->filter(fn ($item) => in_array($item->status, ['due', 'overdue'], true))->values();
        $gstOverdue = $calendar->filter(fn ($item) => $item->source === 'gst_due' && $item->start < $today->toDateString())->count();

        $attention = array_values(array_filter([
            $gstOverdue ? $this->attention('GST return overdue', $gstOverdue, $link('finance.tax.view', '/finance/gst-returns'), 'critical', 'Past the Inland Revenue due date', 'Open GST returns') : null,
            $unfiledCount ? $this->attention('Payday filings to send', $unfiledCount, $link('finance.tax.manage', '/finance/ird-filings'), 'warning', 'Posted pay runs not yet filed with Inland Revenue', 'Open payday filing') : null,
            $waiting->count() ? $this->attention('Pay runs waiting for approval', $waiting->count(), $payrollHref, 'warning', $this->money((float) $waiting->sum('total_gross')).' gross', 'Review pay runs') : null,
            $overdueCount ? $this->attention('Bills overdue', $overdueCount, $billsHref, 'warning', $this->money($overdueTotal).' still to pay', 'Open bills') : null,
        ]));
        $metrics = [
            ['label' => 'Payroll to approve', 'value' => $waiting->count(), 'href' => $payrollHref,
                'caption' => $waiting->count() ? $this->money((float) $waiting->sum('total_gross')).' gross' : 'No pay runs waiting'],
            ['label' => 'Bills due in 7 days', 'value' => (int) round($dueTotal), 'display' => $this->money($dueTotal), 'href' => $billsHref,
                'caption' => $dueCount.' '.($dueCount === 1 ? 'bill' : 'bills').($overdueCount ? ' · '.$overdueCount.' overdue' : '')],
            ['label' => 'Payday filings to send', 'value' => $unfiledCount, 'caption' => 'Posted pay runs not yet filed', 'href' => $link('finance.tax.manage', '/finance/ird-filings')],
        ];
        $deadlines = $calendar->map(fn ($item) => $this->deadline($item->title, $item->start, $item->link ?: '/finance/calendar', 'Finance'))->all();

        $blocks = [];
        if ($wants('office-payroll')) {
            $rows = $waiting->take(4)->map(fn ($run) => [
                'label' => 'Pay period to '.$run->period_end->format('j M'), 'date' => $run->period_end->toDateString(),
                'status' => 'Waiting for approval', 'tone' => 'warning', 'href' => $payrollHref,
            ])->all();
            $latestUnfiled = (clone $unfiled)->orderByDesc('period_end')->limit(max(0, 6 - count($rows)))->get(['id', 'period_end']);
            foreach ($latestUnfiled as $run) {
                $rows[] = ['label' => 'Payday filing · period to '.$run->period_end->format('j M'), 'date' => $run->period_end->toDateString(),
                    'status' => 'Not filed', 'tone' => 'warning', 'href' => $link('finance.tax.manage', '/finance/ird-filings')];
            }
            $blocks['office-payroll'] = $this->ready('Pay runs waiting and payday filings still to send', $payrollHref, $rows, $waiting->count() + $unfiledCount)
                + ['actionLabel' => 'Open payroll', 'empty' => 'No pay runs are waiting and every posted pay run has been filed.',
                    'footnote' => 'Payday filing is due 2 working days after pay day'];
        }
        if ($wants('office-bills')) {
            $rows = (clone $bills)->whereDate('due_date', '<=', $today->addDays(7)->toDateString())->with('vendor:id,name')->orderBy('due_date')->limit(6)->get()
                ->map(fn (FinBill $bill) => [
                    'label' => ($bill->vendor?->name ?? 'Supplier').' · '.$this->money($bill->getAmountDue()), 'date' => $bill->due_date->toDateString(),
                    'status' => $bill->due_date->toDateString() < $today->toDateString() ? 'Overdue' : 'Due soon',
                    'tone' => $bill->due_date->toDateString() < $today->toDateString() ? 'warning' : 'neutral',
                    'href' => $user->canDo('finance.ap.view') ? '/finance/bills/'.$bill->id : '/finance',
                ])->all();
            $blocks['office-bills'] = $this->ready('Overdue or due in the next 7 days · '.$this->money($overdueTotal + $dueTotal), $billsHref, $rows, $overdueCount + $dueCount)
                + ['actionLabel' => 'Open bills', 'empty' => 'No approved bills are overdue or due in the next 7 days.'];
        }
        if (array_filter(['trend', 'distribution', 'office-funding', 'office-cash'], $wants)) {
            // The Finance dashboard's figures, worked out once (about the cost of opening /finance).
            $dashboard = app(FinanceDashboard::class)->getDashboardData($org);
            // Complete months only: a part-month would look like a fall in both lines.
            $series = collect($dashboard['revenueByMonth'])->map(fn ($month, $i) => [
                'label' => Carbon::createFromFormat('!M Y', $month['month'])->toDateString(),
                'value' => round((float) $month['amount']), 'comparison' => round((float) ($dashboard['expensesByMonth'][$i]['amount'] ?? 0)),
            ])->filter(fn ($point) => $point['label'] < $today->startOfMonth()->toDateString())->values()->all();
            $blocks['trend'] = $this->ready('Posted income and spending · the last '.count($series).' complete months', '/finance', [], null, $series)
                + ['title' => 'Money in and out', 'seriesLabel' => 'Money in', 'comparisonLabel' => 'Money out', 'unit' => 'NZD', 'interval' => 'month',
                    'footnote' => 'From posted journals · this month shows once it is finished'];
            $aging = $dashboard['arAging'];
            $bands = ['current' => 'Current', 'd1_30' => '1–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'd90_plus' => 'Over 90 days'];
            $blocks['distribution'] = $this->ready('Unpaid invoices by how long they have been outstanding', $link('finance.ar.view', '/finance/receivables'),
                array_map(fn ($key, $label) => ['label' => $label, 'value' => (float) $aging[$key], 'display' => $this->money((float) $aging[$key])], array_keys($bands), $bands), (int) round((float) $aging['total']))
                + ['title' => 'Money owed to us', 'unit' => 'NZD'];
            $use = $dashboard['fundingUtilisation'];
            $stages = ['claimed_paid' => 'Paid', 'awaiting_remittance' => 'Claimed, awaiting payment', 'delivered_unclaimed' => 'Delivered, not yet claimed', 'write_off_risk' => 'At risk of write-off'];
            $blocks['office-funding'] = $this->ready('Funded support by claim stage', $link('funding.viewAny', '/operations/funding/claims'),
                array_map(fn ($key, $label) => ['label' => $label, 'value' => (float) ($use[$key] ?? 0), 'display' => $this->money((float) ($use[$key] ?? 0))], array_keys($stages), $stages), null)
                + ['unit' => 'NZD', 'footnote' => 'Recorded funded support and claims · amounts in NZ dollars'];
            $runway = $dashboard['cashRunwayDays'];
            $blocks['office-cash'] = $this->ready('Cash in active bank accounts', '/finance/cash-position', [
                ['label' => 'Cash on hand', 'detail' => 'Active bank account balances', 'value' => round((float) $dashboard['cashBalance']), 'display' => $this->money((float) $dashboard['cashBalance'])],
                ['label' => 'Days it covers', 'detail' => 'At the average daily spending of the last 90 days', 'value' => $runway, 'display' => $runway === null ? 'Not enough spending recorded' : $runway.' days'],
            ], 2);
        }
        if ($wants('sites')) {
            // Last complete month: a part-month would look under budget everywhere.
            $month = $today->subMonthNoOverflow()->format('Y-m');
            $siteIds = $user->canDo('finance.insights.viewAllSites') ? null : $this->sites->accessibleSiteIds($user, []);
            $variance = app(BudgetVarianceService::class)->organisationVariance($siteIds, $month, $month);
            $labels = ['over_budget' => 'Over budget', 'approaching' => 'Close to budget', 'under_budget' => 'Under budget', 'on_track' => 'On track'];
            $rows = collect($variance['sites'])->filter(fn ($site) => (float) $site['planned'] > 0)->sortByDesc(fn ($site) => (float) $site['actual'] / (float) $site['planned'])->take(6)
                ->map(fn ($site) => ['label' => $site['site_name'], 'value' => round((float) $site['actual']), 'target' => round((float) $site['planned']),
                    'display' => round((float) $site['actual'] / (float) $site['planned'] * 100).'%', 'detail' => $this->money((float) $site['actual']).' of '.$this->money((float) $site['planned']),
                    'status' => $labels[$site['status']] ?? 'On track', 'tone' => $site['status'] === 'over_budget' ? 'warning' : 'neutral'])->values()->all();
            $blocks['sites'] = $this->ready('Spending against budget · '.$today->subMonthNoOverflow()->format('F Y'), $link('finance.reports.view', '/finance/reports/budget-vs-actuals'), $rows)
                + ['presentation' => 'progress-rows', 'empty' => 'No site budgets are set for last month.'];
        }
        if ($wants('office-tax')) {
            $rows = $calendar->where('source', 'gst_due')->take(4)->map(fn ($item) => [
                'label' => $item->title.($item->amount !== null ? ' · '.$this->money((float) $item->amount) : ''), 'date' => $item->start,
                'status' => $item->start < $today->toDateString() ? 'Overdue' : 'Due', 'tone' => $item->start < $today->toDateString() ? 'critical' : 'neutral',
                'href' => $link('finance.tax.view', '/finance/gst-returns'),
            ])->values()->all();
            if ($unfiledCount) {
                $rows[] = ['label' => 'Payday filings to send', 'value' => $unfiledCount, 'status' => 'Not filed', 'tone' => 'warning', 'href' => $link('finance.tax.manage', '/finance/ird-filings')];
            }
            $blocks['office-tax'] = $this->ready('GST returns due in the next 45 days and payday filings', '/finance/calendar', $rows)
                + ['empty' => 'No GST return is due in the next 45 days and every payday filing has been sent.'];
        }

        return ['blocks' => $blocks, 'attention' => $attention, 'metrics' => $metrics, 'deadlines' => $deadlines];
    }

    private function vehicleReviews(User $user): array
    {
        $queue = app(VehicleFinanceReviewQueue::class);
        // The finance review queue's own Site scope, as on its page.
        $waiting = $queue->scoped($user)->where('status', 'submitted');
        $count = (clone $waiting)->count();
        $rows = (clone $waiting)->with('asset:id,name')->orderBy('due_on')->orderBy('id')->limit(6)->get()->map(fn ($request) => [
            // A dated row shows its date in place of its detail, so the amount goes in the label.
            'label' => ($request->asset?->name ?? 'Vehicle').' · '.($request->source_label ?: 'Cost review').($request->amount !== null ? ' · '.$this->money((float) $request->amount) : ''),
            'date' => $request->due_on?->toDateString(), 'detail' => 'Waiting for a finance decision',
            'status' => 'Waiting for finance', 'tone' => 'neutral', 'href' => '/finance/vehicle-reviews?request='.$request->id,
        ])->all();
        $dated = (clone $waiting)->whereNotNull('due_on')->orderBy('due_on')->limit(10)->get(['id', 'due_on', 'source_label']);

        return [
            'blocks' => ['office-vehicle-reviews' => $this->ready('Vehicle costs waiting for a finance decision', '/finance/vehicle-reviews', $rows, $count)
                + ['actionLabel' => 'Review vehicle costs', 'empty' => 'No vehicle costs are waiting for a finance decision.']],
            'attention' => $count ? [$this->attention('Vehicle costs to review', $count, '/finance/vehicle-reviews', 'neutral', 'Waiting for a finance decision', 'Review vehicle costs')] : [],
            'metrics' => [['label' => 'Vehicle costs to review', 'value' => $count, 'caption' => 'Waiting for a finance decision', 'href' => '/finance/vehicle-reviews']],
            'deadlines' => $dated->map(fn ($request) => $this->deadline('Vehicle cost review · '.($request->source_label ?: 'review'), $request->due_on->toDateString(), '/finance/vehicle-reviews?request='.$request->id, 'Vehicle reviews'))->all(),
        ];
    }

    private function compliance(User $user): array
    {
        // The compliance dashboard's own list: open obligations due within 30 days
        // (overdue included) and care plan reviews in the person's sites.
        $due = app(ComplianceMetricsService::class)->whatsDue($user);
        $obligations = collect($due['obligations']);
        $reviews = collect($due['reviews']);
        $overdue = $obligations->where('days', '<', 0)->count();
        $reviewsOverdue = $reviews->where('days', '<', 0)->count();
        $rows = $obligations->sortBy('due_date')->take($reviews->isEmpty() ? 6 : 5)->map(fn ($item) => [
            'label' => $item['title'], 'date' => $item['due_date'],
            'status' => $item['days'] < 0 ? 'Overdue' : ($item['days'] === 0 ? 'Due today' : 'Due soon'),
            'tone' => $item['days'] < 0 ? 'warning' : 'neutral', 'href' => $item['href'] ?: '/compliance',
        ])->values()->all();
        if ($reviews->isNotEmpty()) {
            // Reviews name the person supported, so they stay summarised here.
            $rows[] = ['label' => 'Care plan reviews', 'value' => $reviews->count(), 'detail' => $reviewsOverdue ? $reviewsOverdue.' overdue' : 'Due in the next 30 days',
                'status' => $reviewsOverdue ? 'Overdue' : 'Due soon', 'tone' => $reviewsOverdue ? 'warning' : 'neutral', 'href' => '/compliance'];
        }

        return [
            'blocks' => ['office-compliance' => $this->ready('Overdue or due in the next 30 days', '/compliance', $rows, $obligations->count() + $reviews->count())
                + ['actionLabel' => 'Open compliance', 'empty' => 'Nothing is overdue or due in the next 30 days.']],
            'attention' => array_values(array_filter([
                $overdue ? $this->attention('Compliance items overdue', $overdue, '/compliance', 'warning', 'Open obligations past their due date', 'Open compliance') : null,
                $reviewsOverdue ? $this->attention('Care plan reviews overdue', $reviewsOverdue, '/compliance', 'warning', 'Reviews past their due date', 'Open compliance') : null,
            ])),
            'metrics' => [['label' => 'Compliance overdue', 'value' => $overdue + $reviewsOverdue, 'href' => '/compliance',
                'caption' => ($obligations->count() + $reviews->count() - $overdue - $reviewsOverdue).' more due in 30 days']],
            'deadlines' => $obligations->map(fn ($item) => $this->deadline($item['title'], $item['due_date'], $item['href'] ?: '/compliance', 'Compliance'))->values()->all(),
        ];
    }

    private function safety(User $user, array $siteIds): array
    {
        // The health and safety dashboard's Site rule, limited to the selected sites.
        $scope = array_values(array_intersect($siteIds, $this->sites->accessibleSiteIds($user, ['healthSafety.viewAllSites'])));
        $hs = app(HsDashboardService::class);
        $actions = collect($hs->overdueCorrectiveActions($scope, 100));
        $expiring = collect($hs->expiringFeed($scope, 30, 20, $user));
        $sinceInjury = app(HsKpiService::class)->daysSinceLostTimeInjury($scope);
        $lapsed = $expiring->where('days_until', '<', 0)->count();
        $rows = $actions->take(4)->map(fn ($action) => [
            'label' => $action['title'], 'date' => $action['due_date'], 'status' => 'Overdue', 'tone' => 'warning', 'href' => '/health-safety',
        ])->merge($expiring->take(6 - min(4, $actions->count()))->map(fn ($item) => [
            'label' => $item['type_label'].' · '.$item['label'], 'date' => $item['due_date'],
            'status' => $item['days_until'] < 0 ? 'Overdue' : 'Due soon', 'tone' => $item['days_until'] < 0 ? 'warning' : 'neutral',
            'href' => $item['register_url'] ?: '/health-safety',
        ]))->values()->all();
        $injury = $sinceInjury === null ? 'no lost-time injury recorded' : $sinceInjury.' '.($sinceInjury === 1 ? 'day' : 'days').' since a lost-time injury';

        return [
            'blocks' => ['office-safety' => $this->ready('Overdue corrective actions and renewals due · '.$injury, '/health-safety', $rows, $actions->count() + $expiring->count())
                + ['actionLabel' => 'Open health & safety', 'empty' => 'No corrective actions are overdue and nothing is due for renewal in 30 days.']],
            'attention' => array_values(array_filter([
                $actions->count() ? $this->attention('Safety actions overdue', $actions->count(), '/health-safety', 'warning', 'Corrective actions past their due date', 'Open safety actions') : null,
                $lapsed ? $this->attention('Safety renewals overdue', $lapsed, '/health-safety', 'warning', 'Risk assessments, drills, data sheets or PPE checks', 'Open registers') : null,
            ])),
            'metrics' => [
                ['label' => 'Safety actions overdue', 'value' => $actions->count(), 'caption' => 'Corrective actions past their date', 'href' => '/health-safety'],
                ['label' => 'Since a lost-time injury', 'value' => $sinceInjury, 'display' => $sinceInjury === null ? 'None recorded' : $sinceInjury.' days',
                    'caption' => 'Recorded workplace injuries', 'href' => '/health-safety'],
            ],
            'deadlines' => $actions->map(fn ($action) => $this->deadline($action['title'], $action['due_date'], '/health-safety', 'Health & safety'))
                ->merge($expiring->map(fn ($item) => $this->deadline($item['type_label'].' · '.$item['label'], $item['due_date'], $item['register_url'] ?: '/health-safety', 'Health & safety')))->values()->all(),
        ];
    }

    private function fleet(User $user, array $siteIds, bool $oneSite): array
    {
        // The vehicle compliance page's own vehicle access.
        $access = app(SecurityDevicesAccessService::class);
        $vehicles = ($access->canReadFleetVehicles($user) ? $access->accessibleVehiclesForFleet($user) : $access->accessibleAssets($user, true))
            ->with(['homeSite:id,name', 'site:id,name', 'fleetResponsible:id,name'])->orderBy('name')->get();
        if ($oneSite) {
            $vehicles = $vehicles->filter(fn ($vehicle) => in_array((int) ($vehicle->home_site_id ?? $vehicle->site_id), $siteIds, true))->values();
        }
        $checks = app(ComplianceQueueProjection::class)->rows($vehicles)
            ->filter(fn ($row) => isset(self::FLEET_CHECKS[$row['kind']]) && in_array($row['state'], ['expired', 'failed', 'due_soon'], true));
        $lapsed = $checks->whereIn('state', ['expired', 'failed']);
        $href = '/fleet-assets/compliance'.($oneSite ? '?site_id='.$siteIds[0] : '');
        // Nothing due shows the empty message rather than a row of zero bars.
        $rows = $checks->isEmpty() ? [] : collect(self::FLEET_CHECKS)->map(fn ($label, $kind) => ['label' => $label, 'value' => $checks->where('kind', $kind)->count(), 'href' => $href.($oneSite ? '&' : '?').'kind='.$kind])->values()->all();
        $label = fn ($row) => self::FLEET_CHECKS[$row['kind']].' · '.(($row['vehicle']['registration_number'] ?? null) ?: $row['vehicle']['name']);

        return [
            'blocks' => ['office-fleet' => $this->ready('Due in the next 30 days, expired or failed'.($lapsed->count() ? ' · '.$lapsed->count().' need action now' : ''), $href, $rows, $checks->count())
                + ['empty' => 'No vehicle checks are due in the next 30 days.']],
            'attention' => array_values(array_filter([
                $lapsed->count() ? $this->attention('Vehicle checks expired or failed', $lapsed->count(), $href, 'critical', 'Check the vehicle is not used until it is sorted', 'Open vehicle compliance') : null,
                ($checks->count() - $lapsed->count()) ? $this->attention('Vehicle checks due in 30 days', $checks->count() - $lapsed->count(), $href, 'neutral', 'WOF, registration, COF, road user charges or insurance', 'Open vehicle compliance') : null,
            ])),
            'metrics' => [['label' => 'Vehicle checks due', 'value' => $checks->count(), 'href' => $href,
                'caption' => $lapsed->count() ? $lapsed->count().' expired or failed' : 'In the next 30 days']],
            'deadlines' => $checks->filter(fn ($row) => $row['expires_on'])->map(fn ($row) => $this->deadline($label($row), $row['expires_on'], $href, 'Fleet'))->values()->all(),
        ];
    }

    private function risks(User $user): array
    {
        $today = $this->today()->toDateString();
        $risks = RiskRegisterEntry::query()->current();
        $due = (clone $risks)->whereDate('next_review_date', '<=', $today)->count();
        $upcoming = (clone $risks)->whereNotNull('next_review_date')->whereDate('next_review_date', '<=', $this->today()->addDays(30)->toDateString())
            ->with('riskOwner:id,name')->orderBy('next_review_date')->limit(6)->get();
        $rows = $upcoming->map(fn ($risk) => [
            'label' => $risk->title, 'date' => $risk->next_review_date->toDateString(), 'detail' => $risk->riskOwner?->name ?: 'Owner not assigned',
            'status' => $risk->next_review_date->toDateString() <= $today ? 'Review due' : 'Review soon',
            'tone' => $risk->next_review_date->toDateString() <= $today ? 'warning' : 'neutral', 'href' => '/governance/risks/'.$risk->id,
        ])->all();

        return [
            'blocks' => ['office-risks' => $this->ready('Next review date passed or within 30 days', '/governance/risks', $rows, $upcoming->count())
                + ['actionLabel' => 'Open risk register', 'empty' => 'No risk reviews are due in the next 30 days.']],
            'attention' => $due ? [$this->attention('Risk reviews due', $due, '/governance/risks', 'warning', 'Current risks past their review date', 'Review risks')] : [],
            'metrics' => [['label' => 'Risk reviews due', 'value' => $due, 'caption' => 'Current risks past their review date', 'href' => '/governance/risks']],
            'deadlines' => $upcoming->map(fn ($risk) => $this->deadline('Risk review · '.$risk->title, $risk->next_review_date->toDateString(), '/governance/risks/'.$risk->id, 'Risks'))->all(),
        ];
    }

    private function audit(User $user, callable $wants, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! $wants('office-audit')) {
            return ['blocks' => [], 'attention' => [], 'metrics' => [], 'deadlines' => []];
        }
        // The audit page's own query, with its board pack and medication privacy rules.
        $audit = app(AuditLogViewService::class);
        $filters = ['date_from' => $start->toDateString(), 'date_to' => $end->subDay()->toDateString()];
        $rows = collect(self::AUDIT_AREAS)->map(fn ($label, $module) => [
            'label' => $label, 'value' => $audit->query([...$filters, 'module' => $module], $user)->reorder()->count(),
            'href' => '/audit-logs?'.http_build_query([...$filters, 'module' => $module]),
        ])->filter(fn ($row) => $row['value'] > 0)->sortByDesc('value')->values()->all();

        return [
            'blocks' => ['office-audit' => $this->ready('Changes recorded in the audit trail · selected period', '/audit-logs?'.http_build_query($filters), $rows, (int) array_sum(array_column($rows, 'value')))
                + ['empty' => 'No changes were recorded in this period.']],
            'attention' => [], 'metrics' => [], 'deadlines' => [],
        ];
    }

    private function ready(string $subtitle, ?string $href, array $rows = [], ?int $total = null, array $series = []): array
    {
        return ['state' => 'ready', 'subtitle' => $subtitle, 'href' => $href, 'rows' => $rows, 'series' => $series, 'total' => $total ?? count($rows)];
    }

    private function attention(string $label, int $count, string $href, string $tone, string $detail, string $action): array
    {
        return ['label' => $label, 'value' => $count, 'href' => $href, 'detail' => $detail, 'tone' => $tone, 'actionLabel' => $action,
            'status' => match ($tone) { 'critical' => 'Urgent', 'warning' => 'Needs review', default => 'To review' }];
    }

    private function deadline(string $label, string $date, string $href, string $area): array
    {
        return ['label' => $label, 'date' => substr($date, 0, 10), 'href' => $href, 'area' => $area];
    }

    private function money(float $amount): string
    {
        return ($amount < 0 ? '-$' : '$').number_format(abs($amount), 0);
    }

    /** The NZ calendar day: date-only columns are compared with it, not UTC today(). */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
    }
}
