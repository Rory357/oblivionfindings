<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $now = now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();

        $billedStatuses = ['approved', 'billed', 'paid'];

        $baseQuery = $this->accessibleEntries($auth);

        $totalBilledThisMonth = (clone $baseQuery)
            ->whereIn('status', $billedStatuses)
            ->whereBetween('service_date', [$monthStart, $monthEnd])
            ->sum('amount');

        $outstanding = (clone $baseQuery)
            ->where('status', 'approved')
            ->sum('amount');

        $paidThisMonth = (clone $baseQuery)
            ->where('status', 'paid')
            ->whereBetween('service_date', [$monthStart, $monthEnd])
            ->sum('amount');

        $pendingCount = (clone $baseQuery)
            ->where('status', 'pending')
            ->count();

        $recentEntries = (clone $baseQuery)
            ->with(['client:id,first_name,last_name', 'staff:id,name'])
            ->orderByDesc('service_date')
            ->limit(10)
            ->get();

        $statusBreakdown = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $entries = (clone $baseQuery)
            ->with(['client:id,first_name,last_name', 'staff:id,name', 'serviceAgreement:id,title'])
            ->when(! empty($request->get('status')), fn ($q) => $q->where('status', $request->get('status')))
            ->when(! empty($request->get('q')), fn ($q) => $q->where('notes', 'like', '%'.$request->get('q').'%'))
            ->orderByDesc('service_date')
            ->paginate(20)
            ->withQueryString();

        return inertia('finance/billing/Index', [
            'stats' => [
                'billed_this_month' => (float) $totalBilledThisMonth,
                'outstanding' => (float) $outstanding,
                'paid_this_month' => (float) $paidThisMonth,
                'pending_count' => $pendingCount,
            ],
            'entries' => $entries,
            'status_breakdown' => $statusBreakdown,
            'filters' => $request->only(['status', 'q']),
        ]);
    }

    public function entries(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'status' => ['nullable', 'string', 'in:pending,approved,billed,paid,cancelled'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        if (! empty($data['client_id'])) {
            $this->siteAccess->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                ['reports.viewAny'],
            );
        }

        $entries = $this->accessibleEntries($auth)
            ->with(['client:id,first_name,last_name', 'staff:id,name', 'serviceAgreement:id,title'])
            ->when(! empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['date_from']), fn ($q) => $q->where('service_date', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($q) => $q->where('service_date', '<=', $data['date_to']))
            ->orderByDesc('service_date')
            ->paginate(25)
            ->withQueryString();

        $clients = $this->siteAccess->applyClientScope(
            Client::query()->orderBy('first_name'),
            $auth,
            ['reports.viewAny'],
        )
            ->get(['id', 'first_name', 'last_name']);

        return inertia('finance/billing/Entries', [
            'entries' => $entries,
            'clients' => $clients,
            'filters' => $request->only(['client_id', 'status', 'date_from', 'date_to']),
        ]);
    }

    private function accessibleEntries(User $user): Builder
    {
        return BillingEntry::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['reports.viewAny'],
            ))
            ->where(function (Builder $query): void {
                $query->whereNull('service_agreement_id')
                    ->orWhereHas('serviceAgreement', fn (Builder $agreementQuery) => $agreementQuery
                        ->whereColumn('service_agreements.client_id', 'billing_entries.client_id'));
            });
    }
}
