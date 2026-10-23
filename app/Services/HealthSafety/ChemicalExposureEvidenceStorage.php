<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalExposureEvidenceCleanup;
use App\Models\ChemicalExposureEvidenceStaging;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use LogicException;

/** Private physical operations only; services retain original authority and durable intent before invoking them. */
final class ChemicalExposureEvidenceStorage
{
    public function descriptor(UploadedFile $file): array
    {
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240']])->validate();
        $path = $file->getRealPath();
        $sha = $path ? hash_file('sha256', $path) : false;
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if (! $file->isValid() || ! $sha || $file->getSize() < 1 || $name === '' || strlen($name) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            ChemicalCommandValues::invalid('file', 'The private evidence file could not be read safely.');
        }
        return ['sha256' => $sha, 'size' => (int) $file->getSize(), 'mime' => $file->getMimeType(), 'original_name' => $name];
    }

    public function bytes(string $path): ?array
    {
        $this->assertPath($path);
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) { return null; }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) { throw new LogicException('Private exposure evidence could not be opened.'); }
        try {
            $hash = hash_init('sha256'); $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) { throw new LogicException('Exposure evidence could not be completely read.'); }
                $size += strlen($chunk); hash_update($hash, $chunk);
            }
            return ['sha256' => hash_final($hash), 'size' => $size];
        } finally { fclose($stream); }
    }

    public function put(ChemicalExposureEvidenceStaging $stage, UploadedFile $file): void
    {
        if (DB::transactionLevel() < 1 || $stage->receipt_id !== null || ! in_array($stage->state, ['staged', 'stored', 'unknown', 'cleanup_pending', 'cleaned'], true)) {
            throw new LogicException('Exposure file requires a committed, currently admitted original reservation.');
        }
        $this->assertPath($stage->path);
        $facts = $this->descriptor($file);
        if (ChemicalCommandValues::digest($facts) !== ChemicalCommandValues::digest($stage->file_evidence)) {
            ChemicalCommandValues::invalid('file', 'This evidence does not match the retained original upload intent.');
        }
        $expected = array_intersect_key($facts, ['sha256' => true, 'size' => true]);
        $existing = $this->bytes($stage->path);
        if ($existing !== null && $existing !== $expected) { throw new LogicException('Different bytes occupy this unique reserved evidence path.'); }
        if ($existing === null) {
            $stored = Storage::disk('private')->putFileAs(dirname($stage->path), $file, basename($stage->path));
            if ($stored !== $stage->path || $this->bytes($stage->path) !== $expected) {
                throw new LogicException('Exposure staged bytes were not durably confirmed.');
            }
        }
    }

    public function discard(string $path, array $expected): void
    {
        $actual = $this->bytes($path);
        if ($actual === null) { return; }
        if ($actual !== $expected || ! Storage::disk('private')->delete($path) || Storage::disk('private')->exists($path)) {
            throw new LogicException('Exposure physical cleanup remains pending.');
        }
    }

    public function reservationDigest(ChemicalExposureEvidenceStaging $stage): string
    {
        return ChemicalCommandValues::digest(array_intersect_key($stage->attributesToArray(), array_flip([
            'intent_id', 'actor_id', 'exposure_id', 'substance_id', 'worker_id', 'site_id', 'expected_version', 'disk', 'path', 'file_evidence', 'command_values'])));
    }

    /** Known retained reference surfaces: identity-only, including soft-deleted pointers and immutable history. */
    public function retainedReference(string $path, CurrentAuthorizationReads $reads, ?int $stageId = null,
        ?int $evidenceId = null, ?int $cleanupId = null, ?int $acceptedStageId = null): bool
    {
        $reads->assertActive(); $this->assertPath($path);
        foreach (['workplace_injury_attachments', 'first_aid_attachments', 'emergency_drill_attachments',
            'hs_risk_assessment_attachments', 'restraint_event_attachments', 'ppe_attachments', 'ppe_allocation_attachments',
            'ppe_inspection_attachments', 'client_incident_attachments', 'fleet_incident_attachments', 'safeguarding_attachments',
            'privacy_attachments', 'hs_attachments', 'chemical_exposure_evidence'] as $table) {
            if (! Schema::hasTable($table)) { continue; }
            $q = DB::table($table)->where('path', $path)->where(fn ($disk) => $disk->whereIn('disk', ['private', 'local'])
                ->when($table === 'hs_attachments', fn ($d) => $d->orWhereNull('disk')));
            if ($table === 'chemical_exposure_evidence' && $evidenceId !== null) { $q->where('id', '!=', $evidenceId); }
            if ($q->lock('for share nowait')->exists()) { return true; }
        }
        foreach ([['hr_documents', 'storage_path', 'storage_disk'], ['hr_documents', 'signed_document_path', 'storage_disk'],
            ['client_documents', 'storage_path', 'storage_disk'], ['site_documents', 'storage_path', 'storage_disk'],
            ['asset_documents', 'storage_path', 'storage_disk'], ['safety_data_sheets', 'document_path', null],
            ['chemical_evidence_staging', 'path', 'disk'], ['ppe_evidence_staging', 'path', 'disk'],
            ['ppe_evidence_cleanups', 'path', 'disk'], ['ppe_evidence_relocation_cleanups', 'destination_path', null],
            ['chemical_exposure_evidence_staging', 'path', 'disk'], ['chemical_exposure_evidence_cleanups', 'path', 'disk'],
            ['it_attachments', 'path', null], ['it_attachment_storage_intents', 'path', null],
            ['governance_action_evidence', 'path', 'disk'], ['compliance_evidence', 'file_path', null],
            ['audit_evidence_packs', 'file_path', null], ['control_room_evidence_items', 'storage_path', null],
            ['control_room_evidence_packs', 'export_path', null], ['client_consents', 'signed_document_path', 'signed_document_disk'],
            ['hr_staff_compliance_status', 'evidence_path', 'evidence_disk'], ['hr_performance_reviews', 'evidence_path', null],
            ['hr_goals', 'evidence_path', null], ['work_capacity_assessments', 'document_path', null],
            ['medication_downtime_sheets', 'path', null], ['medication_order_files', 'file_path', null],
            ['medication_stock_photos', 'path', null], ['medication_support_agreements', 'attachment_path', null]] as [$table, $column, $diskColumn]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) { continue; }
            $q = DB::table($table)->where($column, $path);
            if ($diskColumn) { $q->where(fn ($d) => $d->whereIn($diskColumn, ['private', 'local'])->orWhereNull($diskColumn)); }
            if ($table === 'chemical_exposure_evidence_staging') {
                foreach (array_filter([$stageId, $acceptedStageId], fn ($id) => $id !== null) as $id) { $q->where('id', '!=', $id); }
            }
            if ($table === 'chemical_exposure_evidence_cleanups' && $cleanupId !== null) { $q->where('id', '!=', $cleanupId); }
            if ($q->lock('for share nowait')->exists()) { return true; }
        }
        foreach ([['chemical_source_versions', 'content->source_after->document_path'], ['chemical_source_versions', 'content->source_before->document_path'],
            ['ppe_source_versions', 'content->source_after->path'], ['ppe_source_versions', 'content->source_before->path'],
            ['workplace_injury_source_versions', 'content->after->path'], ['workplace_injury_source_versions', 'content->before->path'],
            ['workplace_injury_source_versions', 'content->after->document_path'], ['workplace_injury_source_versions', 'content->before->document_path'],
            ['chemical_exposure_source_versions', 'content->source_after->path'], ['chemical_exposure_source_versions', 'content->source_before->path']] as [$table, $column]) {
            if (! Schema::hasTable($table)) { continue; }
            $q = DB::table($table)->where($column, $path);
            // Only the exact removed evidence's proven history is superseded; other retained private history keeps bytes.
            if ($table === 'chemical_exposure_source_versions' && $evidenceId !== null) {
                $q->where(fn ($other) => $other->where('source_type', '!=', 'evidence')->orWhere('source_id', '!=', $evidenceId));
            }
            if ($q->lock('for share nowait')->exists()) { return true; }
        }
        if (Schema::hasTable('ppe_source_versions') && DB::table('ppe_source_versions')
            ->whereJsonContains('content->facts->documents', ['after' => ['path' => $path, 'disk' => 'private']])
            ->lock('for share nowait')->exists()) { return true; }
        if (Schema::hasTable('site_hazards')) {
            foreach (['photo_paths', 'document_paths', 'resolution_evidence'] as $column) {
                if (Schema::hasColumn('site_hazards', $column) && DB::table('site_hazards')
                    ->where(fn ($q) => $q->whereJsonContains($column, $path)->orWhereJsonContains($column, ['path' => $path]))
                    ->lock('for share nowait')->exists()) { return true; }
            }
        }
        return false;
    }

    private function assertPath(string $path): void
    {
        if (! preg_match('~^health-safety/chemical-exposures/[1-9][0-9]*/[a-f0-9-]{36}/[a-f0-9-]{36}/evidence$~D', $path)) {
            throw new LogicException('Exposure file has no canonical private generated path.');
        }
    }
}