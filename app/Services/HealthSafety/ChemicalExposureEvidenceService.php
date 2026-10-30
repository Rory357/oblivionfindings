<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureEvidence;
use App\Models\ChemicalExposureEvidenceCleanup;
use App\Models\ChemicalExposureEvidenceStaging;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Source-bound private evidence with a physically committed reservation and independently recoverable cleanup. */
final class ChemicalExposureEvidenceService
{
    public function __construct(private readonly ChemicalExposureCommandAccess $access,
        private readonly ChemicalExposureSourceService $source, private readonly ChemicalExposureLedger $ledger,
        private readonly ChemicalExposureEvidenceStorage $storage) {}

    public function upload(SubstanceExposureRecord|int $source, User $actor, UploadedFile $file, array $input): array
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Exposure uploads require an outermost physical command; no file was staged.'); }
        $v = ChemicalCommandValues::normalize($input, ['description' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $v['file_evidence'] = $this->storage->descriptor($file);
        $reservation = $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($source, $v) {
            $root = $this->access->source($source, $current, $reads, $sites);
            $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
            $intent = $this->ledger->claim($current, $reads, (int) $root->id, (int) $root->hazardous_substance_id, 'upload_evidence', $v, $sites);
            if ($r = $this->ledger->receipt($intent, $reads)) {
                return ['receipt' => $this->ledger->reconstruct($r, $intent, $root, $current, $reads, $sites)];
            }
            $this->expected($root, $v);
            $stage = ChemicalExposureEvidenceStaging::where('intent_id', $intent->id)->lockForUpdate()->first();
            if (! $stage) {
                $stage = new ChemicalExposureEvidenceStaging(['intent_id' => $intent->id, 'actor_id' => $current->id,
                    'exposure_id' => $root->id, 'substance_id' => $root->hazardous_substance_id, 'worker_id' => $root->user_id,
                    'site_id' => $root->site_id, 'expected_version' => $v['expected_version'], 'disk' => 'private',
                    'path' => 'health-safety/chemical-exposures/'.$root->id.'/'.$intent->idempotency_key.'/'.Str::uuid().'/evidence',
                    'file_evidence' => $v['file_evidence'], 'command_values' => $v, 'state' => 'staged', 'attempts' => 0]);
                $stage->reservation_digest = $this->storage->reservationDigest($stage);
                $this->source->save($stage);
            }
            $this->assertStage($stage, $intent, $root, $current, $reads);
            return ['staging_id' => (int) $stage->id];
        });
        if (isset($reservation['receipt'])) { return $reservation['receipt']; }
        $stageId = $reservation['staging_id']; // The canonical reservation transaction has physically committed here.
        try {
            $this->withStage($actor, $stageId, function ($root, $stage, $intent, $current, $reads, $sites) use ($file, $v) {
                $this->expected($root, $v);
                if ($stage->receipt_id !== null || $stage->state === 'accepted') { throw new LogicException('Accepted exposure bytes cannot be overwritten.'); }
                if ($this->storage->retainedReference($stage->path, $reads, (int) $stage->id)) {
                    throw new LogicException('The staged exposure path is already retained by another source.');
                }
                $this->storage->put($stage, $file);
                $stage->forceFill(['state' => 'stored', 'attempts' => $stage->attempts + 1]);
                $this->source->save($stage);
                return null;
            });
            return $this->source->command($source, $actor, 'upload_evidence', $v,
                function ($root, $current, $reads, $sites) use ($stageId, $v) {
                    $stage = ChemicalExposureEvidenceStaging::where('exposure_id', $root->id)->where('actor_id', $current->id)
                        ->whereKey($stageId)->lockForUpdate()->firstOrFail();
                    $intent = ChemicalCommandIntent::whereKey($stage->intent_id)->where('source_type', 'exposure')
                        ->where('actor_id', $current->id)->lockForUpdate()->firstOrFail();
                    $this->assertStage($stage, $intent, $root, $current, $reads);
                    $expected = array_intersect_key($v['file_evidence'], ['sha256' => true, 'size' => true]);
                    if ($stage->state !== 'stored' || $stage->receipt_id !== null || $this->storage->bytes($stage->path) !== $expected) {
                        throw new LogicException('Exposure upload cannot accept unconfirmed or changed private bytes.');
                    }
                    $evidence = new ChemicalExposureEvidence(['exposure_id' => $root->id, 'source_schema_version' => 1,
                        'source_revision' => $root->source_revision + 1, 'disk' => 'private', 'path' => $stage->path,
                        ...$v['file_evidence'], 'description' => $v['description'] ?? null, 'uploaded_by' => $current->id,
                        'uploader_snapshot' => ['id' => (int) $current->id, 'name' => $current->name]]);
                    $this->source->save($evidence);
                    return [$evidence, 'evidence', null, true, ['file_evidence' => $v['file_evidence'], 'staging_id' => $stageId]];
                }, fn ($root, $current, $reads, $result, $sites) => $this->bindStageInside($root, $current, $reads, $stageId, $result, $sites));
        } catch (Throwable $error) {
            $this->markPending($actor, $stageId);
            throw $error;
        }
    }

