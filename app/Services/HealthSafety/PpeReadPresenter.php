<?php

namespace App\Services\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeInspection;
use App\Models\PpeInventory;
use Illuminate\Support\Facades\Storage;

/** Field-preserving detached PPE projections; retained records are not proof of fitness/compliance. */
final class PpeReadPresenter
{
    public static function date(mixed $value): ?string
    {
        return $value?->toDateString();
    }

    public static function timestamp(mixed $value): ?string
    {
        return $value?->copy()->timezone('Pacific/Auckland')->toIso8601String();
    }
    public function inventoryRow(PpeInventory $i, string $today): array
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
            'purchase_date' => self::date($i->purchase_date),
            'expiry_date' => self::date($i->expiry_date),
            'last_inspected_at' => self::date($i->last_inspected_at),
            'next_inspection_due' => self::date($i->next_inspection_due),
        ];
    }

    public function allocationRow(PpeAllocation $a): array
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
            'allocated_at' => self::timestamp($a->allocated_at),
            'fit_test_completed' => (bool) $a->fit_test_completed,
            'fit_test_date' => self::date($a->fit_test_date),
            'training_completed' => (bool) $a->training_completed,
            'training_date' => self::date($a->training_date),
            'acknowledged' => (bool) $a->acknowledged,
            'acknowledged_at' => self::timestamp($a->acknowledged_at),
        ];
    }

    public function itemDetailPayload(PpeInventory $i, $activeAllocations = null, ?callable $evidenceProof = null): array
    {
        $activeAllocations ??= $i->allocations->whereNull('returned_at');
        $activeAllocation = $activeAllocations->count() === 1 ? $activeAllocations->first() : null;

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
            'purchase_date' => self::date($i->purchase_date),
            'expiry_date' => self::date($i->expiry_date),
            'last_inspected_at' => self::date($i->last_inspected_at),
            'next_inspection_due' => self::date($i->next_inspection_due),
            'condemned_at' => self::timestamp($i->condemned_at),
            'condemned_by' => $i->condemnedBy ? ['id' => $i->condemnedBy->id, 'name' => $i->condemnedBy->name] : null,
            'condemned_reason' => $i->condemned_reason,
            'disposed_at' => self::timestamp($i->disposed_at),
            'disposed_by' => $i->disposedBy ? ['id' => $i->disposedBy->id, 'name' => $i->disposedBy->name] : null,
            'disposal_method' => $i->disposal_method,
            'created_by' => $i->createdBy ? ['id' => $i->createdBy->id, 'name' => $i->createdBy->name] : null,
            'created_at' => self::timestamp($i->created_at),
            'active_allocation_state' => $activeAllocations->count() > 1 ? 'ambiguous' : ($activeAllocations->isEmpty() ? 'none' : 'recorded'),
            'active_allocation' => $activeAllocation ? [
                'id' => $activeAllocation->id,
                'user' => $activeAllocation->user ? ['id' => $activeAllocation->user->id, 'name' => $activeAllocation->user->name] : null,
                'allocated_at' => self::timestamp($activeAllocation->allocated_at),
                'fit_test_completed' => (bool) $activeAllocation->fit_test_completed,
                'fit_test_date' => self::date($activeAllocation->fit_test_date),
                'fit_test_result' => $activeAllocation->fit_test_result,
                'training_completed' => (bool) $activeAllocation->training_completed,
                'acknowledged' => (bool) $activeAllocation->acknowledged,
                'acknowledged_at' => self::timestamp($activeAllocation->acknowledged_at),
            ] : null,
            'allocations' => $i->allocations->map(fn (PpeAllocation $a) => [
                'id' => $a->id,
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                'allocated_at' => self::timestamp($a->allocated_at),
                'returned_at' => self::timestamp($a->returned_at),
                'acknowledged' => (bool) $a->acknowledged,
            ])->values()->all(),
            'inspections' => $i->inspections->map(fn (PpeInspection $ins) => [
                'id' => $ins->id,
                'result' => $ins->result,
                'condition_after' => $ins->condition_after,
                'findings' => $ins->findings,
                'action_taken' => $ins->action_taken,
                'inspected_at' => self::timestamp($ins->inspected_at),
                'inspector' => $ins->inspector ? ['id' => $ins->inspector->id, 'name' => $ins->inspector->name] : null,
                'next_inspection_due' => self::date($ins->next_inspection_due),
                'attachments' => $this->serializeAttachments($ins->attachments, "/health-safety/ppe/inspections/{$ins->id}/attachments", $evidenceProof),
            ])->values()->all(),
            'attachments' => $this->serializeAttachments($i->attachments, "/health-safety/ppe/inventory/{$i->id}/attachments", $evidenceProof),
            'history' => $this->itemHistory($i),
        ];
    }

    public function allocationDetailPayload(PpeAllocation $a, ?callable $evidenceProof = null): array
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
            'allocated_at' => self::timestamp($a->allocated_at),
            'returned_at' => self::timestamp($a->returned_at),
            'fit_test_completed' => (bool) $a->fit_test_completed,
            'fit_test_date' => self::date($a->fit_test_date),
            'fit_test_result' => $a->fit_test_result,
            'training_completed' => (bool) $a->training_completed,
            'training_date' => self::date($a->training_date),
            'acknowledged' => (bool) $a->acknowledged,
            'acknowledged_at' => self::timestamp($a->acknowledged_at),
            'acknowledged_by' => $a->acknowledgedBy ? ['id' => $a->acknowledgedBy->id, 'name' => $a->acknowledgedBy->name] : null,
            'notes' => $a->notes,
            'issued_by' => $a->createdBy ? ['id' => $a->createdBy->id, 'name' => $a->createdBy->name] : null,
            'attachments' => $this->serializeAttachments($a->attachments, "/health-safety/ppe/allocations/{$a->id}/attachments", $evidenceProof),
        ];
    }

    public function itemHistory(PpeInventory $i): array
    {
        $events = [];

        $events[] = ['type' => 'created', 'label' => 'Item added to register', 'at' => self::timestamp($i->created_at), 'actor' => $i->createdBy?->name];

        foreach ($i->allocations as $a) {
            $events[] = ['type' => 'allocated', 'label' => 'Allocated to '.($a->user->name ?? 'worker'), 'at' => self::timestamp($a->allocated_at), 'actor' => $a->createdBy?->name];
            if ($a->acknowledged_at) {
                $events[] = ['type' => 'acknowledged', 'label' => 'Allocation acknowledged', 'at' => self::timestamp($a->acknowledged_at), 'actor' => $a->acknowledgedBy?->name];
            }
            if ($a->returned_at) {
                $events[] = ['type' => 'returned', 'label' => 'Returned from '.($a->user->name ?? 'worker'), 'at' => self::timestamp($a->returned_at), 'actor' => null];
            }
        }

        foreach ($i->inspections as $ins) {
            $events[] = ['type' => 'inspected', 'label' => 'Inspection — '.str_replace('_', ' ', (string) $ins->result), 'at' => self::timestamp($ins->inspected_at), 'actor' => $ins->inspector?->name];
        }

        if ($i->condemned_at) {
            $events[] = ['type' => 'condemned', 'label' => 'Condemned'.($i->condemned_reason ? ' — '.$i->condemned_reason : ''), 'at' => self::timestamp($i->condemned_at), 'actor' => $i->condemnedBy?->name];
        }
        if ($i->disposed_at) {
            $events[] = ['type' => 'disposed', 'label' => 'Disposed'.($i->disposal_method ? ' — '.$i->disposal_method : ''), 'at' => self::timestamp($i->disposed_at), 'actor' => $i->disposedBy?->name];
        }

        usort($events, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return $events;
    }

    public function serializeAttachments($attachments, string $base, ?callable $evidenceProof = null): array
    {
        return $attachments->map(function ($attachment) use ($base, $evidenceProof) {
            // The canonical callback owns byte/provenance proof; public storage never creates a link.
            if ($evidenceProof) {
                $proof = $evidenceProof($attachment);
            } else {
                $safe = $attachment->disk === 'private' && is_string($attachment->path) && $attachment->path !== ''
                    && ! str_contains($attachment->path, '..') && ! str_starts_with($attachment->path, '/')
                    && ! str_contains($attachment->path, chr(92)) && ! str_contains($attachment->path, ':')
                    && ! preg_match('/[\x00-\x1f\x7f]/', $attachment->path);
                try {
                    $available = $safe && Storage::disk('private')->exists($attachment->path);
                } catch (\Throwable) {
                    $available = false;
                }
                $proof = ['attribution_state' => 'legacy_unknown', 'available' => $available];
            }
            $available = $proof['available'];
            if ($proof['attribution_state'] === 'repair_required') {
                return ['id' => (int) $attachment->id, 'attribution_state' => 'repair_required', 'available' => false,
                    'original_name' => null, 'url' => null, 'download_url' => null, 'mime' => null, 'kind' => null,
                    'can_relocate' => false, 'relocate_blocked_reason' => 'The retained source proof requires repair.',
                    'notes' => null, 'alt_text' => null, 'size' => null, 'is_image' => false, 'uploaded_by' => null, 'created_at' => null];
            }
            $href = $available ? "{$base}/{$attachment->id}/download" : null;
            return [
                'id' => (int) $attachment->id, 'original_name' => $attachment->original_name,
                'url' => $href, 'download_url' => $href, 'available' => $available, 'attribution_state' => $proof['attribution_state'],
                'can_relocate' => (bool) ($proof['can_relocate'] ?? false), 'relocate_blocked_reason' => $proof['relocate_blocked_reason'] ?? null,
                'mime' => $attachment->mime, 'kind' => $attachment->kind, 'notes' => $attachment->notes,
                'alt_text' => $attachment->alt_text, 'size' => $attachment->size,
                'is_image' => $attachment->isImage(),
                'uploaded_by' => $attachment->uploader ? ['id' => (int) $attachment->uploader->id, 'name' => $attachment->uploader->name] : null,
                'created_at' => self::timestamp($attachment->created_at),
            ];
        })->values()->all();
    }

    /** Current optimistic command identity; reference IDs do not grant mutation authority. */
    public function commandContext($root, $source, string $kind): array
    {
        return ['expected_version' => (int) ($root->source_revision ?? 0),
            'source_schema_version' => (int) ($root->source_schema_version ?? 0),
            'root_kind' => 'inventory', 'root_id' => (int) $root->id,
            'source_kind' => $kind, 'source_id' => (int) $source->id,
            'site_id' => (int) $root->site_id,
            'user_id' => $source instanceof PpeAllocation ? (int) $source->user_id : null];
    }
    public function currency(PpeInventory $item): array
    {
        $today = now('Pacific/Auckland')->toDateString();
        return [
            'expiry_state' => $item->expiry_date === null ? 'unknown' : (self::date($item->expiry_date) < $today ? 'expired' : 'in_date'),
            'inspection_state' => $item->next_inspection_due === null ? 'unknown' : (self::date($item->next_inspection_due) < $today ? 'overdue' : 'scheduled'),
            'inventory_complete' => null, 'fit_test_current' => null, 'training_current' => null,
            'fitness_for_work' => null, 'release_authorized' => null,
        ];
    }
}
