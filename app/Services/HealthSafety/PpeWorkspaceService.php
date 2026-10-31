<?php

namespace App\Services\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeAllocationAttachment;
use App\Models\PpeAttachment;
use App\Models\PpeInspection;
use App\Models\PpeInspectionAttachment;
use App\Models\PpeSourceVersion;
use App\Models\PpeType;
use App\Models\User;
use Throwable;
use LogicException;

/** Admission precedes child/narrative/evidence queries; only detached DTOs leave current authority. */
final class PpeWorkspaceService
{
    public function __construct(private readonly PpeAccessService $access, private readonly PpeReadPresenter $presenter) {}

    public function item(User $viewer, int $id, array $pages = []): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($id, $pages) {
            $item = $this->access->assertInventory($id, $current, $reads, $sites);
            $commands = app(PpeCommandService::class);
            $state = $commands->sourceState($item);
            if ($state === 'repair_required') {
                return $this->unavailable($item->id, 'inventory');
            }
            $item->load(['site:id,name', 'createdBy:id,name', 'updatedBy:id,name', 'condemnedBy:id,name', 'disposedBy:id,name']);
            $item->setRelation('ppeType', $reads->query(PpeType::query())->whereKey($item->ppe_type_id)->first());
            $catalogueState = $item->ppeType ? $commands->sourceState($item->ppeType) : 'repair_required';
            $allocations = $reads->query(PpeAllocation::query())->where('ppe_inventory_id', $item->id)
                ->with(['user:id,name', 'createdBy:id,name', 'acknowledgedBy:id,name'])->orderByDesc('allocated_at')->orderByDesc('id')
                ->paginate(25, ['*'], 'allocations_page', max(1, (int) ($pages['allocations_page'] ?? 1)));
            $inspections = $reads->query(PpeInspection::query())->where('ppe_inventory_id', $item->id)
                ->with('inspector:id,name')->orderByDesc('inspected_at')->orderByDesc('id')
                ->paginate(25, ['*'], 'inspections_page', max(1, (int) ($pages['inspections_page'] ?? 1)));
            $childIntegrity = ['allocations' => 'readable', 'inspections' => 'readable'];
            $childStates = ['allocations' => [], 'inspections' => []];
            $readableAllocations = $allocations->getCollection()->filter(function ($child) use ($commands, $item, &$childIntegrity, &$childStates) {
                $childStates['allocations'][(int) $child->id] = $commands->sourceState($item, $child);
                if ($childStates['allocations'][(int) $child->id] === 'repair_required') {
                    $childIntegrity['allocations'] = 'repair_required';
                    return false;
                }
                return true;
            })->values();
            $readableInspections = $inspections->getCollection()->filter(function ($child) use ($commands, $item, &$childIntegrity, &$childStates) {
                $childStates['inspections'][(int) $child->id] = $commands->sourceState($item, $child);
                if ($childStates['inspections'][(int) $child->id] === 'repair_required') {
                    $childIntegrity['inspections'] = 'repair_required';
                    return false;
                }
                return true;
            })->values();
            $inspectionEvidence = [];
            foreach ($readableInspections as $inspection) {
                $files = $reads->query(PpeInspectionAttachment::query())->where('ppe_inspection_id', $inspection->id)
                    ->with('uploader:id,name')->orderByDesc('id')->paginate(25, ['*'], 'attachments_page', 1);
                $inspection->setRelation('attachments', $files->getCollection());
                $inspectionEvidence[(int) $inspection->id] = $this->pageMeta($files);
            }
            $attachments = $reads->query(PpeAttachment::query())->where('ppe_inventory_id', $item->id)->with('uploader:id,name')
                ->orderByDesc('id')->paginate(25, ['*'], 'attachments_page', max(1, (int) ($pages['attachments_page'] ?? 1)));
            $item->setRelation('allocations', $readableAllocations);
            $item->setRelation('inspections', $readableInspections);
            $item->setRelation('attachments', $attachments->getCollection());
            $activeBase = $reads->query(PpeAllocation::query())->where('ppe_inventory_id', $item->id)->whereNull('returned_at');
            $activeCount = (clone $activeBase)->count();
            $active = $activeBase->with('user:id,name')->orderByDesc('id')->limit(2)->get();
            $activeDamaged = $active->contains(fn ($child) => $commands->sourceState($item, $child) === 'repair_required');
            $capabilities = $this->itemCapabilities($item, $current, $activeCount, $state);
            if ($activeDamaged) {
                $capabilities['can'] = array_fill_keys(array_keys($capabilities['can']), false);
                $capabilities['blocked_reasons'] = array_fill_keys(array_keys($capabilities['can']), 'An active issue source requires repair.');
            }
            $history = $this->history($item, $reads, $pages);
            if ($catalogueState === 'repair_required') { $item->setRelation('ppeType', null); }
            $payload = $this->presenter->itemDetailPayload($item, $activeDamaged ? collect() : $active,
                fn ($file) => $this->evidenceState($item, $file, $current->canDo('hazards.manage')));
            foreach (['allocations', 'inspections'] as $section) {
                $payload[$section] = array_map(function ($row) use ($section, $childStates) {
                    $state = $childStates[$section][$row['id']];
                    return [...$row, 'attribution_state' => $state, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown'];
                }, $payload[$section]);
            }
            $payload['active_allocation_attribution'] = $activeCount === 1 && ! $activeDamaged
                ? $commands->sourceState($item, $active->first()) : ($activeDamaged ? 'repair_required' : null);
            if ($activeDamaged) {
                $payload['active_allocation_state'] = 'repair_required';
                $payload['active_allocation'] = null;
            }
            return [...$payload,
                'pages' => ['allocations' => $this->pageMeta($allocations), 'inspections' => $this->pageMeta($inspections),
                    'attachments' => $this->pageMeta($attachments), 'inspection_attachments' => $inspectionEvidence, 'history' => $history['page']],
                'child_integrity' => $childIntegrity, 'catalogue_integrity' => $catalogueState, 'command_history' => $history['rows'], 'command_history_integrity' => $history['integrity'],
                'history_scope' => 'loaded_record_pages', 'history_attribution' => 'reconstructed_records',
                'command_history_scope' => 'inventory_aggregate', 'currency' => $this->presenter->currency($item),
                'command_context' => $this->presenter->commandContext($item, $item, 'inventory'),
                'href' => '/health-safety/ppe/inventory/'.$item->id, 'attribution_state' => $state, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown', ...$capabilities];
        });
    }