    /** Accepted stage binding recovery is exact; receipt remains the authoritative acceptance, even if an after-commit write failed. */
    public function bindAcceptedStage(User $actor, int $stageId): array
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Exposure accepted stage reconciliation requires physical commit.'); }
        return $this->withStage($actor, $stageId, function ($root, $stage, $intent, $current, $reads, $sites) {
            $receipt = $this->ledger->receipt($intent, $reads);
            if (! $receipt || $receipt->command !== 'upload_evidence') { throw new LogicException('This upload has no exact retained acceptance.'); }
            $this->ledger->reconstruct($receipt, $intent, $root, $current, $reads, $sites);
            $file = $this->admittedEvidenceInReads($root, (int) $receipt->source_id, $current, $reads, $sites, true);
            if ($file->path !== $stage->path || $file->disk !== 'private'
                || $file->sha256 !== ($stage->file_evidence['sha256'] ?? null) || $file->size !== ($stage->file_evidence['size'] ?? null)) {
                throw new LogicException('Accepted upload physical source linkage is damaged.');
            }
            $stage->forceFill(['state' => 'accepted', 'receipt_id' => $receipt->id, 'evidence_id' => $file->id]);
            $this->source->save($stage);
            return $this->stageDto($stage, true);
        });
    }

    public function remove(SubstanceExposureRecord|int $source, int $evidenceId, User $actor, array $input): array
    {
        $v = ChemicalCommandValues::normalize($input, ['reason' => ['required', 'string', 'max:2000']]);
        if (trim($v['reason']) === '') { ChemicalCommandValues::invalid('reason', 'Record why this evidence is removed from the active record.'); }
        $v['evidence_id'] = $evidenceId;
        return $this->source->command($source, $actor, 'remove_evidence', $v, function ($root, $current, $reads, $sites) use ($evidenceId, $v) {
            $file = $this->admittedEvidenceInReads($root, $evidenceId, $current, $reads, $sites);
            $this->source->currentVersion($root, $file, 'evidence', $reads, $current, $sites);
            $before = $this->ledger->snapshot($file);
            $file->forceFill(['source_revision' => $root->source_revision + 1, 'removed_by' => $current->id,
                'removal_reason' => $v['reason'], 'deleted_at' => now()->utc()]);
            $this->source->save($file);
            return [$file, 'evidence', $before, true, ['reason' => $v['reason'], 'file_evidence' => ['sha256' => $file->sha256, 'size' => $file->size]]];
        }, function ($root, $current, $reads, $result, $sites) {
            $file = $this->admittedEvidenceInReads($root, (int) $result['source_id'], $current, $reads, $sites, true);
            $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('actor_id', $current->id)
                ->where('idempotency_key', $result['idempotency_key'])->where('command', 'remove_evidence')->firstOrFail();
            $cleanup = ChemicalExposureEvidenceCleanup::where('evidence_id', $file->id)->where('exposure_id', $root->id)
                ->where('site_id', $root->site_id)->lockForUpdate()->first(['id', 'exposure_id', 'site_id', 'evidence_id', 'receipt_id']);
            if ($cleanup !== null) {
                $this->admitCleanupIdentityBeforeBody($cleanup, $root, $current, $reads, $sites);
                $cleanup = $reads->query(ChemicalExposureEvidenceCleanup::query())->whereKey($cleanup->id)
                    ->where('exposure_id', $root->id)->where('site_id', $root->site_id)->where('evidence_id', $file->id)
                    ->where('receipt_id', $cleanup->receipt_id)->firstOrFail();
            }
            if (! $cleanup) {
                $cleanup = new ChemicalExposureEvidenceCleanup(['actor_id' => $current->id, 'exposure_id' => $root->id,
                    'site_id' => $root->site_id, 'evidence_id' => $file->id, 'receipt_id' => $receipt->id, 'disk' => 'private',
                    'path' => $file->path, 'sha256' => $file->sha256, 'size' => $file->size, 'state' => 'pending', 'attempts' => 0]);
                $this->source->save($cleanup);
            }
            $this->removedProof($cleanup, $root, $current, $reads, $sites);
            $id = (int) $cleanup->id;
            DB::afterCommit(function () use ($current, $id) {
                try { $this->retryRemovalCleanup($current, $id); }
                catch (Throwable) { /* The committed pending ledger remains recoverable under current source authority. */ }
            });
        });
    }

    /** Inspection never interprets a durable stage as source acceptance. */
    public function inspectStaging(User $actor, int $id): array
    {
        return $this->withStage($actor, $id, function ($root, $stage, $intent, $current, $reads, $sites) {
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                $this->ledger->reconstruct($receipt, $intent, $root, $current, $reads, $sites);
                return ['id' => (int) $stage->id, 'state' => 'accepted', 'accepted' => true, 'can_retry' => false];
            }
            return $this->stageDto($stage);
        });
    }

    public function retryStagingCleanup(User $actor, int $id): array
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Exposure cleanup cannot run inside an outer transaction.'); }
        return $this->withStage($actor, $id, function ($root, $stage, $intent, $current, $reads, $sites) {
            if ($this->ledger->receipt($intent, $reads) !== null || $stage->receipt_id !== null || $stage->state === 'accepted') {
                throw new LogicException('Accepted exposure bytes cannot be staging cleanup candidates.');
            }
            $stage->forceFill(['state' => 'cleanup_pending', 'attempts' => $stage->attempts + 1]);
            $this->source->save($stage);
            try {
                if ($this->storage->retainedReference($stage->path, $reads, (int) $stage->id)) { return $this->stageDto($stage); }
                $this->storage->discard($stage->path, array_intersect_key($stage->file_evidence, ['sha256' => true, 'size' => true]));
                $stage->state = 'cleaned'; $this->source->save($stage);
            } catch (Throwable) {
                // Keep the physically committed original reservation and an explicit pending state; no false purge claim.
                $stage->state = 'cleanup_pending'; $this->source->save($stage);
            }
            return $this->stageDto($stage);
        });
    }

    public function stagingAttemptsForViewer(User $actor, int $afterId = 0): array
    {
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($afterId) {
            $this->access->assertOperation($current, $reads, 'manage');
            $rows = $reads->query(ChemicalExposureEvidenceStaging::query())->where('actor_id', $current->id)
                ->whereIn('site_id', $sites)->whereIn('exposure_id', app(ChemicalExposureAccessService::class)
                    ->readableQuery($current, $reads, $sites)->select('substance_exposure_records.id'))
                ->where('id', '>', max(0, $afterId))->where('state', '!=', 'accepted')->orderBy('id')->limit(26)->get(['id', 'exposure_id']);
            $items = [];
            foreach ($rows->take(25) as $identity) {
                $root = $this->access->source((int) $identity->exposure_id, $current, $reads, $sites);
                $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
                $this->admitStageIdentity((int) $identity->id, $root, $current, $reads, $sites);
                $stage = $reads->query(ChemicalExposureEvidenceStaging::query())->where('actor_id', $current->id)
                    ->where('exposure_id', $root->id)->whereIn('site_id', $sites)->whereKey($identity->id)->firstOrFail();
                $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($stage->intent_id)->where('actor_id', $current->id)
                    ->where('source_type', 'exposure')->firstOrFail();
                $this->assertStage($stage, $intent, $root, $current, $reads);
                $items[] = $this->stageDto($stage);
            }
            return ['attempts' => $items, 'next_cursor' => $rows->count() > 25 ? (int) $rows[24]->id : null];
        });
    }

    public function cleanupAttemptsForViewer(User $actor, int $afterId = 0): array
    {
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($afterId) {
            $this->access->assertOperation($current, $reads, 'manage');
            $rows = $reads->query(ChemicalExposureEvidenceCleanup::query())->where('actor_id', $current->id)
                ->whereIn('site_id', $sites)->whereIn('exposure_id', app(ChemicalExposureAccessService::class)
                    ->readableQuery($current, $reads, $sites)->select('substance_exposure_records.id'))
                ->where('id', '>', max(0, $afterId))->orderBy('id')->limit(26)->get(['id', 'exposure_id']);
            $items = [];
            foreach ($rows->take(25) as $identity) {
                $root = $this->access->source((int) $identity->exposure_id, $current, $reads, $sites);
                $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
                $cleanupIdentity = $reads->query(ChemicalExposureEvidenceCleanup::query())->where('actor_id', $current->id)
                    ->where('exposure_id', $root->id)->whereIn('site_id', $sites)->whereKey($identity->id)
                    ->firstOrFail(['id', 'exposure_id', 'site_id', 'evidence_id', 'receipt_id']);
                $this->admitCleanupIdentityBeforeBody($cleanupIdentity, $root, $current, $reads, $sites);
                $row = $reads->query(ChemicalExposureEvidenceCleanup::query())->where('actor_id', $current->id)
                    ->where('exposure_id', $root->id)->whereIn('site_id', $sites)->whereKey($cleanupIdentity->id)
                    ->where('evidence_id', $cleanupIdentity->evidence_id)->where('receipt_id', $cleanupIdentity->receipt_id)->firstOrFail();
                $this->removedProof($row, $root, $current, $reads, $sites);
                $items[] = $this->cleanupDto($row);
            }
            return ['attempts' => $items, 'next_cursor' => $rows->count() > 25 ? (int) $rows[24]->id : null];
        });
    }

    public function retryRemovalCleanup(User $actor, int $id): array
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Exposure byte purge must follow the real root commit.'); }
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($id) {
            $this->access->assertOperation($current, $reads, 'manage');
            $identity = $reads->query(ChemicalExposureEvidenceCleanup::query())->select(['id', 'exposure_id', 'site_id', 'evidence_id', 'receipt_id'])
                ->where('actor_id', $current->id)->whereIn('site_id', $sites)->whereKey($id)->firstOrFail();
            $root = $this->access->source((int) $identity->exposure_id, $current, $reads, $sites);
            $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
            $this->admitCleanupIdentityBeforeBody($identity, $root, $current, $reads, $sites);
            $rowQuery = $reads->query(ChemicalExposureEvidenceCleanup::query())->where('actor_id', $current->id)
                ->where('exposure_id', $root->id)->whereIn('site_id', $sites)->whereKey($id)
                ->where('evidence_id', $identity->evidence_id)->where('receipt_id', $identity->receipt_id);
            $rowQuery->getQuery()->beforeQuery(fn ($compiled) => $compiled->lock('for update nowait'));
            $row = $rowQuery->firstOrFail();
            $file = $this->removedProof($row, $root, $current, $reads, $sites);
            $row->attempts++; $row->state = 'pending'; $this->source->save($row);
            try {
                $stage = $reads->query(ChemicalExposureEvidenceStaging::query())->where('evidence_id', $file->id)->where('exposure_id', $root->id)->first();
                $acceptedStageId = null;
                if ($stage) {
                    $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($stage->intent_id)->where('source_type', 'exposure')->firstOrFail();
                    $historical = new User; $historical->forceFill(['id' => $stage->actor_id]);
                    $this->assertStage($stage, $intent, $root, $historical, $reads);
                    $upload = $reads->query(ChemicalExposureCommandReceipt::query())->whereKey($stage->receipt_id)->firstOrFail();
                    $this->ledger->assertAcceptance($upload, $intent, $root->getMorphClass(), $reads, $current, $sites);
                    if ($upload->source_type !== 'evidence' || $upload->source_id !== (int) $file->id || $stage->state !== 'accepted'
                        || $stage->path !== $file->path || $stage->file_evidence['sha256'] !== $file->sha256
                        || $stage->file_evidence['size'] !== $file->size) { throw new LogicException('Retained upload staging proof conflicts.'); }
                    $acceptedStageId = (int) $stage->id;
                }
                if ($this->storage->retainedReference($row->path, $reads, null, (int) $file->id, (int) $row->id, $acceptedStageId)) {
                    return $this->cleanupDto($row);
                }
                $this->storage->discard($row->path, ['sha256' => $row->sha256, 'size' => $row->size]);
                $row->state = 'complete'; $this->source->save($row);
            } catch (Throwable) {
                $row->state = 'pending'; $this->source->save($row);
            }
            return $this->cleanupDto($row);
        });
    }

    /** Internal download descriptor only; controllers must spool and compare one opened stream before emission. */
    public function evidenceReadProof(User $viewer, int $exposureId, int $evidenceId): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($exposureId, $evidenceId) {
            $root = $this->access->source($exposureId, $current, $reads, $sites, 'read');
            $file = $this->admittedEvidenceInReads($root, $evidenceId, $current, $reads, $sites);
            $version = $this->source->currentVersion($root, $file, 'evidence', $reads, $current, $sites);
            if (! $version || $file->disk !== 'private' || ($version->content['facts']['file_evidence']['sha256'] ?? null) !== $file->sha256
                || ($version->content['facts']['file_evidence']['size'] ?? null) !== $file->size) {
                throw new LogicException('Exposure download source proof is unconfirmed.');
            }
            $actual = $this->storage->bytes($file->path);
            if ($actual !== ['sha256' => $file->sha256, 'size' => $file->size]) {
                throw new LogicException('Exposure download bytes are unavailable or damaged.');
            }
            return ['disk' => 'private', 'path' => $file->path, 'original_name' => $file->original_name, 'mime' => $file->mime,
                'byte_proof' => $actual, 'source_version_id' => (int) $version->id, 'source_revision' => (int) $file->source_revision];
        });
    }

    /** Audit-only proof using the caller's active source admission; no path/body or refreshed actor escapes. */
    public function auditSubjectIsProven(string $kind, int $id, SubstanceExposureRecord $root, User $current,
        CurrentAuthorizationReads $reads, array $sites): bool
    {
        $this->access->assertOperation($current, $reads, 'read');
        if ($kind === 'staging') {
            $this->admitStageIdentity($id, $root, $current, $reads, $sites);
            $stage = $reads->query(ChemicalExposureEvidenceStaging::query())->where('exposure_id', $root->id)->whereKey($id)->firstOrFail();
            $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($stage->intent_id)->where('source_type', 'exposure')->firstOrFail();
            $historical = new User; $historical->forceFill(['id' => $stage->actor_id]);
            $this->assertStage($stage, $intent, $root, $historical, $reads);
            if ($stage->receipt_id !== null || $stage->state === 'accepted') {
                $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->whereKey($stage->receipt_id)->where('intent_id', $intent->id)->firstOrFail();
                $this->ledger->assertAcceptance($receipt, $intent, $root->getMorphClass(), $reads, $current, $sites);
                if ($receipt->command !== 'upload_evidence' || $receipt->source_id !== $stage->evidence_id) { throw new LogicException('Exposure accepted stage subject is damaged.'); }
            }
            return true;
        }
        if ($kind !== 'cleanup') { return false; }
        $identity = $reads->query(ChemicalExposureEvidenceCleanup::query())->where('exposure_id', $root->id)->whereKey($id)
            ->firstOrFail(['id', 'exposure_id', 'site_id', 'evidence_id', 'receipt_id']);
        $this->admitCleanupIdentityBeforeBody($identity, $root, $current, $reads, $sites);
        $row = $reads->query(ChemicalExposureEvidenceCleanup::query())->where('exposure_id', $root->id)->whereKey($id)
            ->where('evidence_id', $identity->evidence_id)->where('receipt_id', $identity->receipt_id)->firstOrFail();
        $file = $this->admittedEvidenceInReads($root, (int) $row->evidence_id, $current, $reads, $sites, true);
        $version = $this->source->currentVersion($root, $file, 'evidence', $reads, $current, $sites);
        $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->whereKey($row->receipt_id)->firstOrFail();
        $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($receipt->intent_id)->where('source_type', 'exposure')->firstOrFail();
        $this->ledger->assertAcceptance($receipt, $intent, $root->getMorphClass(), $reads, $current, $sites);
        if (! $file->trashed() || $receipt->command !== 'remove_evidence' || $receipt->source_id !== (int) $file->id
            || $receipt->source_version_id !== $version?->id || $row->site_id !== (int) $root->site_id
            || $row->path !== $file->path || $row->sha256 !== $file->sha256 || $row->size !== $file->size) {
            throw new LogicException('Exposure cleanup audit source is damaged.');
        }
        return true;
    }
    private function withStage(User $actor, int $id, callable $callback): mixed
    {
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($id, $callback) {
            $this->access->assertOperation($current, $reads, 'manage');
            $identity = $reads->query(ChemicalExposureEvidenceStaging::query())->select(['id', 'exposure_id'])
                ->where('actor_id', $current->id)->whereIn('site_id', $sites)->whereKey($id)->firstOrFail();
            $root = $this->access->source((int) $identity->exposure_id, $current, $reads, $sites);
            $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
            $this->admitStageIdentity($id, $root, $current, $reads, $sites);
            $stageQuery = $reads->query(ChemicalExposureEvidenceStaging::query())->where('actor_id', $current->id)
                ->where('exposure_id', $root->id)->whereIn('site_id', $sites)->whereKey($id);
            $stageQuery->getQuery()->beforeQuery(fn ($compiled) => $compiled->lock('for update nowait'));
            $stage = $stageQuery->firstOrFail();
            $intent = ChemicalCommandIntent::whereKey($stage->intent_id)->where('actor_id', $current->id)
                ->where('source_type', 'exposure')->lockForUpdate()->firstOrFail();
            $this->assertStage($stage, $intent, $root, $current, $reads);
            return $callback($root, $stage, $intent, $current, $reads, $sites);
        });
    }

    /** Original reserved identity/association must be admitted before any staged UUID, description or file body. */
    private function admitStageIdentity(int $id, SubstanceExposureRecord $root, User $current,
        CurrentAuthorizationReads $reads, array $sites): void
    {
        $this->access->assertOperation($current, $reads, 'read');
        $identity = $reads->query(ChemicalExposureEvidenceStaging::query())->select(['id', 'exposure_id', 'substance_id',
            'worker_id', 'site_id', 'expected_version'])->where('exposure_id', $root->id)->whereKey($id)->firstOrFail();
        $this->access->site((int) $identity->site_id, $reads, $sites);
        if ($identity->exposure_id !== (int) $root->id || $identity->substance_id !== (int) $root->hazardous_substance_id
            || $identity->worker_id !== (int) $root->user_id || $identity->site_id !== (int) $root->site_id
            || $identity->expected_version > (int) $root->source_revision) {
            throw new LogicException('Exposure reserved original source tuple is no longer canonical.');
        }
        if ($identity->expected_version > 0) {
            $version = $reads->query(\App\Models\ChemicalExposureSourceVersion::query())->select(['id', 'worker_id', 'site_id', 'substance_id'])
                ->where('exposure_id', $root->id)->where('aggregate_revision', $identity->expected_version)->first();
            if (! $version || $version->worker_id !== $identity->worker_id || $version->site_id !== $identity->site_id
                || $version->substance_id !== $identity->substance_id) {
                throw new LogicException('Exposure staged original source version identity is missing or changed.');
            }
            $this->source->admitVersionMetadata((int) $version->id, $current, $reads, $sites);
        }
    }
    private function assertStage(ChemicalExposureEvidenceStaging $stage, ChemicalCommandIntent $intent,
        SubstanceExposureRecord $root, User $actor, CurrentAuthorizationReads $reads): void
    {
        $reads->assertActive();
        if ($stage->intent_id !== (int) $intent->id || $stage->actor_id !== (int) $actor->id
            || $intent->actor_id !== (int) $actor->id || $intent->source_type !== 'exposure' || $intent->source_id !== (int) $root->id
            || $intent->parent_id !== (int) $root->hazardous_substance_id || $intent->command !== 'upload_evidence'
            || $stage->expected_version !== $intent->expected_version || $stage->exposure_id !== (int) $root->id
            || $stage->substance_id !== (int) $root->hazardous_substance_id || $stage->worker_id !== (int) $root->user_id
            || $stage->site_id !== (int) $root->site_id || $stage->disk !== 'private'
            || ! is_array($stage->command_values)
            || ($stage->command_values['idempotency_key'] ?? null) !== $intent->idempotency_key
            || ChemicalCommandValues::digest($stage->file_evidence) !== ChemicalCommandValues::digest($stage->command_values['file_evidence'] ?? null)
            || ! hash_equals($intent->fingerprint, ChemicalCommandValues::fingerprint('exposure', (int) $root->id,
                (int) $root->hazardous_substance_id, (int) $actor->id, 'upload_evidence', $stage->command_values))
            || ! str_starts_with($stage->path, 'health-safety/chemical-exposures/'.$root->id.'/'.$intent->idempotency_key.'/')
            || ! hash_equals($stage->reservation_digest, $this->storage->reservationDigest($stage))) {
            throw new LogicException('Exposure reservation original intent or canonical source tuple is damaged.');
        }
    }

    private function expected(SubstanceExposureRecord $root, array $v): void
    {
        if ((int) $root->source_revision !== $v['expected_version']) {
            ChemicalCommandValues::invalid('expected_version', 'The private source changed. Keep this upload and review current work.');
        }
    }

    private function markPending(User $actor, int $id): void
    {
        try {
            $this->withStage($actor, $id, function ($root, $stage, $intent, $current, $reads, $sites) {
                if ($this->ledger->receipt($intent, $reads) === null && $stage->receipt_id === null) {
                    $stage->state = 'cleanup_pending'; $this->source->save($stage);
                }
                return null;
            });
        } catch (Throwable) {
            // The physically committed original stage remains discoverable; failure to mark cannot erase it or claim rejection accepted.
        }
    }

    private function bindStageInside(SubstanceExposureRecord $root, User $current, CurrentAuthorizationReads $reads,
        int $stageId, array $result, array $sites): void
    {
        $stage = ChemicalExposureEvidenceStaging::where('exposure_id', $root->id)->where('actor_id', $current->id)
            ->whereKey($stageId)->lock('for update nowait')->firstOrFail();
        $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($stage->intent_id)->where('source_type', 'exposure')->firstOrFail();
        $this->assertStage($stage, $intent, $root, $current, $reads);
        $receipt = $this->ledger->receipt($intent, $reads);
        if (! $receipt || $receipt->source_id !== $result['source_id'] || $receipt->command !== 'upload_evidence') {
            throw new LogicException('Exposure staging has no exact accepted upload.');
        }
        $this->ledger->reconstruct($receipt, $intent, $root, $current, $reads, $sites);
        $file = $this->admittedEvidenceInReads($root, (int) $receipt->source_id, $current, $reads, $sites, true);
        if ($file->path !== $stage->path || $file->disk !== 'private'
            || $file->sha256 !== ($stage->file_evidence['sha256'] ?? null) || $file->size !== ($stage->file_evidence['size'] ?? null)) {
            throw new LogicException('Exposure accepted upload tuple was altered.');
        }
        $stage->forceFill(['state' => 'accepted', 'receipt_id' => $receipt->id, 'evidence_id' => $file->id]);
        $this->source->save($stage);
    }
    private function removedProof(ChemicalExposureEvidenceCleanup $row, SubstanceExposureRecord $root,
        User $current, CurrentAuthorizationReads $reads, array $sites): ChemicalExposureEvidence
    {
        $file = $this->admittedEvidenceInReads($root, (int) $row->evidence_id, $current, $reads, $sites, true);
        $v = $this->source->currentVersion($root, $file, 'evidence', $reads, $current, $sites);
        $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->whereKey($row->receipt_id)->where('actor_id', $current->id)->firstOrFail();
        $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($receipt->intent_id)->where('source_type', 'exposure')->firstOrFail();
        $this->ledger->reconstruct($receipt, $intent, $root, $current, $reads, $sites);
        if (! $file->trashed() || $receipt->command !== 'remove_evidence' || $receipt->source_id !== (int) $file->id
            || $receipt->source_version_id !== $v?->id || $row->site_id !== (int) $root->site_id || $row->disk !== 'private'
            || $row->path !== $file->path || $row->sha256 !== $file->sha256 || $row->size !== $file->size) {
            throw new LogicException('Exposure removal cleanup has no exact accepted source proof.');
        }
        return $file;
    }

    /** The canonical Source guard owns all typed/legacy/original-context decisions. */
    private function evidenceIdentityBeforeBody(SubstanceExposureRecord $root, int $id, User $current,
        CurrentAuthorizationReads $reads, array $sites, bool $includeRemoved = false): ChemicalExposureEvidence
    {
        $query = $includeRemoved ? ChemicalExposureEvidence::withTrashed() : ChemicalExposureEvidence::query();
        $identity = $reads->query($query)->where('exposure_id', $root->id)->whereKey($id)
            ->firstOrFail(['id', 'exposure_id', 'source_schema_version', 'source_revision']);
        $this->source->assertSourceIdentityBeforeBody($root, $identity, 'evidence', $reads, $current, $sites);
        if ((int) $identity->source_schema_version === 1) {
            $version = $reads->query(\App\Models\ChemicalExposureSourceVersion::query())
                ->where('exposure_id', $root->id)->where('source_type', 'evidence')->where('source_id', $identity->id)
                ->orderByDesc('aggregate_revision')->first(['id', 'aggregate_revision']);
            if ($version === null || (int) $version->aggregate_revision !== (int) $identity->source_revision) {
                throw new LogicException('Exposure evidence identity lacks its exact current accepted revision.');
            }
            $this->source->admitVersionMetadata((int) $version->id, $current, $reads, $sites);
        }
        return $identity;
    }

    private function admittedEvidenceInReads(SubstanceExposureRecord $root, int $id, User $current,
        CurrentAuthorizationReads $reads, array $sites, bool $includeRemoved = false): ChemicalExposureEvidence
    {
        $identity = $this->evidenceIdentityBeforeBody($root, $id, $current, $reads, $sites, $includeRemoved);
        $query = $includeRemoved ? ChemicalExposureEvidence::withTrashed() : ChemicalExposureEvidence::query();
        return $reads->query($query)->where('exposure_id', $root->id)->whereKey($identity->id)
            ->where('source_schema_version', $identity->source_schema_version)
            ->where('source_revision', $identity->source_revision)->firstOrFail();
    }

    /** No cleanup path/digest body before its accepted original receipt and exact child lineage. */
    private function admitCleanupIdentityBeforeBody(ChemicalExposureEvidenceCleanup $identity,
        SubstanceExposureRecord $root, User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        if ($identity->exposure_id !== (int) $root->id || $identity->site_id !== (int) $root->site_id) {
            throw new LogicException('Exposure cleanup identity differs from its canonical source.');
        }
        $this->access->site((int) $identity->site_id, $reads, $sites);
        $accepted = $this->source->admitReceiptMetadata((int) $identity->receipt_id, $current, $reads, $sites);
        if (($accepted['after']['exposure_id'] ?? null) !== (int) $root->id) {
            throw new LogicException('Exposure cleanup acceptance belongs to another source.');
        }
        $this->evidenceIdentityBeforeBody($root, (int) $identity->evidence_id, $current, $reads, $sites, true);
    }

    private function stageDto(ChemicalExposureEvidenceStaging $row, bool $accepted = false): array
    {
        return ['id' => (int) $row->id, 'state' => $row->state, 'accepted' => $accepted,
            'recorded_at' => $row->created_at?->copy()->utc()->toIso8601String(), 'can_retry' => $row->receipt_id === null && $row->state !== 'accepted'];
    }

    private function cleanupDto(ChemicalExposureEvidenceCleanup $row): array
    {
        return ['id' => (int) $row->id, 'state' => $row->state, 'removed_from_record' => true,
            'recorded_at' => $row->created_at?->copy()->utc()->toIso8601String(), 'can_retry' => $row->state !== 'complete'];
    }
}
