<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = FleetWorkOrder::query()
            ->with(['asset:id,name,asset_tag', 'reportedBy:id,name', 'assignedTo:id,name']);

        // CSV export
        if ($request->input('export') === 'csv') {
            $all = (clone $query)->latest()->limit(5000)->get();
            return response()->streamDownload(function () use ($all) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['Title', 'Asset', 'Priority', 'Status', 'Assigned To', 'Due Date', 'Created']);
                foreach ($all as $wo) {
                    $this->putCsv($handle, [
                        $wo->title, $wo->asset?->name ?? '', $wo->priority, $wo->status,
                        $wo->assignedTo?->name ?? '', optional($wo->due_at)->format('Y-m-d') ?? '',
                        optional($wo->created_at)->format('Y-m-d') ?? '',
                    ]);
                }
                fclose($handle);
            }, 'work-orders-export.csv');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->input('asset_id'));
        }

        // Sorting
        $allowedSorts = ['created_at', 'priority', 'status', 'title', 'due_at'];
        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) $sort = 'created_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'desc';

        $workOrders = $query->reorder()->orderBy($sort, $direction)->paginate(25)->withQueryString();

        $users = User::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('fleet-assets/maintenance/work-orders/index', [
            'work_orders' => [
                'data' => $workOrders->getCollection()->map(fn ($wo) => [
                    'id' => $wo->id,
                    'title' => $wo->title,
                    'status' => $wo->status,
                    'priority' => $wo->priority,
                    'asset' => $wo->asset ? ['id' => $wo->asset->id, 'name' => $wo->asset->name, 'asset_tag' => $wo->asset->asset_tag] : null,
                    'reported_by' => $wo->reportedBy ? ['id' => $wo->reportedBy->id, 'name' => $wo->reportedBy->name] : null,
                    'assigned_to' => $wo->assignedTo ? ['id' => $wo->assignedTo->id, 'name' => $wo->assignedTo->name] : null,
                    'due_at' => optional($wo->due_at)->toISOString(),
                    'created_at' => optional($wo->created_at)->toISOString(),
                ])->values(),
                'links' => $workOrders->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $workOrders->currentPage(),
                    'last_page' => $workOrders->lastPage(),
                    'total' => $workOrders->total(),
                ],
            ],
            'filters' => $request->only(['status', 'priority', 'asset_id']),
            'users' => $users,
        ]);
    }

    public function create(Request $request)
    {
        $assets = Asset::query()->orderBy('name')->get(['id', 'name', 'asset_tag', 'category']);
        $users = User::query()->orderBy('name')->get(['id', 'name']);

        // Fetch recent failed checklist runs for linking
        $checklistRuns = \App\Models\FleetChecklistRun::query()
            ->where('passed', false)
            ->with('asset:id,name', 'template:id,name')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'asset_name' => $r->asset?->name ?? 'Unknown',
                'template_name' => $r->template?->name ?? 'Unknown',
                'run_at' => optional($r->created_at)->toISOString(),
            ])
            ->values();

        return Inertia::render('fleet-assets/maintenance/work-orders/create', [
            'assets' => $assets,
            'users' => $users,
            'checklist_runs' => $checklistRuns,
            'prefill_asset_id' => $request->input('asset_id'),
            'prefill_checklist_run_id' => $request->input('checklist_run_id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', 'in:low,medium,high,critical'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'checklist_run_id' => ['nullable', 'integer', 'exists:fleet_checklist_runs,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $asset = Asset::query()->select('id', 'category')->findOrFail($data['asset_id']);

        $data['reported_by_user_id'] = $request->user()->id;
        $data['category'] = $asset->category ?: 'maintenance';
        $data['status'] = 'open';

        $workOrder = FleetWorkOrder::create($data);

        AuditLogger::log('fleet.work_order.create', $workOrder, [
            'asset_id' => $data['asset_id'],
            'title' => $data['title'],
        ]);

        return redirect()->route('fleet-assets.work-orders.show', $workOrder)
            ->with('success', 'Work order created.');
    }

    public function show(Request $request, FleetWorkOrder $workOrder)
    {
        $workOrder->load([
            'asset:id,name,asset_tag,category,status',
            'reportedBy:id,name,email',
            'assignedTo:id,name,email',
        ]);

        return Inertia::render('fleet-assets/maintenance/work-orders/show', [
            'work_order' => $workOrder,
        ]);
    }

    public function update(Request $request, FleetWorkOrder $workOrder)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,in_progress,on_hold,completed,cancelled'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high,critical'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'completed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $workOrder->update($data);

        AuditLogger::log('fleet.work_order.update', $workOrder, [
            'work_order_id' => $workOrder->id,
        ]);

        return back()->with('success', 'Work order updated.');
    }

    public function bulkAction(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:complete,in_progress,assign'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $updateData = [];
        switch ($data['action']) {
            case 'complete':
                $updateData = ['status' => 'completed', 'completed_at' => now()];
                break;
            case 'in_progress':
                $updateData = ['status' => 'in_progress'];
                break;
            case 'assign':
                if (!empty($data['assigned_to_user_id'])) {
                    $updateData = ['assigned_to_user_id' => $data['assigned_to_user_id']];
                }
                break;
        }

        if (!empty($updateData)) {
            FleetWorkOrder::whereIn('id', $data['ids'])->update($updateData);
        }

        AuditLogger::log('fleet.work_orders.bulk_action', null, [
            'action' => $data['action'],
            'count' => count($data['ids']),
        ]);

        return back()->with('success', 'Bulk action applied to ' . count($data['ids']) . ' work order(s).');
    }
}