    public function allocation(User $viewer, int $id, array $pages = []): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($id, $pages) {
            $ordinary = $current->canDo('hazards.view');
            $allocation = $this->access->assertAllocation($id, $current, $reads, $sites, $ordinary ? 'view' : 'own_view');
            $item = $allocation->ppeInventory;
            if ($ordinary) {
                $state = app(PpeCommandService::class)->sourceState($item, $allocation);
                if ($state === 'repair_required') {
                    return $this->unavailable($allocation->id, 'allocations');
                }
            }
            if (! $ordinary) {
                $state = app(PpeCommandService::class)->ownSourceState($item, $allocation);
                if ($state === 'repair_required') {
                    return [...$this->unavailable($allocation->id, 'allocations'), 'read_scope' => 'own_allocation'];
                }
            }
            $item->load(['site' => fn ($q) => $reads->query($q->getQuery())->select(['id', 'name'])]);
            $item->setRelation('ppeType', $reads->query(PpeType::query())->whereKey($item->ppe_type_id)
                ->when(! $ordinary, fn ($q) => $q->select(['id', 'name', 'category', 'is_active', 'source_schema_version', 'source_revision']))->first());
            $catalogueState = $item->ppeType ? ($ordinary ? app(PpeCommandService::class)->sourceState($item->ppeType)
                : app(PpeCommandService::class)->ownTypeReferenceState($item->ppeType)) : 'repair_required';
            if ($catalogueState === 'repair_required') { $item->setRelation('ppeType', null); }
            $allocation->load('user:id,name');
            if (! $ordinary) {
                return [...$this->presenter->allocationRow($allocation), 'returned_at' => PpeReadPresenter::timestamp($allocation->returned_at),
                    'command_context' => $this->presenter->commandContext($item, $allocation, 'allocations'),
                    'read_scope' => 'own_allocation', 'catalogue_integrity' => $catalogueState, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown', 'attribution_state' => $state, 'fit_test_current' => null, 'training_current' => null,
                    'href' => '/health-safety/ppe/allocations/'.$allocation->id,
                    'can' => ['manage' => false, 'evidence' => false, 'return' => false, 'acknowledge' => false,
                        'acknowledge_own' => $allocation->returned_at === null && ! $allocation->acknowledged],
                    'blocked_reasons' => ['return' => 'Current PPE management permission is required.', 'evidence' => 'Current PPE module read authority is required.']];
            }
            $allocation->load(['createdBy:id,name', 'acknowledgedBy:id,name']);
            $attachments = $reads->query(PpeAllocationAttachment::query())->where('ppe_allocation_id', $allocation->id)->with('uploader:id,name')
                ->orderByDesc('id')->paginate(25, ['*'], 'attachments_page', max(1, (int) ($pages['attachments_page'] ?? 1)));
            $allocation->setRelation('attachments', $attachments->getCollection());
            $history = $this->history($item, $reads, $pages);
            $manage = $current->canDo('hazards.manage');
            $live = $allocation->returned_at === null;
            return [...$this->presenter->allocationDetailPayload($allocation, fn ($file) => $this->evidenceState($item, $file, $current->canDo('hazards.manage'))),
                'attribution_state' => $state, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown', 'pages' => ['attachments' => $this->pageMeta($attachments), 'history' => $history['page']],
                'command_history' => $history['rows'], 'command_history_integrity' => $history['integrity'], 'command_history_scope' => 'inventory_aggregate',
                'command_context' => $this->presenter->commandContext($item, $allocation, 'allocations'), 'read_scope' => 'site_operational', 'catalogue_integrity' => $catalogueState,
                'fit_test_current' => null, 'training_current' => null, 'fitness_for_work' => null,
                'href' => '/health-safety/ppe/allocations/'.$allocation->id,
                'can' => ['manage' => $manage, 'evidence' => $manage, 'return' => $manage && $live,
                    'acknowledge' => $manage && $live && ! $allocation->acknowledged,
                    'acknowledge_own' => (int) $allocation->user_id === (int) $current->id && $live && ! $allocation->acknowledged],
                'blocked_reasons' => ['return' => ! $manage ? 'Current PPE management permission is required.' : (! $live ? 'This issue has already been returned.' : null),
                    'acknowledge' => ! $manage ? 'Current PPE management permission is required.' : (! $live ? 'This issue has already been returned.' : ($allocation->acknowledged ? 'This issue has already been acknowledged.' : null))]];
        });
    }

    public function inspection(User $viewer, int $id, array $pages = []): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($id, $pages) {
            $inspection = $this->access->assertInspection($id, $current, $reads, $sites);
            $item = $inspection->ppeInventory;
            $state = app(PpeCommandService::class)->sourceState($item, $inspection);
            if ($state === 'repair_required') {
                return $this->unavailable($inspection->id, 'inspections');
            }
            $inspection->load('inspector:id,name');
            $attachments = $reads->query(PpeInspectionAttachment::query())->where('ppe_inspection_id', $inspection->id)->with('uploader:id,name')
                ->orderByDesc('id')->paginate(25, ['*'], 'attachments_page', max(1, (int) ($pages['attachments_page'] ?? 1)));
            $history = $this->history($item, $reads, $pages);
            return ['id' => (int) $inspection->id, 'ppe_inventory_id' => (int) $inspection->ppe_inventory_id,
                'result' => $inspection->result, 'condition_after' => $inspection->condition_after,
                'findings' => $inspection->findings, 'action_taken' => $inspection->action_taken,
                'inspected_at' => PpeReadPresenter::timestamp($inspection->inspected_at),
                'next_inspection_due' => PpeReadPresenter::date($inspection->next_inspection_due),
                'inspector' => $inspection->inspector ? ['id' => (int) $inspection->inspector->id, 'name' => $inspection->inspector->name] : null,
                'attachments' => $this->presenter->serializeAttachments($attachments->getCollection(), '/health-safety/ppe/inspections/'.$inspection->id.'/attachments', fn ($file) => $this->evidenceState($item, $file, $current->canDo('hazards.manage'))),
                'command_context' => $this->presenter->commandContext($item, $inspection, 'inspections'),
                'href' => '/health-safety/ppe/inspections/'.$inspection->id,
                'attribution_state' => $state, 'source_integrity' => $state === 'recorded' ? 'intact' : 'legacy_unknown', 'pages' => ['attachments' => $this->pageMeta($attachments), 'history' => $history['page']],
                'command_history' => $history['rows'], 'command_history_integrity' => $history['integrity'], 'command_history_scope' => 'inventory_aggregate',
                'inventory_href' => '/health-safety/ppe/inventory/'.$inspection->ppe_inventory_id,
                'release_authorized' => null, 'can' => ['manage' => $current->canDo('hazards.manage'), 'evidence' => $current->canDo('hazards.manage')]];
        });
    }

    /** INTERNAL stream descriptor only: controllers must never serialize this into page props. */
    public function evidence(User $viewer, string $kind, int $parentId, int $attachmentId): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($kind, $parentId, $attachmentId) {
            $this->access->assertOperation($current, 'view');
            [$parent, $class] = match ($kind) {
                'inventory' => [$this->access->assertInventory($parentId, $current, $reads, $sites), PpeAttachment::class],
                'allocations' => [$this->access->assertAllocation($parentId, $current, $reads, $sites), PpeAllocationAttachment::class],
                'inspections' => [$this->access->assertInspection($parentId, $current, $reads, $sites), PpeInspectionAttachment::class],
                default => abort(404),
            };
            $identity = new $class;
            $identity->id = $attachmentId;
            $attachment = $this->access->assertAttachment($identity, $parent, $current, $reads, $sites);
            $root = $kind === 'inventory' ? $parent : $parent->ppeInventory;
            $commands = app(PpeCommandService::class);
            abort_if($commands->sourceState($root, $parent) === 'repair_required', 404);
            abort_unless($attachment->disk === 'private' && self::safePath($attachment->path), 404);
            try {
                $proof = $commands->evidenceReadProof($root, $attachment);
                abort_unless($proof['available'] && in_array($proof['attribution_state'], ['recorded', 'legacy_unknown'], true), 404);
                $bytes = $proof['byte_proof'];
                abort_unless(is_array($bytes) && is_int($bytes['size']) && $bytes['size'] >= 0
                    && is_string($bytes['digest']) && preg_match('/^[a-f0-9]{64}$/D', $bytes['digest']), 404);
            } catch (Throwable) {
                abort(404);
            }
            return ['disk' => 'private', 'path' => $attachment->path, 'original_name' => $attachment->original_name,
                'mime' => $attachment->mime, 'size' => $bytes['size'], 'digest' => $bytes['digest'], 'attribution_state' => $proof['attribution_state']];
        });
    }

    private static function safePath(mixed $path): bool
    {
        return is_string($path) && $path !== '' && ! str_contains($path, '..') && ! str_contains($path, chr(92))
            && ! str_starts_with($path, '/') && ! str_contains($path, ':') && ! preg_match('/[\x00-\x1f\x7f]/', $path);
    }

    private function evidenceState($root, $file, bool $manage = false): array
    {
        $commands = app(PpeCommandService::class);
        $relocation = ['can_relocate' => false, 'relocate_blocked_reason' => 'Current PPE management permission is required.'];
        if ($commands->sourceState($root, $file) === 'repair_required') {
            return ['attribution_state' => 'repair_required', 'available' => false,
                'can_relocate' => false, 'relocate_blocked_reason' => 'The retained source proof requires repair.'];
        }
        if (in_array($file->disk, [null, 'public'], true) && self::safePath($file->path)) {
            if ($manage) {
                try {
                    $constraint = $commands->relocationConstraint($root, $file);
                    $relocation = ['can_relocate' => $constraint['allowed'], 'relocate_blocked_reason' => $constraint['blocked_reason']];
                } catch (Throwable) {
                    $relocation = ['can_relocate' => false, 'relocate_blocked_reason' => 'The retained public byte proof is unavailable.'];
                }
            }
            return ['attribution_state' => 'legacy_unknown', 'available' => false, ...$relocation];
        }
        $relocation = ['can_relocate' => false, 'relocate_blocked_reason' => 'Only genuine retained public PPE evidence can be transferred.'];
        if ($file->disk !== 'private' || ! self::safePath($file->path)) {
            return ['attribution_state' => 'unavailable', 'available' => false, ...$relocation];
        }
        try {
            return [...$commands->evidenceProof($root, $file), ...$relocation];
        } catch (Throwable) {
            return ['attribution_state' => 'unavailable', 'available' => false, ...$relocation];
        }
    }

    private function history($item, $reads, array $pages): array
    {
        $page = $reads->query(PpeSourceVersion::query())->where('root_type', 'inventory')->where('root_id', $item->id)
            ->where('site_id', $item->site_id)->orderByDesc('aggregate_revision')->orderByDesc('id')
            ->paginate(25, ['*'], 'history_page', max(1, (int) ($pages['history_page'] ?? 1)));
        $integrity = 'recorded';
        $rows = $page->getCollection()->map(function ($version) use ($item, &$integrity) {
            $proof = app(PpeCommandService::class)->historyProjection($item, $version);
            if ($proof['integrity'] !== 'recorded') {
                $integrity = 'repair_required';
                return ['id' => (int) $version->id, 'command' => null, 'version' => null, 'recorded_at' => null,
                    'actor' => null, 'attribution_state' => 'repair_required'];
            }
            return ['id' => $proof['id'], 'command' => $proof['command'], 'version' => $proof['revision'],
                'recorded_at' => PpeReadPresenter::timestamp($version->recorded_at), 'actor' => $proof['actor'], 'attribution_state' => 'recorded'];
        })->all();
        return ['rows' => $rows, 'page' => $this->pageMeta($page), 'integrity' => $page->total() === 0 ? 'legacy_unknown' : $integrity];
    }

    private function unavailable(int $id, string $kind): array
    {
        return ['id' => $id, 'attribution_state' => 'repair_required', 'source_integrity' => 'repair_required', 'read_scope' => 'source_repair_required',
            'href' => '/health-safety/ppe/'.$kind.'/'.$id, 'command_context' => null,
            'allocations' => null, 'inspections' => null, 'attachments' => null, 'history' => null, 'command_history' => null,
            'pages' => null, 'active_allocation' => null, 'active_allocation_state' => 'repair_required',
            'can' => array_fill_keys(['manage', 'evidence', 'allocate', 'inspect', 'condemn', 'dispose', 'hold', 'release', 'return', 'acknowledge', 'acknowledge_own'], false),
            'blocked_reasons' => ['manage' => 'The retained source proof requires repair.']];
    }

    private function pageMeta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'total_scope' => 'canonical_source_rows'];
    }

    private function itemCapabilities($item, User $current, int $active, string $state): array
    {
        $manage = $current->canDo('hazards.manage');
        try {
            $constraints = app(PpeCommandService::class)->constraints($item, $active, $item->ppeType);
        } catch (LogicException) {
            return ['can' => array_fill_keys(['manage', 'evidence', 'allocate', 'hold', 'release', 'condemn', 'dispose', 'inspect'], false),
                'blocked_reasons' => ['manage' => 'The retained command history requires repair.']];
        }
        $can = ['manage' => $manage && $state !== 'repair_required', 'evidence' => $manage && $state !== 'repair_required'];
        $blocked = [];
        foreach ($constraints as $command => $constraint) {
            $can[$command] = $manage && $state !== 'repair_required' && $constraint['allowed'];
            $blocked[$command] = ! $manage ? 'Current PPE management permission is required.'
                : ($state === 'repair_required' ? 'The retained source proof requires repair.' : $constraint['blocked_reason']);
        }
        return ['can' => $can, 'blocked_reasons' => $blocked];
    }
}
