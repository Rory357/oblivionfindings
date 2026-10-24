<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\PpeAllocation;
use App\Models\PpeAllocationAttachment;
use App\Models\PpeAttachment;
use App\Models\PpeCommandReceipt;
use App\Models\PpeCommandIntentClaim;
use App\Models\PpeEvidenceCleanup;
use App\Models\PpeEvidenceRelocationCleanup;
use App\Models\PpeEvidenceStaging;
use App\Models\PpeInspection;
use App\Models\PpeInspectionAttachment;
use App\Models\PpeInventory;
use App\Models\PpeSourceVersion;
use App\Models\PpeType;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** PPE source facts only: no competency, clinical fitness or Workforce approval. */
final class PpeCommandService
{
    private const FIELDS = [
        'type' => ['id', 'name', 'category', 'description', 'hazards_addressed', 'standards_reference', 'inspection_frequency', 'typical_lifespan_months', 'is_active'],
        'inventory' => ['id', 'ppe_type_id', 'site_id', 'brand', 'model', 'serial_number', 'purchase_date', 'expiry_date', 'condition', 'quantity', 'location', 'status', 'last_inspected_at', 'next_inspection_due', 'condemned_at', 'condemned_by', 'condemned_reason', 'disposed_at', 'disposed_by', 'disposal_method', 'created_by', 'updated_by'],
        'allocation' => ['id', 'ppe_inventory_id', 'user_id', 'allocated_at', 'returned_at', 'fit_test_completed', 'fit_test_date', 'fit_test_result', 'training_completed', 'training_date', 'acknowledged', 'acknowledged_at', 'acknowledged_by', 'notes', 'created_by', 'updated_by'],
        'inspection' => ['id', 'ppe_inventory_id', 'inspected_by', 'inspected_at', 'result', 'condition_after', 'findings', 'action_taken', 'next_inspection_due', 'created_by', 'updated_by'],
    ];
    private const EVIDENCE_FIELDS = ['id', 'uploaded_by', 'disk', 'original_name', 'path', 'mime', 'size', 'kind', 'notes', 'alt_text', 'deleted_at'];

    public function __construct(private readonly PpeAccessService $access, private readonly PpeEvidenceStorage $storage) {}

