<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FleetPersonalTrip;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class MileageController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('fleet_personal_trips')) {
            return Inertia::render('fleet-assets/mileage/index', [
                'trips' => ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]],
                'filters' => $request->only(['date_from', 'date_to', 'status', 'user_id']),
                'staff' => [],
                'stats' => [
                    'trips_this_month' => 0,
                    'total_distance' => 0,
                    'total_reimbursement' => 0,
                    'pending_approval' => 0,
                ],
                'staff_summary' => [],
            ]);
        }

        $user = $request->user();
        $isManager = $user->can('fleet.viewAny') || $user->hasRole('admin');

        $query = FleetPersonalTrip::query()
            ->with(['user:id,name', 'client:id,first_name,last_name', 'approvedBy:id,name']);

        // Non-managers only see their own trips
        if (!$isManager) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        $trips = $query->latest('date')->paginate(25)->withQueryString();

        // Summary stats
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $monthQuery = FleetPersonalTrip::query()
            ->whereBetween('date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')]);

        if (!$isManager) {
            $monthQuery->where('user_id', $user->id);
        }

        $tripsThisMonth = (clone $monthQuery)->count();
        $totalDistance = (clone $monthQuery)->sum('distance_km');
        $totalReimbursement = (clone $monthQuery)->sum('total_amount');

        $pendingQuery = FleetPersonalTrip::where('status', 'pending');
        if (!$isManager) {
            $pendingQuery->where('user_id', $user->id);
        }
        $pendingApproval = $pendingQuery->count();

        // Staff summary for managers
        $staffSummary = [];
        if ($isManager) {
            $staffSummary = FleetPersonalTrip::query()
                ->join('users', 'fleet_personal_trips.user_id', '=', 'users.id')
                ->whereBetween('date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                ->groupBy('fleet_personal_trips.user_id', 'users.name')
                ->selectRaw('fleet_personal_trips.user_id, users.name, SUM(distance_km) as total_km, SUM(total_amount) as total_amount, COUNT(*) as trip_count')
                ->orderByDesc('total_km')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'label' => $row->name,
                    'value' => round((float) $row->total_km, 1),
                    'amount' => round((float) $row->total_amount, 2),
                    'trips' => $row->trip_count,
                ])
                ->toArray();
        }

        // Staff list for filter dropdown (managers only)
        $staff = [];
        if ($isManager) {
            $staff = User::query()
                ->whereIn('id', FleetPersonalTrip::distinct()->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);
        }

        return Inertia::render('fleet-assets/mileage/index', [
            'trips' => [
                'data' => $trips->getCollection()->map(fn ($t) => [
                    'id' => $t->id,
                    'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name] : null,
                    'date' => $t->date?->format('Y-m-d'),
                    'start_location' => $t->start_location,
                    'end_location' => $t->end_location,
                    'distance_km' => (float) $t->distance_km,
                    'purpose' => $t->purpose,
                    'client' => $t->client ? [
                        'id' => $t->client->id,
                        'name' => trim(($t->client->first_name ?? '') . ' ' . ($t->client->last_name ?? '')),
                    ] : null,
                    'rate_per_km' => (float) $t->rate_per_km,
                    'total_amount' => (float) $t->total_amount,
                    'status' => $t->status,
                    'approved_by' => $t->approvedBy ? ['id' => $t->approvedBy->id, 'name' => $t->approvedBy->name] : null,
                    'approved_at' => optional($t->approved_at)->toISOString(),
                    'notes' => $t->notes,
                    'created_at' => optional($t->created_at)->toISOString(),
                ])->values(),
                'links' => $trips->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $trips->currentPage(),
                    'last_page' => $trips->lastPage(),
                    'total' => $trips->total(),
                ],
            ],
            'filters' => $request->only(['date_from', 'date_to', 'status', 'user_id']),
            'staff' => $staff,
            'stats' => [
                'trips_this_month' => $tripsThisMonth,
                'total_distance' => round((float) $totalDistance, 1),
                'total_reimbursement' => round((float) $totalReimbursement, 2),
                'pending_approval' => $pendingApproval,
            ],
            'staff_summary' => $staffSummary,
            'is_manager' => $isManager,
        ]);
    }

    public function create(Request $request)
    {
        $clients = [];
        if (Schema::hasTable('clients')) {
            $clients = Client::query()
                ->orderBy('first_name')
                ->limit(200)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                ]);
        }

        return Inertia::render('fleet-assets/mileage/create', [
            'clients' => $clients,
            'ird_rate' => 0.95,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'start_location' => ['required', 'string', 'max:255'],
            'end_location' => ['required', 'string', 'max:255'],
            'distance_km' => ['required', 'numeric', 'min:0.1', 'max:9999'],
            'purpose' => ['required', 'string', 'in:client_visit,meeting,training,admin,other'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'shift_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $rate = 0.95; // NZ IRD rate
        $data['user_id'] = $request->user()->id;
        $data['rate_per_km'] = $rate;
        $data['total_amount'] = round((float) $data['distance_km'] * $rate, 2);
        $data['status'] = 'pending';

        $trip = FleetPersonalTrip::create($data);

        AuditLogger::log('fleet.mileage.create', $trip, [
            'distance_km' => $data['distance_km'],
            'total_amount' => $data['total_amount'],
        ]);

        return redirect()->route('fleet-assets.mileage.index')
            ->with('success', 'Mileage claim submitted.');
    }

    public function approve(Request $request, FleetPersonalTrip $trip)
    {
        abort_if($trip->user_id === $request->user()->id, 403, 'Cannot approve your own mileage claim.');
        abort_unless($trip->status === 'pending', 422, 'Only pending claims can be approved.');

        $trip->update([
            'status' => 'approved',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        AuditLogger::log('fleet.mileage.approve', $trip);

        return back()->with('success', 'Mileage claim approved.');
    }

    public function reject(Request $request, FleetPersonalTrip $trip)
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $trip->update([
            'status' => 'rejected',
            'notes' => $data['notes'] ?? $trip->notes,
        ]);

        AuditLogger::log('fleet.mileage.reject', $trip);

        return back()->with('success', 'Mileage claim rejected.');
    }

    public function markPaid(Request $request, FleetPersonalTrip $trip)
    {
        abort_unless($trip->status === 'approved', 422, 'Only approved claims can be marked as paid.');

        $trip->update([
            'status' => 'paid',
        ]);

        AuditLogger::log('fleet.mileage.paid', $trip);

        return back()->with('success', 'Claim marked as paid.');
    }

    public function export(Request $request)
    {
        if (!Schema::hasTable('fleet_personal_trips')) {
            abort(404);
        }

        $query = FleetPersonalTrip::query()
            ->with(['user:id,name', 'client:id,first_name,last_name']);

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $all = $query->latest('date')->limit(5000)->get();

        return response()->streamDownload(function () use ($all) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Staff', 'Date', 'Start Location', 'End Location', 'Distance (km)', 'Purpose', 'Client', 'Rate/km', 'Total Amount', 'Status', 'Approved By', 'Approved At', 'Notes']);
            foreach ($all as $t) {
                fputcsv($handle, [
                    $t->id,
                    $t->user?->name ?? '',
                    $t->date?->format('Y-m-d') ?? '',
                    $t->start_location,
                    $t->end_location,
                    $t->distance_km,
                    $t->purpose,
                    $t->client ? trim(($t->client->first_name ?? '') . ' ' . ($t->client->last_name ?? '')) : '',
                    $t->rate_per_km,
                    $t->total_amount,
                    $t->status,
                    $t->approvedBy?->name ?? '',
                    optional($t->approved_at)->format('Y-m-d H:i') ?? '',
                    $t->notes ?? '',
                ]);
            }
            fclose($handle);
        }, 'mileage-claims-' . now()->format('Y-m-d') . '.csv');
    }
}
