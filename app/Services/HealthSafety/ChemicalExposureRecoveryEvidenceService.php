<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureRecoveryEvidence;
use App\Models\ChemicalExposureRecoveryStaging;
use App\Models\ChemicalExposureRecoveryVersion;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** New review basis only. No old exposure evidence is loaded, linked or relocated by this purpose. */
final class ChemicalExposureRecoveryEvidenceService
{
    public function __construct(private readonly ChemicalExposureAssociationRecoveryService $recovery,
        private readonly ChemicalExposureRecoveryLedger $ledger, private readonly ChemicalExposureEvidenceStorage $storage) {}

    public function upload(User $actor, int $exposureId, UploadedFile $file, array $input): array
    {
        $this->physical();
        $v = ChemicalCommandValues::normalize($input, ['description' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $v['file_evidence'] = $this->storage->descriptor($file);
        $reservation = $this->recovery->within($actor, $exposureId, function ($identity, $case, $current, $reads, $sites) use ($v) {
            if ($case === null) { ChemicalCommandValues::invalid('source', 'Record the later review before reserving its new evidence.'); }
            $values = [...$v, 'sealed_digest' => $case->sealed_digest];
            $intent = $this->ledger->claim($current, $identity, 'upload_review_basis', $values, $reads);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                $this->ledger->prove($case, $receipt->version_id, $reads);
                return ['receipt' => array_replace(array_fill_keys(ChemicalExposureRecoveryLedger::KEYS, null), $receipt->accepted_result)];
            }
            if ($case->revision !== $v['expected_version']) { ChemicalCommandValues::invalid('expected_version', 'This review changed before upload.'); }
            $stage = ChemicalExposureRecoveryStaging::where('case_id', $case->id)->where('intent_id', $intent->id)->lockForUpdate()
                ->select(['id', 'case_id', 'exposure_id', 'intent_id', 'idempotency_key', 'actor_id', 'expected_version', 'path',
                    'claim_fingerprint', 'reservation_digest', 'state', 'receipt_id', 'attempts'])
                ->selectRaw('SHA2(CAST(claim_frame AS BINARY), 256) AS observed_fingerprint')->first();
            if ($stage === null) {
                $stage = new ChemicalExposureRecoveryStaging(['case_id' => $case->id, 'exposure_id' => $identity['id'],
                    'intent_id' => $intent->id, 'idempotency_key' => $intent->idempotency_key, 'actor_id' => $current->id, 'expected_version' => $v['expected_version'],
                    'path' => 'health-safety/chemical-exposures/'.$identity['id'].'/'.$intent->idempotency_key.'/'.Str::uuid().'/evidence',
                    'claim_frame' => $this->ledger->frame($current, $identity, 'upload_review_basis', $values), 'claim_fingerprint' => $intent->fingerprint, 'state' => 'reserved', 'receipt_id' => null, 'attempts' => 0]);
                $stage->reservation_digest = $this->reservationDigest($stage); $this->ledger->save($stage);
            }
            $this->proveStage($stage, $case, $intent, $reads);
            return ['id' => (int) $stage->id];
        }, write: true);
        if (isset($reservation['receipt'])) { return $reservation['receipt']; }
        $id = $reservation['id']; // Real reservation/UUID transaction has physically committed.
        try {
            $this->withStage($actor, $exposureId, $id, function ($stage, $case, $intent, $current, $reads) use ($file, $v) {
                if ($stage->actor_id !== (int) $current->id || $stage->expected_version !== $case->revision
                    || $stage->receipt_id !== null || $stage->state === 'accepted') { throw new LogicException('This exact original upload is not currently admissible.'); }
                if ($this->retained($stage->path, $reads, $stage->id)) { throw new LogicException('Another retained source uses these reserved bytes.'); }
                if (ChemicalCommandValues::digest($this->storage->descriptor($file)) !== ChemicalCommandValues::digest($v['file_evidence'])) {
                    ChemicalCommandValues::invalid('file', 'The file differs from the original durable intent.');
                }
                $wanted = $this->byteProof($stage);
                $actual = $this->storage->bytes($stage->path);
                if ($actual !== null && $actual !== $wanted) { throw new LogicException('Different bytes occupy the unique recovery path.'); }
                if ($actual === null) {
                    $path = Storage::disk('private')->putFileAs(dirname($stage->path), $file, basename($stage->path));
                    if ($path !== $stage->path || $this->storage->bytes($stage->path) !== $wanted) { throw new LogicException('Recovery basis bytes are not durably stored.'); }
                }
                $stage->forceFill(['state' => 'stored', 'attempts' => $stage->attempts + 1]); $this->ledger->save($stage);
                return null;
            });
            return $this->withStage($actor, $exposureId, $id, function ($stage, $case, $intent, $current, $reads) use ($v) {
                if ($stage->actor_id !== (int) $current->id || $case->revision !== $v['expected_version']
                    || $stage->receipt_id !== null || $stage->state !== 'stored'
                    || $this->storage->bytes($stage->path) !== $this->byteProof($stage)) {
                    throw new LogicException('Recovery evidence acceptance requires exact current reserved bytes and version.');
                }
                $previous = $this->ledger->prove($case, $case->current_version_id, $reads);
                $result = $this->ledger->accept($case, $intent, $current, $reads, $previous->decision,
                    $previous->review_context, $previous->reviewed_at->format('Y-m-d H:i:s.u'),
                    ['staging_id' => (int) $stage->id, 'file_evidence' => $v['file_evidence'], 'path' => $stage->path,
                        'description' => $v['description'] ?? null, 'medical_fact_origin' => 'newly_uploaded_review_basis']);
                $receipt = $this->ledger->receipt($intent, $reads);
                $file = new ChemicalExposureRecoveryEvidence(['case_id' => $case->id, 'version_id' => $result['source_version_id'],
                    'staging_id' => $stage->id, 'path' => $stage->path, ...$v['file_evidence']]);
                $this->ledger->save($file);
                $stage->forceFill(['state' => 'accepted', 'receipt_id' => $receipt->id]); $this->ledger->save($stage);
                return $result;
            });
        } catch (Throwable $failure) {
            try {
                $this->withStage($actor, $exposureId, $id, function ($stage, $case, $intent, $current, $reads) {
                    if ($stage->receipt_id === null && $this->ledger->receipt($intent, $reads) === null) {
                        $stage->state = 'cleanup_pending'; $this->ledger->save($stage);
                    }
                    return null;
                });
            } catch (Throwable) { /* Original durable row remains reserved/stored, never a fabricated accepted or clean state. */ }
            throw $failure;
        }
    }

    public function inspectStaging(User $actor, int $exposureId, int $id): array
    {
        return $this->withStage($actor, $exposureId, $id, fn ($stage, $case, $intent, $current, $reads) => $this->stageDto($stage));
    }

    public function retryStagingCleanup(User $actor, int $exposureId, int $id): array
    {
        $this->physical();
        return $this->withStage($actor, $exposureId, $id, function ($stage, $case, $intent, $current, $reads) {
            if ($stage->receipt_id !== null || $this->ledger->receipt($intent, $reads) !== null || $stage->state === 'accepted') {
                throw new LogicException('Accepted recovery evidence is retained, not rejected-upload cleanup.');
            }
            if ($this->retained($stage->path, $reads, $stage->id)) {
                $stage->state = 'unknown'; $this->ledger->save($stage); return $this->stageDto($stage);
            }
            $this->storage->discard($stage->path, $this->byteProof($stage));
            $stage->forceFill(['state' => 'cleaned', 'attempts' => $stage->attempts + 1]); $this->ledger->save($stage);
            return $this->stageDto($stage);
        });
    }

    /** Internal download descriptor only. An earlier unresolved basis stays sealed after later association review. */
    public function evidence(User $actor, int $exposureId, int $id): array
    {
        return $this->recovery->within($actor, $exposureId, function ($identity, $case, $current, $reads, $sites) use ($id) {
            abort_if($case === null, 404);
            $identity = $reads->query(ChemicalExposureRecoveryEvidence::query())->where('case_id', $case->id)->whereKey($id)->firstOrFail(['id', 'version_id']);
            $version = $this->ledger->prove($case, $identity->version_id, $reads);
            abort_unless($version->decision === 'reviewed_association' && $version->review_context !== null, 403);
            $file = $reads->query(ChemicalExposureRecoveryEvidence::query())->where('case_id', $case->id)->where('version_id', $version->id)->whereKey($id)->firstOrFail();
            $body = $reads->query(ChemicalExposureRecoveryVersion::query())->where('case_id', $case->id)->whereKey($version->id)
                ->whereRaw('SHA2(CAST(content AS BINARY), 256) = ?', [$version->content_digest])->firstOrFail(['id', 'content']);
            $accepted = $body->content['facts'];
            if (($accepted['path'] ?? null) !== $file->path || ($accepted['staging_id'] ?? null) !== $file->staging_id
                || ChemicalCommandValues::digest($accepted['file_evidence'] ?? null) !== ChemicalCommandValues::digest([
                    'sha256' => $file->sha256, 'size' => $file->size, 'mime' => $file->mime, 'original_name' => $file->original_name])
                || $this->storage->bytes($file->path) !== ['sha256' => $file->sha256, 'size' => $file->size]) {
                throw new LogicException('Recovery evidence lacks its exact accepted observed bytes.');
            }
            $stage = $reads->query(ChemicalExposureRecoveryStaging::query())->where('case_id', $case->id)
                ->where('exposure_id', $case->exposure_id)->whereKey($file->staging_id)
                ->select(['id', 'case_id', 'exposure_id', 'intent_id', 'idempotency_key', 'actor_id', 'expected_version', 'path',
                    'claim_fingerprint', 'reservation_digest', 'state', 'receipt_id', 'attempts'])
                ->selectRaw('SHA2(CAST(claim_frame AS BINARY), 256) AS observed_fingerprint')->firstOrFail();
            $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($stage->intent_id)->firstOrFail();
            $this->proveStage($stage, $case, $intent, $reads);
            $receipt = $this->ledger->receipt($intent, $reads);
            if ($stage->state !== 'accepted' || $stage->path !== $file->path || $receipt === null
                || $receipt->version_id !== (int) $version->id || $stage->receipt_id !== (int) $receipt->id) {
                throw new LogicException('New evidence lost its exact accepted original staging identity.');
            }
            $this->ledger->prove($case, $version->id, $reads);
            return ['disk' => 'private', 'path' => $file->path, 'sha256' => $file->sha256, 'size' => $file->size,
                'mime' => $file->mime, 'original_name' => $file->original_name];
        });
    }

    private function withStage(User $actor, int $exposureId, int $id, callable $operation): mixed
    {
        return $this->recovery->within($actor, $exposureId, function ($identity, $case, $current, $reads, $sites) use ($id, $operation) {
            abort_if($case === null, 404);
            $stage = ChemicalExposureRecoveryStaging::where('case_id', $case->id)->where('exposure_id', $identity['id'])->whereKey($id)->lockForUpdate()
                ->select(['id', 'case_id', 'exposure_id', 'intent_id', 'idempotency_key', 'actor_id', 'expected_version', 'path', 'claim_fingerprint', 'reservation_digest', 'state', 'receipt_id', 'attempts', 'created_at', 'updated_at'])
                ->selectRaw('SHA2(CAST(claim_frame AS BINARY), 256) AS observed_fingerprint')
                ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(claim_frame, '$.values.file_evidence.sha256')) AS expected_sha")
                ->selectRaw("JSON_EXTRACT(claim_frame, '$.values.file_evidence.size') AS expected_size")->firstOrFail();
            $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($stage->intent_id)->firstOrFail();
            $this->proveStage($stage, $case, $intent, $reads);
            return $operation($stage, $case, $intent, $current, $reads, $sites);
        }, write: true);
    }

