<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\Client;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('billing.viewAny'), 403);

        $orgId = $auth->organization_id;
        $now = now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();

        $billedStatuses = ['approved', 'billed', 'paid'];

        $totalBilledThisMonth = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->whereIn('status', $billedStatuses)
            ->whereBetween('service_date', [$monthStart, $monthEnd])
            ->sum('amount');

        $outstanding = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->where('status', 'approved')
            ->sum('amount');

        $paidThisMonth = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->where('status', 'paid')
            ->whereBetween('service_date', [$monthStart, $monthEnd])
            ->sum('amount');

        $pendingCount = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->where('status', 'pending')
            ->count();

        $recentEntries = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->with(['client:id,first_name,last_name', 'staff:id,name'])
            ->orderByDesc('service_date')
            ->limit(10)
            ->get();

        return inertia('operations/billing/Index', [
            'stats' => [
                'total_billed_this_month' => (float) $totalBilledThisMonth,
                'outstanding' => (float) $outstanding,
                'paid_this_month' => (float) $paidThisMonth,
                'pending_entries_count' => $pendingCount,
            ],
            'recentEntries' => $recentEntries,
        ]);
    }

    public function entries(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('billing.viewAny'), 403);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'status' => ['nullable', 'string', 'in:pending,approved,billed,paid,cancelled'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $entries = BillingEntry::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name', 'staff:id,name', 'serviceAgreement:id,title'])
            ->when(!empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(!empty($data['date_from']), fn ($q) => $q->where('service_date', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn ($q) => $q->where('service_date', '<=', $data['date_to']))
            ->orderByDesc('service_date')
            ->paginate(25)
            ->withQueryString();

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/billing/Entries', [
            'entries' => $entries,
            'clients' => $clients,
            'filters' => $request->only(['client_id', 'status', 'date_from', 'date_to']),
        ]);
    }
}
