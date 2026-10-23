<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\PpeEvidenceCleanup;
use App\Models\PpeEvidenceStaging;
use App\Models\PpeCommandReceipt;
use App\Models\PpeSourceVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

final class PpeEvidenceStorage
{
    public function identity(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $digest = $path ? hash_file('sha256', $path) : false;
        if (! $file->isValid() || ! $digest) {
            PpeCommandValues::invalid('file', 'The staged evidence could not be read. Keep the file and retry.');
        }
        return ['original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(),
            'size' => $file->getSize(), 'digest' => $digest];
    }

    /** The planned path was physically committed before this write starts. */
    public function stageReserved(UploadedFile $file, PpeEvidenceStaging $attempt): array
    {
        $identity = $this->identity($file);
        if ($attempt->receipt_id !== null || $attempt->state !== 'staged' || $attempt->disk !== 'private'
            || $attempt->file_digest !== $identity['digest'] || $attempt->file_size !== $identity['size']) {
            throw new LogicException('The PPE upload does not match its reserved attempt.');
        }
        $disk = Storage::disk('private');
        // Generated per-attempt path; never overwrite bytes, including an interrupted earlier attempt.
        if ($disk->exists($attempt->path)) {
            throw new LogicException('The reserved PPE path already contains bytes; inspect that attempt before retrying.');
        }
        $path = $disk->putFileAs(dirname($attempt->path), $file, basename($attempt->path));
        if ($path !== $attempt->path || $this->bytes($attempt->path) !== ['digest' => $identity['digest'], 'size' => $identity['size']]) {
            throw new LogicException('The private evidence bytes were not durably confirmed.');
        }
        return ['disk' => 'private', 'path' => $attempt->path, ...$identity];
    }
    private const PUBLIC_ATTACHMENT_TABLES = ['workplace_injury_attachments', 'first_aid_attachments', 'emergency_drill_attachments',
        'hs_risk_assessment_attachments', 'restraint_event_attachments', 'ppe_attachments', 'ppe_allocation_attachments', 'ppe_inspection_attachments',
        'client_incident_attachments', 'fleet_incident_attachments', 'safeguarding_attachments', 'privacy_attachments'];