    private function typeRules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'category' => ['required', 'in:'.PpeCommandValues::CATEGORIES],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'], 'hazards_addressed' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'standards_reference' => ['sometimes', 'nullable', 'string', 'max:255'], 'inspection_frequency' => ['sometimes', 'nullable', 'in:daily,weekly,monthly,quarterly,annually'],
            'typical_lifespan_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600']];
    }

    private function documentRules(): array
    {
        return ['documents' => ['sometimes', 'array', 'max:20'], 'documents.*' => ['array:file,kind,note'],
            'documents.*.file' => ['required', 'file', 'max:20480'], 'documents.*.kind' => ['sometimes', 'nullable', 'string', 'max:30'],
            'documents.*.note' => ['sometimes', 'nullable', 'string', 'max:1000']];
    }

    public function createType(User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, $this->typeRules(), true);
        return $this->create('type', $actor, $values, function ($current) use ($values) {
            return new PpeType([...$this->payload($values), 'is_active' => true]);
        });
    }

    public function updateType(PpeType|int $type, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, $this->typeRules());
        return $this->execute('type', $this->id($type), $actor, 'update_type', 'catalogue_manage', $values,
            fn ($root, $subject, $current, $reads, $sites, $payload) => $root->fill($payload));
    }

    public function setTypeActive(PpeType|int $type, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['active' => ['required', 'boolean']]);
        return $this->execute('type', $this->id($type), $actor, $values['active'] ? 'activate_type' : 'deactivate_type', 'catalogue_manage', $values,
            fn ($root, $subject, $current, $reads, $sites, $payload) => $root->forceFill(['is_active' => $payload['active']]));
    }

    private function inventoryValues(array $input, bool $create): array
    {
        $rules = ['brand' => ['sometimes', 'nullable', 'string', 'max:255'], 'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'], 'purchase_date' => ['sometimes', 'nullable', 'string'],
            'expiry_date' => ['sometimes', 'nullable', 'string'], 'condition' => ['sometimes', 'in:'.PpeCommandValues::CONDITIONS],
            'quantity' => ['sometimes', 'integer', 'min:'.($create ? '1' : '0'), 'max:2147483647'], 'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_inspection_due' => ['sometimes', 'nullable', 'string']];
        if ($create) {
            $rules += ['ppe_type_id' => ['required', 'integer', 'min:1'], 'site_id' => ['required', 'integer', 'min:1'], ...$this->documentRules()];
        } else {
            // Old edit forms carry these fields; unchanged identities are checked, never silently reassigned.
            $rules += ['ppe_type_id' => ['sometimes', 'integer', 'min:1'], 'site_id' => ['sometimes', 'integer', 'min:1'],
                'status' => ['sometimes', 'in:available,allocated,maintenance,condemned,disposed'], 'reason' => ['sometimes', 'string', 'max:2000']];
        }
        $values = PpeCommandValues::normalize($input, $rules, $create);
        foreach (['purchase_date', 'expiry_date', 'next_inspection_due'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = PpeCommandValues::date($values[$key], $key, $key === 'purchase_date');
            }
        }
        return $values;
    }

    public function createInventory(User $actor, array $input): array
    {
        $values = $this->inventoryValues($input, true);
        return $this->create('inventory', $actor, $values, function ($current, $reads, $sites) use ($values) {
            $this->access->assertSite($values['site_id'], $current, $reads, $sites);
            abort_unless($reads->query(Site::query())->whereKey($values['site_id'])->where('is_active', true)->exists(), 404);
            $type = $this->access->assertType($values['ppe_type_id'], $current, $reads, 'create');
            $this->currentVersion($type, $type);
            if (! $type->is_active) {
                PpeCommandValues::invalid('ppe_type_id', 'Choose an active PPE catalogue type for new stock.');
            }
            $payload = array_diff_key($this->payload($values), ['documents' => true]);
            $this->inventoryDates($payload);
            return new PpeInventory([...$payload, 'status' => ($payload['condition'] ?? 'good') === 'condemned' ? 'maintenance' : 'available', 'condition' => $payload['condition'] ?? 'good',
                'quantity' => $payload['quantity'] ?? 1, 'created_by' => $current->id, 'updated_by' => $current->id]);
        });
    }

    public function updateInventory(PpeInventory|int $inventory, User $actor, array $input): array
    {
        $values = $this->inventoryValues($input, false);
        return $this->execute('inventory', $this->id($inventory), $actor, 'update_inventory', 'update', $values,
            function ($root, $subject, $current, $reads, $sites, $payload) {
                foreach (['site_id', 'ppe_type_id'] as $identity) {
                    if (isset($payload[$identity]) && $payload[$identity] !== (int) $root->$identity) {
                        PpeCommandValues::invalid($identity, 'PPE source ownership cannot be reassigned by an inventory edit.');
                    }
                }
                $status = $payload['status'] ?? $root->status;
                if ($status !== $root->status) {
                    if ($root->status === 'maintenance' && $status === 'available') {
                        $this->requireConstraint($root, 'release');
                    } elseif (! (in_array($root->status, ['available', 'allocated'], true) && $status === 'maintenance')) {
                        PpeCommandValues::invalid('status', 'Use the actual allocation, condemnation or disposal action; terminal stock cannot be released.');
                    }
                    if (trim($payload['reason'] ?? '') === '') {
                        PpeCommandValues::invalid('reason', 'Record why this stock is placed on hold or released.');
                    }
                }
                if (($payload['condition'] ?? $root->condition) === 'condemned' && $root->condition !== 'condemned') {
                    PpeCommandValues::invalid('condition', 'Use the reasoned condemnation or failed inspection action.');
                }
                if (in_array($root->status, ['condemned', 'disposed'], true) && isset($payload['condition']) && $payload['condition'] !== $root->condition) {
                    PpeCommandValues::invalid('condition', 'Terminal stock cannot be relabelled serviceable.');
                }
                $this->inventoryDates([...$this->snapshot($root), ...$payload]);
                return $root->fill(array_diff_key($payload, ['reason' => true]));
            });
    }

    public function allocate(PpeInventory|int $inventory, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['user_id' => ['required', 'integer', 'min:1'],
            'fit_test_completed' => ['sometimes', 'boolean'], 'fit_test_date' => ['sometimes', 'nullable', 'string'],
            'fit_test_result' => ['sometimes', 'nullable', 'string', 'max:255'], 'training_completed' => ['sometimes', 'boolean'],
            'training_date' => ['sometimes', 'nullable', 'string'], 'acknowledged' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        foreach (['fit_test_date', 'training_date'] as $date) {
            if (array_key_exists($date, $values)) {
                $values[$date] = PpeCommandValues::date($values[$date], $date, true);
            }
        }
        foreach (['fit_test', 'training'] as $fact) {
            if (($values[$fact.'_completed'] ?? false) && empty($values[$fact.'_date'])) {
                PpeCommandValues::invalid($fact.'_date', 'Record the actual date of this completed fact.');
            }
        }
        return $this->execute('inventory', $this->id($inventory), $actor, 'allocate', 'allocate', $values,
            function ($root, $subject, $current, $reads, $sites, $payload) {
                $this->requireConstraint($root, 'allocate');
                $this->access->currentStaff($payload['user_id'], (int) $root->site_id, $current, $reads, $sites);
                $type = $this->access->assertType((int) $root->ppe_type_id, $current, $reads, 'allocate');
                $this->currentVersion($type, $type);
                if (! $type->is_active) {
                    PpeCommandValues::invalid('ppe_type_id', 'Retired catalogue stock cannot be newly issued.');
                }
                $root->status = 'allocated';
                $ack = $payload['acknowledged'] ?? false;
                return new PpeAllocation([...$payload, 'ppe_inventory_id' => $root->id, 'allocated_at' => now()->utc(),
                    'acknowledged' => $ack, 'acknowledged_at' => $ack ? now()->utc() : null, 'acknowledged_by' => $ack ? $current->id : null,
                    'created_by' => $current->id, 'updated_by' => $current->id]);
            });
    }

    public function acknowledge(PpeAllocation|int $allocation, User $actor, array $input, bool $own = false): array
    {
        $values = PpeCommandValues::normalize($input, []);
        return $this->execute('allocation', $this->id($allocation), $actor, $own ? 'acknowledge_own' : 'acknowledge',
            $own ? 'acknowledge_own' : 'acknowledge', $values, function ($root, $subject, $current) {
                if ($subject->returned_at) {
                    PpeCommandValues::invalid('allocation', 'Returned PPE cannot be acknowledged as a current issue.');
                }
                if (! $subject->acknowledged) {
                    $subject->forceFill(['acknowledged' => true, 'acknowledged_at' => now()->utc(), 'acknowledged_by' => $current->id]);
                }
                return $subject;
            });
    }

    public function returnAllocation(PpeAllocation|int $allocation, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'condition' => ['sometimes', 'nullable', 'in:'.PpeCommandValues::CONDITIONS]]);
        return $this->execute('allocation', $this->id($allocation), $actor, 'return', 'return', $values,
            function ($root, $subject, $current, $reads, $sites, $payload) {
                if ($subject->returned_at) {
                    PpeCommandValues::invalid('allocation', 'This issue was already returned. Review its retained receipt.');
                }
                $subject->returned_at = now()->utc();
                if (isset($payload['notes'])) {
                    $subject->notes = $payload['notes'];
                }
                if (! in_array($root->status, ['maintenance', 'condemned', 'disposed'], true)) {
                    if (($payload['condition'] ?? null) === 'condemned') {
                        $this->condemnFacts($root, $current, 'Condemned on return: '.($payload['notes'] ?? 'failed return check'));
                    } else {
                        if (isset($payload['condition'])) {
                            $root->condition = $payload['condition'];
                        }
                        $others = $root->allocations()->whereNull('returned_at')->whereKeyNot($subject->id)->lockForUpdate()->exists();
                        $root->status = $root->condition === 'condemned' ? 'maintenance' : ($others ? 'allocated' : 'available');
                    }
                }
                return $subject;
            });
    }

    public function inspect(PpeInventory|int $inventory, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['result' => ['required', 'in:pass,fail,needs_repair,condemned'],
            'condition_after' => ['sometimes', 'nullable', 'in:'.PpeCommandValues::CONDITIONS], 'findings' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'action_taken' => ['sometimes', 'nullable', 'string', 'max:2000'], 'next_inspection_due' => ['sometimes', 'nullable', 'string'], ...$this->documentRules()]);
        if (array_key_exists('next_inspection_due', $values)) {
            $values['next_inspection_due'] = PpeCommandValues::date($values['next_inspection_due'], 'next_inspection_due');
        }
        if ($values['result'] === 'pass' && ($values['condition_after'] ?? null) === 'condemned') {
            PpeCommandValues::invalid('condition_after', 'A condemned item cannot be recorded as passing.');
        }
        return $this->execute('inventory', $this->id($inventory), $actor, 'inspect', 'inspect', $values,
            function ($root, $subject, $current, $reads, $sites, $payload) {
                $this->requireConstraint($root, 'inspect');
                $root->last_inspected_at = now(config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC')->toDateString();
                if (array_key_exists('next_inspection_due', $payload)) {
                    $root->next_inspection_due = $payload['next_inspection_due'];
                }
                if (isset($payload['condition_after'])) {
                    $root->condition = $payload['condition_after'];
                }
                if ($payload['result'] === 'condemned' || ($payload['condition_after'] ?? null) === 'condemned') {
                    $this->condemnFacts($root, $current, $payload['findings'] ?? 'Condemned at inspection.');
                } elseif (in_array($payload['result'], ['fail', 'needs_repair'], true)) {
                    $root->status = 'maintenance';
                }
                return new PpeInspection([...array_diff_key($payload, ['documents' => true]), 'ppe_inventory_id' => $root->id,
                    'inspected_by' => $current->id, 'inspected_at' => now()->utc(), 'created_by' => $current->id, 'updated_by' => $current->id]);
            });
    }

    public function condemn(PpeInventory|int $inventory, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['reason' => ['required', 'string', 'max:2000']]);
        return $this->execute('inventory', $this->id($inventory), $actor, 'condemn', 'condemn', $values,
            function ($root, $subject, $current, $reads, $sites, $payload) {
                $this->requireConstraint($root, 'condemn');
                $this->condemnFacts($root, $current, $payload['reason']);
                return $root;
            });
    }

    public function dispose(PpeInventory|int $inventory, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['disposal_method' => ['required', 'string', 'max:255'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        return $this->execute('inventory', $this->id($inventory), $actor, 'dispose', 'dispose', $values,
            function ($root, $subject, $current, $reads, $sites, $payload) {
                $this->requireConstraint($root, 'dispose');
                // Disposal notes remain in immutable intent; the earlier condemnation reason is preserved.
                $root->forceFill(['status' => 'disposed', 'disposed_at' => now()->utc(), 'disposed_by' => $current->id,
                    'disposal_method' => $payload['disposal_method']]);
                return $root;
            });
    }

    private function condemnFacts(PpeInventory $root, User $actor, string $reason): void
    {
        $root->forceFill(['status' => 'condemned', 'condition' => 'condemned', 'condemned_at' => now()->utc(),
            'condemned_by' => $actor->id, 'condemned_reason' => $reason]);
    }

    private function inventoryDates(array $facts): void
    {
        if (isset($facts['purchase_date'], $facts['expiry_date']) && $facts['expiry_date'] < $facts['purchase_date']) {
            PpeCommandValues::invalid('expiry_date', 'Expiry cannot precede purchase.');
        }
    }

    public function uploadAttachment(string $parentType, int $parentId, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['file' => ['required', 'file', 'max:20480'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:30'], 'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255']]);
        return $this->execute($parentType, $parentId, $actor, 'upload_attachment', 'evidence', $values,
            function ($root, $subject, $current, $reads, $sites, $payload, &$staged) use ($parentType) {
                $stored = $this->stageReserved($payload['file'], $staged);
                $class = $this->attachmentClass($parentType);
                return new $class([...array_diff_key($stored, ['digest' => true]),
                    $this->parentForeignKey($parentType) => $subject->id, 'uploaded_by' => $current->id,
                    ...array_diff_key($payload, ['file' => true])]);
            });
    }

    public function removeAttachment(string $parentType, int $parentId, int $attachmentId, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['reason' => ['required', 'string', 'max:2000']]);
        if (trim($values['reason']) === '') { PpeCommandValues::invalid('reason', 'Record why this evidence is removed.'); }
        return $this->execute($parentType, $parentId, $actor, 'remove_attachment', 'evidence', $values,
            function ($root, $subject, $current, $reads, $sites) use ($parentType, $attachmentId) {
                $class = $this->attachmentClass($parentType);
                $attachment = $class::withTrashed()->whereKey($attachmentId)->where($this->parentForeignKey($parentType), $subject->id)->lockForUpdate()->firstOrFail();
                $this->access->assertAttachment($attachment, $subject, $current, $reads, $sites, 'evidence');
                $this->currentVersion($root, $attachment);
                $attachment->deleted_at = now()->utc();
                return $attachment;
            }, $attachmentId);
    }

    private function legacyOrigin(Model $root, Model $attachment): array
    {
        $this->assertAttachmentRoot($root, $attachment);
        if ($this->currentVersion($root, $attachment) !== null || $attachment->source_schema_version !== 0
            || $attachment->source_revision !== 0 || ! in_array($attachment->disk, [null, 'public'], true) || $attachment->trashed()) {
            PpeCommandValues::invalid('attachment', 'Only a genuinely unproven retained public attachment can be transferred.');
        }
        $parentType = str_replace('_attachment', '', $this->kind($attachment));
        $bytes = $this->storage->legacyPublicBytes($parentType, $attachment->path);
        if ($bytes === null) { PpeCommandValues::invalid('attachment', 'The retained public PPE bytes are unavailable; no private transfer was confirmed.'); }
        if ($attachment->size !== null && (int) $attachment->size !== $bytes['size']) {
            PpeCommandValues::invalid('attachment', 'Observed public bytes disagree with the retained size; review source integrity before transfer.');
        }
        if ($this->storage->publicPathReferenced($attachment->path, $attachment->getTable(), (int) $attachment->id)) {
            PpeCommandValues::invalid('attachment', 'The public path is referenced by another retained source; a one-file transfer cannot resolve ambiguous ownership.');
        }
        return ['attachment_type' => $this->kind($attachment), 'attachment_id' => (int) $attachment->id,
            'parent_type' => $parentType, 'parent_id' => (int) $attachment->{$this->parentForeignKey($parentType)},
            'disk' => 'public', 'path' => $attachment->path, 'original_metadata' => $this->snapshot($attachment),
            'original_created_at' => $attachment->getRawOriginal('created_at'), 'observed_bytes' => $bytes,
            'original_attribution_state' => 'legacy_unknown'];
    }

    private function assertAttachmentRoot(Model $root, Model $attachment): void
    {
        if (! $root instanceof PpeInventory) { throw new LogicException('PPE evidence requires its exact inventory root.'); }
        $type = str_replace('_attachment', '', $this->kind($attachment));
        $parentId = (int) $attachment->{$this->parentForeignKey($type)};
        $matches = $type === 'inventory' ? $parentId === (int) $root->id
            : ($type === 'allocation' ? PpeAllocation::query() : PpeInspection::query())
                ->whereKey($parentId)->where('ppe_inventory_id', $root->id)->lock('for share nowait')->exists();
        if (! $matches) { throw new LogicException('PPE evidence belongs to another canonical parent.'); }
    }
    /** No grant: admitted module evidence only; caller intersects current management authority. */
    public function relocationConstraint(Model $root, Model $attachment): array
    {
        try {
            $this->currentVersion($root, $root);
            $this->legacyOrigin($root, $attachment);
            return ['allowed' => true, 'blocked_reason' => null];
        } catch (\Illuminate\Validation\ValidationException $error) {
            return ['allowed' => false, 'blocked_reason' => $error->errors()['attachment'][0] ?? 'Legacy transfer is unavailable.'];
        } catch (LogicException) {
            return ['allowed' => false, 'blocked_reason' => 'Current PPE source or byte proof requires repair before legacy transfer.'];
        }
    }

    public function relocateLegacyAttachment(string $parentType, int $parentId, int $attachmentId, User $actor, array $input): array
    {
        $values = PpeCommandValues::normalize($input, ['reason' => ['required', 'string', 'max:2000']]);
        if (trim($values['reason']) === '') { PpeCommandValues::invalid('reason', 'Record why the retained public evidence is transferred.'); }
        if (DB::transactionLevel() !== 0) { throw new LogicException('PPE legacy transfer requires an outermost transaction before reservation.'); }
        $prepared = $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($parentType, $parentId, $attachmentId, $values) {
            $parent = match ($parentType) {
                'inventory' => $this->access->assertInventory($parentId, $current, $reads, $sites, 'evidence'),
                'allocation' => $this->access->assertAllocation($parentId, $current, $reads, $sites, 'evidence'),
                'inspection' => $this->access->assertInspection($parentId, $current, $reads, $sites, 'evidence'),
                default => throw new LogicException('Unknown PPE relocation parent.'),
            };
            $root = $parent instanceof PpeInventory ? $parent : $this->source('inventory', (int) $parent->ppe_inventory_id);
            $this->currentVersion($root, $root); if ($parent !== $root) { $this->currentVersion($root, $parent); }
            // Identity discovery does not lock an absent receipt range. The unique claim arbitrates new attempts.
            $receiptId = PpeCommandReceipt::where('idempotency_key', $values['idempotency_key'])->value('id');
            $receipt = $receiptId === null ? null : $reads->query(PpeCommandReceipt::query())->whereKey($receiptId)->first();
            if ($receipt) {
                if ($receipt->user_id !== (int) $current->id || $receipt->command !== 'relocate_attachment') {
                    PpeCommandValues::invalid('idempotency_key', 'This key belongs to another PPE command intent.');
                }
                $result = $this->validatedReceipt($receipt, $root, $current, $reads);
                $version = $reads->query(PpeSourceVersion::query())->whereKey($receipt->source_version_id)->firstOrFail();
                $origin = $version->content['facts']['legacy_origin'] ?? null;
                $payload = ['reason' => $values['reason'], '_legacy_origin' => $origin];
                $fingerprint = PpeCommandValues::digest([$parentType, $parentId, $attachmentId, $current->id, 'relocate_attachment', $values['expected_version'], $payload]);
                $this->assertRetainedAttemptIntent($values['idempotency_key'], $current, 'relocate_attachment', $fingerprint);
                $this->assertReceiptIntent($receipt, $current, 'relocate_attachment', $fingerprint);
                if ($receipt->source_type !== $parentType.'_attachment' || $receipt->source_id !== $attachmentId
                    || ! is_array($origin) || ($origin['parent_id'] ?? null) !== $parentId || ($origin['parent_type'] ?? null) !== $parentType) {
                    PpeCommandValues::invalid('idempotency_key', 'The retained transfer belongs to another canonical source.');
                }
                $cleanup = $reads->query(PpeEvidenceRelocationCleanup::query())->where('receipt_id', $receipt->id)->first();
                if (! $cleanup) { throw new LogicException('The accepted PPE transfer cleanup evidence is missing.'); }
                $this->originCleanupProjection($cleanup, $current, $reads, $sites);
                DB::afterCommit(fn () => $this->safeOriginPurge($cleanup->id));
                return ['result' => $result, 'origin' => null, 'ids' => []];
            }
            if ((int) $root->source_revision !== $values['expected_version']) { PpeCommandValues::invalid('expected_version', 'This PPE record changed. Review current work before relocating evidence.'); }
            $class = $this->attachmentClass($parentType);
            $attachment = $this->access->assertAttachment($reads->query($class::query())->whereKey($attachmentId)->where($this->parentForeignKey($parentType), $parentId)->firstOrFail(), $parent, $current, $reads, $sites, 'evidence');
            $origin = $this->legacyOrigin($root, $attachment);
            $payload = ['reason' => $values['reason'], '_legacy_origin' => $origin];
            $fingerprint = PpeCommandValues::digest([$parentType, $parentId, $attachmentId, $current->id, 'relocate_attachment', $values['expected_version'], $payload]);
            $claim = $this->claimIntent($current, 'relocate_attachment', $values['idempotency_key'], $fingerprint, 'inventory', (int) $root->id,
                (int) $root->site_id, $parentType, $parentId, $attachmentId, $values['expected_version']);
            $this->assertRetainedAttemptIntent($values['idempotency_key'], $current, 'relocate_attachment', $fingerprint);
            $folder = match ($parentType) { 'inventory' => 'ppe_attachments', 'allocation' => 'ppe_allocation_attachments', 'inspection' => 'ppe_inspection_attachments' };
            $attempt = new PpeEvidenceStaging(['intent_claim_id' => $claim->id, 'user_id' => $current->id, 'root_type' => 'inventory', 'root_id' => $root->id,
                'source_type' => $parentType, 'source_id' => $parentId, 'site_id' => $root->site_id, 'command' => 'relocate_attachment',
                'idempotency_key' => $values['idempotency_key'], 'expected_version' => $values['expected_version'], 'fingerprint' => $fingerprint,
                'intent' => $payload, 'legacy_origin' => $origin, 'disk' => 'private', 'path' => $folder.'/'.strtolower((string) \Illuminate\Support\Str::uuid()).'.bin',
                'file_digest' => $origin['observed_bytes']['digest'], 'file_size' => $origin['observed_bytes']['size'], 'state' => 'staged']);
            $attempt->reservation_digest = PpeCommandValues::digest($attempt->identity()); $this->persist($attempt);
            return ['result' => null, 'origin' => $origin, 'ids' => [(int) $attempt->id]];
        });
        if (DB::transactionLevel() !== 0) { throw new LogicException('PPE transfer reservation did not physically commit.'); }
        if ($prepared['result'] !== null) { return $prepared['result']; }
        $values['_legacy_origin'] = $prepared['origin'];
        return $this->execute($parentType, $parentId, $actor, 'relocate_attachment', 'evidence', $values,
            function ($root, $parent, $current, $reads, $sites, $payload, &$staged) use ($parentType, $attachmentId) {
                $class = $this->attachmentClass($parentType);
                $attachment = $class::whereKey($attachmentId)->where($this->parentForeignKey($parentType), $parent->id)->lockForUpdate()->firstOrFail();
                $this->access->assertAttachment($attachment, $parent, $current, $reads, $sites, 'evidence');
                if (PpeCommandValues::digest($this->legacyOrigin($root, $attachment)) !== PpeCommandValues::digest($payload['_legacy_origin'])) {
                    PpeCommandValues::invalid('attachment', 'The legacy metadata or observed bytes changed after review; keep the draft and review again.');
                }
                $attempt = PpeEvidenceStaging::whereKey($staged[0])->lockForUpdate()->firstOrFail(); $this->assertReservation($attempt);
                $stored = $this->storage->stageLegacyReserved($attempt);
                // Recheck the public source after reading it, before accepting any private reference.
                if (PpeCommandValues::digest($this->legacyOrigin($root, $attachment)) !== PpeCommandValues::digest($payload['_legacy_origin'])) {
                    PpeCommandValues::invalid('attachment', 'Legacy bytes changed during transfer; no private acceptance was committed.');
                }
                return $attachment->forceFill($stored);
            }, $attachmentId, $prepared['ids']);
    }
    /** Admitted source only; this helper does not confer authority. */
    public function constraints(PpeInventory $item, int $activeAllocationCount, ?PpeType $freshType = null): array
    {
        if (DB::transactionLevel() < 1) { throw new LogicException('PPE constraints require active current source authority.'); }
        $freshType ??= PpeType::whereKey($item->ppe_type_id)->lock('for share nowait')->first();
        $typeActive = $freshType && (int) $freshType->id === (int) $item->ppe_type_id && $freshType->is_active && $this->sourceState($freshType) !== 'repair_required';
        $terminal = in_array($item->status, ['condemned', 'disposed'], true);
        $expired = $item->expiry_date && $item->expiry_date->toDateString() < now(config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC')->toDateString();
        $definitions = [
            'hold' => [in_array($item->status, ['available', 'allocated'], true), 'Only live stock can be placed on a new maintenance hold.'],
            'allocate' => [$item->status === 'available' && $activeAllocationCount === 0 && $item->quantity > 0 && ! $expired && $item->condition !== 'condemned' && $typeActive, 'Only available, in-date stock without an active issue can be allocated.'],
            'condemn' => [! $terminal && $activeAllocationCount === 0, 'Return every active issue before manual condemnation.'],
            'dispose' => [$item->status === 'condemned' && $activeAllocationCount === 0, 'Disposal requires condemned stock and no active issue.'],
            'inspect' => [! $terminal, 'Condemned or disposed stock cannot be recorded as inspected for service.'],
            'release' => [$item->status === 'maintenance' && $activeAllocationCount === 0 && ! $expired && $item->condition !== 'condemned' && $this->passedAfterHold($item), 'Release requires a confirmed passing inspection after the hold, no active issue and a reason.'],
        ];
        return array_map(fn ($definition) => ['allowed' => $definition[0], 'blocked_reason' => $definition[0] ? null : $definition[1]], $definitions);
    }

    private function requireConstraint(PpeInventory $item, string $command): void
    {
        $count = $item->allocations()->whereNull('returned_at')->lockForUpdate()->count();
        $constraint = $this->constraints($item, $count)[$command];
        if (! $constraint['allowed']) {
            PpeCommandValues::invalid('status', $constraint['blocked_reason']);
        }
    }

    private function passedAfterHold(PpeInventory $item): bool
    {
        if ($item->status !== 'maintenance') {
            return false;
        }
        $versions = PpeSourceVersion::where('root_type', 'inventory')->where('root_id', $item->id)
            ->whereIn('command', ['inspect', 'update_inventory'])->orderByDesc('aggregate_revision')->lock('for share nowait')->get();
        $pass = null;
        foreach ($versions as $version) {
            $this->validateVersion($version);
            $before = $version->content['root_before'] ?? null;
            $after = $version->content['root_after'] ?? null;
            if (! is_array($after)) {
                return false;
            }
            $inspection = $version->content['source_after'] ?? [];
            $holding = ($after['status'] ?? null) === 'maintenance' && (($before['status'] ?? null) !== 'maintenance'
                || ($version->command === 'inspect' && in_array($inspection['result'] ?? null, ['fail', 'needs_repair'], true)));
            if ($holding) {
                return $pass !== null && $pass > $version->aggregate_revision;
            }
            if ($version->command === 'inspect' && ($inspection['result'] ?? null) === 'pass'
                && ($before['status'] ?? null) === 'maintenance' && ($after['status'] ?? null) === 'maintenance') {
                $pass ??= $version->aggregate_revision;
            }
        }
        // A new governed pass with a retained maintenance before-state is sufficient for a legacy hold.
        return $pass !== null;
    }

    private function create(string $rootType, User $actor, array $values, callable $factory): array
    {
        $this->assertFileTransaction($values);
        $staged = $this->reserveEvidence($rootType, null, $actor, 'create_'.$rootType, $rootType === 'type' ? 'catalogue_manage' : 'create', $values, $factory);
        try {
            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($rootType, $values, $factory, &$staged) {
                $operation = $rootType === 'type' ? 'catalogue_manage' : 'create';
                $this->access->assertOperation($current, $operation);
                $payload = $this->payload($values);
                $fingerprint = PpeCommandValues::digest([$rootType, null, $current->id, 'create_'.$rootType, null, $this->intent($payload)]);
                $candidateRoot = $factory($current, $reads, $sites);
                $this->claimIntent($current, 'create_'.$rootType, $values['idempotency_key'], $fingerprint, $rootType, null,
                    $candidateRoot instanceof PpeInventory ? (int) $candidateRoot->site_id : null, $rootType, null, null, null);
                $this->assertRetainedAttemptIntent($values['idempotency_key'], $current, 'create_'.$rootType, $fingerprint);
                $receipt = PpeCommandReceipt::where('idempotency_key', $values['idempotency_key'])->lockForUpdate()->first();
                if ($receipt) {
                    $this->assertReceiptIntent($receipt, $current, 'create_'.$rootType, $fingerprint);
                    $rootQuery = $this->root($receipt->root_type, $receipt->root_id);
                    if ($receipt->root_type === 'inventory') { $rootQuery->whereIn('site_id', $sites); }
                    $root = $rootQuery->lockForUpdate()->firstOrFail();
                    $this->authorize($rootType, $root, $current, $reads, $sites, $operation);
                    // The original new Site/type eligibility remains current on capture replay.
                    $factory($current, $reads, $sites);
                    $this->currentVersion($root, $root);
                    $result = $this->validatedReceipt($receipt, $root, $current, $reads);
                    if ($staged !== []) { DB::afterCommit(fn () => $this->rejectStaging($current, $staged)); }
                    return $result;
                }
                $root = $candidateRoot;

                $root->forceFill(['source_schema_version' => 1, 'source_revision' => 1]);
                $this->persist($root);
                $documents = $this->documents($root, $rootType, $payload['documents'] ?? [], $current, $staged);
                $version = $this->version($root, $root, $current, 'create_'.$rootType, $values['idempotency_key'], null, null,
                    ['input' => $this->intent($payload), 'documents' => $documents]);
                $result = $this->accept($root, $root, $current, 'create_'.$rootType, $values['idempotency_key'], $fingerprint, null, $version, $reads);
                $this->bindStaging($staged, $root, $current, $result, $fingerprint, $reads);
                return $result;
            });
        } catch (Throwable $failure) {
            $this->rejectStaging($actor, $staged);
            $this->translateDuplicate($failure);
            throw $failure;
        }
    }

    private function execute(string $type, int $id, User $actor, string $command, string $operation, array $values,
        callable $write, ?int $attachmentId = null, ?array $preparedStages = null): array
    {
        $this->assertFileTransaction($values);
        $staged = $preparedStages ?? $this->reserveEvidence($type, $id, $actor, $command, $operation, $values);
        try {
            return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($type, $id, $command, $operation, $values, $write, $attachmentId, &$staged) {
                $this->access->assertOperation($current, $operation);
                $rootType = $type === 'type' ? 'type' : 'inventory';
                $rootId = $id;
                if (! in_array($type, ['type', 'inventory'], true)) {
                    $class = match ($type) { 'allocation' => PpeAllocation::class, 'inspection' => PpeInspection::class,
                        default => throw new LogicException('Unknown PPE parent.') };
                    // Identity-only discovery; do not acquire a shared parent lock before the ordered exclusive lock.
                    $discovery = $class::whereKey($id)->select(['id', 'ppe_inventory_id']);
                    if ($operation === 'acknowledge_own') { $discovery->where('user_id', $current->id); }
                    $rootId = (int) $discovery->firstOrFail()->ppe_inventory_id;
                }
                $rootQuery = $this->root($rootType, $rootId);
                if ($rootType === 'inventory') { $rootQuery->whereIn('site_id', $sites); }
                $root = $rootQuery->lockForUpdate()->firstOrFail();
                if ($rootType === $type) {
                    $subject = $root;
                } else {
                    $child = $class::whereKey($id)->where('ppe_inventory_id', $rootId);
                    if ($operation === 'acknowledge_own') { $child->where('user_id', $current->id); }
                    $subject = $child->lockForUpdate()->firstOrFail();
                }
                $this->authorize($type, $subject, $current, $reads, $sites, $operation);
                if ($rootType === 'inventory' && $type !== 'inventory' && (int) $subject->ppe_inventory_id !== $rootId) {
                    abort(404);
                }
                $payload = $this->payload($values);
                $fingerprint = PpeCommandValues::digest([$type, $id, $attachmentId, $current->id, $command,
                    $values['expected_version'], $this->intent($payload)]);
                $this->currentVersion($root, $root);
                if ($subject !== $root) { $this->currentVersion($root, $subject); }
                $this->claimIntent($current, $command, $values['idempotency_key'], $fingerprint, $rootType, (int) $root->id,
                    $root instanceof PpeInventory ? (int) $root->site_id : null, $type, $id, $attachmentId, $values['expected_version']);
                $this->assertRetainedAttemptIntent($values['idempotency_key'], $current, $command, $fingerprint);
                $receipt = PpeCommandReceipt::where('idempotency_key', $values['idempotency_key'])->lockForUpdate()->first();
                if ($receipt) {
                    $this->assertReceiptIntent($receipt, $current, $command, $fingerprint);
                    if ($attachmentId !== null && $command === 'remove_attachment') {
                        $this->assertRemovedSource($receipt, $subject, $attachmentId);
                    }
                    $this->currentVersion($root, $root);
                    if ($subject !== $root) { $this->currentVersion($root, $subject); }
                    if ($command === 'allocate') {
                        $this->access->currentStaff((int) $payload['user_id'], (int) $root->site_id, $current, $reads, $sites);
                    }
                    $result = $this->validatedReceipt($receipt, $root, $current, $reads);
                    $cleanup = PpeEvidenceCleanup::where('receipt_id', $receipt->id)->first();
                    if ($cleanup) {
                        $this->schedulePurge($cleanup->id);
                    }
                    if ($staged !== []) {
                        DB::afterCommit(fn () => $this->rejectStaging($current, $staged));
                    }
                    return $result;
                }
                $this->currentVersion($root, $root);
                if ($subject !== $root) {
                    $this->currentVersion($root, $subject);
                }
                if ((int) $root->source_revision !== $values['expected_version']) {
                    PpeCommandValues::invalid('expected_version', 'This PPE record changed. Review current work before renewing the command.');
                }
                $beforeRoot = $this->snapshot($root);

                $beforeSubject = $this->snapshot($subject);
                $saved = $write($root, $subject, $current, $reads, $sites, $payload, $staged);
                $sourceBefore = $saved === $subject ? $beforeSubject : ($saved->exists ? $this->snapshotOriginal($saved) : null);
                $changed = ! $saved->exists || $this->snapshot($root) !== $beforeRoot || $this->snapshot($saved) !== $sourceBefore
                    || ! empty($payload['documents']);
                $version = null;
                if ($changed) {
                    if ((int) $root->source_revision >= 4294967295) { PpeCommandValues::invalid('expected_version', 'The PPE revision cannot be advanced safely.'); }
                    $revision = (int) $root->source_revision + 1;
                    $root->forceFill(['source_schema_version' => 1, 'source_revision' => $revision]);
                    if ($root instanceof PpeInventory) {
                        $root->updated_by = $current->id;
                    }
                    $saved->forceFill(['source_schema_version' => 1, 'source_revision' => $revision]);
                    if ($saved instanceof PpeAllocation || $saved instanceof PpeInspection) {
                        $saved->updated_by = $current->id;
                    }
                    $this->persist($root);
                    if ($saved !== $root) {
                        $this->persist($saved);
                    }
                    $documents = $this->documents($saved, $this->kind($saved), $payload['documents'] ?? [], $current, $staged);
                    $facts = ['input' => $this->intent($payload), 'documents' => $documents];
                    if ($command === 'relocate_attachment') { $facts['legacy_origin'] = $payload['_legacy_origin']; }
                    if (str_ends_with($this->kind($saved), '_attachment')) {
                        $facts['file_evidence'] = $saved->disk === 'private' ? $this->storage->bytes($saved->path) : null;
                    }
                    $version = $this->version($root, $saved, $current, $command, $values['idempotency_key'], $beforeRoot, $sourceBefore, $facts);
                }
                $result = $this->accept($root, $saved, $current, $command, $values['idempotency_key'], $fingerprint,
                    $values['expected_version'], $version, $reads);
                $this->bindStaging($staged, $root, $current, $result, $fingerprint, $reads);
                if ($command === 'relocate_attachment') {
                    $receipt = PpeCommandReceipt::where('idempotency_key', $values['idempotency_key'])->sole();
                    $origin = $payload['_legacy_origin'];
                    $cleanup = new PpeEvidenceRelocationCleanup(['purpose' => 'legacy_public_origin', 'receipt_id' => $receipt->id,
                        'user_id' => $current->id, 'root_id' => $root->id, 'site_id' => $root->site_id, 'parent_type' => $type,
                        'parent_id' => $id, 'attachment_type' => $this->kind($saved), 'attachment_id' => $saved->id,
                        'origin_disk' => 'public', 'origin_path' => $origin['path'], 'file_digest' => $origin['observed_bytes']['digest'],
                        'file_size' => $origin['observed_bytes']['size'], 'destination_path' => $saved->path, 'state' => 'pending']);
                    $this->persist($cleanup); DB::afterCommit(fn () => $this->safeOriginPurge($cleanup->id));
                }
                if ($command === 'remove_attachment') {
                    $receipt = PpeCommandReceipt::where('idempotency_key', $values['idempotency_key'])->sole();
                    $bytes = $version?->content['facts']['file_evidence'] ?? null;
                    $cleanup = new PpeEvidenceCleanup(['receipt_id' => $receipt->id, 'parent_type' => $type, 'parent_id' => $id,
                        'attachment_type' => $this->kind($saved), 'attachment_id' => $saved->id, 'user_id' => $current->id,
                        'disk' => $saved->disk ?: 'private', 'path' => $saved->path, 'file_digest' => $bytes['digest'] ?? null,
                        'file_size' => $bytes['size'] ?? null, 'state' => $saved->disk === 'private' ? 'pending' : 'unknown']);
                    $this->persist($cleanup);
                    $this->schedulePurge($cleanup->id);
                }
                return $result;
            });
        } catch (Throwable $failure) {
            $this->rejectStaging($actor, $staged);
            $this->translateDuplicate($failure);
            throw $failure;
        }
    }

    private function documents(Model $source, string $type, array $documents, User $actor, array &$staged): array
    {
        $facts = [];
        foreach ($documents as $document) {
            $stored = $this->stageReserved($document['file'], $staged);
            $class = $this->attachmentClass($type);
            $attachment = new $class([...array_diff_key($stored, ['digest' => true]), $this->parentForeignKey($type) => $source->id,
                'uploaded_by' => $actor->id, 'kind' => $document['kind'] ?? ($type === 'inspection' ? 'inspection_report' : 'certificate'),
                'notes' => $document['note'] ?? null]);
            $attachment->forceFill(['source_schema_version' => 1, 'source_revision' => $source->source_revision]);
            $this->persist($attachment);
            $facts[] = ['source_type' => $this->kind($attachment), 'source_id' => (int) $attachment->id,
                'after' => $this->snapshot($attachment), 'file_evidence' => ['digest' => $stored['digest'], 'size' => $stored['size']]];
        }
        return $facts;
    }

    /** Physically committed reservation, on the same connection, before any byte write. */
    private function reserveEvidence(string $type, ?int $id, User $actor, string $command, string $operation, array $values, ?callable $factory = null): array
    {
        $files = isset($values['file']) ? [$values['file']] : array_column($values['documents'] ?? [], 'file');
        if ($files === []) { return []; }
        if (DB::transactionLevel() !== 0) { throw new LogicException('PPE staging reservations require a physical outer commit.'); }
        $reserved = $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($type, $id, $command, $operation, $values, $factory, $files) {
            $this->access->assertOperation($current, $operation);
            $payload = $this->payload($values);
            $fingerprint = $id === null
                ? PpeCommandValues::digest([$type, null, $current->id, $command, null, $this->intent($payload)])
                : PpeCommandValues::digest([$type, $id, null, $current->id, $command, $values['expected_version'], $this->intent($payload)]);

            if ($id === null) {
                $root = $factory($current, $reads, $sites);
                $source = $root;
            } else {
                $source = match ($type) {
                    'inventory' => $this->access->assertInventory($id, $current, $reads, $sites, $operation),
                    'allocation' => $this->access->assertAllocation($id, $current, $reads, $sites, $operation),
                    'inspection' => $this->access->assertInspection($id, $current, $reads, $sites, $operation),
                    default => throw new LogicException('Unknown PPE upload source.'),
                };
                $root = $type === 'inventory' ? $source : $this->source('inventory', (int) $source->ppe_inventory_id);
                $this->currentVersion($root, $root);
                if ($source !== $root) { $this->currentVersion($root, $source); }

            }
            $folder = match ($command === 'inspect' ? 'inspection' : $type) {
                'inventory' => 'ppe_attachments', 'allocation' => 'ppe_allocation_attachments', 'inspection' => 'ppe_inspection_attachments',
                default => throw new LogicException('PPE catalogue commands cannot stage evidence.'),
            };
            $claim = $this->claimIntent($current, $command, $values['idempotency_key'], $fingerprint, 'inventory', $id === null ? null : (int) $root->id,
                (int) $root->site_id, $type, $id, null, $values['expected_version'] ?? null);
            $this->assertRetainedAttemptIntent($values['idempotency_key'], $current, $command, $fingerprint);
            $existing = $reads->query(PpeCommandReceipt::query())->where('idempotency_key', $values['idempotency_key'])->first();
            if ($existing) {
                $this->assertReceiptIntent($existing, $current, $command, $fingerprint);
                return []; // The acceptance path independently rechecks original authority and retained proof.
            }
            if ($id !== null && (int) $root->source_revision !== $values['expected_version']) {
                PpeCommandValues::invalid('expected_version', 'This PPE record changed. Review current work before staging evidence.');
            }
            $ids = [];
            foreach ($files as $file) {
                $identity = $this->storage->identity($file);
                $attempt = new PpeEvidenceStaging(['intent_claim_id' => $claim->id, 'user_id' => $current->id, 'root_type' => 'inventory', 'root_id' => $root->exists ? $root->id : null,
                    'source_type' => $type, 'source_id' => $id, 'site_id' => $root->site_id, 'command' => $command,
                    'idempotency_key' => $values['idempotency_key'], 'expected_version' => $values['expected_version'] ?? null,
                    'fingerprint' => $fingerprint, 'intent' => $this->intent($payload), 'legacy_origin' => null, 'disk' => 'private',
                    'path' => $folder.'/'.strtolower((string) \Illuminate\Support\Str::uuid()).'.bin',
                    'file_digest' => $identity['digest'], 'file_size' => $identity['size'], 'state' => 'staged']);
                $attempt->reservation_digest = PpeCommandValues::digest($attempt->identity());
                $this->persist($attempt);
                $ids[] = (int) $attempt->id;
            }
            return $ids;
        });
        if (DB::transactionLevel() !== 0) { throw new LogicException('PPE staging reservation did not physically commit.'); }
        return $reserved;
    }

    private function stageReserved(UploadedFile $file, array $ids): array
    {
        $identity = $this->storage->identity($file);
        foreach ($ids as $id) {
            $row = PpeEvidenceStaging::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertReservation($row);
            if ($row->file_digest === $identity['digest'] && $row->file_size === $identity['size']
                && ! \Illuminate\Support\Facades\Storage::disk('private')->exists($row->path)) {
                return $this->storage->stageReserved($file, $row);
            }
        }
        throw new LogicException('The PPE file has no matching committed staging reservation.');
    }

    private function bindStaging(array $ids, Model $root, User $actor, array $result, string $fingerprint, CurrentAuthorizationReads $reads): void
    {
        if ($ids === []) { return; }
        $receipt = $reads->query(PpeCommandReceipt::query())->where('idempotency_key', $result['idempotency_key'])->sole();
        $this->validatedReceipt($receipt, $root, $actor, $reads);
        $version = $reads->query(PpeSourceVersion::query())->whereKey($result['source_version_id'])->firstOrFail();
        $after = [$version->content['source_after'], ...array_column($version->content['facts']['documents'] ?? [], 'after')];
        foreach ($ids as $id) {
            $row = PpeEvidenceStaging::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertReservation($row);
            $matches = array_values(array_filter($after, fn ($snapshot) => ($snapshot['path'] ?? null) === $row->path && ($snapshot['disk'] ?? null) === 'private'));
            if ($row->receipt_id !== null || $row->state !== 'staged' || $row->user_id !== (int) $actor->id
                || $row->site_id !== (int) $root->site_id || ($row->root_id !== null && $row->root_id !== (int) $root->id)
                || $row->command !== $result['command'] || $row->idempotency_key !== $result['idempotency_key']
                || ! hash_equals($row->fingerprint, $fingerprint) || count($matches) !== 1
                || ! PpeCommandValues::sameBytes($this->storage->bytes($row->path), ['digest' => $row->file_digest, 'size' => $row->file_size])) {
                throw new LogicException('PPE staged evidence does not match exact durable acceptance.');
            }
            $row->forceFill(['receipt_id' => $receipt->id, 'state' => 'accepted']);
            $this->persist($row);
        }
    }

    private function assertReservation(PpeEvidenceStaging $row): void
    {
        if (! is_string($row->reservation_digest) || ! in_array($row->state, ['staged', 'accepted', 'cleanup_pending', 'unknown', 'cleaned'], true)
            || (($row->receipt_id !== null) !== ($row->state === 'accepted'))
            || ! hash_equals($row->reservation_digest, PpeCommandValues::digest($row->identity()))
            || ! preg_match('~^ppe_(?:allocation_|inspection_)?attachments/[0-9a-f-]{36}\.bin$~D', $row->path)) {
            throw new LogicException('PPE reserved byte/source identity requires repair.');
        }
        $claim = PpeCommandIntentClaim::where('idempotency_key', $row->idempotency_key)->lock('for share nowait')->first();
        $identity = ['idempotency_key' => $row->idempotency_key, 'user_id' => $row->user_id, 'command' => $row->command,
            'root_type' => $row->root_type, 'root_id' => $row->root_id, 'site_id' => $row->site_id,
            'source_type' => $row->source_type, 'source_id' => $row->source_id,
            'attachment_id' => $row->command === 'relocate_attachment' ? ($row->legacy_origin['attachment_id'] ?? null) : null,
            'expected_version' => $row->expected_version, 'fingerprint' => $row->fingerprint];
        if (! $claim || (int) $claim->id !== $row->intent_claim_id || ! hash_equals($claim->identity_digest, PpeCommandValues::digest($claim->identity()))
            || PpeCommandValues::digest($identity) !== PpeCommandValues::digest($claim->identity())) {
            throw new LogicException('PPE staging lacks its exact retained reviewed intent claim.');
        }
    }
    private function stagingAuthority(PpeEvidenceStaging $row, User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        abort_unless($row->user_id === (int) $current->id && $row->disk === 'private', 404);
        $this->assertReservation($row);
        if ($row->root_id === null) {
            abort_unless($row->source_id === null && $row->command === 'create_inventory' && $row->root_type === 'inventory', 404);
            $this->access->assertOperation($current, 'create');
            $this->access->assertSite($row->site_id, $current, $reads, $sites);
            abort_unless($reads->query(Site::query())->whereKey($row->site_id)->where('is_active', true)->exists(), 404);
            $type = $this->access->assertType((int) ($row->intent['ppe_type_id'] ?? 0), $current, $reads, 'create');
            $this->currentVersion($type, $type);
            abort_unless($type->is_active, 403);
        } else {
            $root = $reads->query(PpeInventory::query())->whereKey($row->root_id)->whereIn('site_id', $sites)->firstOrFail();
            abort_unless((int) $root->site_id === $row->site_id, 404);
            $source = $row->source_type === 'inventory' ? $root : $this->source($row->source_type, $row->source_id);
            abort_unless($source instanceof PpeInventory || (int) $source->ppe_inventory_id === (int) $root->id, 404);
            $this->authorize($row->source_type, $source, $current, $reads, $sites, $row->command === 'inspect' ? 'inspect' : 'evidence');
            $this->currentVersion($root, $root);
            if ($source !== $root) { $this->currentVersion($root, $source); }
        }
        $attachmentId = null;
        if ($row->command === 'relocate_attachment') {
            $origin = $row->legacy_origin;
            if (! is_array($origin) || ($origin['attachment_type'] ?? null) !== $row->source_type.'_attachment'
                || ! is_int($origin['attachment_id'] ?? null) || $origin['attachment_id'] < 1
                || ($origin['parent_type'] ?? null) !== $row->source_type || ($origin['parent_id'] ?? null) !== $row->source_id
                || ($origin['disk'] ?? null) !== 'public' || ($origin['original_attribution_state'] ?? null) !== 'legacy_unknown'
                || PpeCommandValues::digest($row->intent['_legacy_origin'] ?? null) !== PpeCommandValues::digest($origin)
                || ! PpeCommandValues::sameBytes($origin['observed_bytes'] ?? null, ['digest' => $row->file_digest, 'size' => $row->file_size])) {
                throw new LogicException('PPE transfer staging origin requires repair.');
            }
            $attachmentId = $origin['attachment_id'];
        } elseif ($row->legacy_origin !== null) {
            throw new LogicException('An upload attempt cannot be relabelled as a legacy transfer.');
        }
        $fingerprint = $row->source_id === null
            ? PpeCommandValues::digest([$row->source_type, null, $current->id, $row->command, null, $row->intent])
            : PpeCommandValues::digest([$row->source_type, $row->source_id, $attachmentId, $current->id, $row->command, $row->expected_version, $row->intent]);
        if (! hash_equals($row->fingerprint, $fingerprint)) { throw new LogicException('PPE staging intent evidence requires repair.'); }
        if ($row->receipt_id !== null) {
            $receipt = $reads->query(PpeCommandReceipt::query())->whereKey($row->receipt_id)->firstOrFail();
            $acceptedRoot = $reads->query(PpeInventory::query())->whereKey($receipt->root_id)->whereIn('site_id', $sites)->firstOrFail();
            $this->validatedReceipt($receipt, $acceptedRoot, $current, $reads);
            $version = $reads->query(PpeSourceVersion::query())->whereKey($receipt->source_version_id)->firstOrFail();
            $direct = ($version->content['source_after']['path'] ?? null) === $row->path
                && ($version->content['source_after']['disk'] ?? null) === 'private';
            $documents = array_values(array_filter($version->content['facts']['documents'] ?? [],
                fn ($document) => ($document['after']['path'] ?? null) === $row->path && ($document['after']['disk'] ?? null) === 'private'));
            $proof = $direct ? ($version->content['facts']['file_evidence'] ?? null)
                : (count($documents) === 1 ? ($documents[0]['file_evidence'] ?? null) : null);
            if ($receipt->idempotency_key !== $row->idempotency_key || ! hash_equals($receipt->fingerprint, $row->fingerprint)
                || $receipt->root_type !== 'inventory' || ($row->root_id !== null && $receipt->root_id !== $row->root_id)
                || (int) $acceptedRoot->site_id !== $row->site_id || ($direct ? count($documents) !== 0 : count($documents) !== 1)
                || ! PpeCommandValues::sameBytes($proof, ['digest' => $row->file_digest, 'size' => $row->file_size])
                || ($row->command === 'relocate_attachment' && PpeCommandValues::digest($version->content['facts']['legacy_origin'] ?? null) !== PpeCommandValues::digest($row->legacy_origin))) {
                throw new LogicException('PPE staging acceptance identity requires repair.');
            }
        }
    }

    /** This actor's currently admitted unresolved attempts; no totals, paths or command UUIDs. */
    public function stagingAttemptsForViewer(User $viewer, int $afterId = 0): array
    {
        if ($afterId < 0) { PpeCommandValues::invalid('after_id', 'Use a valid staging continuation.'); }
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($afterId) {
            $this->access->assertOperation($current, 'evidence');
            $rows = $reads->query(PpeEvidenceStaging::query())->where('user_id', $current->id)->whereIn('site_id', $sites)
                ->whereNull('receipt_id')->whereIn('state', ['staged', 'cleanup_pending', 'unknown'])
                ->where('id', '>', $afterId)->orderBy('id')->limit(26)->get();
            $attempts = [];
            foreach ($rows->take(25) as $row) {
                try { $this->stagingAuthority($row, $current, $reads, $sites); }
                catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { continue; }
                catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $denial) {
                    if (in_array($denial->getStatusCode(), [403, 404], true)) { continue; }
                    throw $denial;
                }
                $attempts[] = ['id' => (int) $row->id, 'state' => $row->state, 'accepted' => false,
                    'command' => $row->command, 'recorded_at' => $row->created_at->toIso8601String(), 'can_retry' => true];
            }
            return ['attempts' => $attempts, 'next_cursor' => $rows->count() > 25 ? (int) $rows[24]->id : null];
        });
    }
    /** Direct command recovery only; callers must keep this UUID out of navigation history. */
    public function stagingAttemptsForKey(User $viewer, string $key, int $afterId = 0): array
    {
        if ($afterId < 0 || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $key)) {
            PpeCommandValues::invalid('receipt_key', 'Use the original PPE command UUID and valid continuation.');
        }
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($key, $afterId) {
            $this->access->assertOperation($current, 'evidence');
            $rows = $reads->query(PpeEvidenceStaging::query())->where('user_id', $current->id)
                ->where('idempotency_key', strtolower($key))->where('id', '>', $afterId)->orderBy('id')->limit(26)->get();
            $attempts = [];
            foreach ($rows->take(25) as $row) {
                $this->stagingAuthority($row, $current, $reads, $sites);
                $attempts[] = ['id' => (int) $row->id, 'state' => $row->state, 'accepted' => $row->receipt_id !== null];
            }
            return ['attempts' => $attempts, 'next_cursor' => $rows->count() > 25 ? $attempts[array_key_last($attempts)]['id'] : null];
        });
    }
    public function stagingForViewer(User $viewer, int $id): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($id) {
            $row = $reads->query(PpeEvidenceStaging::query())->whereKey($id)->where('user_id', $current->id)->firstOrFail();
            $this->stagingAuthority($row, $current, $reads, $sites);
            return ['id' => (int) $row->id, 'state' => $row->state, 'accepted' => $row->receipt_id !== null];
        });
    }

    public function resumeStagingCleanup(User $viewer, int $id): array
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Unaccepted PPE staging cleanup requires an outermost transaction.'); }
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($id) {
            $row = PpeEvidenceStaging::whereKey($id)->where('user_id', $current->id)->lockForUpdate()->firstOrFail();
            $this->stagingAuthority($row, $current, $reads, $sites);
            if ($row->receipt_id !== null || $row->state === 'accepted') {
                PpeCommandValues::invalid('staging', 'Accepted evidence is protected; use the governed record removal command.');
            }
            $state = 'unknown';
            try {
                if (! $this->storage->retainedStagingReference($row)) {
                    $bytes = $this->storage->bytes($row->path);
                    if ($bytes === null) { $state = 'cleaned'; }
                    elseif (PpeCommandValues::sameBytes($bytes, ['digest' => $row->file_digest, 'size' => $row->file_size])) {
                        $this->storage->discard($row->path);
                        $state = 'cleaned';
                    }
                }
            } catch (Throwable) { $state = 'cleanup_pending'; }
            $row->forceFill(['state' => $state, 'attempts' => $row->attempts + 1]);
            $this->persist($row);
            return ['id' => (int) $row->id, 'state' => $state, 'accepted' => false];
        });
    }

    private function rejectStaging(User $actor, array $ids): void
    {
        foreach ($ids as $id) {
            try { $this->resumeStagingCleanup($actor, $id); }
            catch (Throwable $failure) {
                // The committed reservation remains discoverable even if permission or delivery failed.
                report($failure);
            }
        }
    }

    /** Guarded uploads require ownership of the physical transaction for rollback cleanup. */
    private function assertFileTransaction(array $values): void
    {
        if (DB::transactionLevel() > 0 && (isset($values['file']) || ! empty($values['documents']))) {
            throw new LogicException('PPE staged-file commands require an outermost transaction so failed acceptance cannot orphan private bytes.');
        }
    }

    /** Internal current-authorised callback only; no receipt or history grants access. */
    public function currentVersion(Model $root, Model $source): ?PpeSourceVersion
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('PPE source proof requires an active current-authority transaction.');
        }
        $type = $this->kind($source);
        $sameRoot = $type === $this->kind($root) && (int) $source->id === (int) $root->id;
        $schema = (int) $source->source_schema_version;
        $revision = (int) $source->source_revision;
        $versions = PpeSourceVersion::where(function ($query) use ($type, $source, $root, $sameRoot) {
            if ($sameRoot) {
                $query->where('root_type', $type)->where('root_id', $source->id);
                return;
            }
            $query->where(fn ($direct) => $direct->where('source_type', $type)->where('source_id', $source->id));
            if (str_ends_with($type, '_attachment')) {
                $query->orWhereJsonContains('content->facts->documents', ['source_type' => $type, 'source_id' => (int) $source->id]);
            }
        });
        if ($schema === 0) {
            if ($revision !== 0 || (clone $versions)->lock('for share nowait')->exists()) {
                throw new LogicException('Retained PPE source history cannot be downgraded to legacy.');
            }
            return null;
        }
        if ($schema !== 1 || $revision < 1) {
            throw new LogicException('PPE source markers require repair.');
        }
        $version = $versions->orderByDesc('aggregate_revision')->lock('for share nowait')->first();
        if (! $version) {
            throw new LogicException('The governed PPE source proof is missing.');
        }
        $this->validateVersion($version);
        if ($version->root_type !== $this->kind($root) || $version->root_id !== (int) $root->id
            || $version->aggregate_revision !== $revision || $revision > (int) $root->source_revision
            || $version->site_id !== ($root instanceof PpeInventory ? (int) $root->site_id : null)) {
            throw new LogicException('PPE source proof does not match its canonical parent and revision.');
        }
        $after = $sameRoot ? $version->content['root_after'] : $version->content['source_after'];
        if (! $sameRoot && ($version->source_type !== $type || $version->source_id !== (int) $source->id)) {
            $matches = array_values(array_filter($version->content['facts']['documents'] ?? [],
                fn ($document) => ($document['source_type'] ?? null) === $type && ($document['source_id'] ?? null) === (int) $source->id));
            if (count($matches) !== 1) {
                throw new LogicException('PPE evidence version identity is ambiguous.');
            }
            $after = $matches[0]['after'];
        }
        if (PpeCommandValues::digest($after) !== PpeCommandValues::digest($this->snapshot($source))) {
            throw new LogicException('PPE current facts differ from their immutable version.');
        }
        if (! $sameRoot) {
            $this->currentVersion($root, $root);
        } elseif ($version->aggregate_revision !== (int) $root->source_revision
            || PpeCommandValues::digest($version->content['root_after']) !== PpeCommandValues::digest($this->snapshot($root))) {
            throw new LogicException('PPE aggregate facts require repair.');
        }
        return $version;
    }

    public function sourceState(Model $root, ?Model $source = null): string
    {
        try {
            return $this->currentVersion($root, $source ?? $root) ? 'recorded' : 'legacy_unknown';
        } catch (LogicException) {
            return 'repair_required';
        }
    }

    /** Retained history metadata only; caller first admits the current canonical root. */
    public function historyProjection(Model $root, PpeSourceVersion $version): array
    {
        try {
            if (DB::transactionLevel() < 1) { throw new LogicException('PPE history requires active authority.'); }
            $this->validateVersion($version);
            if ($version->root_type !== $this->kind($root) || $version->root_id !== (int) $root->id
                || $version->site_id !== ($root instanceof PpeInventory ? (int) $root->site_id : null)
                || ! is_string($version->actor_snapshot['name'] ?? null)) {
                throw new LogicException('PPE retained history is bound to another source.');
            }
            return ['integrity' => 'recorded', 'id' => (int) $version->id, 'revision' => $version->aggregate_revision,
                'command' => $version->command, 'at' => $version->recorded_at->toIso8601String(),
                'actor' => ['id' => $version->user_id, 'name' => $version->actor_snapshot['name']]];
        } catch (LogicException) {
            return ['integrity' => 'repair_required'];
        }
    }

    /** Actual private byte proof; never supplies a path, digest, filename or competence claim. */
    public function evidenceProof(Model $root, Model $attachment): array
    {
        return array_intersect_key($this->evidenceReadProof($root, $attachment), array_flip(['attribution_state', 'available']));
    }

    /** Internal download descriptor is derived from the same verified read, never serialized. */
    public function evidenceReadProof(Model $root, Model $attachment): array
    {
        try {
            $version = $this->currentVersion($root, $attachment);
            if ($attachment->disk !== 'private') {
                return ['attribution_state' => $version ? 'repair_required' : 'legacy_unknown', 'available' => false, 'byte_proof' => null];
            }
            $actual = $this->storage->bytes($attachment->path);
            if (! $version) {
                return ['attribution_state' => 'legacy_unknown', 'available' => $actual !== null, 'byte_proof' => $actual];
            }
            $expected = $version->content['facts']['file_evidence'] ?? null;
            if ($version->source_type !== $this->kind($attachment) || $version->source_id !== (int) $attachment->id) {
                $matches = array_values(array_filter($version->content['facts']['documents'] ?? [],
                    fn ($row) => ($row['source_type'] ?? null) === $this->kind($attachment) && ($row['source_id'] ?? null) === (int) $attachment->id));
                $expected = count($matches) === 1 ? $matches[0]['file_evidence'] ?? null : null;
            }
            if (! is_array($expected) || ! is_string($expected['digest'] ?? null) || ! is_int($expected['size'] ?? null)) {
                return ['attribution_state' => 'repair_required', 'available' => false, 'byte_proof' => null];
            }
            if ($actual === null) {
                return ['attribution_state' => 'unavailable', 'available' => false, 'byte_proof' => null];
            }
            return PpeCommandValues::sameBytes($actual, $expected) ? ['attribution_state' => 'recorded', 'available' => true, 'byte_proof' => $actual]
                : ['attribution_state' => 'repair_required', 'available' => false, 'byte_proof' => null];
        } catch (LogicException) {
            return ['attribution_state' => 'repair_required', 'available' => false, 'byte_proof' => null];
        }
    }
    /** Only separately selected immutable factual columns; caller has admitted exact current ownership. */
    public function ownSourceState(PpeInventory $root, PpeAllocation $allocation): string
    {
        if (DB::transactionLevel() < 1) { throw new LogicException('PPE own proof requires active canonical authority.'); }
        if ((int) $allocation->ppe_inventory_id !== (int) $root->id) { return 'repair_required'; }
        $rootVersions = PpeSourceVersion::where('root_type', 'inventory')->where('root_id', $root->id);
        $childVersions = PpeSourceVersion::where('source_type', 'allocation')->where('source_id', $allocation->id);
        $rootSchema = (int) $root->source_schema_version; $childSchema = (int) $allocation->source_schema_version;
        $rootRevision = (int) $root->source_revision; $childRevision = (int) $allocation->source_revision;
        if ($rootSchema === 0 && ($rootRevision !== 0 || (clone $rootVersions)->lock('for share nowait')->exists())
            || $childSchema === 0 && ($childRevision !== 0 || (clone $childVersions)->lock('for share nowait')->exists())
            || ! in_array($rootSchema, [0, 1], true) || ! in_array($childSchema, [0, 1], true)
            || $rootSchema === 1 && $rootRevision < 1 || $childSchema === 1 && ($childRevision < 1 || $childRevision > $rootRevision)) {
            return 'repair_required';
        }
        if ($rootSchema === 1) {
            $version = $rootVersions->select(['id', 'root_type', 'root_id', 'site_id', 'aggregate_revision', 'source_schema_version', 'factual_content', 'factual_digest'])
                ->orderByDesc('aggregate_revision')->lock('for share nowait')->first();
            if (! $version || $version->source_schema_version !== 1 || $version->aggregate_revision !== $rootRevision
                || $version->site_id !== (int) $root->site_id || ! is_array($version->factual_content)
                || ! is_string($version->factual_digest)
                || ! hash_equals($version->factual_digest, PpeCommandValues::digest($version->factual_content))
                || PpeCommandValues::digest($version->factual_content) !== PpeCommandValues::digest($this->factualSnapshot($root))) {
                return 'repair_required';
            }
        }
        if ($childSchema === 1) {
            $version = $childVersions->select(['id', 'root_type', 'root_id', 'source_type', 'source_id', 'site_id', 'person_id',
                'aggregate_revision', 'source_schema_version', 'own_content', 'own_digest'])
                ->orderByDesc('aggregate_revision')->lock('for share nowait')->first();
            if (! $version || $version->root_type !== 'inventory' || $version->root_id !== (int) $root->id
                || $version->site_id !== (int) $root->site_id || $version->person_id !== (int) $allocation->user_id
                || $version->source_schema_version !== 1 || $version->aggregate_revision !== $childRevision
                || ! is_array($version->own_content) || ! is_string($version->own_digest)
                || ! hash_equals($version->own_digest, PpeCommandValues::digest($version->own_content))
                || PpeCommandValues::digest($version->own_content) !== PpeCommandValues::digest($this->ownSnapshot($root, $allocation))) {
                return 'repair_required';
            }
        }
        return $rootSchema === 1 && $childSchema === 1 ? 'recorded' : 'legacy_unknown';
    }

    /** Canonical reference already admitted by exact own inventory; no catalogue descriptions/history. */
    public function ownTypeReferenceState(PpeType $type): string
    {
        if (DB::transactionLevel() < 1) { throw new LogicException('PPE own reference proof requires active authority.'); }
        $versions = PpeSourceVersion::where('root_type', 'type')->where('root_id', $type->id);
        $schema = (int) $type->source_schema_version; $revision = (int) $type->source_revision;
        if ($schema === 0) {
            return $revision === 0 && ! (clone $versions)->lock('for share nowait')->exists() ? 'legacy_unknown' : 'repair_required';
        }
        if ($schema !== 1 || $revision < 1) { return 'repair_required'; }
        $version = $versions->select(['id', 'root_type', 'root_id', 'aggregate_revision', 'source_schema_version', 'factual_content', 'factual_digest'])
            ->orderByDesc('aggregate_revision')->lock('for share nowait')->first();
        return $version && $version->source_schema_version === 1 && $version->aggregate_revision === $revision
            && is_array($version->factual_content) && is_string($version->factual_digest)
            && hash_equals($version->factual_digest, PpeCommandValues::digest($version->factual_content))
            && PpeCommandValues::digest($version->factual_content) === PpeCommandValues::digest($this->factualSnapshot($type))
            ? 'recorded' : 'repair_required';
    }
    private function factualSnapshot(Model $root): array
    {
        $columns = $root instanceof PpeInventory ? PpeAccessService::INVENTORY_OWN_COLUMNS
            : ['id', 'name', 'category', 'is_active', 'source_schema_version', 'source_revision'];
        return ['schema' => 1, 'root_type' => $this->kind($root), 'root_id' => (int) $root->id,
            'site_id' => $root instanceof PpeInventory ? (int) $root->site_id : null,
            'revision' => (int) $root->source_revision, 'facts' => array_intersect_key($this->snapshot($root), array_flip($columns))];
    }

    private function ownSnapshot(PpeInventory $root, PpeAllocation $allocation): array
    {
        return ['schema' => 1, 'root_type' => 'inventory', 'root_id' => (int) $root->id, 'site_id' => (int) $root->site_id,
            'source_type' => 'allocation', 'source_id' => (int) $allocation->id, 'worker_id' => (int) $allocation->user_id,
            'revision' => (int) $allocation->source_revision,
            'facts' => array_intersect_key($this->snapshot($allocation), array_flip(PpeAccessService::ALLOCATION_FACTUAL_COLUMNS))];
    }
    private function validateVersion(PpeSourceVersion $version): void
    {
        $content = $version->content;
        $tuple = array_intersect_key($version->attributesToArray(), array_flip(['root_type', 'root_id', 'source_type', 'source_id', 'site_id', 'person_id', 'aggregate_revision', 'command', 'idempotency_key', 'user_id']));
        if (! is_array($content) || PpeCommandValues::digest($content['tuple'] ?? null) !== PpeCommandValues::digest($tuple)
            || PpeCommandValues::digest($content['recorder'] ?? null) !== PpeCommandValues::digest($version->actor_snapshot)
            || ($content['recorder']['id'] ?? null) !== $version->user_id
            || ($content['recorded_at'] ?? null) !== $version->recorded_at?->utc()->format('Y-m-d\TH:i:s.u\Z')) {
            throw new LogicException('PPE retained attribution or tuple is invalid.');
        }
        if ($version->source_schema_version !== 1 || ! is_array($content)
            || ! hash_equals($version->content_digest, PpeCommandValues::digest($content))
            || ! is_array($content['root_after'] ?? null) || ! is_array($content['source_after'] ?? null)
            || ($content['root_after']['id'] ?? null) !== $version->root_id
            || ($content['root_after']['source_revision'] ?? null) !== $version->aggregate_revision
            || ($content['source_after']['id'] ?? null) !== $version->source_id
            || ($content['root_after']['source_schema_version'] ?? null) !== 1
            || ($content['source_after']['source_schema_version'] ?? null) !== 1
            || ($content['source_after']['source_revision'] ?? null) !== $version->aggregate_revision
            || ($version->root_type === 'inventory' && ($content['root_after']['site_id'] ?? null) !== $version->site_id)
            || (in_array($version->source_type, ['allocation', 'inspection', 'inventory_attachment'], true)
                && ($content['source_after']['ppe_inventory_id'] ?? null) !== $version->root_id)) {
            throw new LogicException('The immutable PPE version is invalid.');
        }
    }

    private function version(Model $root, Model $source, User $actor, string $command, string $key,
        ?array $rootBefore, ?array $sourceBefore, array $facts): PpeSourceVersion
    {
        $at = now()->utc();
        $tuple = ['root_type' => $this->kind($root), 'root_id' => (int) $root->id, 'source_type' => $this->kind($source),
            'source_id' => (int) $source->id, 'site_id' => $root instanceof PpeInventory ? (int) $root->site_id : null,
            'person_id' => $source instanceof PpeAllocation ? (int) $source->user_id : ($source instanceof PpeInspection ? (int) $source->inspected_by : null),
            'aggregate_revision' => (int) $root->source_revision, 'command' => $command, 'idempotency_key' => $key, 'user_id' => (int) $actor->id];
        $content = ['tuple' => $tuple, 'recorder' => ['id' => (int) $actor->id, 'name' => $actor->name], 'recorded_at' => $at->format('Y-m-d\TH:i:s.u\Z'),
            'root_before' => $rootBefore, 'root_after' => $this->snapshot($root),
            'source_before' => $sourceBefore, 'source_after' => $this->snapshot($source), 'facts' => $facts];
        $version = new PpeSourceVersion(['root_type' => $this->kind($root), 'root_id' => $root->id,
            'source_type' => $this->kind($source), 'source_id' => $source->id,
            'site_id' => $root instanceof PpeInventory ? $root->site_id : null,
            'person_id' => $source instanceof PpeAllocation ? $source->user_id : ($source instanceof PpeInspection ? $source->inspected_by : null),
            'aggregate_revision' => $root->source_revision, 'source_schema_version' => 1, 'command' => $command,
            'idempotency_key' => $key, 'user_id' => $actor->id, 'actor_snapshot' => ['id' => (int) $actor->id, 'name' => $actor->name],
            'content' => $content, 'content_digest' => PpeCommandValues::digest($content), 'recorded_at' => $at,
            'factual_content' => $this->factualSnapshot($root), 'factual_digest' => PpeCommandValues::digest($this->factualSnapshot($root)),
            'own_content' => $source instanceof PpeAllocation ? $this->ownSnapshot($root, $source) : null,
            'own_digest' => $source instanceof PpeAllocation ? PpeCommandValues::digest($this->ownSnapshot($root, $source)) : null]);
        $this->persist($version);
        $this->validateVersion($version);
        return $version;
    }

    private function accept(Model $root, Model $source, User $actor, string $command, string $key, string $fingerprint,
        ?int $expected, ?PpeSourceVersion $version, CurrentAuthorizationReads $reads): array
    {
        $result = ['accepted' => true, 'actor_id' => (int) $actor->id, 'command' => $command, 'idempotency_key' => $key,
            'root_type' => $this->kind($root), 'root_id' => (int) $root->id, 'source_type' => $this->kind($source),
            'source_id' => (int) $source->id, 'expected_version' => $expected, 'version' => (int) $root->source_revision,
            'source_version_id' => $version ? (int) $version->id : null];
        $this->assertRetainedAttemptIntent($key, $actor, $command, $fingerprint);
        $meta = [...$result, 'fingerprint' => $fingerprint];
        $auditRequest = request()->duplicate();
        $auditRequest->setUserResolver(fn () => $actor);
        AuditLogger::logOrFail('ppe.'.$command, $root, $meta, $auditRequest);
        $audits = $reads->query(AuditLog::query())->where('user_id', $actor->id)->where('action', 'ppe.'.$command)
            ->where('auditable_type', $root->getMorphClass())->where('auditable_id', $root->id)
            ->where('meta->idempotency_key', $key)->get();
        if ($audits->count() !== 1 || PpeCommandValues::digest($audits->sole()->meta) !== PpeCommandValues::digest($meta)) {
            throw new LogicException('Exact PPE command audit acceptance was not persisted.');
        }
        $claim = $reads->query(PpeCommandIntentClaim::query())->where('idempotency_key', $key)->firstOrFail();
        $receipt = new PpeCommandReceipt(['intent_claim_id' => $claim->id, 'idempotency_key' => $key, 'user_id' => $actor->id,
            'root_type' => $result['root_type'], 'root_id' => $root->id, 'source_type' => $result['source_type'], 'source_id' => $source->id,
            'command' => $command, 'expected_version' => $expected, 'fingerprint' => $fingerprint,
            'accepted_result' => $result, 'source_version_id' => $version?->id, 'audit_log_id' => $audits->sole()->id, 'created_at' => now()->utc()]);
        $this->persist($receipt);
        $receipt->persisted();
        return $this->validatedReceipt($receipt, $root, $actor, $reads);
    }

    /** Fresh authority and ordered canonical root locks precede the unique insert; no absent-key read proves exclusion. */
    private function claimIntent(User $actor, string $command, string $key, string $fingerprint, string $rootType,
        ?int $rootId, ?int $siteId, string $sourceType, ?int $sourceId, ?int $attachmentId, ?int $expected): PpeCommandIntentClaim
    {
        if (DB::transactionLevel() < 1) { throw new LogicException('PPE intent claim requires current source authority.'); }
        $identity = ['idempotency_key' => $key, 'user_id' => (int) $actor->id, 'command' => $command, 'root_type' => $rootType,
            'root_id' => $rootId, 'site_id' => $siteId, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'attachment_id' => $attachmentId, 'expected_version' => $expected, 'fingerprint' => $fingerprint];
        // Insert first: an absent UUID does not acquire a gap lock or establish exclusion.
        $candidate = new PpeCommandIntentClaim([...$identity, 'identity_digest' => PpeCommandValues::digest($identity), 'created_at' => now()->utc()]);
        try { $this->persist($candidate); $claim = $candidate; }
        catch (QueryException $collision) {
            if ((int) ($collision->errorInfo[1] ?? 0) !== 1062 || ! str_contains($collision->getMessage(), 'ppe_intent_uuid_uq')) { throw $collision; }
            $claim = PpeCommandIntentClaim::where('idempotency_key', $key)->lockForUpdate()->first();
            if (! $claim) { throw new LogicException('The unique PPE intent claim was not recoverable.'); }
        }
        if (! is_string($claim->identity_digest) || ! hash_equals($claim->identity_digest, PpeCommandValues::digest($claim->identity()))) {
            throw new LogicException('Retained PPE intent identity requires repair.');
        }
        if (PpeCommandValues::digest($identity) !== PpeCommandValues::digest($claim->identity())) {
            PpeCommandValues::invalid('idempotency_key', 'This PPE UUID is durably claimed by another actor, source or reviewed intent.');
        }
        return $claim;
    }

    private function assertReceiptClaim(PpeCommandReceipt $receipt, Model $root, CurrentAuthorizationReads $reads): void
    {
        $claim = $reads->query(PpeCommandIntentClaim::query())->where('idempotency_key', $receipt->idempotency_key)->first();
        $creates = in_array($receipt->command, ['create_type', 'create_inventory'], true);
        if (! $claim || (int) $claim->id !== $receipt->intent_claim_id || ! is_string($claim->identity_digest) || ! hash_equals($claim->identity_digest, PpeCommandValues::digest($claim->identity()))
            || $claim->user_id !== $receipt->user_id || $claim->command !== $receipt->command || $claim->fingerprint !== $receipt->fingerprint
            || $claim->expected_version !== $receipt->expected_version || $claim->root_type !== $receipt->root_type
            || $claim->root_id !== ($creates ? null : $receipt->root_id)
            || $claim->site_id !== ($root instanceof PpeInventory ? (int) $root->site_id : null)) {
            throw new LogicException('PPE acceptance lacks its exact retained reviewed intent claim.');
        }
        if ($creates) {
            $matches = $claim->source_type === $receipt->root_type && $claim->source_id === null && $claim->attachment_id === null;
        } elseif (in_array($receipt->command, ['acknowledge', 'acknowledge_own', 'return'], true)) {
            $matches = $claim->source_type === 'allocation' && $claim->source_id === $receipt->source_id && $claim->attachment_id === null;
        } elseif (in_array($receipt->command, ['upload_attachment', 'remove_attachment', 'relocate_attachment'], true)) {
            $type = str_replace('_attachment', '', $receipt->source_type); $class = $this->attachmentClass($type);
            $parent = $class::withTrashed()->whereKey($receipt->source_id)->where($this->parentForeignKey($type), $claim->source_id)
                ->select(['id', $this->parentForeignKey($type)])->lock('for share nowait')->first();
            $matches = $parent !== null && $claim->source_type === $type
                && $claim->attachment_id === ($receipt->command === 'upload_attachment' ? null : $receipt->source_id);
        } else {
            $matches = $claim->source_type === $receipt->root_type && $claim->source_id === $receipt->root_id && $claim->attachment_id === null;
        }
        if (! $matches) { throw new LogicException('PPE accepted intent target differs from its immutable receipt.'); }
    }
    private function assertRetainedAttemptIntent(string $key, User $actor, string $command, string $fingerprint): void
    {
        $attempts = PpeEvidenceStaging::where('idempotency_key', $key)->lock('for share nowait')->get();
        foreach ($attempts as $attempt) {
            if ($attempt->user_id !== (int) $actor->id || $attempt->command !== $command || ! hash_equals($attempt->fingerprint, $fingerprint)) {
                PpeCommandValues::invalid('idempotency_key', 'This PPE key retains a different actor, source or reviewed attempt. Renew the key after reviewing changed intent.');
            }
            $this->assertReservation($attempt);
            $attachmentId = $attempt->command === 'relocate_attachment' ? ($attempt->legacy_origin['attachment_id'] ?? null) : null;
            $retained = $attempt->source_id === null
                ? PpeCommandValues::digest([$attempt->source_type, null, $actor->id, $attempt->command, null, $attempt->intent])
                : PpeCommandValues::digest([$attempt->source_type, $attempt->source_id, $attachmentId, $actor->id,
                    $attempt->command, $attempt->expected_version, $attempt->intent]);
            if (! hash_equals($attempt->fingerprint, $retained)
                || ($attempt->command === 'relocate_attachment'
                    ? (! is_int($attachmentId) || $attachmentId < 1 || PpeCommandValues::digest($attempt->legacy_origin) !== PpeCommandValues::digest($attempt->intent['_legacy_origin'] ?? null))
                    : $attempt->legacy_origin !== null)) {
                throw new LogicException('Retained PPE attempt source and intent require repair.');
            }
        }
    }
    private function assertReceiptIntent(PpeCommandReceipt $receipt, User $actor, string $command, string $fingerprint): void
    {
        if ($receipt->user_id !== (int) $actor->id || $receipt->command !== $command || ! hash_equals($receipt->fingerprint, $fingerprint)) {
            PpeCommandValues::invalid('idempotency_key', 'This command key already belongs to a different actor or reviewed PPE intent.');
        }
    }

    private function validatedReceipt(PpeCommandReceipt $receipt, Model $root, User $actor, CurrentAuthorizationReads $reads): array
    {
        $this->assertReceiptClaim($receipt, $root, $reads);
        $result = $receipt->accepted_result;
        $fields = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id', 'source_type', 'source_id', 'expected_version', 'version', 'source_version_id'];
        if (! is_array($result) || count($result) !== count($fields) || array_diff(array_keys($result), $fields)
            || ($result['accepted'] ?? null) !== true || ($result['actor_id'] ?? null) !== (int) $actor->id
            || $receipt->user_id !== (int) $actor->id || ($result['command'] ?? null) !== $receipt->command
            || ($result['idempotency_key'] ?? null) !== $receipt->idempotency_key
            || ($result['root_type'] ?? null) !== $receipt->root_type || ($result['root_id'] ?? null) !== $receipt->root_id
            || $receipt->root_type !== $this->kind($root) || $receipt->root_id !== (int) $root->id
            || ($result['source_type'] ?? null) !== $receipt->source_type || ($result['source_id'] ?? null) !== $receipt->source_id
            || ($result['expected_version'] ?? null) !== $receipt->expected_version
            || ($result['source_version_id'] ?? null) !== $receipt->source_version_id
            || ! is_int($result['version'] ?? null) || $result['version'] < 0 || $result['version'] > (int) $root->source_revision) {
            throw new LogicException('Retained PPE acceptance is invalid.');
        }
        $creates = in_array($receipt->command, ['create_type', 'create_inventory'], true);
        $noops = ['update_inventory', 'update_type', 'activate_type', 'deactivate_type', 'acknowledge', 'acknowledge_own'];
        $validLink = $creates
            ? $receipt->expected_version === null && $result['version'] === 1 && $receipt->source_version_id !== null
            : $receipt->expected_version !== null && ($receipt->source_version_id !== null
                ? $result['version'] === $receipt->expected_version + 1
                : $result['version'] === $receipt->expected_version && in_array($receipt->command, $noops, true));
        if (! $validLink) { throw new LogicException('PPE acceptance revision linkage is impossible.'); }
        if ($receipt->source_version_id === null && $reads->query(PpeSourceVersion::query())->where('idempotency_key', $receipt->idempotency_key)->exists()) {
            throw new LogicException('A changed PPE acceptance cannot be relabelled as a no-op.');
        }
        if ($receipt->source_version_id !== null) {
            $version = $reads->query(PpeSourceVersion::query())->whereKey($receipt->source_version_id)->first();
            if (! $version) {
                throw new LogicException('PPE accepted source version is missing.');
            }
            $this->validateVersion($version);
            if ($version->root_type !== $receipt->root_type || $version->root_id !== $receipt->root_id
                || $version->source_type !== $receipt->source_type || $version->source_id !== $receipt->source_id
                || $version->user_id !== $receipt->user_id || $version->command !== $receipt->command
                || $version->idempotency_key !== $receipt->idempotency_key || $version->aggregate_revision !== $result['version']) {
                throw new LogicException('PPE accepted version belongs to a different command.');
            }
        }
        $audit = $reads->query(AuditLog::query())->whereKey($receipt->audit_log_id)->first();
        $meta = [...$result, 'fingerprint' => $receipt->fingerprint];
        if (! $audit || (int) $audit->user_id !== (int) $actor->id || $audit->action !== 'ppe.'.$receipt->command
            || $audit->auditable_type !== $root->getMorphClass() || (int) $audit->auditable_id !== (int) $root->id
            || PpeCommandValues::digest($audit->meta) !== PpeCommandValues::digest($meta)) {
            throw new LogicException('PPE acceptance audit is missing or inconsistent.');
        }
        return array_intersect_key($result, array_flip($fields));
    }

    public function confirmationForKey(User $viewer, string $key): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $key)) {
            PpeCommandValues::invalid('receipt_key', 'Use the original PPE command UUID.');
        }
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($key) {
            $receipt = $reads->query(PpeCommandReceipt::query())->where('idempotency_key', strtolower($key))->where('user_id', $current->id)->first();
            if (! $receipt) {
                return null;
            }
            $rootQuery = $this->root($receipt->root_type, $receipt->root_id);
            if ($receipt->root_type === 'inventory') { $rootQuery->whereIn('site_id', $sites); }
            $root = $rootQuery->lock('for share nowait')->firstOrFail();
            [$type, $id, $operation] = $this->receiptAuthority($receipt, $root);
            $source = $this->source($type, $id);
            $this->authorize($type, $source, $current, $reads, $sites, $operation);
            if ($root instanceof PpeInventory && $source instanceof PpeInventory && $source->id !== $root->id
                || $root instanceof PpeInventory && ! $source instanceof PpeInventory && (int) $source->ppe_inventory_id !== (int) $root->id) {
                abort(404);
            }
            if ($receipt->command === 'remove_attachment') {
                $this->assertRemovedSource($receipt, $source, $receipt->source_id);
            }
            if ($receipt->command === 'create_inventory') {
                abort_unless($reads->query(Site::query())->whereKey($root->site_id)->where('is_active', true)->exists(), 404);
                $type = $this->access->assertType((int) $root->ppe_type_id, $current, $reads, 'create');
                $this->currentVersion($type, $type);
                abort_unless($type->is_active, 403);
            }
            if ($receipt->command === 'allocate') {
                $allocation = $this->source('allocation', $receipt->source_id);
                $this->access->currentStaff((int) $allocation->user_id, (int) $root->site_id, $current, $reads, $sites);
            }
            $this->currentVersion($root, $root);
            if ($source !== $root) { $this->currentVersion($root, $source); }
            return $this->validatedReceipt($receipt, $root, $current, $reads);
        });
    }

    private function receiptAuthority(PpeCommandReceipt $receipt, Model $root): array
    {
        $operation = match ($receipt->command) {
            'create_type', 'update_type', 'activate_type', 'deactivate_type' => 'catalogue_manage',
            'create_inventory' => 'create', 'update_inventory' => 'update', 'allocate' => 'allocate',
            'acknowledge' => 'acknowledge', 'acknowledge_own' => 'acknowledge_own', 'return' => 'return', 'inspect' => 'inspect',
            'condemn' => 'condemn', 'dispose' => 'dispose', 'upload_attachment', 'remove_attachment', 'relocate_attachment' => 'evidence',
            default => throw new LogicException('Unknown retained PPE command.'),
        };
        if (in_array($receipt->command, ['acknowledge', 'acknowledge_own', 'return'], true)) {
            return ['allocation', $receipt->source_id, $operation];
        }
        if (in_array($receipt->command, ['upload_attachment', 'remove_attachment', 'relocate_attachment'], true)) {
            $type = str_replace('_attachment', '', $receipt->source_type);
            $class = $this->attachmentClass($type);
            $query = $class::withTrashed()->whereKey($receipt->source_id)->select(['id', $this->parentForeignKey($type)]);
            if ($type === 'inventory') { $query->where('ppe_inventory_id', $root->id); }
            else {
                $parentTable = $type === 'allocation' ? 'ppe_allocations' : 'ppe_inspections';
                $query->whereExists(function ($parent) use ($parentTable, $class, $type, $root) {
                    $parent->selectRaw('1')->from($parentTable)->whereColumn($parentTable.'.id', (new $class)->getTable().'.'.$this->parentForeignKey($type))
                        ->where($parentTable.'.ppe_inventory_id', $root->id)->whereNull($parentTable.'.deleted_at');
                });
            }
            $row = $query->lock('for share nowait')->firstOrFail();
            return [$type, (int) $row->{$this->parentForeignKey($type)}, $operation];
        }
        return [$receipt->root_type, $receipt->root_id, $operation];
    }

    private function assertRemovedSource(PpeCommandReceipt $receipt, Model $parent, int $id): void
    {
        $type = str_replace('_attachment', '', $receipt->source_type);
        $class = $this->attachmentClass($type);
        $attachment = $class::withTrashed()->whereKey($id)->where($this->parentForeignKey($type), $parent->id)->lock('for share nowait')->firstOrFail();
        abort_unless($attachment->trashed() && $receipt->source_id === (int) $attachment->id
            && (int) $attachment->{$this->parentForeignKey($type)} === (int) $parent->id, 404);
    }

    private function originCleanupProjection(PpeEvidenceRelocationCleanup $row, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        abort_unless($row->user_id === (int) $current->id, 404);
        $this->access->assertOperation($current, 'evidence');
        $root = $this->access->assertInventory($row->root_id, $current, $reads, $sites, 'evidence');
        abort_unless((int) $root->site_id === $row->site_id, 404);
        $parent = $row->parent_type === 'inventory' ? $root : $this->source($row->parent_type, $row->parent_id);
        abort_unless($parent instanceof PpeInventory ? (int) $parent->id === $row->parent_id
            : (int) $parent->ppe_inventory_id === (int) $root->id, 404);
        $this->authorize($row->parent_type, $parent, $current, $reads, $sites, 'evidence');
        $this->currentVersion($root, $root);
        if ($parent !== $root) { $this->currentVersion($root, $parent); }
        $receipt = $reads->query(PpeCommandReceipt::query())->whereKey($row->receipt_id)->firstOrFail();
        $this->validatedReceipt($receipt, $root, $current, $reads);
        $version = $reads->query(PpeSourceVersion::query())->whereKey($receipt->source_version_id)->firstOrFail();
        $origin = $version->content['facts']['legacy_origin'] ?? null;
        $after = $version->content['source_after'] ?? null;
        $bytes = ['digest' => $row->file_digest, 'size' => $row->file_size];
        if ($row->purpose !== 'legacy_public_origin' || $row->origin_disk !== 'public'
            || ! in_array($row->state, ['pending', 'unknown', 'complete'], true)
            || $receipt->command !== 'relocate_attachment' || $receipt->root_type !== 'inventory' || $receipt->root_id !== $row->root_id
            || $receipt->source_type !== $row->attachment_type || $receipt->source_id !== $row->attachment_id
            || ! is_array($origin) || ($origin['attachment_type'] ?? null) !== $row->attachment_type
            || ($origin['attachment_id'] ?? null) !== $row->attachment_id || ($origin['parent_type'] ?? null) !== $row->parent_type
            || ($origin['parent_id'] ?? null) !== $row->parent_id || ($origin['disk'] ?? null) !== 'public'
            // Byte facts are compared by canonical digest: stored JSON returns with MySQL's key order.
            || ($origin['path'] ?? null) !== $row->origin_path || ! isset($origin['observed_bytes'])
            || PpeCommandValues::digest($origin['observed_bytes']) !== PpeCommandValues::digest($bytes)
            || ($origin['original_attribution_state'] ?? null) !== 'legacy_unknown'
            || ($origin['original_metadata']['id'] ?? null) !== $row->attachment_id
            || ($origin['original_metadata'][$this->parentForeignKey($row->parent_type)] ?? null) !== $row->parent_id
            || PpeCommandValues::digest($origin['original_metadata'] ?? null) !== PpeCommandValues::digest($version->content['source_before'] ?? null)
            || ($origin['original_metadata']['path'] ?? null) !== $row->origin_path
            || ! in_array($origin['original_metadata']['disk'] ?? null, [null, 'public'], true)
            || ($after['id'] ?? null) !== $row->attachment_id || ($after['disk'] ?? null) !== 'private'
            || ($after['path'] ?? null) !== $row->destination_path
            || ($after[$this->parentForeignKey($row->parent_type)] ?? null) !== $row->parent_id
            || ! isset($version->content['facts']['file_evidence'])
            || PpeCommandValues::digest($version->content['facts']['file_evidence']) !== PpeCommandValues::digest($bytes)
            || $version->site_id !== $row->site_id) {
            throw new LogicException('PPE public-origin cleanup is not bound to its exact transfer acceptance.');
        }
        $class = $this->attachmentClass($row->parent_type);
        $attachment = $reads->query($class::withTrashed())->whereKey($row->attachment_id)->where($this->parentForeignKey($row->parent_type), $row->parent_id)->firstOrFail();
        $this->assertAttachmentRoot($root, $attachment);
        $this->currentVersion($root, $attachment);
        if ((int) $attachment->{$this->parentForeignKey($row->parent_type)} !== $row->parent_id
            || $attachment->disk !== 'private' || $attachment->path !== $row->destination_path) {
            throw new LogicException('Transferred PPE evidence no longer has its canonical private reference.');
        }
        return ['id' => (int) $row->id, 'purpose' => 'legacy_public_origin', 'state' => $row->state,
            'private_reference_recorded' => true, 'public_copy_cleanup' => $row->state];
    }

    public function relocationCleanupForViewer(User $viewer, int $id): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($id) {
            $row = $reads->query(PpeEvidenceRelocationCleanup::query())->whereKey($id)->where('user_id', $current->id)->firstOrFail();
            return $this->originCleanupProjection($row, $current, $reads, $sites);
        });
    }

    public function relocationCleanupAttemptsForViewer(User $viewer, int $afterId = 0): array
    {
        if ($afterId < 0) { PpeCommandValues::invalid('after_id', 'Use a valid transfer-cleanup continuation.'); }
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($afterId) {
            $this->access->assertOperation($current, 'evidence');
            $rows = $reads->query(PpeEvidenceRelocationCleanup::query())->where('user_id', $current->id)->whereIn('site_id', $sites)
                ->whereIn('state', ['pending', 'unknown'])->where('id', '>', $afterId)->orderBy('id')->limit(26)->get();
            $attempts = [];
            foreach ($rows->take(25) as $row) {
                try { $projection = $this->originCleanupProjection($row, $current, $reads, $sites); }
                catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { continue; }
                catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $denial) {
                    if (in_array($denial->getStatusCode(), [403, 404], true)) { continue; }
                    throw $denial;
                }
                $attempts[] = [...$projection, 'recorded_at' => $row->created_at->toIso8601String(), 'can_retry' => true];
            }
            return ['attempts' => $attempts, 'next_cursor' => $rows->count() > 25 ? (int) $rows[24]->id : null];
        });
    }

    public function resumeRelocationCleanup(User $viewer, int $id): array
    {
        $result = $this->relocationCleanupForViewer($viewer, $id);
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->safeOriginPurge($id));
            return $result;
        }
        $this->safeOriginPurge($id);
        return $this->relocationCleanupForViewer($viewer, $id);
    }

    private function safeOriginPurge(int $id): void
    {
        try {
            if (DB::transactionLevel() !== 0) { throw new LogicException('Public PPE cleanup cannot precede physical transfer commit.'); }
            $identity = PpeEvidenceRelocationCleanup::whereKey($id)->select(['id', 'user_id'])->firstOrFail();
            $actor = User::findOrFail($identity->user_id);
            $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($id) {
                $row = PpeEvidenceRelocationCleanup::whereKey($id)->where('user_id', $current->id)->lockForUpdate()->firstOrFail();
                $this->originCleanupProjection($row, $current, $reads, $sites);
                if ($row->state === 'complete') { return null; }
                $state = 'unknown';
                try {
                    $expected = ['digest' => $row->file_digest, 'size' => $row->file_size];
                    // Never erase the public fallback when the accepted private bytes are absent or changed.
                    if (PpeCommandValues::sameBytes($this->storage->bytes($row->destination_path), $expected)) {
                        $state = $this->storage->purgeLegacyOrigin($row->parent_type, $row->origin_path, $expected);
                    }
                } catch (Throwable $failure) { report($failure); }
                $row->forceFill(['state' => $state, 'attempts' => $row->attempts + 1,
                    'completed_at' => $state === 'complete' ? now()->utc() : null]);
                $this->persist($row);
                return null;
            });
        } catch (Throwable $failure) {
            // The accepted reference remains immutable; pending delivery remains durable on authority or save failure.
            report($failure);
        }
    }
    private function cleanupProjection(PpeEvidenceCleanup $cleanup, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        abort_unless($cleanup->user_id === (int) $current->id, 404);
        $this->access->assertOperation($current, 'evidence');
        $receipt = $reads->query(PpeCommandReceipt::query())->whereKey($cleanup->receipt_id)->firstOrFail();
        $root = $reads->query(PpeInventory::query())->whereKey($receipt->root_id)->whereIn('site_id', $sites)->firstOrFail();
        $parent = $cleanup->parent_type === 'inventory' ? $root : $this->source($cleanup->parent_type, $cleanup->parent_id);
        abort_unless($parent instanceof PpeInventory ? (int) $parent->id === $cleanup->parent_id
            : (int) $parent->ppe_inventory_id === (int) $root->id, 404);
        $this->authorize($cleanup->parent_type, $parent, $current, $reads, $sites, 'evidence');
        $this->currentVersion($root, $root);
        if ($parent !== $root) { $this->currentVersion($root, $parent); }
        $this->validatedReceipt($receipt, $root, $current, $reads);
        if ($receipt->command !== 'remove_attachment' || $receipt->user_id !== $cleanup->user_id
            || $receipt->source_type !== $cleanup->attachment_type || $receipt->source_id !== $cleanup->attachment_id) {
            throw new LogicException('PPE cleanup is not bound to its exact removal acceptance.');
        }
        $this->assertRemovedSource($receipt, $parent, $cleanup->attachment_id);
        return ['id' => (int) $cleanup->id, 'state' => $cleanup->state, 'removed_from_record' => true];
    }

    public function cleanupForViewer(User $viewer, int $id): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($id) {
            $cleanup = $reads->query(PpeEvidenceCleanup::query())->whereKey($id)->where('user_id', $current->id)->firstOrFail();
            return $this->cleanupProjection($cleanup, $current, $reads, $sites);
        });
    }

    public function cleanupAttemptsForViewer(User $viewer, int $afterId = 0): array
    {
        if ($afterId < 0) { PpeCommandValues::invalid('after_id', 'Use a valid cleanup continuation.'); }
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($afterId) {
            $this->access->assertOperation($current, 'evidence');
            $rows = $reads->query(PpeEvidenceCleanup::query())->where('user_id', $current->id)
                ->whereIn('state', ['pending', 'unknown'])->where('id', '>', $afterId)
                ->whereExists(function ($query) use ($sites) {
                    $query->selectRaw('1')->from('ppe_command_receipts as pper')
                        ->join('ppe_inventory as ppei', 'ppei.id', '=', 'pper.root_id')
                        ->whereColumn('pper.id', 'ppe_evidence_cleanups.receipt_id')->where('pper.root_type', 'inventory')
                        ->whereNull('ppei.deleted_at')->whereIn('ppei.site_id', $sites);
                })->orderBy('id')->limit(26)->get();
            $attempts = [];
            foreach ($rows->take(25) as $row) {
                try { $projection = $this->cleanupProjection($row, $current, $reads, $sites); }
                catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { continue; }
                catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $denial) {
                    if (in_array($denial->getStatusCode(), [403, 404], true)) { continue; }
                    throw $denial;
                }
                $attempts[] = [...$projection, 'recorded_at' => $row->created_at->toIso8601String(),
                    'can_retry' => $row->disk === 'private'];
            }
            return ['attempts' => $attempts, 'next_cursor' => $rows->count() > 25 ? (int) $rows[24]->id : null];
        });
    }
    public function resumeCleanup(User $viewer, int $id): array
    {
        $result = $this->cleanupForViewer($viewer, $id);
        if (DB::transactionLevel() > 0) {
            $this->schedulePurge($id);
            return $result;
        }
        $this->safePurge($id);
        return $this->cleanupForViewer($viewer, $id);
    }

    private function schedulePurge(int $id): void
    {
        DB::afterCommit(fn () => $this->safePurge($id));
    }

    private function safePurge(int $id): void
    {
        try {
            $identity = PpeEvidenceCleanup::whereKey($id)->select(['id', 'user_id'])->firstOrFail();
            $actor = User::findOrFail($identity->user_id);
            $this->storage->purge($id, function () use ($actor, $id): void {
                $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($id) {
                    $row = $reads->query(PpeEvidenceCleanup::query())->whereKey($id)->where('user_id', $current->id)->firstOrFail();
                    $this->cleanupProjection($row, $current, $reads, $sites);
                    return null;
                });
            });
        } catch (Throwable $failure) {
            // Acceptance already committed; durable pending state remains retryable.
            report($failure);
        }
    }

    private function persist(Model $model): void
    {
        $expected = $this->snapshot($model);
        if (! $model->save() || ! $model->exists || ! $model->getKey()) {
            throw new LogicException('The PPE source write was refused.');
        }
        $saved = $model->newQueryWithoutScopes()->whereKey($model->id)->lockForUpdate()->first();
        if (! $saved) {
            throw new LogicException('The PPE source write was not persisted.');
        }
        // IDs/defaults may be assigned at create; explicit submitted fields must round-trip exactly.
        foreach ($expected as $field => $value) {
            if (! in_array($field, ['id', 'updated_at'], true) && PpeCommandValues::digest($value) !== PpeCommandValues::digest($this->snapshot($saved)[$field] ?? null)) {
                throw new LogicException('The exact PPE source values were not persisted.');
            }
        }
        $model->setRawAttributes($saved->getAttributes(), true);
    }

    private function snapshot(Model $source): array
    {
        if ($source instanceof PpeCommandIntentClaim || $source instanceof PpeSourceVersion || $source instanceof PpeCommandReceipt || $source instanceof PpeEvidenceCleanup || $source instanceof PpeEvidenceStaging || $source instanceof PpeEvidenceRelocationCleanup) {
            return $source->attributesToArray();
        }
        $type = $this->kind($source);
        $fields = self::FIELDS[$type] ?? [...self::EVIDENCE_FIELDS, $this->parentForeignKey(str_replace('_attachment', '', $type))];

        $result = [];
        foreach ([...$fields, 'source_schema_version', 'source_revision'] as $field) {
            if (! array_key_exists($field, $source->getAttributes())) {
                continue;
            }
            $value = $source->getAttribute($field);
            if ($value instanceof \DateTimeInterface) {
                $value = str_ends_with($field, '_date') || in_array($field, ['last_inspected_at', 'next_inspection_due'], true)
                    ? $value->format('Y-m-d') : \Illuminate\Support\Carbon::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
            } elseif ($value !== null && (str_ends_with($field, '_id') || str_ends_with($field, '_by')
                || in_array($field, ['id', 'quantity', 'size', 'source_schema_version', 'source_revision', 'typical_lifespan_months'], true))) {
                $value = (int) $value;
            }
            $result[$field] = $value;
        }
        return $result;
    }

    private function snapshotOriginal(Model $source): array
    {
        $original = $source->newInstance([], true);
        $original->setRawAttributes($source->getRawOriginal(), true);
        return $this->snapshot($original);
    }

    private function payload(array $values): array
    {
        return array_diff_key($values, ['idempotency_key' => true, 'expected_version' => true]);
    }

    private function intent(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return $this->storage->identity($value);
        }
        return is_array($value) ? array_map($this->intent(...), $value) : $value;
    }

    private function id(Model|int $source): int { return $source instanceof Model ? (int) $source->id : $source; }

    private function kind(Model $source): string
    {
        return match (true) {
            $source instanceof PpeType => 'type', $source instanceof PpeInventory => 'inventory', $source instanceof PpeAllocation => 'allocation',
            $source instanceof PpeInspection => 'inspection', $source instanceof PpeAttachment => 'inventory_attachment',
            $source instanceof PpeAllocationAttachment => 'allocation_attachment', $source instanceof PpeInspectionAttachment => 'inspection_attachment',
            $source instanceof PpeSourceVersion, $source instanceof PpeCommandReceipt, $source instanceof PpeEvidenceCleanup => 'ledger',
            default => throw new LogicException('Unknown PPE source type.'),
        };
    }

    private function root(string $type, int $id): \Illuminate\Database\Eloquent\Builder
    {
        $class = match ($type) { 'type' => PpeType::class, 'inventory' => PpeInventory::class,
            default => throw new LogicException('Unknown PPE aggregate type.') };
        return $class::whereKey($id);
    }

    private function source(string $type, int $id, bool $lock = false): Model
    {
        $class = match ($type) { 'type' => PpeType::class, 'inventory' => PpeInventory::class,
            'allocation' => PpeAllocation::class, 'inspection' => PpeInspection::class,
            default => throw new LogicException('Unknown PPE command parent.') };
        return $class::whereKey($id)->lock($lock ? 'for update' : 'for share nowait')->firstOrFail();
    }

    private function authorize(string $type, Model $source, User $actor, CurrentAuthorizationReads $reads, array $sites, string $operation): void
    {
        match ($type) {
            'type' => $this->access->assertType($source->id, $actor, $reads, $operation),
            'inventory' => $this->access->assertInventory($source->id, $actor, $reads, $sites, $operation),
            'allocation' => $this->access->assertAllocation($source->id, $actor, $reads, $sites, $operation),
            'inspection' => $this->access->assertInspection($source->id, $actor, $reads, $sites, $operation),
            default => throw new LogicException('Unknown PPE command authority.'),
        };
    }

    private function attachmentClass(string $type): string
    {
        return match ($type) { 'inventory' => PpeAttachment::class, 'allocation' => PpeAllocationAttachment::class,
            'inspection' => PpeInspectionAttachment::class, default => throw new LogicException('Unknown PPE evidence parent.') };
    }

    private function parentForeignKey(string $type): string
    {
        return match ($type) { 'inventory' => 'ppe_inventory_id', 'allocation' => 'ppe_allocation_id',
            'inspection' => 'ppe_inspection_id', default => throw new LogicException('Unknown PPE evidence parent.') };
    }

    private function translateDuplicate(Throwable $failure): void
    {
        if ($failure instanceof QueryException && str_contains($failure->getMessage(), 'ppe_receipt_uuid_uq')) {
            PpeCommandValues::invalid('idempotency_key', 'Another PPE command accepted this key. Recover its exact receipt before retrying.');
        }
    }
}
