<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalEvidenceCleanup;
use App\Models\ChemicalEvidenceStaging;
use App\Models\ChemicalSourceVersion;
use App\Models\SafetyDataSheet;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use LogicException;

/** Durable physical intents precede private bytes; accepted or historically referenced bytes are never cleanup candidates. */
final class ChemicalEvidenceStorage
{
    public function descriptor(UploadedFile $file): array
    {
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240']])->validate();
        $size = $file->getSize();
        $digest = hash_file('sha256', $file->getRealPath());
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if ($size === false || $digest === false || $size < 1 || $name === '' || strlen($name) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            ChemicalCommandValues::invalid('file', 'Use a nonempty document with a safe original filename.');
        }
        return ['sha256' => $digest, 'size' => (int) $size, 'mime' => $file->getMimeType(), 'original_name' => $name];
    }

    public function reserve(ChemicalCommandIntent $intent, int $parentId, array $commandValues, CurrentAuthorizationReads $reads): ChemicalEvidenceStaging
    {
        $reads->assertActive();
        $descriptor = $commandValues['file_evidence'] ?? null;
        if (! is_array($descriptor) || $intent->source_type !== 'sds' || $intent->command !== 'upload_sds'
            || $intent->source_id !== null || $intent->parent_id !== $parentId
            || ChemicalCommandValues::fingerprint('sds', null, $parentId, $intent->actor_id, 'upload_sds', $commandValues) !== $intent->fingerprint) {
            throw new LogicException('Chemical physical reservation requires its exact admitted command.');
        }
        $row = ChemicalEvidenceStaging::where('intent_id', $intent->id)->lockForUpdate()->first();
        if ($row) {
            $this->assertIntent($row);
            if ($row->parent_id !== $parentId || $row->actor_id !== $intent->actor_id || $row->idempotency_key !== $intent->idempotency_key
                || ChemicalCommandValues::digest($descriptor) !== ChemicalCommandValues::digest($this->facts($row))) {
                throw new LogicException('Chemical physical intent was rebound.');
            }
            if ($row->state === 'cleaning') { throw new LogicException('Chemical physical cleanup must finish before an exact retry.'); }
            if ($row->state === 'cleaned') {
                $row->state = 'reserved';
                if (! $row->save()) { throw new LogicException('Chemical physical retry reservation was vetoed.'); }
            }
            return $row;
        }
        $row = new ChemicalEvidenceStaging(['intent_id' => $intent->id, 'parent_id' => $parentId, 'actor_id' => $intent->actor_id,
            'idempotency_key' => $intent->idempotency_key, 'disk' => 'private',
            'path' => 'health-safety/chemical-evidence/'.$parentId.'/'.$intent->idempotency_key.'/document',
            ...$descriptor, 'command_values' => $commandValues, 'state' => 'reserved', 'attempts' => 0]);
        if (! $row->save()) { throw new LogicException('Chemical physical reservation was vetoed.'); }
        $stored = ChemicalEvidenceStaging::whereKey($row->id)->lockForUpdate()->first();
        if (! $stored || $stored->path !== $row->path || ChemicalCommandValues::digest($this->facts($stored)) !== ChemicalCommandValues::digest($descriptor)) {
            throw new LogicException('Chemical physical reservation was not persisted.');
        }
        return $stored;
    }

