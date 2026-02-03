<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\RespiteResourceAllocation;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RespiteResourceAllocationController extends Controller
{
    public function index(Request $request): Response
    {
        $query = RespiteResourceAllocation::query()->with('asset');

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->resource_type);
        }

        $allocations = $query->orderByDesc('start_at')->paginate(20)->withQueryString();

        return Inertia::render('respite/resources/index', [
            'allocations' => $allocations,
            'filters' => $request->only(['resource_type']),
            'assets' => Asset::query()
                ->select('id', 'name', 'asset_tag', 'category', 'status')
                ->orderBy('name')
                ->limit(300)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booking_id' => 'nullable|exists:respite_bookings,id',
            'resource_type' => 'required|in:asset',
            'resource_id' => 'required|exists:assets,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
        ]);

        $validated['created_by'] = auth()->id();

        RespiteResourceAllocation::create($validated);

        event(new RespiteEvent('respite.resource.allocated', [
            'booking_id' => $validated['booking_id'] ?? null,
            'resource_type' => $validated['resource_type'],
            'resource_id' => $validated['resource_id'] ?? null,
        ]));

        return back()->with('success', 'Resource allocation saved.');
    }

    public function destroy(RespiteResourceAllocation $allocation): RedirectResponse
    {
        $allocation->delete();

        return back()->with('success', 'Resource allocation removed.');
    }
}
