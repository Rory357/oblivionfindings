<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\FundingClaim;
use App\Models\ServiceAgreement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FundingController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('funding.viewAny'), 403);

        $orgId = $auth->organization_id;

        // Budget stats across active service agreements
        $budgetStats = ServiceAgreement::query()
            ->where('organization_id', $orgId)
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
        $claimsByStatus = FundingClaim::query()
            ->where('organization_id', $orgId)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Top 10 agreements by utilisation
        $topAgreements = ServiceAgreement::query()
            ->where('organization_id', $orgId)
            ->active()
            ->where('total_budget', '>', 0)
            ->with(['client:id,first_name,last_name'])
            ->orderByRaw('(budget_used / total_budget) DESC')
            ->limit(10)
            ->get(['id', 'client_id', 'title', 'total_budget', 'budget_used', 'ends_at']);

        $topAgreements->each(fn ($a) => $a->append(['budget_utilisation_percent', 'budget_remaining']));

        return inertia('operations/funding/Index', [
            'budgetStats' => [
                'total_budget' => (float) $budgetStats->total_budget,
                'total_used' => (float) $budgetStats->total_used,
                'total_remaining' => (float) $budgetStats->total_remaining,
                'utilisation_percent' => $utilisationPercent,
            ],
            'claimsByStatus' => $claimsByStatus,
            'topAgreements' => $topAgreements,
        ]);
    }
}
