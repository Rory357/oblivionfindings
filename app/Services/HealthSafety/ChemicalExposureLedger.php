<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Callers must admit the original private operation before any intent, version or receipt query. */
final class ChemicalExposureLedger
{
    public const RECEIPT_KEYS = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id',
        'source_type', 'source_id', 'expected_version', 'version', 'source_version_id', 'changed'];
    public const NOOP_COMMANDS = ['correct_exposure'];

    public function claim(User $actor, CurrentAuthorizationReads $reads, ?int $exposureId, int $substanceId,
        string $command, array $values, array $sites): ChemicalCommandIntent
    {
        $reads->assertActive();
        $fingerprint = ChemicalCommandValues::fingerprint('exposure', $exposureId, $substanceId, (int) $actor->id, $command, $values);
        DB::table('chemical_command_intents')->insertOrIgnore(['idempotency_key' => $values['idempotency_key'],
            'actor_id' => $actor->id, 'actor_snapshot' => json_encode(['id' => (int) $actor->id, 'name' => $actor->name], JSON_THROW_ON_ERROR),
            'source_type' => 'exposure', 'source_id' => $exposureId, 'parent_id' => $substanceId, 'command' => $command,
            'expected_version' => $values['expected_version'] ?? null, 'parent_expected_version' => null,
            'fingerprint' => $fingerprint, 'created_at' => now()->utc()->format('Y-m-d H:i:s.u')]);
        // The shared UNIQUE UUID arbitrates simultaneous public/private and different-root first claims.
        $identity = ChemicalCommandIntent::select(['id', 'source_id', 'parent_id', 'command', 'expected_version', 'parent_expected_version', 'fingerprint'])
            ->where('idempotency_key', $values['idempotency_key'])->where('actor_id', $actor->id)
            ->where('source_type', 'exposure')->lockForUpdate()->first();
        if (! $identity || $identity->source_id !== $exposureId || $identity->parent_id !== $substanceId
            || $identity->command !== $command || $identity->expected_version !== ($values['expected_version'] ?? null)
            || $identity->parent_expected_version !== null || ! hash_equals($identity->fingerprint, $fingerprint)) {
            ChemicalCommandValues::invalid('idempotency_key', 'This UUID is unavailable for this exact chemical command.');
        }
        $receiptIdentity = $reads->query(ChemicalExposureCommandReceipt::query())->select(['id'])
            ->where('intent_id', $identity->id)->where('actor_id', $actor->id)->first();
        if ($receiptIdentity) {
            app(ChemicalExposureSourceService::class)->admitReceiptMetadata((int) $receiptIdentity->id,
                $actor, $reads, $sites, $command === 'create_exposure' ? 'capture' : 'read');
        } elseif ($exposureId !== null && ($values['expected_version'] ?? 0) > 0
            && $reads->query(\App\Models\ChemicalExposureEvidenceStaging::query())->where('intent_id', $identity->id)->exists()) {
            $versionIdentity = $reads->query(ChemicalExposureSourceVersion::query())->select(['id'])
                ->where('exposure_id', $exposureId)->where('aggregate_revision', $values['expected_version'])->first();
            if (! $versionIdentity) { throw new LogicException('Reserved exposure intent has no original source acceptance.'); }
            app(ChemicalExposureSourceService::class)->admitVersionMetadata((int) $versionIdentity->id, $actor, $reads, $sites);
        }
        $intent = ChemicalCommandIntent::whereKey($identity->id)->where('actor_id', $actor->id)
            ->where('source_type', 'exposure')->lockForUpdate()->firstOrFail();        // After the UNIQUE claim, retained original foreign IDs must still bind this same immutable intent.
        foreach ([ChemicalExposureCommandReceipt::class, ChemicalExposureSourceVersion::class] as $model) {
            if ($reads->query($model::query())->where('idempotency_key', $intent->idempotency_key)
                ->where('intent_id', '!=', $intent->id)->exists()) {
                throw new LogicException('Retained chemical proof cannot recreate a missing original UUID intent.');
            }
        }
        if ($reads->query(\App\Models\ChemicalExposureEvidenceStaging::query())
            ->where('command_values->idempotency_key', $intent->idempotency_key)->where('intent_id', '!=', $intent->id)->exists()
            || DB::table('chemical_command_receipts')->where('idempotency_key', $intent->idempotency_key)
                ->where('intent_id', '!=', $intent->id)->lock('for share nowait')->exists()) {
            throw new LogicException('Retained chemical physical intent cannot be rebound.');
        }
        return $intent;
    }

    public function receipt(ChemicalCommandIntent $intent, CurrentAuthorizationReads $reads): ?ChemicalExposureCommandReceipt
    {
        $reads->assertActive();
        if ($intent->source_type !== 'exposure') { throw new LogicException('No private receipt admission for a public intent.'); }
        return ChemicalExposureCommandReceipt::where('intent_id', $intent->id)->where('actor_id', $intent->actor_id)
            ->where('idempotency_key', $intent->idempotency_key)->lockForUpdate()->first();
    }

    public function version(ChemicalCommandIntent $intent, SubstanceExposureRecord $root, Model $source, string $kind,
        User $actor, CurrentAuthorizationReads $reads, ?array $before, ?array $sourceBefore, array $facts = []): ChemicalExposureSourceVersion
    {
        $reads->assertActive();
        $tuple = ['exposure_id' => (int) $root->id, 'source_type' => $kind, 'source_id' => (int) $source->id,
            'substance_id' => (int) $root->hazardous_substance_id, 'worker_id' => (int) $root->user_id,
            'site_id' => (int) $root->site_id, 'recorder_id' => $root->created_by === null ? null : (int) $root->created_by,
            'actor_id' => (int) $actor->id, 'intent_id' => (int) $intent->id, 'aggregate_revision' => (int) $root->source_revision,
            'expected_version' => $intent->expected_version, 'source_schema_version' => 1,
            'command' => $intent->command, 'idempotency_key' => $intent->idempotency_key];
        if (array_key_exists('reported_facts', $facts)) {
            if (! is_array($facts['reported_facts'])) { throw new LogicException('Reported facts must be a closed contract.'); }
            ChemicalExposureReportedFacts::assertContract($facts['reported_facts'], $this->snapshot($root));
        }
        $actorSnapshot = ['id' => (int) $actor->id, 'name' => $actor->name];
        $at = now()->utc();
        $content = ['tuple' => $tuple, 'actor_snapshot' => $actorSnapshot, 'recorded_at' => $at->format('Y-m-d H:i:s.u'),
            'intent_fingerprint' => $intent->fingerprint, 'root_before' => $before, 'source_before' => $sourceBefore,
            'root_after' => $this->snapshot($root), 'source_after' => $this->snapshot($source), 'facts' => $facts];
        $version = new ChemicalExposureSourceVersion([...$tuple, 'actor_snapshot' => $actorSnapshot,
            'content' => $content, 'content_digest' => ChemicalCommandValues::digest($content), 'recorded_at' => $at]);
        if (! $version->save()) { throw new LogicException('Exposure immutable version save was vetoed.'); }
        $retained = $version->persisted();
        $this->assertDigest($retained);
        return $retained;
    }

    public function accept(ChemicalCommandIntent $intent, SubstanceExposureRecord $root, Model $source, string $kind,
        ?ChemicalExposureSourceVersion $version, User $actor, CurrentAuthorizationReads $reads, bool $changed, array $sites): array
    {
        $reads->assertActive();
        if ($changed !== ($version !== null) || (! $changed && ! in_array($intent->command, self::NOOP_COMMANDS, true))) {
            throw new LogicException('Exposure source version must describe a genuine change or an allowed unchanged command.');
        }
        $request = Request::create('/');
        $request->setUserResolver(fn () => $actor);
        $result = ['accepted' => true, 'actor_id' => (int) $actor->id, 'command' => $intent->command,
            'idempotency_key' => $intent->idempotency_key, 'root_type' => 'exposure', 'root_id' => (int) $root->id,
            'source_type' => $kind, 'source_id' => (int) $source->id, 'expected_version' => $intent->expected_version,
            'version' => (int) $root->source_revision, 'source_version_id' => $version?->id, 'changed' => $changed];
        $afterTuple = $this->sourceTuple($this->snapshot($root));
        $beforeSnapshot = $version?->content['root_before'] ?? null;
        $tuple = ['before' => is_array($beforeSnapshot) ? $this->sourceTuple($beforeSnapshot) : ($version === null ? $afterTuple : null),
            'after' => $afterTuple];
        $tupleDigest = ChemicalCommandValues::digest($tuple);
        $meta = [...array_diff_key($result, ['accepted' => true, 'command' => true]),
            'source_content_digest' => $version?->content_digest, 'source_tuple_digest' => $tupleDigest];
        AuditLogger::logOrFail('chemical.exposure.'.$intent->command, $root, $meta, $request);
        $rows = AuditLog::where('user_id', $actor->id)->where('action', 'chemical.exposure.'.$intent->command)
            ->where('auditable_type', $root->getMorphClass())->where('auditable_id', $root->id)
            ->where('meta->idempotency_key', $intent->idempotency_key)->lockForUpdate()->get();
        if ($rows->count() !== 1 || ChemicalCommandValues::digest(array_intersect_key($rows[0]->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('Exposure exact semantic audit was not retained.');
        }
        $receipt = new ChemicalExposureCommandReceipt(['idempotency_key' => $intent->idempotency_key, 'intent_id' => $intent->id,
            'actor_id' => $actor->id, 'exposure_id' => $root->id, 'source_type' => $kind, 'source_id' => $source->id,
            'command' => $intent->command, 'expected_version' => $intent->expected_version, 'fingerprint' => $intent->fingerprint,
            'source_version_id' => $version?->id, 'source_content_digest' => $version?->content_digest,
            'source_tuple' => $tuple, 'source_tuple_digest' => $tupleDigest, 'audit_log_id' => $rows[0]->id,
            'accepted_result' => $result, 'created_at' => now()->utc()]);
        if (! $receipt->save()) { throw new LogicException('Exposure acceptance save was vetoed.'); }
        return $this->reconstruct($receipt->persisted(), $intent, $root, $actor, $reads, $sites, $intent->command === 'create_exposure' ? 'capture' : 'read');
    }
    public function reconstruct(ChemicalExposureCommandReceipt $receipt, ChemicalCommandIntent $intent,
        SubstanceExposureRecord $root, User $actor, CurrentAuthorizationReads $reads, array $sites, string $purpose = 'read'): array
    {
        $reads->assertActive();
        $this->assertAcceptance($receipt, $intent, $root->getMorphClass(), $reads, $actor, $sites, $purpose);
        if ($receipt->actor_id !== (int) $actor->id || $receipt->exposure_id !== (int) $root->id
            || $intent->parent_id !== (int) $root->hazardous_substance_id
            || ($intent->source_id !== null && $intent->source_id !== (int) $root->id)) {
            throw new LogicException('Exposure acceptance does not belong to this actor and current source.');
        }
        if ($purpose === 'capture') {
            $after = $receipt->source_tuple['after'];
            if ((int) $root->source_schema_version !== 1 || (int) $root->source_revision < $receipt->accepted_result['version']
                || $after['exposure_id'] !== (int) $root->id || $after['substance_id'] !== (int) $root->hazardous_substance_id
                || $after['worker_id'] !== (int) $root->user_id || $after['site_id'] !== (int) $root->site_id) {
                throw new LogicException('Original capture no longer matches its current canonical factual source.');
            }
        }
        return array_intersect_key($receipt->accepted_result, array_flip(self::RECEIPT_KEYS));
    }

    public function assertAcceptance(ChemicalExposureCommandReceipt $r, ChemicalCommandIntent $intent, string $morph,
        CurrentAuthorizationReads $reads, User $current, array $sites, string $purpose = 'read'): void
    {
        $reads->assertActive();
        app(ChemicalExposureCommandAccess::class)->assertOperation($current, $reads, $purpose);
        if ($purpose === 'capture' && ($r->actor_id !== (int) $current->id || $intent->actor_id !== (int) $current->id
            || $r->command !== 'create_exposure' || $intent->command !== 'create_exposure')) {
            throw new LogicException('Capture recovery cannot inspect another actor or saved private command.');
        }
        $sourceProof = app(ChemicalExposureSourceService::class);
        $admittedReceipt = $sourceProof->admitReceiptMetadata((int) $r->id, $current, $reads, $sites, $purpose);
        if (ChemicalCommandValues::digest($r->source_tuple) !== ChemicalCommandValues::digest($admittedReceipt)
            || $r->source_tuple_digest !== ChemicalCommandValues::digest($admittedReceipt)) {
            throw new LogicException('Exposure receipt original source binding differs from admitted metadata.');
        }
        $result = $r->accepted_result;
        if (! is_array($result) || array_diff(self::RECEIPT_KEYS, array_keys($result)) !== []
            || $r->intent_id !== (int) $intent->id || $intent->source_type !== 'exposure'
            || $intent->actor_id !== $r->actor_id || $intent->command !== $r->command
            || $intent->idempotency_key !== $r->idempotency_key || $intent->expected_version !== $r->expected_version
            || ! hash_equals($r->fingerprint, $intent->fingerprint) || ($result['accepted'] ?? null) !== true
            || ! is_bool($result['changed']) || ! is_int($result['version']) || $result['version'] < 0) {
            throw new LogicException('Exposure retained acceptance identity is unconfirmed.');
        }
        $identity = ['actor_id' => $r->actor_id, 'command' => $r->command, 'idempotency_key' => $r->idempotency_key,
            'root_type' => 'exposure', 'root_id' => $r->exposure_id, 'source_type' => $r->source_type, 'source_id' => $r->source_id,
            'expected_version' => $r->expected_version, 'source_version_id' => $r->source_version_id];
        foreach ($identity as $key => $value) {
            if ($result[$key] !== $value) { throw new LogicException('Exposure receipt identity was altered.'); }
        }
        $meta = [...array_diff_key(array_intersect_key($result, array_flip(self::RECEIPT_KEYS)), ['accepted' => true, 'command' => true]), 'source_content_digest' => $r->source_content_digest, 'source_tuple_digest' => $r->source_tuple_digest];
        $audit = $reads->query(AuditLog::query())->whereKey($r->audit_log_id)->first();
        if (! $audit || (int) $audit->user_id !== $r->actor_id || $audit->action !== 'chemical.exposure.'.$r->command
            || $audit->auditable_type !== $morph || (int) $audit->auditable_id !== $r->exposure_id
            || ChemicalCommandValues::digest(array_intersect_key($audit->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('Exposure acceptance audit is missing or damaged.');
        }
        if ($result['changed']) {
            if ($result['version'] !== ($r->expected_version === null ? 1 : $r->expected_version + 1) || $r->source_version_id === null) {
                throw new LogicException('Exposure changed acceptance has impossible revision linkage.');
            }
            $sourceProof = app(ChemicalExposureSourceService::class);
            $admitted = $sourceProof->admitVersionMetadata($r->source_version_id, $current, $reads, $sites, $purpose);
            $version = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $r->exposure_id)->whereKey($r->source_version_id)->first();
            $sourceProof->assertAdmittedMetadata($version, $admitted);
            if (! $version || ! is_string($r->source_content_digest) || ! hash_equals($r->source_content_digest, $version->content_digest) || $version->exposure_id !== $r->exposure_id || $version->source_type !== $r->source_type
                || $version->source_id !== $r->source_id || $version->aggregate_revision !== $result['version']
                || $version->actor_id !== $r->actor_id || $version->intent_id !== $intent->id
                || $version->command !== $r->command || $version->idempotency_key !== $r->idempotency_key
                || $version->expected_version !== $r->expected_version
                || ($version->content['intent_fingerprint'] ?? null) !== $intent->fingerprint) {
                throw new LogicException('Exposure acceptance version is unconfirmed.');
            }
            $acceptedTuple = ['before' => is_array($version->content['root_before'] ?? null)
                ? $this->sourceTuple($version->content['root_before']) : null,
                'after' => $this->sourceTuple($version->content['root_after'])];
            if (ChemicalCommandValues::digest($acceptedTuple) !== $r->source_tuple_digest) {
                throw new LogicException('Exposure receipt original tuple is not bound to its accepted version.');
            }
            $this->assertDigest($version);
        } elseif (ChemicalCommandValues::digest($r->source_tuple['before'] ?? null) !== ChemicalCommandValues::digest($r->source_tuple['after'] ?? null)
            || $r->source_content_digest !== null || $r->source_version_id !== null || $result['version'] !== $r->expected_version
            || ! in_array($r->command, self::NOOP_COMMANDS, true)) {
            throw new LogicException('Exposure unchanged acceptance has impossible command linkage.');
        }
    }

    public function assertDigest(ChemicalExposureSourceVersion $v): void
    {
        $tuple = array_intersect_key($v->attributesToArray(), array_flip(['exposure_id', 'source_type', 'source_id',
            'substance_id', 'worker_id', 'site_id', 'recorder_id', 'actor_id', 'intent_id', 'aggregate_revision',
            'expected_version', 'source_schema_version', 'command', 'idempotency_key']));
        if ($v->source_schema_version !== 1 || ! is_array($v->content)
            || ! hash_equals($v->content_digest, ChemicalCommandValues::digest($v->content))
            || ChemicalCommandValues::digest($tuple) !== ChemicalCommandValues::digest($v->content['tuple'] ?? null)
            || ChemicalCommandValues::digest($v->actor_snapshot) !== ChemicalCommandValues::digest($v->content['actor_snapshot'] ?? null)
            || $v->recorded_at?->copy()->utc()->format('Y-m-d H:i:s.u') !== ($v->content['recorded_at'] ?? null)) {
            throw new LogicException('Exposure immutable digest or tuple is damaged.');
        }
        $after = $v->content['root_after'] ?? [];
        $facts = $v->content['facts'] ?? [];
        if (! is_array($facts)) { throw new LogicException('Exposure immutable fact container is damaged.'); }
        if (array_key_exists('reported_facts', $facts)) {
            if (! is_array($facts['reported_facts'])) { throw new LogicException('Exposure reported contract is damaged.'); }
            ChemicalExposureReportedFacts::assertContract($facts['reported_facts'], $after);
        }
        $child = $v->content['source_after'] ?? [];
        if (($after['id'] ?? null) !== $v->exposure_id || ($after['user_id'] ?? null) !== $v->worker_id
            || ($after['site_id'] ?? null) !== $v->site_id || ($after['hazardous_substance_id'] ?? null) !== $v->substance_id
            || ($after['created_by'] ?? null) !== $v->recorder_id || ($after['source_schema_version'] ?? null) !== 1
            || ($after['source_revision'] ?? null) !== $v->aggregate_revision || ($child['id'] ?? null) !== $v->source_id
            || ($child['source_revision'] ?? null) !== $v->aggregate_revision
            || ($v->source_type !== 'exposure' && ($child['exposure_id'] ?? null) !== $v->exposure_id)) {
            throw new LogicException('Exposure recorded source ownership is damaged.');
        }
    }

    private function sourceTuple(array $snapshot): array
    {
        return ['exposure_id' => $snapshot['id'], 'substance_id' => $snapshot['hazardous_substance_id'],
            'worker_id' => $snapshot['user_id'], 'site_id' => $snapshot['site_id'],
            'related_incident_id' => $snapshot['related_incident_id'] ?? null, 'original_context' => $snapshot['original_context'] ?? null];
    }
    public function snapshot(Model $source): array
    {
        return array_diff_key($source->attributesToArray(), ['created_at' => true, 'updated_at' => true]);
    }
}
