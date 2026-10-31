<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;

final class PpePickerService
{
    public function __construct(private readonly PpeAccessService $access, private readonly HrCurrentStaffService $staff) {}

    public function options(User $viewer, array $filters = [], string $operation = 'view'): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($filters, $operation) {
            $this->access->assertOperation($current, $operation);
            return $this->optionsInContext($current, $reads, $sites, $filters, $operation);
        });
    }

    public function optionsInContext(User $current, CurrentAuthorizationReads $reads, array $sites,
        array $filters = [], string $operation = 'view'): array
    {
        $this->access->assertOperation($current, $operation);
        if (! empty($filters['site_id'])) {
            $this->access->assertSite((int) $filters['site_id'], $current, $reads, $sites);
            $sites = [(int) $filters['site_id']];
        }
        $siteRows = $reads->query(Site::query())->whereIn('id', $sites)->when($operation === 'create', fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name'])
            ->map(fn ($site) => ['id' => (int) $site->id, 'name' => $site->name])->all();
        $types = $reads->query(PpeType::query())->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($type) => app(PpeCommandService::class)->sourceState($type) !== 'repair_required')->values()
            ->map(fn ($type) => ['id' => (int) $type->id, 'name' => $type->name, 'category' => $type->category,
                'standards_reference' => $type->standards_reference, 'inspection_frequency' => $type->inspection_frequency])->all();
        $result = ['sites' => $siteRows, 'types' => $types, 'staff' => [], 'allocatable' => [],
            'staff_page' => null, 'allocatable_page' => null,
            'can' => ['create' => $current->canDo('hazards.manage'), 'catalogue_manage' => $current->canDo('hazards.manage')]];
        if (! $current->canDo('hazards.manage')) {
            return $result;
        }
        $staffQuery = $reads->query($this->staff->currentUsersQuery())->whereHas('hrEmployeeProfile', function ($profile) use ($sites) {
            $profile->where(function ($membership) use ($sites) {
                $membership->whereIn('primary_site_id', $sites);
                foreach ($sites as $siteId) {
                    $membership->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            });
        })->when(! empty($filters['staff_search']), fn ($q) => $q->where('name', 'like', '%'.$filters['staff_search'].'%'));
        $staffPage = $staffQuery->orderBy('name')->orderBy('id')->paginate(50, ['users.id', 'users.name'], 'staff_page', max(1, (int) ($filters['staff_page'] ?? 1)));
        $result['staff'] = $staffPage->getCollection()->map(fn ($person) => ['id' => (int) $person->id, 'name' => $person->name])->all();
        $result['staff_page'] = ['current_page' => $staffPage->currentPage(), 'last_page' => $staffPage->lastPage(), 'total' => $staffPage->total()];
        $items = $reads->query(PpeInventory::query())->whereIn('site_id', $sites)->where('status', 'available')
            ->whereHas('ppeType', fn ($type) => $type->where('is_active', true))
            ->when(! empty($filters['item_search']), fn ($q) => $q->where(fn ($term) => $term->where('serial_number', 'like', '%'.$filters['item_search'].'%')->orWhere('brand', 'like', '%'.$filters['item_search'].'%')))
            ->with(['ppeType' => fn ($q) => $reads->query($q->getQuery()), 'site' => fn ($q) => $reads->query($q->getQuery())->select(['id', 'name'])])->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(50, ['*'], 'item_page', max(1, (int) ($filters['item_page'] ?? 1)));
        // Explicit current identity reads for this bounded page; COUNT subselects must not use an old snapshot.
        $activeCounts = $reads->query(PpeAllocation::query())->whereIn('ppe_inventory_id', $items->getCollection()->pluck('id'))
            ->whereNull('returned_at')->get(['id', 'ppe_inventory_id'])->groupBy('ppe_inventory_id')->map(fn ($rows) => $rows->count())->all();
        $result['allocatable'] = $items->getCollection()->map(function ($item) use ($activeCounts) {
            $commands = app(PpeCommandService::class);
            $state = $commands->sourceState($item);
            $typeState = $item->ppeType ? $commands->sourceState($item->ppeType) : 'repair_required';
            if ($state === 'repair_required' || $typeState === 'repair_required') {
                return ['id' => (int) $item->id, 'label' => null, 'category' => null, 'site' => null,
                    'site_id' => (int) $item->site_id, 'quantity' => null, 'release_authorized' => null,
                    'source_integrity' => 'repair_required', 'can' => ['allocate' => false],
                    'blocked_reason' => 'The current item or catalogue proof requires repair.'];
            }
            $constraint = $commands->constraints($item, (int) ($activeCounts[$item->id] ?? 0), $item->ppeType)['allocate'];
            return ['id' => (int) $item->id, 'label' => trim(($item->ppeType?->name ?? 'Item').' · '.($item->serial_number ?: ($item->brand ?? '#'.$item->id))),
                'category' => $item->ppeType?->category, 'site' => $item->site?->name,
                'site_id' => (int) $item->site_id, 'quantity' => (int) $item->quantity,
                'release_authorized' => null, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown',
                'can' => ['allocate' => $constraint['allowed']], 'blocked_reason' => $constraint['blocked_reason']];
        })->all();
        $result['allocatable_page'] = ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()];
        return $result;
    }

    /** Existing My Day attention predicate; narrow own records, no inventory-wide child/evidence load. */
    public function ownAllocations(User $viewer): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) {
            return $this->access->allocationQuery($current, $reads, $sites, true)->whereNull('returned_at')
                ->with(['ppeInventory' => fn ($q) => $reads->query($q->getQuery())->select(PpeAccessService::INVENTORY_OWN_COLUMNS),
                    'ppeInventory.ppeType' => fn ($q) => $reads->query($q->getQuery())->select(['id', 'name', 'category', 'is_active', 'source_schema_version', 'source_revision']), 'ppeInventory.site' => fn ($q) => $reads->query($q->getQuery())->select(['id', 'name'])])
                ->orderByDesc('allocated_at')->orderByDesc('id')->get()
                ->map(function (PpeAllocation $allocation) {
                    $item = $allocation->ppeInventory;
                    $state = app(PpeCommandService::class)->ownSourceState($item, $allocation);
                    if ($state === 'repair_required') {
                        return ['id' => (int) $allocation->id, 'href' => '/health-safety/ppe/allocations/'.$allocation->id,
                            'attribution_state' => 'repair_required', 'source_integrity' => 'repair_required',
                            'type_name' => null, 'category' => null, 'serial_number' => null, 'site' => null,
                            'allocated_at' => null, 'acknowledged' => null, 'fit_test_required' => null,
                            'fit_test_completed' => null, 'fit_test_current' => null, 'can' => ['acknowledge_own' => false]];
                    }
                    $catalogueState = $item->ppeType ? app(PpeCommandService::class)->ownTypeReferenceState($item->ppeType) : 'repair_required';
                    if ($catalogueState === 'repair_required') { $item->setRelation('ppeType', null); }
                    $respiratory = $item->ppeType?->category === 'respiratory';
                    if ($catalogueState !== 'repair_required' && $allocation->acknowledged && (! $respiratory || $allocation->fit_test_completed)) {
                        return null;
                    }
                    return ['id' => (int) $allocation->id, 'type_name' => $item->ppeType?->name,
                        'category' => $item->ppeType?->category, 'serial_number' => $item->serial_number,
                        'site' => $item->site?->name, 'allocated_at' => PpeReadPresenter::timestamp($allocation->allocated_at),
                        'acknowledged' => (bool) $allocation->acknowledged,
                        'fit_test_required' => $catalogueState === 'repair_required' ? null : $respiratory,
                        'fit_test_completed' => (bool) $allocation->fit_test_completed, 'fit_test_current' => null,
                        'attribution_state' => $state, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown', 'catalogue_integrity' => $catalogueState,
                        'href' => '/health-safety/ppe/allocations/'.$allocation->id,
                        'can' => ['acknowledge_own' => ! $allocation->acknowledged && $allocation->returned_at === null]];
                })->filter(fn ($row) => $row !== null)->values()->all();        });
    }
}