    private function proveStage($stage, $case, $intent, CurrentAuthorizationReads $reads): void
    {
        if ($stage->case_id !== (int) $case->id || $stage->exposure_id !== $case->exposure_id || $stage->actor_id !== $intent->actor_id
            || $intent->source_type !== 'exposure_association_review' || $intent->source_id !== $case->exposure_id || $intent->parent_id !== $case->substance_id
            || $intent->command !== 'upload_review_basis' || $intent->idempotency_key !== $stage->idempotency_key || $intent->expected_version !== $stage->expected_version
            || $stage->reservation_digest !== $this->reservationDigest($stage) || $stage->claim_fingerprint !== $intent->fingerprint
            || ($stage->getRawOriginal('observed_fingerprint') ?? hash('sha256', (string) $stage->getRawOriginal('claim_frame'))) !== $intent->fingerprint) {
            throw new LogicException('Recovery durable reservation or original claim is damaged.');
        }
        $retainedAcceptance = $this->ledger->receipt($intent, $reads);
        if ($retainedAcceptance !== null && $stage->receipt_id === null) {
            throw new LogicException('A retained accepted upload has an unbound staging identity; repair is required.');
        }
        if ($stage->receipt_id !== null) {
            $receipt = $this->ledger->receipt($intent, $reads);
            if ($receipt === null || (int) $receipt->id !== $stage->receipt_id) { throw new LogicException('Accepted recovery stage lost its original acceptance.'); }
            $this->ledger->prove($case, $receipt->version_id, $reads);
        }
    }

