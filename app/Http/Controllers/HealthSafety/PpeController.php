<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PpeController extends Controller
{
    /**
     * List PPE types, inventory items, and allocations.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $tenantId = $user->tenant_id;
        $filters = $request->only(['site_id', 'category', 'status', 'ppe_type_id']);

        // PPE Types
        $ppeTypes = \DB::table('ppe_types')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        // PPE Inventory with type and site info
        $inventoryQuery = \DB::table('ppe_inventory')
            ->join('ppe_types', 'ppe_inventory.ppe_type_id', '=', 'ppe_types.id')
            ->join('sites', 'ppe_inventory.site_id', '=', 'sites.id')
            ->where('sites.tenant_id', $tenantId)
            ->whereNull('ppe_inventory.deleted_at')
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('ppe_inventory.site_id', $filters['site_id']))
            ->when(!empty($filters['category']), fn ($q) => $q->where('ppe_types.category', $filters['category']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('ppe_inventory.status', $filters['status']))
            ->when(!empty($filters['ppe_type_id']), fn ($q) => $q->where('ppe_inventory.ppe_type_id', $filters['ppe_type_id']));

        $inventory = (clone $inventoryQuery)
            ->select(
                'ppe_inventory.*',
                'ppe_types.name as type_name',
                'ppe_types.category as type_category',
                'sites.name as site_name'
            )
            ->orderBy('ppe_types.category')
            ->orderBy('ppe_types.name')
            ->paginate(25)
            ->withQueryString();

        // Active allocations
        $allocations = \DB::table('ppe_allocations')
            ->join('ppe_inventory', 'ppe_allocations.ppe_inventory_id', '=', 'ppe_inventory.id')
            ->join('ppe_types', 'ppe_inventory.ppe_type_id', '=', 'ppe_types.id')
            ->join('sites', 'ppe_inventory.site_id', '=', 'sites.id')
            ->join('users', 'ppe_allocations.user_id', '=', 'users.id')
            ->where('sites.tenant_id', $tenantId)
            ->whereNull('ppe_allocations.returned_at')
            ->whereNull('ppe_allocations.deleted_at')
            ->select(
                'ppe_allocations.*',
                'ppe_types.name as type_name',
                'ppe_types.category as type_category',
                'users.name as user_name',
                'sites.name as site_name'
            )
            ->orderByDesc('ppe_allocations.allocated_at')
            ->limit(50)
            ->get();

        // Stats
        $totalItems = \DB::table('ppe_inventory')
            ->join('sites', 'ppe_inventory.site_id', '=', 'sites.id')
            ->where('sites.tenant_id', $tenantId)
            ->whereNull('ppe_inventory.deleted_at')
            ->whereNotIn('ppe_inventory.status', ['condemned', 'disposed'])
            ->sum('ppe_inventory.quantity');

        $allocated = \DB::table('ppe_allocations')
            ->join('ppe_inventory', 'ppe_allocations.ppe_inventory_id', '=', 'ppe_inventory.id')
            ->join('sites', 'ppe_inventory.site_id', '=', 'sites.id')
            ->where('sites.tenant_id', $tenantId)
            ->whereNull('ppe_allocations.returned_at')
            ->whereNull('ppe_allocations.deleted_at')
            ->count();

        $inspectionsDue = \DB::table('ppe_inventory')
            ->join('sites', 'ppe_inventory.site_id', '=', 'sites.id')
            ->where('sites.tenant_id', $tenantId)
            ->whereNull('ppe_inventory.deleted_at')
            ->whereNotNull('ppe_inventory.next_inspection_due')
            ->where('ppe_inventory.next_inspection_due', '<=', now()->toDateString())
            ->count();

        $condemned = \DB::table('ppe_inventory')
            ->join('sites', 'ppe_inventory.site_id', '=', 'sites.id')
            ->where('sites.tenant_id', $tenantId)
            ->whereNull('ppe_inventory.deleted_at')
            ->where('ppe_inventory.status', 'condemned')
            ->count();

        $sites = \DB::table('sites')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $staff = \DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/ppe/index', [
            'ppeTypes' => $ppeTypes,
            'inventory' => $inventory,
            'allocations' => $allocations,
            'stats' => [
                'total_items' => (int) $totalItems,
                'allocated' => $allocated,
                'inspections_due' => $inspectionsDue,
                'condemned' => $condemned,
            ],
            'sites' => $sites,
            'staff' => $staff,
            'filters' => $filters,
        ]);
    }

    /**
     * Create a new PPE type.
     */
    public function storeType(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

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
    public function storeInventory(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

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
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));

        return redirect()->back()->with('success', 'PPE inventory item added successfully.');
    }

    /**
     * Update a PPE inventory item.
     */
    public function updateInventory(Request $request, PpeInventory $inventory)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

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
            'updated_by' => $user->id,
        ]));

        return redirect()->back()->with('success', 'PPE inventory item updated successfully.');
    }

    /**
     * Allocate PPE to a worker.
     */
    public function allocate(Request $request, PpeInventory $inventory)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

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
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));

        $inventory->update([
            'status' => 'allocated',
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'PPE allocated to worker successfully.');
    }

    /**
     * Return PPE from a worker.
     */
    public function returnPpe(Request $request, PpeAllocation $allocation)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'condition' => ['nullable', 'string', 'in:new,good,fair,poor,condemned'],
        ]);

        $allocation->update([
            'returned_at' => now(),
            'notes' => $validated['notes'] ?? $allocation->notes,
            'updated_by' => $user->id,
        ]);

        // Update inventory condition and status if provided
        $inventoryUpdate = [
            'updated_by' => $user->id,
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
    public function storeInspection(Request $request, PpeInventory $inventory)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'result' => ['required', 'string', 'in:pass,fail,needs_repair,condemned'],
            'condition_after' => ['nullable', 'string', 'in:good,fair,poor,condemned'],
            'findings' => ['nullable', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
            'next_inspection_due' => ['nullable', 'date'],
        ]);

        $inventory->inspections()->create(array_merge($validated, [
            'inspected_by' => $user->id,
            'inspected_at' => now(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));

        // Update inventory inspection dates and condition
        $inventoryUpdate = [
            'last_inspected_at' => now()->toDateString(),
            'updated_by' => $user->id,
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