    /** Called only after the outermost reservation transaction has committed. */
    public function put(ChemicalEvidenceStaging $stage, UploadedFile $file): void
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Chemical bytes require an outermost committed reservation.'); }
        // A second transaction serializes physical writers/cleanup AFTER the durable reservation committed.
        DB::transaction(function () use ($stage, $file) {
            $this->assertIntent($stage); // Claim before staging, matching command reservation/acceptance lock order.
            $current = ChemicalEvidenceStaging::whereKey($stage->id)->lockForUpdate()->firstOrFail();
            $this->assertPath($current);
            $this->assertIntent($current);
            $this->assertWritePath($current->path);
            if (in_array($current->state, ['accepted', 'cleaned', 'cleaning'], true)) {
                throw new LogicException('An accepted or cleanup-bound chemical file cannot be overwritten.');
            }
            if (! $this->verified($current->disk, $current->path, $current->sha256, $current->size)) {
                $path = Storage::disk($current->disk)->putFileAs(dirname($current->path), $file, basename($current->path));
                if ($path !== $current->path || ! $this->verified($current->disk, $current->path, $current->sha256, $current->size)) {
                    throw new LogicException('Chemical staged bytes were not stored exactly.');
                }
            }
            $current->state = 'stored';
            if (! $current->save()) { throw new LogicException('Verified chemical physical state was not retained.'); }
        });
    }

    public function bind(ChemicalEvidenceStaging $stage, ChemicalCommandReceipt $receipt, CurrentAuthorizationReads $reads): void
    {
        $reads->assertActive();
        $row = ChemicalEvidenceStaging::whereKey($stage->id)->lockForUpdate()->firstOrFail();
        $this->assertPath($row);
        $this->assertIntent($row);
        $source = SafetyDataSheet::whereKey($receipt->source_id)->where('hazardous_substance_id', $row->parent_id)->lockForUpdate()->first();
        if ($row->state !== 'stored' || $row->intent_id !== $receipt->intent_id || $row->parent_id !== $receipt->parent_id
            || $row->actor_id !== $receipt->actor_id || $receipt->command !== 'upload_sds'
            || $row->idempotency_key !== $receipt->idempotency_key || ! $source
            || $source->source_schema_version !== 1 || $source->source_revision !== ($receipt->accepted_result['version'] ?? null)
            || $source->document_disk !== $row->disk || $source->document_path !== $row->path
            || $source->document_sha256 !== $row->sha256 || $source->document_size !== $row->size
            || $source->document_mime !== $row->mime || $source->original_name !== $row->original_name
            || ! $this->verified($row->disk, $row->path, $row->sha256, $row->size)) {
            throw new LogicException('Chemical acceptance cannot bind unverified physical evidence.');
        }
        $row->forceFill(['state' => 'accepted', 'receipt_id' => $receipt->id, 'source_id' => $receipt->source_id,
            'source_version_id' => $receipt->source_version_id, 'attempts' => $row->attempts + 1]);
        if (! $row->save()) { throw new LogicException('Chemical physical acceptance binding was vetoed.'); }
        $stored = ChemicalEvidenceStaging::whereKey($row->id)->lockForUpdate()->firstOrFail();
        if ($stored->state !== 'accepted' || $stored->receipt_id !== (int) $receipt->id || $stored->source_version_id !== $receipt->source_version_id) {
            throw new LogicException('Chemical physical acceptance binding was not persisted.');
        }
    }

    public function facts(ChemicalEvidenceStaging $stage): array
    {
        return ['sha256' => $stage->sha256, 'size' => $stage->size, 'mime' => $stage->mime, 'original_name' => $stage->original_name];
    }

    public function verified(string $disk, string $path, ?string $sha256, ?int $size): bool
    {
        if ($disk !== 'private' || $path === '' || str_contains($path, '..') || str_contains($path, '\\')
            || str_starts_with($path, '/') || $sha256 === null || $size === null || ! Storage::disk($disk)->exists($path)
            || ! $this->resolvedPrivateFile($path) || Storage::disk($disk)->size($path) !== $size) { return false; }
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) { return false; }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            return hash_equals($sha256, hash_final($hash));
        } finally { fclose($stream); }
    }

    /** Existing private source bytes remain explicitly legacy; existence does not fabricate a historical hash. */
    public function legacyAvailable(int $parentId, string $disk, string $path): bool
    {
        return $disk === 'private' && str_starts_with($path, 'health-safety/sds/'.$parentId.'/')
            && ! str_contains($path, '..') && ! str_contains($path, '\\')
            && Storage::disk('private')->exists($path) && $this->resolvedPrivateFile($path);
    }

    /** Failure retains a durable recovery/cleanup record, not an unaudited success or eager deletion. */
    public function markFailed(int $stageId): void
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Chemical failure recovery must follow the outer transaction.'); }
        DB::transaction(function () use ($stageId) {
            $row = ChemicalEvidenceStaging::whereKey($stageId)->lockForUpdate()->firstOrFail();
            if (in_array($row->state, ['accepted', 'cleaning', 'cleaned'], true)) { return; }
            $row->forceFill(['state' => 'failed', 'attempts' => $row->attempts + 1]);
            if (! $row->save()) { throw new LogicException('Chemical staging failure was not retained.'); }
            $this->cleanupRecord($row);
        });
    }

    /** Caller has current management and the canonical parent lock. Two-phase state prevents upload/cleanup races. */
    public function prepareCleanup(ChemicalEvidenceStaging $stage, CurrentAuthorizationReads $reads): ?array
    {
        $reads->assertActive();
        $row = ChemicalEvidenceStaging::whereKey($stage->id)->lockForUpdate()->firstOrFail();
        $this->assertPath($row);
        $this->assertIntent($row);
        if ($this->retainedReference($row) || $row->receipt_id !== null || $row->state === 'accepted') { return null; }
        $cleanup = $this->cleanupRecord($row);
        $cleanup->state = 'pending';
        if (! $cleanup->save()) { throw new LogicException('Chemical cleanup delivery intent was vetoed.'); }
        $row->state = 'cleaning';
        if (! $row->save()) { throw new LogicException('Chemical cleanup claim was vetoed.'); }
        return ['id' => (int) $row->id, 'disk' => $row->disk, 'path' => $row->path];
    }

    public function finishCleanup(array $claim, callable $currentAuthority): void
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Physical chemical cleanup must follow outer commit.'); }
        DB::transaction(function () use ($claim, $currentAuthority) {
            $currentAuthority(); // Re-admit actor and canonical parent after the prior outer commit.
            $stored = ChemicalEvidenceStaging::whereKey($claim['id'])->lockForUpdate()->firstOrFail();
            $this->assertPath($stored);
            $this->assertIntent($stored);
            if ($stored->state !== 'cleaning' || $stored->disk !== $claim['disk'] || $stored->path !== $claim['path']
                || $stored->receipt_id !== null || $this->retainedReference($stored)) {
                throw new LogicException('Chemical cleanup has no unreferenced exact durable claim.');
            }
            if (Storage::disk($stored->disk)->exists($stored->path)
                && (! $this->verified($stored->disk, $stored->path, $stored->sha256, $stored->size)
                    || ! Storage::disk($stored->disk)->delete($stored->path) || Storage::disk($stored->disk)->exists($stored->path))) {
                throw new LogicException('Chemical physical cleanup remains pending.');
            }
            $stored->state = 'cleaned';
            if (! $stored->save()) { throw new LogicException('Chemical cleanup completion was vetoed.'); }
            $cleanup = ChemicalEvidenceCleanup::where('staging_id', $stored->id)->lockForUpdate()->firstOrFail();
            $cleanup->forceFill(['state' => 'completed', 'attempts' => $cleanup->attempts + 1]);
            if (! $cleanup->save() || $cleanup->fresh()?->state !== 'completed') {
                throw new LogicException('Chemical cleanup delivery completion was not retained.');
            }
        });
    }

    private function cleanupRecord(ChemicalEvidenceStaging $stage): ChemicalEvidenceCleanup
    {
        $row = ChemicalEvidenceCleanup::firstOrCreate(['staging_id' => $stage->id], ['state' => 'pending', 'attempts' => 0]);
        if (! $row->exists || ! ChemicalEvidenceCleanup::whereKey($row->id)->where('staging_id', $stage->id)->lockForUpdate()->first(['id'])) {
            throw new LogicException('Chemical cleanup delivery intent was not persisted.');
        }
        return $row;
    }

    /** Identity-only retention, including soft-deleted sources and the configured local/private alias. */
    private function retainedReference(ChemicalEvidenceStaging $stage): bool
    {
        $path = $stage->path;
        foreach (['workplace_injury_attachments', 'first_aid_attachments', 'emergency_drill_attachments',
            'hs_risk_assessment_attachments', 'restraint_event_attachments', 'ppe_attachments', 'ppe_allocation_attachments',
            'ppe_inspection_attachments', 'client_incident_attachments', 'fleet_incident_attachments',
            'safeguarding_attachments', 'privacy_attachments', 'hs_attachments'] as $table) {
            if (! Schema::hasTable($table)) { continue; }
            $query = DB::table($table)->where('path', $path)->where(function ($disk) use ($table) {
                $disk->whereIn('disk', ['private', 'local']);
                if ($table === 'hs_attachments') { $disk->orWhereNull('disk'); }
            });
            if ($query->lock('for share nowait')->first(['id'])) { return true; }
        }
        foreach ([['hr_documents', 'storage_path', 'storage_disk'], ['hr_documents', 'signed_document_path', 'storage_disk'],
            ['client_documents', 'storage_path', 'storage_disk'], ['site_documents', 'storage_path', 'storage_disk'],
            ['asset_documents', 'storage_path', 'storage_disk'], ['safety_data_sheets', 'document_path', 'document_disk'],
            ['chemical_evidence_staging', 'path', 'disk'], ['ppe_evidence_staging', 'path', 'disk'],
            ['chemical_exposure_evidence', 'path', 'disk'], ['chemical_exposure_evidence_staging', 'path', 'disk'],
            ['chemical_exposure_evidence_cleanups', 'path', 'disk'],
            ['ppe_evidence_cleanups', 'path', 'disk'], ['ppe_evidence_relocation_cleanups', 'destination_path', null],
            ['it_attachments', 'path', null], ['it_attachment_storage_intents', 'path', null],
            ['governance_action_evidence', 'path', 'disk'], ['compliance_evidence', 'file_path', null],
            ['audit_evidence_packs', 'file_path', null], ['control_room_evidence_items', 'storage_path', null],
            ['control_room_evidence_packs', 'export_path', null], ['client_consents', 'signed_document_path', 'signed_document_disk'],
            ['hr_staff_compliance_status', 'evidence_path', 'evidence_disk'], ['hr_performance_reviews', 'evidence_path', null],
            ['hr_goals', 'evidence_path', null], ['work_capacity_assessments', 'document_path', null],
            ['medication_downtime_sheets', 'path', null], ['medication_order_files', 'file_path', null],
            ['medication_stock_photos', 'path', null], ['medication_support_agreements', 'attachment_path', null]] as [$table, $column, $diskColumn]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) { continue; }
            $query = DB::table($table)->where($column, $path);
            if ($diskColumn !== null) { $query->where(fn ($disk) => $disk->whereIn($diskColumn, ['private', 'local'])->orWhereNull($diskColumn)); }
            if ($table === 'chemical_evidence_staging') { $query->where('id', '!=', $stage->id); }
            if ($query->lock('for share nowait')->first(['id'])) { return true; }
        }
        foreach ([['chemical_source_versions', 'content->source_after->document_path'],
            ['chemical_source_versions', 'content->source_before->document_path'],
            ['chemical_exposure_source_versions', 'content->source_after->path'],
            ['chemical_exposure_source_versions', 'content->source_before->path'],
            ['ppe_source_versions', 'content->source_after->path'], ['ppe_source_versions', 'content->source_before->path'],
            ['workplace_injury_source_versions', 'content->after->path'], ['workplace_injury_source_versions', 'content->before->path'],
            ['workplace_injury_source_versions', 'content->after->document_path'], ['workplace_injury_source_versions', 'content->before->document_path']] as [$table, $column]) {
            if (Schema::hasTable($table) && DB::table($table)->where($column, $path)->lock('for share nowait')->first(['id'])) { return true; }
        }
        if (Schema::hasTable('ppe_source_versions') && DB::table('ppe_source_versions')
            ->whereJsonContains('content->facts->documents', ['after' => ['path' => $path, 'disk' => 'private']])
            ->lock('for share nowait')->first(['id'])) { return true; }
        if (Schema::hasTable('site_hazards')) {
            foreach (['photo_paths', 'document_paths', 'resolution_evidence'] as $column) {
                if (Schema::hasColumn('site_hazards', $column) && DB::table('site_hazards')
                    ->where(fn ($query) => $query->whereJsonContains($column, $path)->orWhereJsonContains($column, ['path' => $path]))
                    ->lock('for share nowait')->first(['id'])) { return true; }
            }
        }
        return false;
    }

    private function assertPath(ChemicalEvidenceStaging $stage): void
    {
        if ($stage->disk !== 'private' || $stage->parent_id < 1 || ! \Illuminate\Support\Str::isUuid($stage->idempotency_key)
            || $stage->idempotency_key !== strtolower($stage->idempotency_key)
            || $stage->path !== 'health-safety/chemical-evidence/'.$stage->parent_id.'/'.$stage->idempotency_key.'/document') {
            throw new LogicException('Chemical staging has no canonical private path.');
        }
    }

    private function assertIntent(ChemicalEvidenceStaging $stage): void
    {
        $intent = ChemicalCommandIntent::whereKey($stage->intent_id)->where('actor_id', $stage->actor_id)
            ->where('source_type', 'sds')->lock('for share nowait')->first();
        if (! $intent || $intent->source_id !== null || $intent->command !== 'upload_sds'
            || $intent->parent_id !== $stage->parent_id || $intent->idempotency_key !== $stage->idempotency_key
            || ! is_array($stage->command_values)
            || ($stage->command_values['idempotency_key'] ?? null) !== $intent->idempotency_key
            || ($stage->command_values['expected_version'] ?? null) !== $intent->expected_version
            || ChemicalCommandValues::digest($stage->command_values['file_evidence'] ?? null) !== ChemicalCommandValues::digest($this->facts($stage))
            || ChemicalCommandValues::fingerprint('sds', null, $stage->parent_id, $stage->actor_id, 'upload_sds', $stage->command_values) !== $intent->fingerprint) {
            throw new LogicException('Chemical staged source no longer proves its original immutable intent.');
        }
    }

    private function resolvedPrivateFile(string $path): bool
    {
        if (config('filesystems.disks.private.driver') !== 'local') { return false; }
        $disk = Storage::disk('private'); $root = realpath($disk->path('')); $file = realpath($disk->path($path));
        $normal = fn ($value) => str_replace('\\', '/', $value);
        return $root !== false && $file !== false && ! is_link($disk->path($path))
            && strcasecmp($normal($file), rtrim($normal($root), '/').'/'.$path) === 0;
    }

    private function assertWritePath(string $path): void
    {
        if (config('filesystems.disks.private.driver') !== 'local') { throw new LogicException('Chemical staged bytes require the configured private local store.'); }
        $disk = Storage::disk('private'); $root = realpath($disk->path(''));
        if ($root === false) { throw new LogicException('Chemical private storage root is unavailable.'); }
        $normal = fn ($value) => str_replace('\\', '/', $value);
        $parts = explode('/', dirname($path)); $relative = '';
        foreach ($parts as $part) {
            $relative = $relative === '' ? $part : $relative.'/'.$part;
            $directory = $disk->path($relative);
            if (is_link($directory)) { throw new LogicException('Chemical staging cannot traverse a linked directory.'); }
            $actual = realpath($directory);
            if ($actual !== false && strcasecmp($normal($actual), rtrim($normal($root), '/').'/'.$relative) !== 0) {
                throw new LogicException('Chemical staging directory left its canonical private root.');
            }
        }
    }
}
