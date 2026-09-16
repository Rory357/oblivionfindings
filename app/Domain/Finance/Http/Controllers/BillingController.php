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

        $statusBreakdown = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // The retired /billing/entries page's filters now live on this index —
        // it is the one place delivered-support entries are listed.
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,approved,billed,paid,cancelled'],
            'q' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
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

        $entries = (clone $baseQuery)
            ->with(['client:id,first_name,last_name', 'staff:id,name', 'serviceAgreement:id,title'])
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(! empty($data['date_from']), fn ($q) => $q->where('service_date', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($q) => $q->where('service_date', '<=', $data['date_to']))
            ->when(! empty($data['q']), function ($query) use ($data): void {
                $search = '%'.$data['q'].'%';
                $query->where(function ($inner) use ($search): void {
                    $inner->where('notes', 'like', $search)
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                            ->where('first_name', 'like', $search)
                            ->orWhere('last_name', 'like', $search));
                });
            })
            ->orderByDesc('service_date')
            ->paginate(20)
            ->withQueryString();

        $clients = $this->siteAccess->applyClientScope(
            Client::query()->orderBy('first_name'),
            $auth,
            ['reports.viewAny'],
        )
            ->get(['id', 'first_name', 'last_name']);

        return inertia('finance/billing/Index', [
            'stats' => [
                'billed_this_month' => (float) $totalBilledThisMonth,
                'outstanding' => (float) $outstanding,
                'paid_this_month' => (float) $paidThisMonth,
                'pending_count' => $pendingCount,
            ],
            'entries' => $entries,
            'clients' => $clients,
            'status_breakdown' => $statusBreakdown,
            'filters' => $request->only(['status', 'q', 'client_id', 'date_from', 'date_to']),
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
