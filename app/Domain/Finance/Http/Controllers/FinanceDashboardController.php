<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\DashboardAggregatorService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class FinanceDashboardController extends Controller
{
    public function __construct(
        private DashboardAggregatorService $dashboardService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $orgId = $user->organization_id;

        $period = (string) $request->query('period', 'month');
        $costCentreIds = $this->intList($request->query('site'));
        $fundingStreamIds = $this->intList($request->query('funder'));

        // Period-aware, filterable metrics (re-run on partial reloads).
        $data = $this->dashboardService->getDashboardData($orgId, $period, $costCentreIds, $fundingStreamIds);
        $data['orgName'] = $user->organization?->name ?? 'Whakaora Support Services';

        // Hero meta (org context, not period-scoped) — closures so partial
        // reloads on period/filter change skip these queries. Sites are not
        // org-scoped in this app (single-org), so counted globally.
        $data['openPeriodLabel'] = fn () => FinFiscalPeriod::forOrganization($orgId)
            ->open()->orderByDesc('id')->value('name');
        $data['siteCount'] = fn () => Site::query()->count();
        $data['regionCount'] = fn () => Site::query()->get(['id', 'region', 'city', 'suburb'])
            ->map(fn (Site $s) => $s->resolved_region)
            ->filter()
            ->unique()
            ->count();

        // Filter options + quick-action modal reference data. Wrapped in closures
        // so Inertia partial reloads (period / filter switches) skip these queries.
        $data['siteOptions'] = fn () => FinCostCentre::forOrganization($orgId)
            ->active()->orderBy('name')->get(['id', 'name']);
        $data['funderOptions'] = fn () => FinFundingStream::forOrganization($orgId)
            ->active()->orderBy('name')->get(['id', 'name']);
        $data['accounts'] = fn () => FinAccount::forOrganization($orgId)
            ->active()->orderBy('code')->get(['id', 'code', 'name']);
        $data['costCentres'] = fn () => FinCostCentre::forOrganization($orgId)
            ->active()->orderBy('code')->get(['id', 'code', 'name']);
        $data['fundingStreams'] = fn () => FinFundingStream::forOrganization($orgId)
            ->active()->orderBy('code')->get(['id', 'code', 'name']);
        $data['vendors'] = fn () => FinVendor::forOrganization($orgId)
            ->active()->orderBy('name')->get(['id', 'name']);
        $data['taxRates'] = fn () => FinTaxRate::forOrganization($orgId)
            ->active()->orderBy('name')->get(['id', 'name', 'rate']);
        $data['clients'] = fn () => Client::query()
            ->when(
                $orgId && Schema::hasColumn('clients', 'organization_id'),
                fn ($q) => $q->where('organization_id', $orgId),
            )
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)])
            ->values();

        return Inertia::render('finance/Dashboard', $data);
    }

    /** Normalise a query param into a list of positive ints. */
    private function intList(mixed $value): array
    {
        return collect((array) $value)
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->values()
            ->all();
    }
}
