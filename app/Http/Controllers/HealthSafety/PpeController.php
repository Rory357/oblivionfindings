<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\StorePpeInventoryRequest;
use App\Http\Requests\HealthSafety\StorePpeTypeRequest;
use App\Models\PpeAllocation;
use App\Models\PpeAllocationAttachment;
use App\Models\PpeAttachment;
use App\Models\PpeInspection;
use App\Models\PpeInspectionAttachment;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PpeController extends Controller
{
    use ServesPrivateAttachments;

    /**
     * PPE & Equipment register — H&S gold-standard command centre.
     *
     * Closure props so a `?item=`/`?allocation=` partial reload (`only: ['detail']`)
     * does not recompute the list, counts and hero. One filtered base per dataset
     * (excluding the active tab) feeds the hero + tab counts so they reflect the
     * filter scope but not the active tab; the tab only narrows the visible list.
     */
    public function index(Request $request): \Inertia\Response
    {
        $tab = (string) $request->input('tab', 'inv_all');
        $filters = $request->only(['site_id', 'category', 'status', 'ppe_type_id', 'search']);

        return Inertia::render('health-safety/ppe/index', [
            'tab' => $tab,
            'filters' => $filters,
            'inventory' => fn () => $this->buildInventoryPayload($this->inventoryBase($filters), $tab),
            'allocations' => fn () => $this->buildAllocationPayload($this->allocationBase($filters), $tab),
            'types' => fn () => $this->buildTypesPayload(),
            // Counts + hero are scoped to the SITE filter only (a genuine scope) — not the
            // category/status/search list refinements — so the tab badges + hero numbers stay
            // a stable overview and don't jump as you refine the list (DESIGN_SPEC §1.3/§2).
            'tabCounts' => fn () => $this->tabCounts($this->siteScopedInventory($filters), $this->siteScopedAllocation($filters)),
            'hero' => fn () => $this->hero($this->siteScopedInventory($filters), $this->siteScopedAllocation($filters), $filters['site_id'] ?? null),
            'sites' => fn () => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => fn () => User::select('id', 'name')->orderBy('name')->get(),
            'allocatable' => fn () => $this->allocatableItems(),
            'detail' => fn () => $this->detail($request),
            'can' => ['manage' => $request->user()?->canDo('hazards.manage') ?? false],
        ]);
    }

    // ───────────────────────── Query bases ─────────────────────────

    /** Inventory scoped to the site filter only — feeds the stable tabCounts + hero overview. */
    private function siteScopedInventory(array $filters): Builder
    {
        return PpeInventory::query()
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']));
    }

    /** Allocations scoped to the site filter only (via the linked item). */
    private function siteScopedAllocation(array $filters): Builder
    {
        return PpeAllocation::query()
            ->when(! empty($filters['site_id']), fn ($q) => $q->whereHas('ppeInventory', fn ($iq) => $iq->where('site_id', $filters['site_id'])));
    }

    /** Inventory filtered by site/category/status/type/search — NOT by the active tab. */
    private function inventoryBase(array $filters): Builder
    {
        return PpeInventory::query()
            ->when(! empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['ppe_type_id']), fn ($q) => $q->where('ppe_type_id', $filters['ppe_type_id']))
            ->when(! empty($filters['category']), fn ($q) => $q->whereHas('ppeType', fn ($tq) => $tq->where('category', $filters['category'])))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(function ($w) use ($term) {
                    $w->where('brand', 'like', $term)
                        ->orWhere('model', 'like', $term)
                        ->orWhere('serial_number', 'like', $term)
                        ->orWhere('location', 'like', $term)
                        ->orWhereHas('ppeType', fn ($tq) => $tq->where('name', 'like', $term))
                        ->orWhereHas('site', fn ($sq) => $sq->where('name', 'like', $term));
                });
            });
    }

    /** Active-or-not allocations filtered by site/category/search via the linked item. */
    private function allocationBase(array $filters): Builder
    {
        return PpeAllocation::query()
            ->when(! empty($filters['site_id']), fn ($q) => $q->whereHas('ppeInventory', fn ($iq) => $iq->where('site_id', $filters['site_id'])))
            ->when(! empty($filters['ppe_type_id']), fn ($q) => $q->whereHas('ppeInventory', fn ($iq) => $iq->where('ppe_type_id', $filters['ppe_type_id'])))
            ->when(! empty($filters['category']), fn ($q) => $q->whereHas('ppeInventory.ppeType', fn ($tq) => $tq->where('category', $filters['category'])))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(function ($w) use ($term) {
                    $w->whereHas('user', fn ($uq) => $uq->where('name', 'like', $term))
                        ->orWhereHas('ppeInventory', fn ($iq) => $iq->where('serial_number', 'like', $term)->orWhere('brand', 'like', $term));
                });
            });
    }

    // ───────────────────────── List payloads ─────────────────────────

    private function buildInventoryPayload(Builder $base, string $tab): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $today = now()->toDateString();

        $base->when($tab === 'inv_available', fn ($q) => $q->where('status', 'available'))
            ->when($tab === 'inv_allocated', fn ($q) => $q->where('status', 'allocated'))
            ->when($tab === 'inv_condemned', fn ($q) => $q->where('status', 'condemned'))
            ->when($tab === 'inv_inspection', fn ($q) => $q->whereNotNull('next_inspection_due')
                ->whereDate('next_inspection_due', '<=', now()->addDays(30)->toDateString()))
            ->when($tab === 'inv_expiring', fn ($q) => $q->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(60)->toDateString()));

        return $base->with(['ppeType:id,name,category,standards_reference', 'site:id,name'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PpeInventory $i) => $this->inventoryRow($i, $today));
    }

    private function inventoryRow(PpeInventory $i, string $today): array
    {
        return [
            'id' => $i->id,
            'ppe_type' => $i->ppeType ? [
                'id' => $i->ppeType->id,
                'name' => $i->ppeType->name,
                'category' => $i->ppeType->category,
                'standards_reference' => $i->ppeType->standards_reference,
            ] : null,
            'site' => $i->site ? ['id' => $i->site->id, 'name' => $i->site->name] : null,
            'brand' => $i->brand,
            'model' => $i->model,
            'serial_number' => $i->serial_number,
            'quantity' => $i->quantity,
            'location' => $i->location,
            'condition' => $i->condition,
            'status' => $i->status,
            'purchase_date' => optional($i->purchase_date)->toDateString(),
            'expiry_date' => optional($i->expiry_date)->toDateString(),
            'last_inspected_at' => optional($i->last_inspected_at)->toDateString(),
            'next_inspection_due' => optional($i->next_inspection_due)->toDateString(),
        ];
    }

    private function buildAllocationPayload(Builder $base, string $tab): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $base->whereNull('returned_at')
            ->when($tab === 'alloc_unack', fn ($q) => $q->where('acknowledged', false));

        return $base->with([
            'ppeInventory.ppeType:id,name,category',
            'ppeInventory.site:id,name',
            'user:id,name',
        ])
            ->orderByDesc('allocated_at')
            ->paginate(25, ['*'], 'allocations_page')
            ->withQueryString()
            ->through(fn (PpeAllocation $a) => $this->allocationRow($a));
    }

    private function allocationRow(PpeAllocation $a): array
    {
        $item = $a->ppeInventory;

        return [
            'id' => $a->id,
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            'inventory_item' => $item ? [
                'id' => $item->id,
                'brand' => $item->brand,
                'model' => $item->model,
                'serial_number' => $item->serial_number,
                'site' => $item->site ? ['id' => $item->site->id, 'name' => $item->site->name] : null,
            ] : null,
            'ppe_type' => $item?->ppeType ? [
                'name' => $item->ppeType->name,
                'category' => $item->ppeType->category,
            ] : null,
            'allocated_at' => optional($a->allocated_at)->toIso8601String(),
            'fit_test_completed' => (bool) $a->fit_test_completed,
            'fit_test_date' => optional($a->fit_test_date)->toDateString(),
            'training_completed' => (bool) $a->training_completed,
            'training_date' => optional($a->training_date)->toDateString(),
            'acknowledged' => (bool) $a->acknowledged,
            'acknowledged_at' => optional($a->acknowledged_at)->toIso8601String(),
        ];
    }

    /** Catalogue tab — ALL types incl. retired (with an Active/Retired pill). */
    private function buildTypesPayload(): \Illuminate\Support\Collection
    {
        return PpeType::query()
            ->withCount('inventory')
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (PpeType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'category' => $t->category,
                'description' => $t->description,
                'hazards_addressed' => $t->hazards_addressed,
                'standards_reference' => $t->standards_reference,
                'inspection_frequency' => $t->inspection_frequency,
                'typical_lifespan_months' => $t->typical_lifespan_months,
                'is_active' => (bool) $t->is_active,
                'inventory_count' => $t->inventory_count,
            ]);
    }

    /** Stream the filtered inventory as a CSV (hero quick action). */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filters = $request->only(['site_id', 'category', 'status', 'ppe_type_id', 'search']);
        $rows = $this->inventoryBase($filters)
            ->with(['ppeType:id,name,category,standards_reference', 'site:id,name'])
            ->orderBy('id')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, ['Type', 'Category', 'Standard', 'Site', 'Location', 'Brand', 'Model', 'Serial', 'Quantity', 'Condition', 'Status', 'Expiry', 'Next inspection']);
            foreach ($rows as $i) {
                $this->putCsv($out, [
                    $i->ppeType?->name,
                    $i->ppeType?->category,
                    $i->ppeType?->standards_reference,
                    $i->site?->name,
                    $i->location,
                    $i->brand,
                    $i->model,
                    $i->serial_number,
                    $i->quantity,
                    $i->condition,
                    $i->status,
                    optional($i->expiry_date)->toDateString(),
                    optional($i->next_inspection_due)->toDateString(),
                ]);
            }
            fclose($out);
        }, 'ppe-register-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Available items for the Allocate-PPE picker (across all pages). */
    private function allocatableItems(): \Illuminate\Support\Collection
    {
        return PpeInventory::with(['ppeType:id,name,category', 'site:id,name'])
            ->where('status', 'available')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PpeInventory $i) => [
                'id' => $i->id,
                'label' => trim(($i->ppeType?->name ?? 'Item').' · '.($i->serial_number ?: ($i->brand ?? '#'.$i->id))),
                'category' => $i->ppeType?->category,
                'site' => $i->site?->name,
            ]);
    }

    // ───────────────────────── Counts + hero ─────────────────────────

    private function tabCounts(Builder $invBase, Builder $allocBase): array
    {
        $in30 = now()->addDays(30)->toDateString();
        $in60 = now()->addDays(60)->toDateString();

        return [
            'inv_all' => (clone $invBase)->count(),
            'inv_available' => (clone $invBase)->where('status', 'available')->count(),
            'inv_allocated' => (clone $invBase)->where('status', 'allocated')->count(),
            'inv_inspection' => (clone $invBase)->whereNotNull('next_inspection_due')
                ->whereDate('next_inspection_due', '<=', $in30)->count(),
            'inv_expiring' => (clone $invBase)->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', $in60)->count(),
            'inv_condemned' => (clone $invBase)->where('status', 'condemned')->count(),
            'alloc_active' => (clone $allocBase)->whereNull('returned_at')->count(),
            'alloc_unack' => (clone $allocBase)->whereNull('returned_at')->where('acknowledged', false)->count(),
            'types' => PpeType::count(),
        ];
    }

    /**
     * Two hero clusters + NZ compliance signals (raw counts/booleans — the React
     * side owns the badge wording). Cluster/attention numbers respect the active
     * filters; hi-vis/footwear coverage is site-scoped only (a cross-category
     * statement that a category filter must not narrow).
     */
    private function hero(Builder $invBase, Builder $allocBase, $siteId): array
    {
        $today = now()->toDateString();
        $in30 = now()->addDays(30)->toDateString();
        $in60 = now()->addDays(60)->toDateString();

        $totalItems = (clone $invBase)->count();
        $totalUnits = (int) (clone $invBase)->whereNotIn('status', ['condemned', 'disposed'])->sum('quantity');
        $allocated = (clone $invBase)->where('status', 'allocated')->count();
        $available = (clone $invBase)->where('status', 'available')->count();
        $inspectionDue = (clone $invBase)->whereNotNull('next_inspection_due')->whereDate('next_inspection_due', '<=', $in30)->count();

        $inspectionOverdue = (clone $invBase)->whereNotNull('next_inspection_due')->whereDate('next_inspection_due', '<', $today)->count();
        $expiring = (clone $invBase)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $in60)->count();
        $condemned = (clone $invBase)->where('status', 'condemned')->count();
        $unack = (clone $allocBase)->whereNull('returned_at')->where('acknowledged', false)->count();

        // RPE fit-test due: active allocation of a respiratory item, fit-test not done (AS/NZS 1715).
        $rpeFitTestDue = (clone $allocBase)->whereNull('returned_at')
            ->where('fit_test_completed', false)
            ->whereHas('ppeInventory.ppeType', fn ($q) => $q->where('category', 'respiratory'))
            ->count();

        // Coverage: at least one in-date, non-condemned item in the category (site-scoped only).
        $coverage = fn (string $category) => PpeInventory::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereHas('ppeType', fn ($q) => $q->where('category', $category))
            ->whereNotIn('status', ['condemned', 'disposed'])
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $today))
            ->exists();

        return [
            'clusters' => [
                'live' => [
                    'total' => $totalItems,
                    'units' => $totalUnits,
                    'allocated' => $allocated,
                    'available' => $available,
                    'inspections_due' => $inspectionDue,
                ],
                'attention' => [
                    'inspection_overdue' => $inspectionOverdue,
                    'expiring' => $expiring,
                    'condemned' => $condemned,
                    'unacknowledged' => $unack,
                ],
            ],
            'compliance' => [
                'rpe_fit_test_due' => $rpeFitTestDue,
                'inspections_overdue' => $inspectionOverdue,
                'inspections_due' => $inspectionDue,
                'items_expiring' => $expiring,
                'condemned_awaiting' => $condemned,
                'hi_vis_covered' => $coverage('high_visibility'),
                'footwear_covered' => $coverage('foot'),
            ],
        ];
    }

    // ───────────────────────── Detail-as-modal ─────────────────────────

    private function detail(Request $request): ?array
    {
        if ($request->filled('item')) {
            $item = PpeInventory::with([
                'ppeType',
                'site:id,name',
                'allocations' => fn ($q) => $q->with('user:id,name', 'createdBy:id,name', 'acknowledgedBy:id,name')->orderByDesc('allocated_at'),
                'inspections' => fn ($q) => $q->with('inspector:id,name', 'attachments.uploader:id,name')->orderByDesc('inspected_at'),
                'attachments.uploader:id,name',
                'createdBy:id,name',
                'updatedBy:id,name',
                'condemnedBy:id,name',
                'disposedBy:id,name',
            ])->find((int) $request->input('item'));

            return $item ? ['kind' => 'item', 'item' => $this->itemDetailPayload($item)] : null;
        }

        if ($request->filled('allocation')) {
            $alloc = PpeAllocation::with([
                'ppeInventory.ppeType',
                'ppeInventory.site:id,name',
                'user:id,name',
                'createdBy:id,name',
                'acknowledgedBy:id,name',
                'attachments.uploader:id,name',
            ])->find((int) $request->input('allocation'));

            return $alloc ? ['kind' => 'allocation', 'allocation' => $this->allocationDetailPayload($alloc)] : null;
        }

        return null;
    }

    private function itemDetailPayload(PpeInventory $i): array
    {
        $activeAllocation = $i->allocations->firstWhere('returned_at', null);

        return [
            'id' => $i->id,
            'ppe_type' => $i->ppeType ? [
                'id' => $i->ppeType->id,
                'name' => $i->ppeType->name,
                'category' => $i->ppeType->category,
                'standards_reference' => $i->ppeType->standards_reference,
                'inspection_frequency' => $i->ppeType->inspection_frequency,
                'hazards_addressed' => $i->ppeType->hazards_addressed,
            ] : null,
            'site' => $i->site ? ['id' => $i->site->id, 'name' => $i->site->name] : null,
            'brand' => $i->brand,
            'model' => $i->model,
            'serial_number' => $i->serial_number,
            'quantity' => $i->quantity,
            'location' => $i->location,
            'condition' => $i->condition,
            'status' => $i->status,
            'purchase_date' => optional($i->purchase_date)->toDateString(),
            'expiry_date' => optional($i->expiry_date)->toDateString(),
            'last_inspected_at' => optional($i->last_inspected_at)->toDateString(),
            'next_inspection_due' => optional($i->next_inspection_due)->toDateString(),
            'condemned_at' => optional($i->condemned_at)->toIso8601String(),
            'condemned_by' => $i->condemnedBy ? ['id' => $i->condemnedBy->id, 'name' => $i->condemnedBy->name] : null,
            'condemned_reason' => $i->condemned_reason,
            'disposed_at' => optional($i->disposed_at)->toIso8601String(),
            'disposed_by' => $i->disposedBy ? ['id' => $i->disposedBy->id, 'name' => $i->disposedBy->name] : null,
            'disposal_method' => $i->disposal_method,
            'created_by' => $i->createdBy ? ['id' => $i->createdBy->id, 'name' => $i->createdBy->name] : null,
            'created_at' => optional($i->created_at)->toIso8601String(),
            'active_allocation' => $activeAllocation ? [
                'id' => $activeAllocation->id,
                'user' => $activeAllocation->user ? ['id' => $activeAllocation->user->id, 'name' => $activeAllocation->user->name] : null,
                'allocated_at' => optional($activeAllocation->allocated_at)->toIso8601String(),
                'fit_test_completed' => (bool) $activeAllocation->fit_test_completed,
                'fit_test_date' => optional($activeAllocation->fit_test_date)->toDateString(),
                'fit_test_result' => $activeAllocation->fit_test_result,
                'training_completed' => (bool) $activeAllocation->training_completed,
                'acknowledged' => (bool) $activeAllocation->acknowledged,
                'acknowledged_at' => optional($activeAllocation->acknowledged_at)->toIso8601String(),
            ] : null,
            'allocations' => $i->allocations->map(fn (PpeAllocation $a) => [
                'id' => $a->id,
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                'allocated_at' => optional($a->allocated_at)->toIso8601String(),
                'returned_at' => optional($a->returned_at)->toIso8601String(),
                'acknowledged' => (bool) $a->acknowledged,
            ])->values(),
            'inspections' => $i->inspections->map(fn (PpeInspection $ins) => [
                'id' => $ins->id,
                'result' => $ins->result,
                'condition_after' => $ins->condition_after,
                'findings' => $ins->findings,
                'action_taken' => $ins->action_taken,
                'inspected_at' => optional($ins->inspected_at)->toIso8601String(),
                'inspector' => $ins->inspector ? ['id' => $ins->inspector->id, 'name' => $ins->inspector->name] : null,
                'next_inspection_due' => optional($ins->next_inspection_due)->toDateString(),
                'attachments' => $this->serializeAttachments($ins->attachments, "/health-safety/ppe/inspections/{$ins->id}/attachments"),
            ])->values(),
            'attachments' => $this->serializeAttachments($i->attachments, "/health-safety/ppe/inventory/{$i->id}/attachments"),
            'history' => $this->itemHistory($i),
        ];
    }

    private function allocationDetailPayload(PpeAllocation $a): array
    {
        $item = $a->ppeInventory;

        return [
            'id' => $a->id,
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            'inventory_item' => $item ? [
                'id' => $item->id,
                'brand' => $item->brand,
                'model' => $item->model,
                'serial_number' => $item->serial_number,
                'condition' => $item->condition,
                'status' => $item->status,
                'site' => $item->site ? ['id' => $item->site->id, 'name' => $item->site->name] : null,
            ] : null,
            'ppe_type' => $item?->ppeType ? [
                'name' => $item->ppeType->name,
                'category' => $item->ppeType->category,
                'standards_reference' => $item->ppeType->standards_reference,
            ] : null,
            'allocated_at' => optional($a->allocated_at)->toIso8601String(),
            'returned_at' => optional($a->returned_at)->toIso8601String(),
            'fit_test_completed' => (bool) $a->fit_test_completed,
            'fit_test_date' => optional($a->fit_test_date)->toDateString(),
            'fit_test_result' => $a->fit_test_result,
            'training_completed' => (bool) $a->training_completed,
            'training_date' => optional($a->training_date)->toDateString(),
            'acknowledged' => (bool) $a->acknowledged,
            'acknowledged_at' => optional($a->acknowledged_at)->toIso8601String(),
            'acknowledged_by' => $a->acknowledgedBy ? ['id' => $a->acknowledgedBy->id, 'name' => $a->acknowledgedBy->name] : null,
            'notes' => $a->notes,
            'issued_by' => $a->createdBy ? ['id' => $a->createdBy->id, 'name' => $a->createdBy->name] : null,
            'attachments' => $this->serializeAttachments($a->attachments, "/health-safety/ppe/allocations/{$a->id}/attachments"),
        ];
    }

    /** @param \Illuminate\Support\Collection<int, mixed> $attachments */
    private function serializeAttachments($attachments, string $base): array
    {
        return $attachments->map(fn ($a) => [
            'id' => $a->id,
            'original_name' => $a->original_name,
            // Private disk → no public URL; the thumbnail/preview loads through the
            // authenticated download route (Content-Disposition is ignored for <img>).
            'url' => "{$base}/{$a->id}/download",
            'download_url' => "{$base}/{$a->id}/download",
            'mime' => $a->mime,
            'kind' => $a->kind,
            'notes' => $a->notes,
            'alt_text' => $a->alt_text,
            'size' => $a->size,
            'is_image' => $a->isImage(),
            'uploaded_by' => $a->uploader ? ['id' => $a->uploader->id, 'name' => $a->uploader->name] : null,
            'created_at' => optional($a->created_at)->toIso8601String(),
        ])->values()->all();
    }

    /** Composed lifecycle timeline (real events — no seeded/fake history). */
    private function itemHistory(PpeInventory $i): array
    {
        $events = [];

        $events[] = ['type' => 'created', 'label' => 'Item added to register', 'at' => optional($i->created_at)->toIso8601String(), 'actor' => $i->createdBy?->name];

        foreach ($i->allocations as $a) {
            $events[] = ['type' => 'allocated', 'label' => 'Allocated to '.($a->user->name ?? 'worker'), 'at' => optional($a->allocated_at)->toIso8601String(), 'actor' => $a->createdBy?->name];
            if ($a->acknowledged_at) {
                $events[] = ['type' => 'acknowledged', 'label' => 'Allocation acknowledged', 'at' => optional($a->acknowledged_at)->toIso8601String(), 'actor' => $a->acknowledgedBy?->name];
            }
            if ($a->returned_at) {
                $events[] = ['type' => 'returned', 'label' => 'Returned from '.($a->user->name ?? 'worker'), 'at' => optional($a->returned_at)->toIso8601String(), 'actor' => null];
            }
        }

        foreach ($i->inspections as $ins) {
            $events[] = ['type' => 'inspected', 'label' => 'Inspection — '.str_replace('_', ' ', (string) $ins->result), 'at' => optional($ins->inspected_at)->toIso8601String(), 'actor' => $ins->inspector?->name];
        }

        if ($i->condemned_at) {
            $events[] = ['type' => 'condemned', 'label' => 'Condemned'.($i->condemned_reason ? ' — '.$i->condemned_reason : ''), 'at' => optional($i->condemned_at)->toIso8601String(), 'actor' => $i->condemnedBy?->name];
        }
        if ($i->disposed_at) {
            $events[] = ['type' => 'disposed', 'label' => 'Disposed'.($i->disposal_method ? ' — '.$i->disposal_method : ''), 'at' => optional($i->disposed_at)->toIso8601String(), 'actor' => $i->disposedBy?->name];
        }

        usort($events, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return $events;
    }

    // ───────────────────────── Type mutations ─────────────────────────

    public function storeType(StorePpeTypeRequest $request): RedirectResponse
    {
        PpeType::create($request->validated());

        return redirect()->back()->with('success', 'PPE type created.');
    }

    public function updateType(StorePpeTypeRequest $request, PpeType $type): RedirectResponse
    {
        $type->update($request->validated());

        return redirect()->back()->with('success', 'PPE type updated.');
    }

    public function activateType(PpeType $type): RedirectResponse
    {
        $type->update(['is_active' => true]);

        return redirect()->back()->with('success', 'PPE type reactivated.');
    }

    public function deactivateType(PpeType $type): RedirectResponse
    {
        $type->update(['is_active' => false]);

        return redirect()->back()->with('success', 'PPE type retired.');
    }

    // ───────────────────────── Inventory mutations ─────────────────────────

    public function storeInventory(StorePpeInventoryRequest $request): RedirectResponse
    {
        $validated = $request->safe()->except('documents');

        $inventory = PpeInventory::create(array_merge($validated, [
            'status' => 'available',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        $this->storeWizardDocuments($request, $inventory->attachments(), 'ppe_attachments', 'certificate');

        return redirect()->back()->with('success', 'PPE inventory item added.');
    }

    public function updateInventory(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'condition' => ['sometimes', 'string', 'in:new,good,fair,poor,condemned'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:available,allocated,maintenance,condemned,disposed'],
            'next_inspection_due' => ['sometimes', 'nullable', 'date'],
        ]);

        $inventory->update(array_merge($validated, ['updated_by' => $request->user()->id]));

        return redirect()->back()->with('success', 'PPE inventory item updated.');
    }

    public function condemn(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        if (in_array($inventory->status, ['condemned', 'disposed'], true)) {
            return redirect()->back()->with('error', 'This item is already out of service.');
        }

        if ($inventory->allocations()->whereNull('returned_at')->exists()) {
            return redirect()->back()->with('error', 'Return the item from the worker before condemning it.');
        }

        $inventory->update([
            'status' => 'condemned',
            'condition' => 'condemned',
            'condemned_at' => now(),
            'condemned_by' => $request->user()->id,
            'condemned_reason' => $validated['reason'],
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Item condemned and removed from service.');
    }

    public function dispose(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'disposal_method' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! in_array($inventory->status, ['condemned'], true)) {
            return redirect()->back()->with('error', 'Condemn the item before disposal.');
        }

        $inventory->update([
            'status' => 'disposed',
            'disposed_at' => now(),
            'disposed_by' => $request->user()->id,
            'disposal_method' => $validated['disposal_method'],
            'condemned_reason' => $validated['reason'] ?? $inventory->condemned_reason,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Item disposed and archived.');
    }

    // ───────────────────────── Allocation mutations ─────────────────────────

    public function allocate(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'fit_test_completed' => ['sometimes', 'boolean'],
            'fit_test_date' => ['nullable', 'date'],
            'fit_test_result' => ['nullable', 'string', 'max:255'],
            'training_completed' => ['sometimes', 'boolean'],
            'training_date' => ['nullable', 'date'],
            'acknowledged' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $acknowledgedAtIssue = $request->boolean('acknowledged');

        $inventory->allocations()->create(array_merge($validated, [
            'allocated_at' => now(),
            'acknowledged' => $acknowledgedAtIssue,
            'acknowledged_at' => $acknowledgedAtIssue ? now() : null,
            'acknowledged_by' => $acknowledgedAtIssue ? $request->user()->id : null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        $inventory->update([
            'status' => 'allocated',
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'PPE allocated to worker.');
    }

    public function acknowledge(Request $request, PpeAllocation $allocation): RedirectResponse
    {
        $allocation->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Allocation acknowledged.');
    }

    /**
     * Worker self-acknowledgement from My Day (auth-only, NOT hazards.manage —
     * support workers have no hazards.* perms). Authorisation is ownership: a worker
     * may only acknowledge an allocation issued to themselves. Mirrors the
     * lone-worker self-check-in 3-actor model.
     */
    public function acknowledgeOwn(Request $request, PpeAllocation $allocation): RedirectResponse
    {
        abort_unless((int) $allocation->user_id === (int) $request->user()->id, 403);

        if ($allocation->returned_at) {
            return redirect()->back()->with('error', 'This PPE has already been returned.');
        }

        $allocation->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'PPE acknowledged.');
    }

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

        $item = $allocation->ppeInventory;
        $inventoryUpdate = ['updated_by' => $request->user()->id];

        // Never resurrect an item already condemned/disposed (e.g. condemned at an inspection
        // while still issued) — closing the allocation must not flip it back to available.
        if (! in_array($item->status, ['condemned', 'disposed'], true)) {
            if (($validated['condition'] ?? null) === 'condemned') {
                $inventoryUpdate['condition'] = 'condemned';
                $inventoryUpdate['status'] = 'condemned';
                $inventoryUpdate['condemned_at'] = now();
                $inventoryUpdate['condemned_by'] = $request->user()->id;
                $inventoryUpdate['condemned_reason'] = 'Condemned on return: '.($validated['notes'] ?? 'failed return check');
            } else {
                if (! empty($validated['condition'])) {
                    $inventoryUpdate['condition'] = $validated['condition'];
                }
                $inventoryUpdate['status'] = 'available';
            }
        }

        $item->update($inventoryUpdate);

        return redirect()->back()->with('success', 'PPE returned.');
    }

    // ───────────────────────── Inspections ─────────────────────────

    public function storeInspection(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'result' => ['required', 'string', 'in:pass,fail,needs_repair,condemned'],
            'condition_after' => ['nullable', 'string', 'in:new,good,fair,poor,condemned'],
            'findings' => ['nullable', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
            'next_inspection_due' => ['nullable', 'date'],
            'documents' => ['nullable', 'array'],
            'documents.*.file' => ['required', 'file', 'max:20480'],
            'documents.*.kind' => ['nullable', 'string', 'max:30'],
            'documents.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $inspection = $inventory->inspections()->create([
            'result' => $validated['result'],
            'condition_after' => $validated['condition_after'] ?? null,
            'findings' => $validated['findings'] ?? null,
            'action_taken' => $validated['action_taken'] ?? null,
            'next_inspection_due' => $validated['next_inspection_due'] ?? null,
            'inspected_by' => $request->user()->id,
            'inspected_at' => now(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $inventoryUpdate = [
            'last_inspected_at' => now()->toDateString(),
            'updated_by' => $request->user()->id,
        ];

        if (! empty($validated['next_inspection_due'])) {
            $inventoryUpdate['next_inspection_due'] = $validated['next_inspection_due'];
        }
        if (! empty($validated['condition_after'])) {
            $inventoryUpdate['condition'] = $validated['condition_after'];
        }
        if ($validated['result'] === 'condemned') {
            $inventoryUpdate['status'] = 'condemned';
            $inventoryUpdate['condition'] = 'condemned';
            $inventoryUpdate['condemned_at'] = now();
            $inventoryUpdate['condemned_by'] = $request->user()->id;
            $inventoryUpdate['condemned_reason'] = $validated['findings'] ?? 'Condemned at inspection.';
        }

        $inventory->update($inventoryUpdate);

        $this->storeWizardDocuments($request, $inspection->attachments(), 'ppe_inspection_attachments', 'inspection_report');

        return redirect()->back()->with('success', 'PPE inspection recorded.');
    }

    // ───────────────────────── Evidence (premium document upload) ─────────────────────────

    public function uploadInventoryAttachment(Request $request, PpeInventory $inventory): RedirectResponse
    {
        $inventory->attachments()->create($this->storeUploadedFile($request, 'ppe_attachments'));

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function downloadInventoryAttachment(PpeInventory $inventory, PpeAttachment $attachment)
    {
        return $this->downloadAttachment($attachment, (int) $attachment->ppe_inventory_id === (int) $inventory->id);
    }

    public function destroyInventoryAttachment(PpeInventory $inventory, PpeAttachment $attachment): RedirectResponse
    {
        return $this->destroyAttachment($attachment, (int) $attachment->ppe_inventory_id === (int) $inventory->id);
    }

    public function uploadAllocationAttachment(Request $request, PpeAllocation $allocation): RedirectResponse
    {
        $allocation->attachments()->create($this->storeUploadedFile($request, 'ppe_allocation_attachments'));

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function downloadAllocationAttachment(PpeAllocation $allocation, PpeAllocationAttachment $attachment)
    {
        return $this->downloadAttachment($attachment, (int) $attachment->ppe_allocation_id === (int) $allocation->id);
    }

    public function destroyAllocationAttachment(PpeAllocation $allocation, PpeAllocationAttachment $attachment): RedirectResponse
    {
        return $this->destroyAttachment($attachment, (int) $attachment->ppe_allocation_id === (int) $allocation->id);
    }

    public function uploadInspectionAttachment(Request $request, PpeInspection $inspection): RedirectResponse
    {
        $inspection->attachments()->create($this->storeUploadedFile($request, 'ppe_inspection_attachments'));

        return redirect()->back()->with('success', 'Document uploaded.');
    }

    public function downloadInspectionAttachment(PpeInspection $inspection, PpeInspectionAttachment $attachment)
    {
        return $this->downloadAttachment($attachment, (int) $attachment->ppe_inspection_id === (int) $inspection->id);
    }

    public function destroyInspectionAttachment(PpeInspection $inspection, PpeInspectionAttachment $attachment): RedirectResponse
    {
        return $this->destroyAttachment($attachment, (int) $attachment->ppe_inspection_id === (int) $inspection->id);
    }

    // ───────────────────────── Attachment helpers ─────────────────────────

    /** Validate + store a single uploaded file; returns the attachment attributes. */
    private function storeUploadedFile(Request $request, string $folder): array
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'kind' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        return [
            'uploaded_by' => $request->user()?->id,
            'disk' => 'private',
            'original_name' => $file->getClientOriginalName(),
            'path' => $file->store($folder, 'private'),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'kind' => $data['kind'] ?? null,
            'notes' => $data['notes'] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
        ];
    }

    /** Stage capture-at-source wizard documents (documents.*.file) onto a relation. */
    private function storeWizardDocuments(Request $request, $relation, string $folder, string $defaultKind): void
    {
        foreach ($request->file('documents', []) as $i => $upload) {
            $file = $upload['file'] ?? null;
            if (! $file) {
                continue;
            }
            $relation->create([
                'uploaded_by' => $request->user()?->id,
                'disk' => 'private',
                'original_name' => $file->getClientOriginalName(),
                'path' => $file->store($folder, 'private'),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'kind' => $request->input("documents.$i.kind") ?: ($file->getClientMimeType() && str_starts_with($file->getClientMimeType(), 'image/') ? 'inspection_photo' : $defaultKind),
                'notes' => $request->input("documents.$i.note"),
            ]);
        }
    }

    private function downloadAttachment($attachment, bool $owns): StreamedResponse
    {
        abort_unless($owns, 404);

        // Private disk + nosniff + CSP sandbox — see ServesPrivateAttachments.
        // (Existence check + hardened streaming live in the trait.)
        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    private function destroyAttachment($attachment, bool $owns): RedirectResponse
    {
        abort_unless($owns, 404);
        $disk = $attachment->disk ?: 'private';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

        return redirect()->back()->with('success', 'Document removed.');
    }
}