    private function retained(string $path, CurrentAuthorizationReads $reads, int $excludedStage): bool
    {
        // No foreign-purpose stage ID is excluded from the existing broad guard.
        if ($this->storage->retainedReference($path, $reads)) { return true; }
        if ($reads->query(ChemicalExposureRecoveryEvidence::query())->where('path', $path)->exists()
            || $reads->query(ChemicalExposureRecoveryStaging::query())->where('path', $path)->where('id', '!=', $excludedStage)->exists()
            || $reads->query(ChemicalExposureRecoveryVersion::query())->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.facts.path')) = ?", [$path])->exists()) { return true; }
        return false;
    }

    private function reservationDigest($stage): string
    {
        return ChemicalCommandValues::digest(array_intersect_key($stage->attributesToArray(), array_flip([
            'case_id', 'exposure_id', 'intent_id', 'idempotency_key', 'actor_id', 'expected_version', 'path', 'claim_fingerprint'])));
    }
    private function byteProof($stage): array
    {
        $sha = $stage->getRawOriginal('expected_sha'); $size = $stage->getRawOriginal('expected_size');
        if (! is_string($sha) || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1 || ! is_numeric($size) || (int) $size < 1 || (int) $size > 10485760) {
            throw new LogicException('Recovery staged byte metadata is invalid.');
        }
        return ['sha256' => $sha, 'size' => (int) $size];
    }
    private function stageDto($stage): array { return ['id' => (int) $stage->id, 'state' => $stage->state, 'accepted' => $stage->receipt_id !== null]; }
    private function physical(): void { if (DB::transactionLevel() !== 0) { throw new LogicException('Recovery basis requires outermost physically committed reservations.'); } }
}
