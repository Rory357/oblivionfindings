<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\FundingClaim;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FundingController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('funding.viewAny'), 403);

        $agreementQuery = $this->accessibleAgreements($auth);
        $claimQuery = $this->accessibleClaims($auth);

        // Budget stats across active service agreements
        $budgetStats = (clone $agreementQuery)
            ->active()
            ->selectRaw('
                COALESCE(SUM(total_budget), 0) as total_budget,
                COALESCE(SUM(budget_used), 0) as total_used,
                COALESCE(SUM(total_budget) - SUM(budget_used), 0) as total_remaining
            ')
            ->first();

        $utilisationPercent = $budgetStats->total_budget > 0
            ? round(($budgetStats->total_used / $budgetStats->total_budget) * 100, 1)
            : 0;

        // Claims grouped by status
        $claimsByStatus = (clone $claimQuery)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Top 10 agreements by utilisation
        $topAgreements = (clone $agreementQuery)
            ->active()
            ->where('total_budget', '>', 0)
            ->with(['client:id,first_name,last_name'])
            ->orderByRaw('(budget_used / total_budget) DESC')
            ->limit(10)
            ->get(['id', 'client_id', 'title', 'total_budget', 'budget_used', 'ends_at']);

        $topAgreements->each(fn ($a) => $a->append(['budget_utilisation_percent', 'budget_remaining']));

        return inertia('operations/funding/Index', [
            'stats' => [
                'total_budget' => (float) $budgetStats->total_budget,
                'total_used' => (float) $budgetStats->total_used,
                'total_remaining' => (float) $budgetStats->total_remaining,
                'utilisation_percent' => $utilisationPercent,
                'active_agreements' => (clone $agreementQuery)->where('status', 'active')->count(),
                'pending_claims' => (clone $claimQuery)->where('status', 'submitted')->count(),
                'expiring_soon' => (clone $agreementQuery)->active()->expiringSoon()->count(),
            ],
            'claims_by_status' => $claimsByStatus,
            'top_agreements' => $topAgreements->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'client_name' => $a->client ? $a->client->first_name.' '.$a->client->last_name : '',
                'total_budget' => $a->total_budget,
                'budget_used' => $a->budget_used,
                'utilisation_percent' => $a->budget_utilisation_percent,
            ]),
        ]);
    }

    private function accessibleAgreements(User $user): Builder
    {
        return ServiceAgreement::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['funding.viewAllSites'],
            ));
    }

    private function accessibleClaims(User $user): Builder
    {
        return FundingClaim::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['funding.viewAllSites'],
            ))
            ->whereHas('serviceAgreement', fn (Builder $agreementQuery) => $agreementQuery
                ->whereColumn('service_agreements.client_id', 'funding_claims.client_id'))
            ->where(function (Builder $scope): void {
                $scope->whereNull('funding_claims.site_id')
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                        ->whereColumn('clients.site_id', 'funding_claims.site_id'));
            });
    }
}
