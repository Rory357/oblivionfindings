<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalEvidenceStaging;
use App\Models\ChemicalSourceVersion;
use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/** Public source commands. Neither catalogue authority nor these receipts authorize identifiable exposures. */
final class ChemicalCommandService
{
    public function __construct(private readonly ChemicalAccessService $access, private readonly ChemicalCommandLedgerService $ledger,
        private readonly ChemicalEvidenceStorage $evidence) {}

    public function create(User $actor, array $input): array
    {
        $values = ChemicalCommandValues::normalize($input, $this->catalogueRules(true), true);
        return $this->access->withCurrentViewer($actor, function ($current, $reads) use ($values) {
            $this->access->assertOperation($current, 'capture');
            $intent = $this->ledger->claim($current, $reads, 'catalogue', null, null, 'create_catalogue', $values);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                $root = $this->catalogue($receipt->root_id, $current, $reads, 'capture');
                $this->currentVersion($root, $root, $reads);
                return $this->ledger->reconstruct($receipt, $intent, $root, $root, $current, $reads);
            }
            $root = new HazardousSubstance($this->payload($values));
            $root->forceFill(['status' => 'active', 'source_schema_version' => 1, 'source_revision' => 1,
                'created_by' => $current->id, 'updated_by' => $current->id]);
            $this->save($root);
            $version = $this->ledger->version($intent, $root, $root, $current, $reads, null, null);
            return $this->ledger->accept($intent, $root, $root, $version, $current, $reads, true);
        });
    }

    public function update(HazardousSubstance|int $substance, User $actor, array $input): array
    {
        $values = ChemicalCommandValues::normalize($input, $this->catalogueRules(false));
        return $this->catalogueCommand($substance, $actor, 'update_catalogue', $values,
            fn ($root, $payload) => $root->fill($payload));
    }

    public function status(HazardousSubstance|int $substance, User $actor, array $input): array
    {
        $values = ChemicalCommandValues::normalize($input, ['status' => ['required', 'string', 'in:active,inactive,removed'],
            'reason' => ['required', 'string', 'max:2000']]);
        $this->reason($values);
        return $this->catalogueCommand($substance, $actor, 'change_status', $values,
            fn ($root, $payload) => $root->fill(['status' => $payload['status'],
                'status_reason' => $payload['status'] === 'active' ? null : $payload['reason']]));
    }

    public function createStorage(HazardousSubstance|int $substance, User $actor, array $input): array
    {
        if (array_key_exists('expected_version', $input)) {
            if ($input['expected_version'] !== null) { ChemicalCommandValues::invalid('expected_version', 'New storage has no prior storage revision; review the separate parent revision.'); }
            unset($input['expected_version']);
        }
        $values = ChemicalCommandValues::normalize($input, $this->storageRules(true), true);
        $this->storageValues($values);
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($substance, $values) {
            $parent = $this->catalogue($substance, $current, $reads, 'capture');
            $this->access->assertSite($values['site_id'], $reads, $sites);
            $this->currentVersion($parent, $parent, $reads);
            $intent = $this->ledger->claim($current, $reads, 'storage', null, (int) $parent->id, 'create_storage', $values);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                $source = $this->storage($parent, $receipt->source_id, $current, $reads, $sites, 'capture', true);
                $this->currentVersion($source, $source, $reads);
                return $this->ledger->reconstruct($receipt, $intent, $source, $source, $current, $reads);
            }
            $this->expected($parent, $values['parent_expected_version'], 'parent_expected_version');
            $source = new SubstanceStorageLocation(['properly_labelled' => false, 'segregation_compliant' => false, ...$this->payload($values),
                'hazardous_substance_id' => $parent->id, 'created_by' => $current->id, 'updated_by' => $current->id]);
            $source->forceFill(['source_schema_version' => 1, 'source_revision' => 1]);
            $this->save($source);
            $version = $this->ledger->version($intent, $source, $source, $current, $reads, null, null);
            return $this->ledger->accept($intent, $source, $source, $version, $current, $reads, true);
        });
    }

    public function updateStorage(HazardousSubstance|int $substance, SubstanceStorageLocation|int $storage, User $actor, array $input): array
    {
        $values = ChemicalCommandValues::normalize($input, $this->storageRules(false));
        $this->storageValues($values);
        return $this->storageCommand($substance, $storage, $actor, 'update_storage', $values,
            function ($source, $payload, $reads, $sites) {
                if (isset($payload['site_id'])) { $this->access->assertSite($payload['site_id'], $reads, $sites); }
                return $source->fill($payload);
            });
    }

    public function auditInventory(HazardousSubstance|int $substance, SubstanceStorageLocation|int $storage, User $actor, array $input): array
    {
        $values = ChemicalCommandValues::normalize($input, ['parent_expected_version' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'current_quantity' => ['present', 'nullable'], 'properly_labelled' => ['required', 'boolean'],
            'segregation_compliant' => ['required', 'boolean'], 'last_audit_date' => ['required', 'string'],
            'storage_notes' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $this->storageValues($values);
        return $this->storageCommand($substance, $storage, $actor, 'audit_inventory', $values,
            fn ($source, $payload) => $source->fill($payload));
    }

    public function archiveStorage(HazardousSubstance|int $substance, SubstanceStorageLocation|int $storage, User $actor, array $input): array
    {
        return $this->storageArchive($substance, $storage, $actor, $input, false);
    }

    public function restoreStorage(HazardousSubstance|int $substance, SubstanceStorageLocation|int $storage, User $actor, array $input): array
    {
        return $this->storageArchive($substance, $storage, $actor, $input, true);
    }

    public function uploadSds(HazardousSubstance|int $substance, User $actor, UploadedFile $file, array $input): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Chemical SDS upload cannot run inside an outer transaction.');
        }
        $values = ChemicalCommandValues::normalize($input, $this->sdsRules(true));
        $this->sdsDates($values);
        $values['file_evidence'] = $this->evidence->descriptor($file);
        // This transaction must commit before durable private bytes exist. Only scalars leave the callback.
        $reservation = $this->access->withCurrentViewer($actor, function ($current, $reads) use ($substance, $values) {
            $parent = $this->catalogue($substance, $current, $reads, 'capture');
            $this->currentVersion($parent, $parent, $reads);
            $intent = $this->ledger->claim($current, $reads, 'sds', null, (int) $parent->id, 'upload_sds', $values);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                $source = $this->sds($parent, $receipt->source_id);
                $this->currentVersion($parent, $source, $reads);
                return ['accepted' => $this->ledger->reconstruct($receipt, $intent, $parent, $source, $current, $reads)];
            }
            $this->expected($parent, $values['expected_version']);
            $stage = $this->evidence->reserve($intent, (int) $parent->id, $values, $reads);
            return ['stage_id' => (int) $stage->id];
        });
        if (isset($reservation['accepted'])) { return $reservation['accepted']; }
        $stageId = $reservation['stage_id'];
        try {
            $stage = ChemicalEvidenceStaging::whereKey($stageId)->firstOrFail();
            $this->evidence->put($stage, $file);
            return $this->acceptStagedSds($substance, $actor, $values, $stageId);
        } catch (\Throwable $failure) {
            $this->evidence->markFailed($stageId);
            throw $failure;
        }
    }

    /** Lost acceptance recovery uses retained exact intent; no new file or invented catalogue revision. */
    public function recoverSds(HazardousSubstance|int $substance, User $actor, string $key): ?array
    {
        if (! Str::isUuid($key)) { return null; }
        return $this->access->withCurrentViewer($actor, function ($current, $reads) use ($substance, $key) {
            $parent = $this->catalogue($substance, $current, $reads, 'capture');
            $this->currentVersion($parent, $parent, $reads);
            $stage = ChemicalEvidenceStaging::where('idempotency_key', strtolower($key))->where('parent_id', $parent->id)
                ->where('actor_id', $current->id)->lockForUpdate()->first();
            if (! $stage) { return null; }
            $intent = ChemicalCommandIntent::whereKey($stage->intent_id)->where('actor_id', $current->id)
                ->where('source_type', 'sds')->lockForUpdate()->firstOrFail();
            $receipt = $this->ledger->receipt($intent, $reads);
            if (! $receipt) { return null; } // A reserved/failed upload remains unaccepted; exact original upload can retry it.
            $source = $this->sds($parent, $receipt->source_id);
            $this->currentVersion($parent, $source, $reads);
            return $this->ledger->reconstruct($receipt, $intent, $parent, $source, $current, $reads);
        });
    }

    public function correctSdsMetadata(HazardousSubstance|int $substance, SafetyDataSheet|int $sds, User $actor, array $input): array
    {
        $values = ChemicalCommandValues::normalize($input, [...$this->sdsRules(false), 'reason' => ['required', 'string', 'max:2000']]);
        $this->sdsDates($values);
        $this->reason($values);
        return $this->sdsCommand($substance, $sds, $actor, 'correct_sds_metadata', $values, false);
    }

    public function setCurrentSds(HazardousSubstance|int $substance, SafetyDataSheet|int $sds, User $actor, array $input): array
    {
        $values = ChemicalCommandValues::normalize($input, ['reason' => ['required', 'string', 'max:2000']]);
        $this->reason($values);
        return $this->sdsCommand($substance, $sds, $actor, 'set_current_sds', $values, true);
    }

    /** Internal authenticated-download descriptor only; never serialize disk/path as a workspace prop or receipt. */
    public function downloadProof(HazardousSubstance|int $substance, SafetyDataSheet|int $sds, User $viewer): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads) use ($substance, $sds) {
            $parent = $this->catalogue($substance, $current, $reads, 'view');
            $sheet = $this->sds($parent, $sds);
            $proof = $this->fileProof($parent, $sheet, $reads);
            return [...$proof, 'substance_id' => (int) $parent->id, 'sds_id' => (int) $sheet->id,
                'disk' => $proof['available'] ? $sheet->document_disk : null,
                'path' => $proof['available'] ? $sheet->document_path : null,
                'filename' => $proof['available'] ? ($sheet->original_name ?: basename($sheet->document_path)) : null];
        });
    }

    public function cleanupSds(HazardousSubstance|int $substance, User $actor, string $key): bool
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Chemical cleanup requires an outermost command.'); }
        if (! Str::isUuid($key)) { return false; }
        $claim = $this->access->withCurrentViewer($actor, function ($current, $reads) use ($substance, $key) {
            $parent = $this->catalogue($substance, $current, $reads, 'manage');
            $this->currentVersion($parent, $parent, $reads);
            $stage = ChemicalEvidenceStaging::where('parent_id', $parent->id)->where('actor_id', $current->id)
                ->where('idempotency_key', strtolower($key))->lockForUpdate()->first();
            return $stage ? $this->evidence->prepareCleanup($stage, $reads) : null;
        });
        if ($claim === null) { return false; }
        $this->evidence->finishCleanup($claim, fn () => $this->access->withCurrentViewer($actor, function ($current, $reads) use ($substance) {
            $parent = $this->catalogue($substance, $current, $reads, 'manage');
            $this->currentVersion($parent, $parent, $reads);
            return true;
        }));
        return true;
    }

    public function confirmationForViewer(User $viewer, string $kind, int $sourceId, string $key): ?array
    {
        if (! Str::isUuid($key) || ! in_array($kind, ['catalogue', 'storage', 'sds'], true)) { return null; }
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($kind, $sourceId, $key) {
            // Authorize the named source before querying its actor-bound receipt.
            $this->access->assertOperation($current, 'capture');
            if ($kind === 'storage') {
                $source = SubstanceStorageLocation::withTrashed()->whereIn('site_id', $sites)->whereKey($sourceId)
                    ->firstOrFail(['id', 'hazardous_substance_id', 'site_id']);
                $this->access->assertSite((int) $source->site_id, $reads, $sites);
                $parent = $this->catalogue((int) $source->hazardous_substance_id, $current, $reads, 'capture');
                $source = $this->storage($parent, $sourceId, $current, $reads, $sites, 'capture', true);
                $root = $source;
                $this->currentVersion($parent, $parent, $reads);
            } elseif ($kind === 'sds') {
                $identity = SafetyDataSheet::whereKey($sourceId)->firstOrFail(['id', 'hazardous_substance_id']);
                $root = $this->catalogue((int) $identity->hazardous_substance_id, $current, $reads, 'capture');
                $source = $this->sds($root, $sourceId);
            } else {
                $root = $source = $this->catalogue($sourceId, $current, $reads, 'capture');
            }
            $this->currentVersion($root, $source, $reads);
            $receipt = ChemicalCommandReceipt::where('idempotency_key', strtolower($key))->where('actor_id', $current->id)
                ->where('source_type', $kind)->where('source_id', $sourceId)->lockForUpdate()->first();
            if (! $receipt) { return null; }
            $this->access->assertOperation($current, $this->captureCommand($receipt->command) ? 'capture' : 'manage');
            $intent = ChemicalCommandIntent::whereKey($receipt->intent_id)->where('actor_id', $current->id)
                ->whereIn('source_type', ChemicalCommandLedgerService::PUBLIC_SOURCE_TYPES)->lockForUpdate()->firstOrFail();
            return $this->ledger->reconstruct($receipt, $intent, $root, $source, $current, $reads);
        });
    }

    /** Additive reader seam. The caller consumes its current actor/sites inside the same active access callback. */
    public function proofForCurrentReads(HazardousSubstance|int $substance, SafetyDataSheet|SubstanceStorageLocation|null $source,
        User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $reads->assertActive();
        $parent = $this->catalogue($substance, $current, $reads, 'view');
        $row = $source instanceof SubstanceStorageLocation
            ? $this->storage($parent, $source, $current, $reads, $sites, 'view', true)
            : ($source instanceof SafetyDataSheet ? $this->sds($parent, $source) : $parent);
        $root = $row instanceof SubstanceStorageLocation ? $row : $parent;
        $base = ['root_type' => $this->ledger->kind($root), 'root_id' => (int) $root->id,
            'source_type' => $this->ledger->kind($row), 'source_id' => (int) $row->id,
            'parent_id' => $row instanceof HazardousSubstance ? null : (int) $parent->id,
            'expected_version' => (int) $root->source_revision,
            'parent_expected_version' => $row instanceof SubstanceStorageLocation ? (int) $parent->source_revision : null,
            'source_revision' => (int) $row->source_revision];
        try {
            $this->currentVersion($parent, $parent, $reads);
            $version = $this->currentVersion($root, $row, $reads);
        } catch (LogicException) {
            return [...$base, 'expected_version' => null, 'parent_expected_version' => null, 'source_revision' => null,
                'source_integrity' => 'repair_required', 'attribution_state' => 'repair_required',
                'source_version_id' => null, 'record' => null, 'current_sds_id' => null,
                'current_sds' => ['state' => 'repair_required', 'id' => null, 'available' => false, 'currency' => 'unknown']];
        }
        $fields = $row instanceof HazardousSubstance ? [...array_keys($this->catalogueRules(false)), 'status', 'status_reason']
            : ($row instanceof SubstanceStorageLocation ? ['site_id', 'location_description', 'current_quantity', 'maximum_quantity',
                'quantity_unit', 'container_type', 'properly_labelled', 'segregation_compliant', 'last_audit_date', 'storage_notes', 'deleted_at']
                : ['version', 'issue_date', 'review_date', 'supplier_name', 'supplier_contact', 'status', 'original_name', 'document_mime', 'document_size']);
        $fields = array_values(array_filter($fields, fn ($key) => ! str_contains($key, '*')));
        $record = $row->only(['id', 'created_at', 'updated_at', ...$fields]);
        foreach (['created_at', 'updated_at', 'issue_date', 'review_date', 'last_audit_date', 'deleted_at'] as $key) {
            if (($record[$key] ?? null) instanceof \DateTimeInterface) {
                $record[$key] = in_array($key, ['created_at', 'updated_at', 'deleted_at'], true)
                    ? $record[$key]->format(DATE_ATOM) : $record[$key]->format('Y-m-d');
            }
        }
        $currentSds = $this->currentSdsProjection($parent, $reads);
        if ($row instanceof SafetyDataSheet) { $record['file'] = $this->fileProof($parent, $row, $reads); }
        return [...$base, 'source_integrity' => $version ? 'valid' : 'legacy_unknown',
            'attribution_state' => $version ? 'recorded' : 'legacy_unknown', 'source_version_id' => $version?->id,
            'record' => $record, 'current_sds_id' => $currentSds['id'], 'current_sds' => $currentSds];
    }

    /** Historical storage facts require their recorded Site as well as the current canonical source Site. */
    public function historyForCurrentReads(HazardousSubstance|int $substance, SafetyDataSheet|SubstanceStorageLocation|null $source,
        User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $proof = $this->proofForCurrentReads($substance, $source, $current, $reads, $sites);
        if ($proof['source_integrity'] === 'repair_required') { return ['source_integrity' => 'repair_required', 'items' => null]; }
        $query = ChemicalSourceVersion::where('source_type', $proof['source_type'])->where('source_id', $proof['source_id']);
        if ($proof['source_type'] === 'catalogue') {
            $query = ChemicalSourceVersion::where('root_type', 'catalogue')->where('root_id', $proof['root_id']);
        }
        $items = [];
        foreach ($query->orderByDesc('aggregate_revision')->orderByDesc('id')->lock('for share nowait')->get() as $version) {
            if ($version->root_type !== $proof['root_type'] || $version->root_id !== $proof['root_id']
                || ($proof['source_type'] !== 'catalogue' && $version->parent_id !== $proof['parent_id'])) {
                return ['source_integrity' => 'repair_required', 'items' => null];
            }
            $visible = true;
            if ($version->site_id !== null) {
                $visible = in_array($version->site_id, $sites, true)
                    && $reads->query(\App\Models\Site::query())->active()->notArchived()->whereNull('archived_at')->whereKey($version->site_id)->exists();
            }
            if (! $visible) { continue; }
            try { $this->ledger->assertAcceptedVersion($version); }
            catch (LogicException) { return ['source_integrity' => 'repair_required', 'items' => null]; }
            $items[] = ['id' => (int) $version->id, 'source_type' => $version->source_type, 'source_id' => $version->source_id,
                'site_id' => $version->site_id, 'version' => $version->aggregate_revision, 'expected_version' => $version->expected_version,
                'parent_expected_version' => $version->parent_expected_version, 'command' => $version->command,
                'recorded_at' => $version->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'actor' => $version->actor_snapshot, 'reason' => $version->content['facts']['reason'] ?? null];
        }
        return ['source_integrity' => $proof['source_integrity'], 'items' => $items];
    }

    private function currentSdsProjection(HazardousSubstance $parent, CurrentAuthorizationReads $reads): array
    {
        if ($parent->current_sds_id === null) {
            return ['state' => (int) $parent->source_schema_version === 0 ? 'legacy_unknown' : 'none',
                'id' => null, 'available' => false, 'currency' => 'unknown'];
        }
        $row = SafetyDataSheet::where('hazardous_substance_id', $parent->id)->whereKey($parent->current_sds_id)->lock('for share nowait')->first();
        if (! $row) { return ['state' => 'repair_required', 'id' => null, 'available' => false, 'currency' => 'unknown']; }
        $file = $this->fileProof($parent, $row, $reads);
        if ($file['attribution_state'] !== 'recorded' || $row->status !== 'current') {
            return ['state' => $file['attribution_state'] === 'legacy_unknown' ? 'legacy_unknown' : 'repair_required',
                'id' => null, 'available' => false, 'currency' => 'unknown'];
        }
        $today = now($this->timezone())->toDateString();
        if ($row->issue_date->toDateString() > $today) {
            return ['state' => 'not_yet_effective', 'id' => (int) $row->id, 'available' => $file['available'], 'currency' => 'not_yet_effective'];
        }
        $currency = $row->review_date === null ? 'unknown' : ($row->review_date->toDateString() < $today ? 'overdue'
            : ($row->review_date->toDateString() <= now($this->timezone())->addDays(SafetyDataSheet::REVIEW_HORIZON_DAYS)->toDateString() ? 'expiring' : 'current'));
        return ['state' => $file['available'] ? 'recorded' : 'unavailable', 'id' => (int) $row->id,
            'available' => $file['available'], 'currency' => $file['available'] ? $currency : 'unknown'];
    }

    /** Full current proof, not a legacy marker shortcut; unrelated aggregate bookkeeping may advance. */
    public function currentVersion(Model $root, Model $source, CurrentAuthorizationReads $reads): ?ChemicalSourceVersion
    {
        $reads->assertActive();
        if ($root->getConnection() !== DB::connection() || $source->getConnection() !== DB::connection()) {
            throw new LogicException('Chemical source proof uses a different physical connection.');
        }
        $rootKind = $this->ledger->kind($root);
        $kind = $this->ledger->kind($source);
        if ((int) $source->source_schema_version === 0) {
            $retained = ChemicalSourceVersion::where(fn ($query) => $query->where('source_type', $kind)->where('source_id', $source->id)
                ->orWhere(fn ($rootQuery) => $rootQuery->where('root_type', $kind)->where('root_id', $source->id)))->lockForUpdate()->exists();
            if ((int) $source->source_revision !== 0 || $retained) { throw new LogicException('Governed chemical source cannot downgrade to legacy.'); }
            if ($source->id !== $root->id || $kind !== $rootKind) { $this->currentVersion($root, $root, $reads); }
            return null;
        }
        if ((int) $source->source_schema_version !== 1 || (int) $source->source_revision < 1) {
            throw new LogicException('Chemical source revision is unconfirmed.');
        }
        if ($kind !== $rootKind || $source->id !== $root->id) { $this->currentVersion($root, $root, $reads); }
        $sameRoot = $kind === $rootKind && $source->id === $root->id;
        $query = $sameRoot ? ChemicalSourceVersion::where('root_type', $rootKind)->where('root_id', $root->id)
            : ChemicalSourceVersion::where('source_type', $kind)->where('source_id', $source->id);
        $version = $query->orderByDesc('aggregate_revision')->orderByDesc('id')->lockForUpdate()->first();
        if (! $version || $version->root_type !== $rootKind || $version->root_id !== (int) $root->id
            || $version->aggregate_revision !== (int) $source->source_revision
            || $version->aggregate_revision > (int) $root->source_revision
            || (! $sameRoot && $version->parent_id !== (int) $source->hazardous_substance_id)
            || ($root instanceof SubstanceStorageLocation && $version->parent_id !== (int) $root->hazardous_substance_id)
            || $version->site_id !== ($root instanceof SubstanceStorageLocation ? (int) $root->site_id : null)) {
            throw new LogicException('Chemical source version tuple is unconfirmed.');
        }
        $this->ledger->assertAcceptedVersion($version);
        if (ChemicalCommandValues::digest($version->content[$sameRoot ? 'root_after' : 'source_after'] ?? null) !== ChemicalCommandValues::digest($this->ledger->snapshot($source))) {
            throw new LogicException('Chemical source was changed outside an accepted command.');
        }
        if ($sameRoot) {
            $latest = ChemicalSourceVersion::where('root_type', $rootKind)->where('root_id', $root->id)
                ->orderByDesc('aggregate_revision')->orderByDesc('id')->lockForUpdate()->first();
            if (! $latest || $latest->aggregate_revision !== (int) $root->source_revision) {
                throw new LogicException('Chemical aggregate version is missing.');
            }
            $this->ledger->assertAcceptedVersion($latest);
            if (ChemicalCommandValues::digest($latest->content['root_after'] ?? null) !== ChemicalCommandValues::digest($this->ledger->snapshot($root))) {
                throw new LogicException('Chemical aggregate after-state is damaged.');
            }
        }
        return $version;
    }

    private function catalogueCommand(HazardousSubstance|int $substance, User $actor, string $command, array $values, callable $mutate): array
    {
        return $this->access->withCurrentViewer($actor, function ($current, $reads) use ($substance, $command, $values, $mutate) {
            $root = $this->catalogue($substance, $current, $reads, 'manage');
            $this->currentVersion($root, $root, $reads);
            $intent = $this->ledger->claim($current, $reads, 'catalogue', (int) $root->id, null, $command, $values);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                return $this->ledger->reconstruct($receipt, $intent, $root, $root, $current, $reads);
            }
            $this->expected($root, $values['expected_version']);
            $before = $this->ledger->snapshot($root);
            $mutate($root, $this->payload($values));
            $changed = $root->isDirty();
            $version = null;
            if ($changed) {
                $root->forceFill(['source_schema_version' => 1, 'source_revision' => $root->source_revision + 1, 'updated_by' => $current->id]);
                $this->save($root);
                $version = $this->ledger->version($intent, $root, $root, $current, $reads, $before, $before, ['reason' => $values['reason'] ?? null]);
            }
            return $this->ledger->accept($intent, $root, $root, $version, $current, $reads, $changed);
        });
    }

    private function storageCommand(HazardousSubstance|int $substance, SubstanceStorageLocation|int $storage, User $actor,
        string $command, array $values, callable $mutate, bool $archived = false): array
    {
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($substance, $storage, $command, $values, $mutate, $archived) {
            $parent = $this->catalogue($substance, $current, $reads, 'manage');
            $source = $this->storage($parent, $storage, $current, $reads, $sites, 'manage', true);
            $this->currentVersion($parent, $parent, $reads);
            $this->currentVersion($source, $source, $reads);
            $intent = $this->ledger->claim($current, $reads, 'storage', (int) $source->id, (int) $parent->id, $command, $values);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                return $this->ledger->reconstruct($receipt, $intent, $source, $source, $current, $reads);
            }
            if (! $archived && $source->trashed()) { abort(404); }
            $this->expected($parent, $values['parent_expected_version'], 'parent_expected_version');
            $this->expected($source, $values['expected_version']);
            $before = $this->ledger->snapshot($source);
            $mutate($source, $this->payload($values), $reads, $sites);
            $changed = $source->isDirty();
            $version = null;
            if ($changed) {
                $source->forceFill(['source_schema_version' => 1, 'source_revision' => $source->source_revision + 1, 'updated_by' => $current->id]);
                $this->save($source);
                $version = $this->ledger->version($intent, $source, $source, $current, $reads, $before, $before, ['reason' => $values['reason'] ?? null]);
            }
            return $this->ledger->accept($intent, $source, $source, $version, $current, $reads, $changed);
        });
    }

    private function storageArchive(HazardousSubstance|int $substance, SubstanceStorageLocation|int $storage, User $actor, array $input, bool $restore): array
    {
        $values = ChemicalCommandValues::normalize($input, ['parent_expected_version' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'reason' => ['required', 'string', 'max:2000']]);
        $this->reason($values);
        return $this->storageCommand($substance, $storage, $actor, $restore ? 'restore_storage' : 'archive_storage', $values,
            function ($source) use ($restore) {
                if ($restore) { $source->deleted_at = null; }
                elseif (! $source->trashed()) { $source->deleted_at = now()->utc(); }
            }, true);
    }

    private function acceptStagedSds(HazardousSubstance|int $substance, User $actor, array $values, int $stageId): array
    {
        return $this->access->withCurrentViewer($actor, function ($current, $reads) use ($substance, $values, $stageId) {
            $parent = $this->catalogue($substance, $current, $reads, 'capture');
            $this->currentVersion($parent, $parent, $reads);
            $intent = $this->ledger->claim($current, $reads, 'sds', null, (int) $parent->id, 'upload_sds', $values);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                $source = $this->sds($parent, $receipt->source_id);
                $this->currentVersion($parent, $source, $reads);
                return $this->ledger->reconstruct($receipt, $intent, $parent, $source, $current, $reads);
            }
            $this->expected($parent, $values['expected_version']);
            $stage = ChemicalEvidenceStaging::whereKey($stageId)->where('intent_id', $intent->id)->lockForUpdate()->firstOrFail();
            if ($stage->state !== 'stored' || $stage->actor_id !== (int) $current->id || $stage->parent_id !== (int) $parent->id
                || $stage->idempotency_key !== $intent->idempotency_key
                || $stage->disk !== 'private' || $stage->path !== 'health-safety/chemical-evidence/'.$parent->id.'/'.$intent->idempotency_key.'/document'
                || ChemicalCommandValues::digest($this->evidence->facts($stage)) !== ChemicalCommandValues::digest($values['file_evidence'])
                || ! $this->evidence->verified($stage->disk, $stage->path, $stage->sha256, $stage->size)) {
                throw new LogicException('Only committed, verified staged SDS bytes may be accepted.');
            }
            $before = $this->ledger->snapshot($parent);
            $future = $values['issue_date'] > now($this->timezone())->toDateString();
            $source = new SafetyDataSheet([...$this->payload($values), 'hazardous_substance_id' => $parent->id,
                'document_path' => $stage->path, 'status' => $future ? 'not_yet_effective' : 'current', 'uploaded_by' => $current->id,
                'created_by' => $current->id, 'updated_by' => $current->id]);
            $source->forceFill(['document_disk' => $stage->disk, 'document_sha256' => $stage->sha256, 'document_size' => $stage->size,
                'document_mime' => $stage->mime, 'original_name' => $stage->original_name,
                'source_schema_version' => 1, 'source_revision' => $parent->source_revision + 1]);
            $this->save($source);
            $previous = $future ? [] : $this->supersede($parent, $source, $current, $reads);
            $parent->forceFill(['current_sds_id' => $future ? $parent->current_sds_id : $source->id, 'source_schema_version' => 1,
                'source_revision' => $parent->source_revision + 1, 'updated_by' => $current->id]);
            $this->save($parent);
            $siblings = $this->retainSuperseded($intent, $parent, $previous, $current, $reads, $before);
            $version = $this->ledger->version($intent, $parent, $source, $current, $reads, $before, null,
                ['file_evidence' => $this->evidence->facts($stage), 'superseded_versions' => $siblings]);
            $result = $this->ledger->accept($intent, $parent, $source, $version, $current, $reads, true);
            $receipt = $this->ledger->receipt($intent, $reads);
            $this->evidence->bind($stage, $receipt, $reads);
            return $result;
        });
    }

    private function sdsCommand(HazardousSubstance|int $substance, SafetyDataSheet|int $sds, User $actor,
        string $command, array $values, bool $setCurrent): array
    {
        return $this->access->withCurrentViewer($actor, function ($current, $reads) use ($substance, $sds, $command, $values, $setCurrent) {
            $parent = $this->catalogue($substance, $current, $reads, 'manage');
            $source = $this->sds($parent, $sds);
            $this->currentVersion($parent, $source, $reads);
            $intent = $this->ledger->claim($current, $reads, 'sds', (int) $source->id, (int) $parent->id, $command, $values);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                return $this->ledger->reconstruct($receipt, $intent, $parent, $source, $current, $reads);
            }
            $this->expected($parent, $values['expected_version']);
            $parentBefore = $this->ledger->snapshot($parent);
            $sourceBefore = $this->ledger->snapshot($source);
            $previous = [];
            if ($setCurrent) {
                if ($source->issue_date === null) { ChemicalCommandValues::invalid('sds_id', 'Record a real printed issue date before selecting this SDS.'); }
                $dates = ['issue_date' => $source->issue_date->toDateString(), 'review_date' => $source->review_date?->toDateString()];
                $this->sdsDates($dates);
                if ($source->issue_date->toDateString() > now($this->timezone())->toDateString()) {
                    ChemicalCommandValues::invalid('sds_id', 'This printed SDS issue date is not yet effective.');
                }
                if ((int) $source->source_schema_version === 0) { $this->observeLegacyBytes($source); }
                if (! $this->evidence->verified($source->document_disk, $source->document_path, $source->document_sha256, $source->document_size)) {
                    ChemicalCommandValues::invalid('sds_id', 'This retained SDS has no verified available document.');
                }
                $source->status = 'current';
                $previous = $this->supersede($parent, $source, $current, $reads);
                $parent->current_sds_id = $source->id;
            } else {
                $source->fill($this->payload($values));
                $dates = ['issue_date' => $source->issue_date?->toDateString(), 'review_date' => $source->review_date?->toDateString()];
                $this->sdsDates($dates);
                if ($dates['issue_date'] > now($this->timezone())->toDateString()) {
                    $source->status = 'not_yet_effective';
                    if ((int) $parent->current_sds_id === (int) $source->id) { $parent->current_sds_id = null; }
                }
            }
            $changed = $source->isDirty() || $parent->isDirty() || $previous !== [];
            $version = null;
            if ($changed) {
                $revision = $parent->source_revision + 1;
                $source->forceFill(['source_schema_version' => 1, 'source_revision' => $revision, 'updated_by' => $current->id]);
                $parent->forceFill(['source_schema_version' => 1, 'source_revision' => $revision, 'updated_by' => $current->id]);
                $this->save($source); $this->save($parent);
                $siblings = $this->retainSuperseded($intent, $parent, $previous, $current, $reads, $parentBefore);
                $version = $this->ledger->version($intent, $parent, $source, $current, $reads, $parentBefore, $sourceBefore,
                    ['reason' => $values['reason'], 'file_evidence' => ['sha256' => $source->document_sha256, 'size' => $source->document_size,
                        'mime' => $source->document_mime, 'original_name' => $source->original_name], 'superseded_versions' => $siblings]);
            }
            return $this->ledger->accept($intent, $parent, $source, $version, $current, $reads, $changed);
        });
    }

    private function supersede(HazardousSubstance $parent, SafetyDataSheet $target, User $current, CurrentAuthorizationReads $reads): array
    {
        $rows = SafetyDataSheet::where('hazardous_substance_id', $parent->id)->where('id', '!=', $target->id)
            ->where('status', 'current')->orderBy('id')->lockForUpdate()->get();
        $previous = [];
        foreach ($rows as $row) {
            $this->currentVersion($parent, $row, $reads);
            $previous[] = ['row' => $row, 'before' => $this->ledger->snapshot($row)];
            $row->forceFill(['status' => 'superseded', 'source_schema_version' => 1,
                'source_revision' => $parent->source_revision + 1, 'updated_by' => $current->id]);
            $this->save($row);
        }
        return $previous;
    }

    private function retainSuperseded(ChemicalCommandIntent $intent, HazardousSubstance $parent, array $rows,
        User $current, CurrentAuthorizationReads $reads, array $parentBefore): array
    {
        $retained = [];
        foreach ($rows as $row) {
            $version = $this->ledger->version($intent, $parent, $row['row'], $current, $reads, $parentBefore, $row['before'], ['superseded_by' => (int) $parent->current_sds_id]);
            $retained[] = ['id' => (int) $version->id, 'digest' => $version->content_digest];
        }
        return $retained;
    }

    private function catalogue(HazardousSubstance|int $id, User $current, CurrentAuthorizationReads $reads, string $operation): HazardousSubstance
    {
        return $this->access->assertCatalogue($id, $current, $reads, $operation, true);
    }

    private function storage(HazardousSubstance $parent, SubstanceStorageLocation|int $id, User $current,
        CurrentAuthorizationReads $reads, array $sites, string $operation, bool $archived): SubstanceStorageLocation
    {
        $this->access->assertOperation($current, $operation);
        $this->physical($id);
        $row = SubstanceStorageLocation::withTrashed()->where('hazardous_substance_id', $parent->id)->whereIn('site_id', $sites)
            ->whereKey($id instanceof Model ? $id->id : $id)->lockForUpdate()->firstOrFail();
        $this->access->assertSite((int) $row->site_id, $reads, $sites);
        if (! $archived && $row->trashed()) { abort(404); }
        return $row;
    }

    private function sds(HazardousSubstance $parent, SafetyDataSheet|int $id): SafetyDataSheet
    {
        $this->physical($id);
        return SafetyDataSheet::where('hazardous_substance_id', $parent->id)->whereKey($id instanceof Model ? $id->id : $id)->lockForUpdate()->firstOrFail();
    }

    private function physical(Model|int $source): void
    {
        if ($source instanceof Model && $source->getConnection() !== DB::connection()) {
            throw new LogicException('Chemical source identity uses a different physical connection.');
        }
    }

    private function save(Model $row): void
    {
        // The semantic command audit replaces generic field-diff duplication for these accepted writes.
        if (! $row->saveQuietly()) { throw new LogicException('Chemical canonical source save failed.'); }
        $row->refresh();
    }

    private function expected(Model $root, int $expected, string $field = 'expected_version'): void
    {
        if ($expected !== (int) $root->source_revision) {
            ChemicalCommandValues::invalid($field, 'This chemical source changed. Reload its current version before submitting.');
        }
    }

    private function payload(array $values): array
    {
        return array_diff_key($values, array_flip(['idempotency_key', 'expected_version', 'parent_expected_version', 'reason', 'file_evidence']));
    }

    private function reason(array $values): void
    {
        if (trim($values['reason'] ?? '') === '') { ChemicalCommandValues::invalid('reason', 'Record the reason for this lifecycle or factual correction.'); }
    }

    private function day(mixed $value, string $field, bool $actual = false): ?string
    {
        $day = ChemicalCommandValues::day($value, $field);
        if ($actual && $day !== null && $day > now($this->timezone())->toDateString()) {
            ChemicalCommandValues::invalid($field, 'An actual recorded fact cannot be dated in the future.');
        }
        return $day;
    }

    private function timezone(): string
    {
        $zone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
        try { new DateTimeZone($zone); } catch (\Exception $invalid) { throw new LogicException('The configured chemical worker timezone is invalid.', 0, $invalid); }
        return $zone;
    }

    private function storageValues(array &$values): void
    {
        foreach (['current_quantity', 'maximum_quantity'] as $field) {
            if (array_key_exists($field, $values)) { $values[$field] = ChemicalCommandValues::decimalQuantity($values[$field], $field); }
        }
        if (array_key_exists('last_audit_date', $values)) { $values['last_audit_date'] = $this->day($values['last_audit_date'], 'last_audit_date', true); }
    }

    private function sdsDates(array &$values): void
    {
        foreach (['issue_date', 'review_date'] as $field) {
            if (array_key_exists($field, $values)) { $values[$field] = $this->day($values[$field], $field); }
        }
        if (isset($values['issue_date'], $values['review_date']) && $values['review_date'] <= $values['issue_date']) {
            ChemicalCommandValues::invalid('review_date', 'The recorded review date must follow the issued date.');
        }
    }

    private function observeLegacyBytes(SafetyDataSheet $source): void
    {
        if (! $this->evidence->legacyAvailable((int) $source->hazardous_substance_id, $source->document_disk, $source->document_path)) {
            ChemicalCommandValues::invalid('sds_id', 'This legacy SDS has no available private document.');
        }
        $stream = Storage::disk('private')->readStream($source->document_path);
        if (! is_resource($stream)) { throw new LogicException('Legacy chemical document bytes are unavailable.'); }
        try { $hash = hash_init('sha256'); hash_update_stream($hash, $stream); $digest = hash_final($hash); }
        finally { fclose($stream); }
        $source->forceFill(['document_sha256' => $digest, 'document_size' => Storage::disk('private')->size($source->document_path),
            'document_mime' => Storage::disk('private')->mimeType($source->document_path)]);
    }

    private function fileProof(HazardousSubstance $parent, SafetyDataSheet $sheet, CurrentAuthorizationReads $reads): array
    {
        try {
            $version = $this->currentVersion($parent, $sheet, $reads);
            $legacy = $version === null;
            $available = $legacy ? $this->evidence->legacyAvailable((int) $parent->id, $sheet->document_disk, $sheet->document_path)
                : $this->evidence->verified($sheet->document_disk, $sheet->document_path, $sheet->document_sha256, $sheet->document_size);
            return ['available' => $available, 'attribution_state' => $legacy ? 'legacy_unknown' : 'recorded',
                'source_version_id' => $version?->id, 'source_revision' => (int) $sheet->source_revision];
        } catch (LogicException) {
            return ['available' => false, 'attribution_state' => 'repair_required', 'source_version_id' => null, 'source_revision' => null];
        }
    }

    private function captureCommand(string $command): bool { return in_array($command, ['create_catalogue', 'create_storage', 'upload_sds'], true); }

    private function catalogueRules(bool $create): array
    {
        $rules = ['name' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'physical_form' => [$create ? 'required' : 'sometimes', 'string', 'in:solid,liquid,gas,powder,aerosol,paste,other'],
            'is_controlled_substance' => ['sometimes', 'boolean'], 'requires_tracking' => ['sometimes', 'boolean'],
            'hazard_classifications' => ['sometimes', 'nullable', 'array'], 'hazard_classifications.*' => ['string', 'max:100'],
            'ghs_pictograms' => ['sometimes', 'nullable', 'array'], 'ghs_pictograms.*' => ['string', 'max:20']];
        foreach (['common_name' => 255, 'un_number' => 50, 'hsno_approval' => 100, 'hsno_classification' => 255, 'signal_word' => 32,
            'hazard_statements' => 5000, 'precautionary_statements' => 5000, 'ppe_required' => 5000, 'storage_requirements' => 5000,
            'handling_precautions' => 5000, 'first_aid_measures' => 5000, 'firefighting_measures' => 5000, 'spill_procedures' => 5000,
            'exposure_limit_type' => 50, 'exposure_limit_value' => 100] as $key => $max) { $rules[$key] = ['sometimes', 'nullable', 'string', 'max:'.$max]; }
        return $rules;
    }

    private function storageRules(bool $create): array
    {
        return ['parent_expected_version' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'site_id' => [$create ? 'required' : 'sometimes', 'integer', 'min:1'],
            'location_description' => [$create ? 'required' : 'sometimes', 'string', 'max:500'],
            'current_quantity' => ['sometimes', 'nullable'], 'maximum_quantity' => ['sometimes', 'nullable'],
            'quantity_unit' => ['sometimes', 'nullable', 'string', 'max:50'], 'container_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'properly_labelled' => ['sometimes', 'boolean'], 'segregation_compliant' => ['sometimes', 'boolean'],
            'last_audit_date' => ['sometimes', 'nullable', 'string'], 'storage_notes' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }

    private function sdsRules(bool $create): array
    {
        return ['version' => [$create ? 'required' : 'sometimes', 'string', 'max:50'],
            'issue_date' => [$create ? 'required' : 'sometimes', 'string'], 'review_date' => ['sometimes', 'nullable', 'string'],
            'supplier_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'supplier_contact' => ['sometimes', 'nullable', 'string', 'max:255']];
    }
}