    /** Only the actual historical PPE hashed upload paths; no caller-supplied disk or path. */
    public function legacyPublicBytes(string $parentType, string $path): ?array
    {
        $folder = match ($parentType) { 'inventory' => 'ppe_attachments', 'allocation' => 'ppe_allocation_attachments',
            'inspection' => 'ppe_inspection_attachments', default => throw new LogicException('Unknown PPE legacy origin.') };
        if (! preg_match('~^'.$folder.'/[A-Za-z0-9]{40}\.[A-Za-z0-9]{1,10}$~D', $path)
            || config('filesystems.disks.public.driver') !== 'local') {
            PpeCommandValues::invalid('attachment', 'The retained legacy path cannot be admitted for a bounded PPE transfer.');
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) { return null; }
        $root = realpath($disk->path('')); $file = realpath($disk->path($path));
        $normal = fn ($value) => str_replace('\\', '/', $value);
        if (! $root || ! $file || is_link($disk->path($path))
            || strcasecmp($normal($file), rtrim($normal($root), '/').'/'.$path) !== 0) {
            PpeCommandValues::invalid('attachment', 'The retained public path does not resolve to its exact canonical PPE file.');
        }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) { throw new LogicException('Legacy PPE bytes could not be read.'); }
        try {
            $hash = hash_init('sha256'); $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) { throw new LogicException('Legacy PPE stream could not be completely read.'); }
                $size += strlen($chunk);
                if ($size > 20971520) { PpeCommandValues::invalid('attachment', 'Legacy transfer is bounded to the existing twenty-megabyte PPE evidence limit.'); }
                hash_update($hash, $chunk);
            }
            return ['digest' => hash_final($hash), 'size' => $size];
        } finally { fclose($stream); }
    }

    /** Identity-only exact-path collision check, including deleted rows; no other source content. */
    public function publicPathReferenced(string $path, ?string $exceptTable = null, ?int $exceptId = null): bool
    {
        foreach (self::PUBLIC_ATTACHMENT_TABLES as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) { continue; }
            $query = DB::table($table)->where('path', $path)->where(fn ($disk) => $disk->where('disk', 'public')->orWhereNull('disk'));
            if ($exceptTable === $table) { $query->where('id', '!=', $exceptId); }
            if ($query->lock('for share nowait')->exists()) { return true; }
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('site_hazards')) {
            foreach (['photo_paths', 'document_paths', 'resolution_evidence'] as $column) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('site_hazards', $column)) { continue; }
                if (DB::table('site_hazards')->where(fn ($query) => $query->whereJsonContains($column, $path)
                    ->orWhereJsonContains($column, ['path' => $path]))->lock('for share nowait')->exists()) { return true; }
            }
        }
        return false;
    }

    public function stageLegacyReserved(PpeEvidenceStaging $attempt): array
    {
        $origin = $attempt->legacy_origin;
        if ($attempt->receipt_id !== null || $attempt->state !== 'staged' || ! is_array($origin)
            || $attempt->disk !== 'private' || $this->legacyPublicBytes($attempt->source_type, $origin['path'])
                !== ['digest' => $attempt->file_digest, 'size' => $attempt->file_size]) {
            throw new LogicException('PPE legacy bytes no longer match their committed transfer intent.');
        }
        $private = Storage::disk('private');
        if ($private->exists($attempt->path)) { throw new LogicException('The reserved transfer destination already contains bytes.'); }
        $stream = Storage::disk('public')->readStream($origin['path']);
        if (! is_resource($stream)) { throw new LogicException('The PPE transfer origin could not be opened.'); }
        try { $written = $private->writeStream($attempt->path, $stream); }
        finally { fclose($stream); }
        if (! $written || $this->bytes($attempt->path) !== ['digest' => $attempt->file_digest, 'size' => $attempt->file_size]) {
            throw new LogicException('Private PPE transfer bytes were not durably confirmed.');
        }
        return ['disk' => 'private', 'path' => $attempt->path];
    }
    /** Only after an accepted private copy and current canonical authority have been proved by the caller. */
    public function purgeLegacyOrigin(string $parentType, string $path, array $expected): string
    {
        if (DB::transactionLevel() < 1) { throw new LogicException('Public PPE cleanup requires locked delivery authority.'); }
        $actual = $this->legacyPublicBytes($parentType, $path);
        if ($actual === null) { return 'complete'; }
        if ($actual !== $expected || $this->publicPathReferenced($path)) { return 'unknown'; }
        $disk = Storage::disk('public');
        return $disk->delete($path) && ! $disk->exists($path) ? 'complete' : 'pending';
    }
    public function bytes(string $path): ?array
    {
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            return null;
        }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new LogicException('PPE evidence bytes could not be read.');
        }
        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);
            return ['digest' => hash_final($hash), 'size' => $size];
        } finally {
            fclose($stream);
        }
    }

    private function validRemoval(PpeEvidenceCleanup $row): bool
    {
        $receipt = PpeCommandReceipt::whereKey($row->receipt_id)->lockForUpdate()->first();
        $version = $receipt?->source_version_id ? PpeSourceVersion::whereKey($receipt->source_version_id)->lock('for share nowait')->first() : null;
        if (! $receipt || ! $version || $receipt->command !== 'remove_attachment'
            || $receipt->user_id !== $row->user_id || $receipt->source_type !== $row->attachment_type
            || $receipt->source_id !== $row->attachment_id || $version->source_type !== $row->attachment_type
            || $version->source_id !== $row->attachment_id || $version->idempotency_key !== $receipt->idempotency_key
            || ! hash_equals($version->content_digest, PpeCommandValues::digest($version->content))) {
            return false;
        }
        $result = $receipt->accepted_result;
        $fields = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id', 'source_type', 'source_id', 'expected_version', 'version', 'source_version_id'];
        $tuple = array_intersect_key($version->attributesToArray(), array_flip(['root_type', 'root_id', 'source_type', 'source_id', 'site_id', 'person_id', 'aggregate_revision', 'command', 'idempotency_key', 'user_id']));
        $audit = AuditLog::whereKey($receipt->audit_log_id)->lock('for share nowait')->first();
        if (! is_array($result) || count($result) !== 11 || array_diff(array_keys($result), $fields)
            || ($result['accepted'] ?? null) !== true || ($result['actor_id'] ?? null) !== $row->user_id
            || ($result['command'] ?? null) !== 'remove_attachment' || ($result['root_type'] ?? null) !== 'inventory'
            || ($result['root_id'] ?? null) !== $receipt->root_id || ($result['source_type'] ?? null) !== $row->attachment_type
            || ($result['source_id'] ?? null) !== $row->attachment_id || ($result['idempotency_key'] ?? null) !== $receipt->idempotency_key
            || ($result['expected_version'] ?? null) !== $receipt->expected_version || $receipt->expected_version === null
            || ($result['version'] ?? null) !== $receipt->expected_version + 1
            || ($result['source_version_id'] ?? null) !== $version->id || $version->aggregate_revision !== $result['version']
            || $version->root_type !== 'inventory' || $version->root_id !== $receipt->root_id || $version->user_id !== $row->user_id
            || $version->command !== 'remove_attachment' || PpeCommandValues::digest($version->content['tuple'] ?? null) !== PpeCommandValues::digest($tuple)
            || ! $audit || (int) $audit->user_id !== $row->user_id || $audit->action !== 'ppe.remove_attachment'
            || $audit->auditable_type !== (new \App\Models\PpeInventory)->getMorphClass() || (int) $audit->auditable_id !== $receipt->root_id
            || PpeCommandValues::digest($audit->meta) !== PpeCommandValues::digest([...$result, 'fingerprint' => $receipt->fingerprint])) {
            return false;
        }
        $after = $version->content['source_after'] ?? [];
        $bytes = $version->content['facts']['file_evidence'] ?? null;
        $foreign = match ($row->parent_type) {
            'inventory' => 'ppe_inventory_id', 'allocation' => 'ppe_allocation_id', 'inspection' => 'ppe_inspection_id', default => null,
        };
        $class = match ($row->parent_type) {
            'inventory' => \App\Models\PpeAttachment::class, 'allocation' => \App\Models\PpeAllocationAttachment::class,
            'inspection' => \App\Models\PpeInspectionAttachment::class, default => null,
        };
        if (! $foreign || ! $class || ($after[$foreign] ?? null) !== $row->parent_id
            || ($after['path'] ?? null) !== $row->path || ($after['disk'] ?? null) !== 'private'
            || empty($after['deleted_at']) || ($bytes['digest'] ?? null) !== $row->file_digest
            || ($bytes['size'] ?? null) !== $row->file_size) {
            return false;
        }
        $attachment = $class::withTrashed()->whereKey($row->attachment_id)->where($foreign, $row->parent_id)->lock('for share nowait')->first();
        return $attachment && $attachment->trashed() && (int) $attachment->$foreign === $row->parent_id
            && $attachment->disk === 'private' && $attachment->path === $row->path;
    }

    /** Exact-path identities only, including deleted rows, durable delivery pointers and the configured local/private root alias. */
    private function retainedPrivateReference(?PpeEvidenceCleanup $removed, ?PpeEvidenceStaging $unaccepted = null): bool
    {
        $path = $removed?->path ?? $unaccepted->path;
        $table = $removed === null ? null : match ($removed->attachment_type) { 'inventory_attachment' => 'ppe_attachments',
            'allocation_attachment' => 'ppe_allocation_attachments', 'inspection_attachment' => 'ppe_inspection_attachments',
            default => throw new LogicException('Unknown PPE removed source.') };
        foreach ([...self::PUBLIC_ATTACHMENT_TABLES, 'hs_attachments'] as $name) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($name)) { continue; }
            $query = DB::table($name)->where('path', $path)->where(function ($disk) use ($name) {
                $disk->whereIn('disk', ['private', 'local']);
                // The generic H&S legacy download defaults NULL to private; June public attachments do not.
                if ($name === 'hs_attachments') { $disk->orWhereNull('disk'); }
            });
            if ($removed !== null && $name === $table) { $query->where('id', '!=', $removed->attachment_id); }
            if ($query->lock('for share nowait')->exists()) { return true; }
        }
        foreach ([['hr_documents', 'storage_path', 'storage_disk'], ['hr_documents', 'signed_document_path', 'storage_disk'],
            ['client_documents', 'storage_path', 'storage_disk'], ['site_documents', 'storage_path', 'storage_disk'], ['asset_documents', 'storage_path', 'storage_disk'],
            ['safety_data_sheets', 'document_path', null], ['chemical_evidence_staging', 'path', 'disk'],
            ['it_attachments', 'path', null], ['it_attachment_storage_intents', 'path', null],
            ['governance_action_evidence', 'path', 'disk'], ['compliance_evidence', 'file_path', null], ['audit_evidence_packs', 'file_path', null],
            ['control_room_evidence_items', 'storage_path', null], ['control_room_evidence_packs', 'export_path', null],
            ['client_consents', 'signed_document_path', 'signed_document_disk'], ['hr_staff_compliance_status', 'evidence_path', 'evidence_disk'],
            ['hr_performance_reviews', 'evidence_path', null], ['hr_goals', 'evidence_path', null], ['work_capacity_assessments', 'document_path', null],
            ['medication_downtime_sheets', 'path', null], ['medication_order_files', 'file_path', null],
            ['medication_stock_photos', 'path', null], ['medication_support_agreements', 'attachment_path', null]] as [$name, $column, $diskColumn]) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($name) || ! \Illuminate\Support\Facades\Schema::hasColumn($name, $column)) { continue; }
            $query = DB::table($name)->where($column, $path);
            if ($diskColumn !== null) { $query->where(fn ($disk) => $disk->whereIn($diskColumn, ['private', 'local'])->orWhereNull($diskColumn)); }
            if ($query->lock('for share nowait')->exists()) { return true; }
        }
        if ($unaccepted !== null && DB::table('ppe_evidence_staging')->where('disk', 'private')->where('path', $path)
            ->where('id', '!=', $unaccepted->id)->lock('for share nowait')->exists()) { return true; }
        // A staging pointer can be superseded only by this exact attachment's accepted removal.
        if ($removed !== null && DB::table('ppe_evidence_staging as stages')->where('stages.disk', 'private')->where('stages.path', $path)
            ->where(function ($query) use ($removed) {
                $query->whereNull('stages.receipt_id')->orWhere('stages.state', '!=', 'accepted')
                    ->orWhereNotExists(function ($receipt) use ($removed) {
                        $receipt->selectRaw('1')->from('ppe_command_receipts as stage_receipts')
                            ->whereColumn('stage_receipts.id', 'stages.receipt_id')
                            ->whereColumn('stage_receipts.intent_claim_id', 'stages.intent_claim_id')
                            ->join('ppe_source_versions as stage_versions', 'stage_versions.id', '=', 'stage_receipts.source_version_id')
                            ->join('audit_logs as stage_audits', 'stage_audits.id', '=', 'stage_receipts.audit_log_id')
                            ->whereColumn('stage_receipts.idempotency_key', 'stages.idempotency_key')
                            ->whereColumn('stage_receipts.user_id', 'stages.user_id')->whereColumn('stage_receipts.fingerprint', 'stages.fingerprint')
                            ->whereColumn('stage_versions.idempotency_key', 'stage_receipts.idempotency_key')
                            ->whereColumn('stage_versions.root_id', 'stage_receipts.root_id')->where('stage_versions.root_type', 'inventory')
                            ->whereColumn('stage_audits.user_id', 'stage_receipts.user_id')->whereColumn('stage_audits.auditable_id', 'stage_receipts.root_id')
                            ->where('stage_audits.auditable_type', (new \App\Models\PpeInventory)->getMorphClass())
                            ->whereColumn('stage_audits.meta->idempotency_key', 'stage_receipts.idempotency_key')
                            ->whereColumn('stage_audits.meta->fingerprint', 'stage_receipts.fingerprint')
                            ->whereRaw("stage_audits.action = CONCAT('ppe.', stage_receipts.command)")
                            ->whereColumn('stage_versions.user_id', 'stage_receipts.user_id')->whereColumn('stage_versions.command', 'stage_receipts.command')
                            ->where('stage_receipts.root_type', 'inventory')
                            ->where('stage_receipts.root_id', function ($root) use ($removed) {
                                $root->select('root_id')->from('ppe_command_receipts')->where('id', $removed->receipt_id);
                            })->where(function ($source) use ($removed) {
                                $source->where(function ($direct) use ($removed) {
                                    $direct->where('stage_versions.source_type', $removed->attachment_type)->where('stage_versions.source_id', $removed->attachment_id)
                                        ->where('stage_versions.content->source_after->path', $removed->path)->where('stage_versions.content->source_after->disk', 'private')
                                        ->where('stage_versions.content->facts->file_evidence->digest', $removed->file_digest)
                                        ->where('stage_versions.content->facts->file_evidence->size', $removed->file_size);
                                })->orWhereJsonContains('stage_versions.content->facts->documents', ['source_type' => $removed->attachment_type,
                                    'source_id' => $removed->attachment_id, 'after' => ['path' => $removed->path, 'disk' => 'private'],
                                    'file_evidence' => ['digest' => $removed->file_digest, 'size' => $removed->file_size]]);
                            })->where('stages.file_digest', $removed->file_digest)->where('stages.file_size', $removed->file_size);
                    });
            })->lock('for share nowait')->exists()) { return true; }
        if (DB::table('ppe_evidence_cleanups')->where('disk', 'private')->where('path', $path)
            ->when($removed !== null, fn ($query) => $query->where('id', '!=', $removed->id))
            ->lock('for share nowait')->exists()) { return true; }
        // Pending public-origin delivery still needs its accepted private copy to remove the public origin safely.
        if (DB::table('ppe_evidence_relocation_cleanups')->where('destination_path', $path)
            ->when($removed !== null, fn ($query) => $query->where(function ($delivery) use ($removed) {
                $delivery->where('state', '!=', 'complete')->orWhere('attachment_type', '!=', $removed->attachment_type)
                    ->orWhere('attachment_id', '!=', $removed->attachment_id);
            }))->lock('for share nowait')->exists()) { return true; }
        if (DB::table('ppe_source_versions')->where('content->source_after->disk', 'private')->where('content->source_after->path', $path)
            ->when($removed !== null, fn ($query) => $query->where(fn ($other) => $other->where('source_type', '!=', $removed->attachment_type)->orWhere('source_id', '!=', $removed->attachment_id)))
            ->lock('for share nowait')->exists()) { return true; }
        foreach ([['chemical_source_versions', 'content->source_after->document_path'],
            ['chemical_source_versions', 'content->source_before->document_path'],
            ['workplace_injury_source_versions', 'content->after->path'],
            ['workplace_injury_source_versions', 'content->before->path'],
            ['workplace_injury_source_versions', 'content->after->document_path'],
            ['workplace_injury_source_versions', 'content->before->document_path']] as [$history, $column]) {
            if (\Illuminate\Support\Facades\Schema::hasTable($history)
                && DB::table($history)->where($column, $path)->lock('for share nowait')->exists()) { return true; }
        }
        $ownDocument = ['source_type' => $removed?->attachment_type, 'source_id' => $removed?->attachment_id,
            'after' => ['path' => $path, 'disk' => 'private']];
        $literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path);
        if (DB::table('ppe_source_versions')->whereJsonContains('content->facts->documents', ['after' => ['path' => $path, 'disk' => 'private']])
            ->when($removed !== null, function ($query) use ($ownDocument, $literal) {
                $query->whereJsonDoesntContain('content->facts->documents', $ownDocument)
                    ->orWhereRaw("COALESCE(JSON_LENGTH(JSON_SEARCH(content, 'all', ?, NULL, '$.facts.documents[*].after.path')), 0) > 1", [$literal]);
            })->lock('for share nowait')->exists()) { return true; }
        if (\Illuminate\Support\Facades\Schema::hasTable('site_hazards')) {
            foreach (['photo_paths', 'document_paths', 'resolution_evidence'] as $column) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('site_hazards', $column)
                    && DB::table('site_hazards')->where(fn ($query) => $query->whereJsonContains($column, $path)
                        ->orWhereJsonContains($column, ['path' => $path]))->lock('for share nowait')->exists()) { return true; }
            }
        }
        return false;
    }
    /** Called only after current actor/source and immutable original reservation proof, under physical locks. */
    public function retainedStagingReference(PpeEvidenceStaging $attempt): bool
    {
        if (DB::transactionLevel() < 1 || $attempt->disk !== 'private' || $attempt->receipt_id !== null
            || $attempt->state === 'accepted' || ! $attempt->exists) {
            throw new LogicException('Only this exact admitted unaccepted reservation may be excluded from cleanup references.');
        }
        return $this->retainedPrivateReference(null, $attempt);
    }

    public function discard(string $path): void
    {
        $disk = Storage::disk('private');
        if ($disk->exists($path) && (! $disk->delete($path) || $disk->exists($path))) {
            throw new LogicException('Unaccepted staged PPE evidence requires cleanup.');
        }
    }

    /** Called exclusively after the physical outer transaction commits. */
    public function purge(int $id, callable $currentAuthority): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('PPE byte purge cannot precede physical commit.');
        }
        DB::transaction(function () use ($id, $currentAuthority): void {
            $currentAuthority(); // Current actor/root/source locks remain held by this physical transaction.
            $row = PpeEvidenceCleanup::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->state === 'complete') {
                return;
            }
            $state = 'unknown';
            try {
                if ($row->disk === 'private' && $this->validRemoval($row) && ! $this->retainedPrivateReference($row)) {
                    $proof = $this->bytes($row->path);
                    if ($proof === null) {
                        $state = 'complete';
                    } elseif ($row->file_digest && $proof === ['digest' => $row->file_digest, 'size' => $row->file_size]) {
                        $disk = Storage::disk('private');
                        $state = $disk->delete($row->path) && ! $disk->exists($row->path) ? 'complete' : 'pending';
                    }
                }
            } catch (Throwable) {
                $state = 'unknown';
            }
            $row->forceFill(['state' => $state, 'attempts' => $row->attempts + 1,
                'completed_at' => $state === 'complete' ? now()->utc() : null]);
            $attempts = $row->attempts;
            if (! $row->save()) {
                throw new LogicException('PPE cleanup delivery was not persisted.');
            }
            $saved = PpeEvidenceCleanup::whereKey($id)->lockForUpdate()->first();
            if (! $saved || $saved->state !== $state || $saved->attempts !== $attempts
                || (($saved->completed_at !== null) !== ($state === 'complete'))) {
                throw new LogicException('Exact PPE cleanup outcome was not persisted.');
            }
        });
    }
}
