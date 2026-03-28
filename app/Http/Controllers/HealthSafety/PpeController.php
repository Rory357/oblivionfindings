<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PpeController extends Controller
{
    /**
     * List PPE types, inventory items, and allocations.
     */
    public function index(Request $request): \Inertia\Response
    {
        $filters = $request->only(['site_id', 'category', 'status', 'ppe_type_id']);

        // PPE Types
        $ppeTypes = PpeType::where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        // PPE Inventory with type and site info
        $inventory = PpeInventory::with(['ppeType:id,name,category', 'site:id,name'])
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['ppe_type_id']), fn ($q) => $q->where('ppe_type_id', $filters['ppe_type_id']))
            ->when(!empty($filters['category']), fn ($q) => $q->whereHas('ppeType', fn ($tq) => $tq->where('category', $filters['category'])))
            ->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        // Active allocations
        $allocations = PpeAllocation::with([
                'ppeInventory.ppeType:id,name,category',
                'ppeInventory.site:id,name',
                'user:id,name',
            ])
            ->whereNull('returned_at')
            ->orderByDesc('allocated_at')
            ->paginate(25, ['*'], 'allocations_page')
            ->withQueryString()
            ->through(function ($allocation) {
                $data = $allocation->toArray();
                $data['inventory_item'] = $data['ppe_inventory'] ?? null;
                $data['ppe_type_name'] = $allocation->ppeInventory?->ppeType?->name;
                $data['allocated_date'] = $data['allocated_at'] ?? null;
                unset($data['ppe_inventory']);
                return $data;
            });

        // Stats
        $totalItems = (int) PpeInventory::whereNotIn('status', ['condemned', 'disposed'])
            ->sum('quantity');

        $allocated = PpeAllocation::whereNull('returned_at')->count();

        $inspectionsDue = PpeInventory::whereNotNull('next_inspection_due')
            ->where('next_inspection_due', '<=', now()->toDateString())
            ->count();

        $condemned = PpeInventory::where('status', 'condemned')->count();

        return Inertia::render('health-safety/ppe/index', [
            'types' => $ppeTypes,
            'inventory' => $inventory,
            'allocations' => $allocations,
            'stats' => [
                'total_items' => $totalItems,
                'allocated' => $allocated,
                'inspections_due' => $inspectionsDue,
                'condemned' => $condemned,
            ],
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    /**
     * Create a new PPE type.
     */
    public function storeType(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:head,eye,ear,respiratory,hand,foot,body,fall_protection,high_visibility,other'],
            'description' => ['nullable', 'string', 'max:2000'],
            'hazards_addressed' => ['nullable', 'string', 'max:2000'],
            'standards_reference' => ['nullable', 'string', 'max:255'],
            'inspection_frequency' => ['nullable', 'string', 'in:daily,weekly,monthly,quarterly,annually'],
            'typical_lifespan_months' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        PpeType::create($validated);

        return redirect()->back()->with('success', 'PPE type created successfully.');
    }

    /**
     * Add a PPE inventory item.
     */
    public function storeInventory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ppe_type_id' => ['required', 'exists:ppe_types,id'],
            'site_id' => ['required', 'exists:sites,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'condition' => ['sometimes', 'string', 'in:new,good,fair,poor,condemned'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
            'next_inspection_due' => ['nullable', 'date'],
        ]);

        PpeInventory::create(array_merge($validated, [
            'status' => 'available',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'PPE inventory item added successfully.');
    }

    /**
     * Update a PPE inventory item.
     */
    public function updateInventory(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'condition' => ['sometimes', 'string', 'in:new,good,fair,poor,condemned'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:available,allocated,maintenance,condemned,disposed'],
            'next_inspection_due' => ['sometimes', 'nullable', 'date'],
        ]);

        $inventory->update(array_merge($validated, [
            'updated_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'PPE inventory item updated successfully.');
    }

    /**
     * Allocate PPE to a worker.
     */
    public function allocate(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'fit_test_completed' => ['sometimes', 'boolean'],
            'fit_test_date' => ['nullable', 'date'],
            'fit_test_result' => ['nullable', 'string', 'max:255'],
            'training_completed' => ['sometimes', 'boolean'],
            'training_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $inventory->allocations()->create(array_merge($validated, [
            'allocated_at' => now(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        $inventory->update([
            'status' => 'allocated',
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'PPE allocated to worker successfully.');
    }

    /**
     * Return PPE from a worker.
     */
    public function returnPpe(Request $request, PpeAllocation $allocation): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'condition' => ['nullable', 'string', 'in:new,good,fair,poor,condemned'],
        ]);

        $allocation->update([
            'returned_at' => now(),
            'notes' => $validated['notes'] ?? $allocation->notes,
            'updated_by' => $request->user()->id,
        ]);

        // Update inventory condition and status if provided
        $inventoryUpdate = [
            'updated_by' => $request->user()->id,
        ];

        if (!empty($validated['condition'])) {
            $inventoryUpdate['condition'] = $validated['condition'];
            $inventoryUpdate['status'] = $validated['condition'] === 'condemned' ? 'condemned' : 'available';
        } else {
            $inventoryUpdate['status'] = 'available';
        }

        $allocation->ppeInventory->update($inventoryUpdate);

        return redirect()->back()->with('success', 'PPE returned successfully.');
    }

    /**
     * Record a PPE inspection.
     */
    public function storeInspection(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'result' => ['required', 'string', 'in:pass,fail,needs_repair,condemned'],
            'condition_after' => ['nullable', 'string', 'in:good,fair,poor,condemned'],
            'findings' => ['nullable', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
            'next_inspection_due' => ['nullable', 'date'],
        ]);

        $inventory->inspections()->create(array_merge($validated, [
            'inspected_by' => $request->user()->id,
            'inspected_at' => now(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        // Update inventory inspection dates and condition
        $inventoryUpdate = [
            'last_inspected_at' => now()->toDateString(),
            'updated_by' => $request->user()->id,
        ];

        if (!empty($validated['next_inspection_due'])) {
            $inventoryUpdate['next_inspection_due'] = $validated['next_inspection_due'];
        }

        if (!empty($validated['condition_after'])) {
            $inventoryUpdate['condition'] = $validated['condition_after'];
        }

        if ($validated['result'] === 'condemned') {
            $inventoryUpdate['status'] = 'condemned';
            $inventoryUpdate['condition'] = 'condemned';
        }

        $inventory->update($inventoryUpdate);

        return redirect()->back()->with('success', 'PPE inspection recorded successfully.');
    }
}
