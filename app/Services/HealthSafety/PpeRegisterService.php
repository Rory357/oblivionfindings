<?php

namespace App\Services\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Same filtered list/count/export boundary; Site-only totals are explicitly separate. */
final class PpeRegisterService
{
    public const CSV_HEADERS = ['Type', 'Category', 'Standard', 'Site', 'Location', 'Brand', 'Model', 'Serial', 'Quantity', 'Condition', 'Status', 'Expiry', 'Next inspection'];

    public function __construct(private readonly PpeAccessService $access, private readonly PpeReadPresenter $presenter,
        private readonly PpePickerService $pickers) {}

    public function index(User $viewer, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($filters) {
            $this->access->assertOperation($current, 'view');
            $tab = (string) ($filters['tab'] ?? 'inv_all');
            $refinements = array_intersect_key($filters, array_flip(['site_id', 'category', 'status', 'ppe_type_id', 'search']));
            if (! empty($refinements['site_id'])) {
                $this->access->assertSite((int) $refinements['site_id'], $current, $reads, $sites);
            }
            $siteInv = $this->access->inventoryQuery($current, $reads, $sites)
                ->when(! empty($refinements['site_id']), fn ($q) => $q->where('site_id', $refinements['site_id']));
            $siteAlloc = $this->access->allocationQuery($current, $reads, $sites)
                ->when(! empty($refinements['site_id']), fn ($q) => $q->whereHas('ppeInventory', fn ($item) => $item->where('site_id', $refinements['site_id'])));
            $inv = $this->inventoryBase($refinements, clone $siteInv);
            $alloc = $this->allocationBase($refinements, clone $siteAlloc);
            // Detached states exist only in this active callback; both filtered and Site-overview bases reuse them.
            $proofs = ['inventory' => [], 'allocations' => [], 'types' => [], 'inventory_type_damage' => [], 'inventory_fingerprints' => [], 'type_fingerprints' => [], 'inventory_type_ids' => []];
            // Catalogue counts retain all approved Sites, independent of the selected register Site.
            $this->scanInventory($this->access->inventoryQuery($current, $reads, $sites), $reads, $proofs);
            $this->scanAllocations(clone $siteAlloc, $reads, $proofs);
            $integrity = $this->summaryIntegrity(clone $inv, clone $alloc, $proofs);
            $overviewIntegrity = $this->summaryIntegrity(clone $siteInv, clone $siteAlloc, $proofs);
            $physicalCounts = $this->tabCounts(clone $inv, clone $alloc);
            $overviewCounts = $this->tabCounts(clone $siteInv, clone $siteAlloc);
            $options = $this->pickers->optionsInContext($current, $reads, $sites, $refinements);
            return [
                'tab' => $tab, 'filters' => $refinements,
                'inventory' => $this->inventoryPage(clone $inv, $tab, (int) ($filters['page'] ?? 1), $reads),
                'allocations' => $this->allocationPage(clone $alloc, $tab, (int) ($filters['allocations_page'] ?? 1), $reads),
                'types' => $this->types($current, $reads, $sites, $proofs),
                'tabCounts' => $this->trustedCounts($physicalCounts, $integrity), 'physical_tabCounts' => $physicalCounts, 'source_integrity' => $integrity,
                'hero' => $this->trustedHero($this->hero(clone $inv, clone $alloc, null), $integrity),
                'site_overview' => ['scope' => 'approved_sites_and_selected_site',
                    'tabCounts' => $this->trustedCounts($overviewCounts, $overviewIntegrity), 'physical_tabCounts' => $overviewCounts, 'source_integrity' => $overviewIntegrity,
                    'hero' => $this->trustedHero($this->hero(clone $siteInv, clone $siteAlloc, null), $overviewIntegrity)],
                'sites' => $options['sites'], 'staff' => $options['staff'], 'allocatable' => $options['allocatable'],
                'staff_page' => $options['staff_page'], 'allocatable_page' => $options['allocatable_page'],
                'can' => ['view' => true, 'manage' => $current->canDo('hazards.manage')],
                'detail' => null,
            ];
        });
    }

    /** Emits detached cell arrays under one current callback; no builder/generator escapes. */
    public function export(User $viewer, array $filters, callable $emit): int
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($filters, $emit) {
            $this->access->assertOperation($current, 'view');
            if (! empty($filters['site_id'])) {
                $this->access->assertSite((int) $filters['site_id'], $current, $reads, $sites);
            }
            $base = $this->inventoryBase($filters, $this->access->inventoryQuery($current, $reads, $sites));
            $proofs = ['inventory' => [], 'allocations' => [], 'types' => [], 'inventory_type_damage' => [], 'inventory_fingerprints' => [], 'type_fingerprints' => [], 'inventory_type_ids' => []];
            $this->scanInventory(clone $base, $reads, $proofs);
            if (in_array('repair_required', $proofs['inventory'], true)) {
                throw ValidationException::withMessages(['export' => 'A matching PPE source requires repair. Review the register before exporting.']);
            }
            $count = 0;
            $base->chunkById(100, function ($items) use ($current, $reads, $sites, $filters, $emit, &$count) {
                foreach ($items as $item) {
                    $fresh = $this->inventoryBase($filters, $this->access->inventoryQuery($current, $reads, $sites))->whereKey($item->id)->first();
                    if (! $fresh) { continue; }
                    $type = $reads->query(PpeType::query())->whereKey($fresh->ppe_type_id)->first();
                    if (app(PpeCommandService::class)->sourceState($fresh) === 'repair_required'
                        || ! $type || app(PpeCommandService::class)->sourceState($type) === 'repair_required') {
                        throw ValidationException::withMessages(['export' => 'The current PPE source requires repair.']);
                    }
                    $site = $reads->query(\App\Models\Site::query())->whereKey($fresh->site_id)->first();
                    $emit([$type->name, $type->category, $type->standards_reference,
                        $site?->name, $fresh->location, $fresh->brand, $fresh->model, $fresh->serial_number,
                        (int) $fresh->quantity, $fresh->condition, $fresh->status,
                        PpeReadPresenter::date($fresh->expiry_date), PpeReadPresenter::date($fresh->next_inspection_due)]);
                    $count++;
                }
            });
            return $count;
        });
    }

    private function inventoryPage(Builder $base, string $tab, int $page, CurrentAuthorizationReads $reads): array
    {
        $today = now('Pacific/Auckland')->toDateString();
        $base->when($tab === 'inv_available', fn ($q) => $q->where('status', 'available'))
            ->when($tab === 'inv_allocated', fn ($q) => $q->where('status', 'allocated'))
            ->when($tab === 'inv_condemned', fn ($q) => $q->where('status', 'condemned'))
            ->when($tab === 'inv_inspection', fn ($q) => $q->whereNotNull('next_inspection_due')->whereDate('next_inspection_due', '<=', now('Pacific/Auckland')->addDays(30)->toDateString()))
            ->when($tab === 'inv_expiring', fn ($q) => $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', now('Pacific/Auckland')->addDays(60)->toDateString()));
        return $base->with(['ppeType' => fn ($q) => $reads->query($q->getQuery()), 'site' => fn ($q) => $reads->query($q->getQuery())->select(['id', 'name'])])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['*'], 'page', max(1, $page))
            ->withQueryString()->through(function (PpeInventory $item) use ($today) {
                // Prove the exact materialized output, independently of the earlier summary scan.
                $state = app(PpeCommandService::class)->sourceState($item);
                if (! $item->ppeType || app(PpeCommandService::class)->sourceState($item->ppeType) === 'repair_required') { $state = 'repair_required'; }
                if ($state === 'repair_required') {
                    return $this->repairRow((int) $item->id, 'inventory');
                }
                return [...$this->presenter->inventoryRow($item, $today), 'currency' => $this->presenter->currency($item),
                    'attribution_state' => $state, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown'];
            })->toArray();
    }

    private function allocationPage(Builder $base, string $tab, int $page, CurrentAuthorizationReads $reads): array
    {
        return $base->whereNull('returned_at')->when($tab === 'alloc_unack', fn ($q) => $q->where('acknowledged', false))
            ->with(['ppeInventory' => fn ($q) => $reads->query($q->getQuery()), 'ppeInventory.ppeType' => fn ($q) => $reads->query($q->getQuery()), 'ppeInventory.site' => fn ($q) => $reads->query($q->getQuery())->select(['id', 'name']), 'user' => fn ($q) => $reads->query($q->getQuery())->select(['id', 'name'])])
            ->orderByDesc('allocated_at')->orderByDesc('id')->paginate(25, ['*'], 'allocations_page', max(1, $page))
            ->withQueryString()->through(function (PpeAllocation $allocation) {
                $commands = app(PpeCommandService::class);
                $item = $allocation->ppeInventory;
                $state = ! $item || ! $item->ppeType || $commands->sourceState($item) === 'repair_required'
                    || $commands->sourceState($item->ppeType) === 'repair_required'
                    ? 'repair_required' : $commands->sourceState($item, $allocation);
                if ($state === 'repair_required') {
                    return $this->repairRow((int) $allocation->id, 'allocations');
                }
                return [...$this->presenter->allocationRow($allocation), 'fit_test_current' => null, 'training_current' => null,
                    'attribution_state' => $state, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown'];
            })->toArray();
    }

    /** Full source snapshots are admitted by the already scoped input, in bounded chunks. */
    private function scanInventory(Builder $base, CurrentAuthorizationReads $reads, array &$proofs): void
    {
        $base->with(['ppeType' => fn ($q) => $reads->query($q->getQuery())])->chunkById(100, function ($items) use (&$proofs) {
            foreach ($items as $item) {
                $this->inventoryState($item, $proofs);
            }
        });
    }

    private function inventoryState(PpeInventory $item, array &$proofs): string
    {
        $type = $item->ppeType;
        $proofs['inventory_type_ids'][$item->id] = (int) $item->ppe_type_id;
        $fingerprint = hash('sha256', json_encode(['inventory' => $item->getRawOriginal(),
            'type' => $type?->getRawOriginal()], JSON_THROW_ON_ERROR));
        $typeFingerprint = $type ? hash('sha256', json_encode($type->getRawOriginal(), JSON_THROW_ON_ERROR)) : null;
        if (isset($proofs['inventory'][$item->id]) && ($proofs['inventory_fingerprints'][$item->id] ?? null) === $fingerprint) {
            return $proofs['inventory'][$item->id];
        }
        $commands = app(PpeCommandService::class);
        $state = $commands->sourceState($item);
        $type = $item->ppeType;
        if ($type && ($proofs['type_fingerprints'][$type->id] ?? null) !== $typeFingerprint) {
            $proofs['types'][$type->id] = $commands->sourceState($type);
            $proofs['type_fingerprints'][$type->id] = $typeFingerprint;
        }
        $typeState = $type ? $proofs['types'][$type->id] : 'repair_required';
        $proofs['inventory_fingerprints'][$item->id] = $fingerprint;
        $state = $typeState === 'repair_required' ? 'repair_required' : $state;
        if ($state === 'repair_required') { $proofs['inventory_type_damage'][$item->ppe_type_id] = true; }
        return $proofs['inventory'][$item->id] = $state;
    }

    private function scanAllocations(Builder $base, CurrentAuthorizationReads $reads, array &$proofs): void
    {
        $base->with(['ppeInventory' => fn ($q) => $reads->query($q->getQuery()), 'ppeInventory.ppeType' => fn ($q) => $reads->query($q->getQuery())])->chunkById(100, function ($allocations) use (&$proofs) {
            foreach ($allocations as $allocation) {
                $item = $allocation->ppeInventory;
                $proofs['allocations'][$allocation->id] = ! $item || $this->inventoryState($item, $proofs) === 'repair_required'
                    ? 'repair_required' : app(PpeCommandService::class)->sourceState($item, $allocation);
            }
        });
    }

    private function summaryIntegrity(Builder $inventory, Builder $allocations, array $proofs): array
    {
        $result = ['inventory' => 'intact', 'allocations' => 'intact'];
        foreach (['inventory' => $inventory, 'allocations' => $allocations] as $kind => $query) {
            $query->select($query->getModel()->getTable().'.id')->chunkById(100, function ($rows) use ($kind, $proofs, &$result) {
                foreach ($rows as $row) {
                    $state = $proofs[$kind][$row->id];
                    if ($state === 'repair_required') {
                        $result[$kind] = 'repair_required';
                        return false;
                    }
                    if ($state === 'legacy_unknown') {
                        $result[$kind] = 'legacy_unknown';
                    }
                }
            });
        }
        return $result;
    }

    private function trustedCounts(array $physical, array $integrity): array
    {
        foreach ($physical as $key => $value) {
            $family = str_starts_with($key, 'inv_') ? 'inventory' : (str_starts_with($key, 'alloc_') ? 'allocations' : null);
            if ($family && $integrity[$family] === 'repair_required') {
                $physical[$key] = null;
            }
        }
        return $physical;
    }

    private function trustedHero(array $hero, array $integrity): array
    {
        $hero['physical_rows'] = ['inventory' => $hero['clusters']['live']['total']];
        $hero['source_integrity'] = $integrity;
        if ($integrity['inventory'] === 'repair_required') {
            $hero['clusters']['live'] = array_fill_keys(array_keys($hero['clusters']['live']), null);
            foreach (['inspection_overdue', 'expiring', 'condemned'] as $key) { $hero['clusters']['attention'][$key] = null; }
            foreach (['inspections_overdue', 'inspections_due', 'items_expiring', 'condemned_awaiting', 'hi_vis_recorded', 'footwear_recorded'] as $key) {
                $hero['compliance'][$key] = null;
            }
        }
        if ($integrity['allocations'] === 'repair_required') {
            $hero['clusters']['attention']['unacknowledged'] = null;
            $hero['compliance']['rpe_fit_test_due'] = null;
        }
        return $hero;
    }

    private function repairRow(int $id, string $kind): array
    {
        return ['id' => $id, 'href' => '/health-safety/ppe/'.$kind.'/'.$id, 'attribution_state' => 'repair_required',
            'source_integrity' => 'repair_required', 'ppe_type' => null, 'inventory_item' => null, 'site' => null,
            'user' => null, 'brand' => null, 'model' => null, 'serial_number' => null, 'quantity' => null,
            'location' => null, 'condition' => null, 'status' => null, 'purchase_date' => null, 'expiry_date' => null,
            'last_inspected_at' => null, 'next_inspection_due' => null, 'allocated_at' => null, 'returned_at' => null,
            'acknowledged' => null, 'fit_test_completed' => null, 'training_completed' => null,
            'currency' => null, 'can' => ['manage' => false]];
    }
    private function types(User $current, CurrentAuthorizationReads $reads, array $sites, array $proofs): array
    {
        $counts = array_count_values($proofs['inventory_type_ids']);
        return $reads->query(PpeType::query())
            ->orderBy('category')->orderBy('name')->get()->map(function ($type) use ($current, $proofs, $counts) {
                $state = app(PpeCommandService::class)->sourceState($type);
                if ($state === 'repair_required') {
                    return ['id' => (int) $type->id, 'name' => null, 'category' => null, 'description' => null,
                        'hazards_addressed' => null, 'standards_reference' => null, 'inspection_frequency' => null,
                        'typical_lifespan_months' => null, 'is_active' => null, 'inventory_count' => isset($proofs['inventory_type_damage'][$type->id]) ? null : (int) ($counts[$type->id] ?? 0),
                    'physical_inventory_count' => (int) ($counts[$type->id] ?? 0), 'count_scope' => 'approved_sites', 'inventory_integrity' => isset($proofs['inventory_type_damage'][$type->id]) ? 'repair_required' : 'readable',
                        'source_integrity' => 'repair_required', 'attribution_state' => 'repair_required',
                        'source_schema_version' => null, 'source_revision' => null, 'command_context' => null,
                        'can' => ['manage' => false]];
                }
                return ['id' => (int) $type->id, 'name' => $type->name, 'category' => $type->category,
                    'description' => $type->description, 'hazards_addressed' => $type->hazards_addressed,
                    'standards_reference' => $type->standards_reference, 'inspection_frequency' => $type->inspection_frequency,
                    'typical_lifespan_months' => $type->typical_lifespan_months, 'is_active' => (bool) $type->is_active,
                    'inventory_count' => isset($proofs['inventory_type_damage'][$type->id]) ? null : (int) ($counts[$type->id] ?? 0),
                    'physical_inventory_count' => (int) ($counts[$type->id] ?? 0), 'count_scope' => 'approved_sites', 'inventory_integrity' => isset($proofs['inventory_type_damage'][$type->id]) ? 'repair_required' : 'readable',
                    'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown', 'attribution_state' => $state,
                    'source_schema_version' => (int) ($type->source_schema_version ?? 0), 'source_revision' => (int) ($type->source_revision ?? 0),
                    'can' => ['manage' => $current->canDo('hazards.manage')],
                    'command_context' => ['root_kind' => 'type', 'root_id' => (int) $type->id, 'source_kind' => 'type',
                        'source_id' => (int) $type->id, 'expected_version' => (int) ($type->source_revision ?? 0),
                        'source_schema_version' => (int) ($type->source_schema_version ?? 0), 'site_id' => null, 'user_id' => null]];
            })->all();
    }
    private function inventoryBase(array $filters, Builder $base): Builder
    {
        return $base
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

    private function allocationBase(array $filters, Builder $base): Builder
    {
        return $base
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

    private function tabCounts(Builder $invBase, Builder $allocBase): array
    {
        $in30 = now('Pacific/Auckland')->addDays(30)->toDateString();
        $in60 = now('Pacific/Auckland')->addDays(60)->toDateString();

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

    private function hero(Builder $invBase, Builder $allocBase, $siteId): array
    {
        $today = now('Pacific/Auckland')->toDateString();
        $in30 = now('Pacific/Auckland')->addDays(30)->toDateString();
        $in60 = now('Pacific/Auckland')->addDays(60)->toDateString();

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

        // Recorded category presence within this base is not inventory completeness or worker coverage.
        $coverage = fn (string $category) => (clone $invBase)
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
                'hi_vis_covered' => null,
                'hi_vis_recorded' => $coverage('high_visibility'),
                'footwear_covered' => null,
                'footwear_recorded' => $coverage('foot'),
                'inventory_complete' => null, 'fit_test_current' => null, 'training_current' => null,
            ],
        ];
    }
}
