<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureRecoveryCase;
use App\Models\ChemicalExposureRecoveryReceipt;
use App\Models\ChemicalExposureRecoveryVersion;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal only: caller admits original sealed identity and each later context before this ledger. No old-root snapshot. */
final class ChemicalExposureRecoveryLedger
{
    public const KEYS = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id', 'source_type',
        'source_id', 'expected_version', 'version', 'source_version_id', 'changed'];

    public function claim(User $actor, array $identity, string $command, array $values, CurrentAuthorizationReads $reads): ChemicalCommandIntent
    {
        $reads->assertActive();
        $fingerprint = ChemicalCommandValues::fingerprint('exposure_association_review', $identity['id'],
            $identity['hazardous_substance_id'], (int) $actor->id, $command, $values);
        DB::table('chemical_command_intents')->insertOrIgnore(['idempotency_key' => $values['idempotency_key'],
            'actor_id' => $actor->id, 'actor_snapshot' => json_encode(['id' => (int) $actor->id, 'name' => $actor->name], JSON_THROW_ON_ERROR),
            'source_type' => 'exposure_association_review', 'source_id' => $identity['id'], 'parent_id' => $identity['hazardous_substance_id'],
            'command' => $command, 'expected_version' => $values['expected_version'], 'parent_expected_version' => null,
            'fingerprint' => $fingerprint, 'created_at' => now()->utc()->format('Y-m-d H:i:s.u')]);
        $intent = ChemicalCommandIntent::where('idempotency_key', $values['idempotency_key'])->lockForUpdate()->firstOrFail();
        if ($intent->actor_id !== (int) $actor->id || $intent->source_type !== 'exposure_association_review'
            || $intent->source_id !== $identity['id'] || $intent->parent_id !== $identity['hazardous_substance_id']
            || $intent->command !== $command || $intent->expected_version !== $values['expected_version']
            || $intent->parent_expected_version !== null || ! hash_equals($intent->fingerprint, $fingerprint)) {
            ChemicalCommandValues::invalid('idempotency_key', 'This UUID belongs to another exact command.');
        }
        foreach ([ChemicalExposureRecoveryVersion::class, ChemicalExposureRecoveryReceipt::class] as $model) {
            if ($reads->query($model::query())->where('idempotency_key', $intent->idempotency_key)->where('intent_id', '!=', $intent->id)->exists()) {
                throw new LogicException('A retained recovery acceptance cannot recreate its original claim.');
            }
        }
        if ($reads->query(\App\Models\ChemicalExposureRecoveryStaging::query())->where('idempotency_key', $intent->idempotency_key)
            ->where('intent_id', '!=', $intent->id)->exists()) { throw new LogicException('Recovery staging lost its original UUID claim.'); }
        return $intent;
    }

    /** Exact canonical byte frame corresponding to the existing global intent fingerprint, not a second grant/policy. */
    public function frame(User $actor, array $identity, string $command, array $values): string
    {
        $data = ['source_type' => 'exposure_association_review', 'source_id' => $identity['id'],
            'parent_id' => $identity['hazardous_substance_id'], 'actor_id' => (int) $actor->id,
            'command' => $command, 'values' => array_diff_key($values, ['idempotency_key' => true])];
        $sort = function ($item) use (&$sort) {
            if (is_array($item)) {
                if (! array_is_list($item)) { ksort($item, SORT_STRING); }
                foreach ($item as $key => $nested) { $item[$key] = $sort($nested); }
            }
            return $item;
        };
        $encoded = json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (hash('sha256', $encoded) !== ChemicalCommandValues::fingerprint('exposure_association_review', $identity['id'],
            $identity['hazardous_substance_id'], (int) $actor->id, $command, $values)) { throw new LogicException('Recovery canonical claim frame differs from shared fingerprint.'); }
        return $encoded;
    }

    public function receipt(ChemicalCommandIntent $intent, CurrentAuthorizationReads $reads): ?ChemicalExposureRecoveryReceipt
    {
        return $reads->query(ChemicalExposureRecoveryReceipt::query())->where('intent_id', $intent->id)
            ->where('idempotency_key', $intent->idempotency_key)->where('actor_id', $intent->actor_id)->first();
    }

    public function accept(ChemicalExposureRecoveryCase $case, ChemicalCommandIntent $intent, User $actor,
        CurrentAuthorizationReads $reads, string $decision, ?array $context, string $reviewedAt, array $newFacts): array
    {
        $reads->assertActive();
        if ($case->revision !== $intent->expected_version) { ChemicalCommandValues::invalid('expected_version', 'This later review changed. Retain your draft.'); }
        $binding = $this->binding($case, $case->revision + 1, $case->current_version_id, $decision, $context);
        $body = ['binding' => $binding, 'intent_fingerprint' => $intent->fingerprint, 'reviewed_at' => $reviewedAt, 'recorded_at' => now()->utc()->format('Y-m-d H:i:s.u'),
            'actor' => ['id' => (int) $actor->id, 'name' => $actor->name], 'facts' => $newFacts];
        $version = new ChemicalExposureRecoveryVersion(['case_id' => $case->id, 'exposure_id' => $case->exposure_id,
            'intent_id' => $intent->id, 'idempotency_key' => $intent->idempotency_key, 'actor_id' => $actor->id,
            'revision' => $case->revision + 1, 'expected_version' => $case->revision, 'previous_version_id' => $case->current_version_id,
            'command' => $intent->command, 'decision' => $decision, 'review_context' => $context,
            'binding_digest' => ChemicalCommandValues::digest($binding), 'content' => $body,
            'reviewed_at' => $reviewedAt, 'recorded_at' => $body['recorded_at']]);
        $version->content_digest = hash('sha256', $version->getAttributes()['content']);
        $this->save($version);
        $case->forceFill(['revision' => (int) $version->revision, 'current_version_id' => (int) $version->id]); $this->save($case);
        $result = ['accepted' => true, 'actor_id' => (int) $actor->id, 'command' => $intent->command,
            'idempotency_key' => $intent->idempotency_key, 'root_type' => 'exposure_recovery', 'root_id' => (int) $case->id,
            'source_type' => 'review', 'source_id' => (int) $version->id, 'expected_version' => $intent->expected_version,
            'version' => (int) $version->revision, 'source_version_id' => (int) $version->id, 'changed' => true];
        $meta = ['scope' => ['site_id' => (int) $case->site_id], 'case_id' => (int) $case->id, 'exposure_id' => (int) $case->exposure_id, 'revision' => (int) $version->revision,
            'expected_version' => $intent->expected_version, 'intent_id' => (int) $intent->id, 'source_version_id' => (int) $version->id,
            'source_content_digest' => $version->content_digest, 'binding_digest' => $version->binding_digest];
        $request = Request::create('/'); $request->setUserResolver(fn () => $actor);
        $action = 'chemical.exposure.recovery.'.$intent->command;
        AuditLogger::logOrFail($action, $case, $meta, $request);
        $audit = $reads->query(AuditLog::query())->where('user_id', $actor->id)->where('action', $action)
            ->where('auditable_type', $case->getMorphClass())->where('auditable_id', $case->id)
            ->where('meta->intent_id', $intent->id)->get(['id', 'meta']);
        if ($audit->count() !== 1 || ChemicalCommandValues::digest($audit[0]->meta) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('The exact private recovery audit was not retained.');
        }
        $receipt = new ChemicalExposureRecoveryReceipt(['case_id' => $case->id, 'version_id' => $version->id,
            'intent_id' => $intent->id, 'idempotency_key' => $intent->idempotency_key, 'actor_id' => $actor->id,
            'audit_log_id' => $audit[0]->id, 'accepted_result' => $result, 'audit_metadata' => $meta,
            'content_digest' => $version->content_digest, 'binding_digest' => $version->binding_digest, 'created_at' => now()->utc()]);
        $this->save($receipt);
        $this->prove($case, (int) $version->id, $reads);
        return $result;
    }

    /** Hash the new serialized review bytes in SQL, but do not return sealed prose into a metadata-only decision. */
    public function prove(ChemicalExposureRecoveryCase $case, int $id, CurrentAuthorizationReads $reads): ChemicalExposureRecoveryVersion
    {
        $version = $reads->query(ChemicalExposureRecoveryVersion::query())->where('case_id', $case->id)->whereKey($id)
            ->select(['id', 'case_id', 'exposure_id', 'intent_id', 'idempotency_key', 'actor_id', 'revision', 'expected_version',
                'previous_version_id', 'command', 'decision', 'review_context', 'binding_digest', 'content_digest', 'reviewed_at', 'recorded_at'])
            ->selectRaw('SHA2(CAST(content AS BINARY), 256) AS observed_digest')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.intent_fingerprint')) AS accepted_fingerprint")
            ->selectRaw("JSON_EXTRACT(content, '$.binding') AS accepted_binding")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.reviewed_at')) AS accepted_reviewed_at")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.recorded_at')) AS accepted_recorded_at")
            ->selectRaw("JSON_EXTRACT(content, '$.actor.id') AS accepted_actor_id")->firstOrFail();
        $receipt = $reads->query(ChemicalExposureRecoveryReceipt::query())->where('case_id', $case->id)->where('version_id', $id)->firstOrFail();
        $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($version->intent_id)->firstOrFail();
        $expected = $this->binding($case, $version->revision, $version->previous_version_id, $version->decision, $version->review_context);
        $audit = $reads->query(AuditLog::query())->whereKey($receipt->audit_log_id)->firstOrFail(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta']);
        $expectedMeta = ['scope' => ['site_id' => (int) $case->site_id], 'case_id' => (int) $case->id, 'exposure_id' => (int) $case->exposure_id, 'revision' => (int) $version->revision,
            'expected_version' => $version->expected_version, 'intent_id' => (int) $intent->id, 'source_version_id' => (int) $version->id,
            'source_content_digest' => $version->content_digest, 'binding_digest' => $version->binding_digest];
        $result = $receipt->accepted_result;
        if ($version->exposure_id !== $case->exposure_id || $version->revision !== $version->expected_version + 1
            || ! hash_equals($version->content_digest, (string) $version->getRawOriginal('observed_digest'))
            || $version->binding_digest !== ChemicalCommandValues::digest($expected)
            || ChemicalCommandValues::digest(json_decode($version->getRawOriginal('accepted_binding'), true, flags: JSON_THROW_ON_ERROR)) !== ChemicalCommandValues::digest($expected)
            || $intent->source_type !== 'exposure_association_review' || $intent->source_id !== $case->exposure_id
            || $intent->parent_id !== $case->substance_id || $intent->actor_id !== $version->actor_id
            || $intent->command !== $version->command || $intent->expected_version !== $version->expected_version
            || $intent->parent_expected_version !== null
            || $version->getRawOriginal('accepted_reviewed_at') !== $version->reviewed_at->utc()->format('Y-m-d H:i:s.u')
            || $version->getRawOriginal('accepted_recorded_at') !== $version->recorded_at->utc()->format('Y-m-d H:i:s.u')
            || (int) $version->getRawOriginal('accepted_actor_id') !== $version->actor_id
            || ChemicalCommandValues::digest($receipt->audit_metadata) !== ChemicalCommandValues::digest($expectedMeta)
            || $intent->idempotency_key !== $version->idempotency_key || $receipt->intent_id !== $intent->id
            || $version->getRawOriginal('accepted_fingerprint') !== $intent->fingerprint
            || $receipt->actor_id !== $version->actor_id || $receipt->idempotency_key !== $intent->idempotency_key
            || $receipt->content_digest !== $version->content_digest || $receipt->binding_digest !== $version->binding_digest
            || $audit->user_id !== $version->actor_id || $audit->action !== 'chemical.exposure.recovery.'.$version->command
            || $audit->auditable_type !== $case->getMorphClass() || (int) $audit->auditable_id !== (int) $case->id
            || ChemicalCommandValues::digest($audit->meta) !== ChemicalCommandValues::digest($receipt->audit_metadata)
            || ($audit->meta['source_content_digest'] ?? null) !== $version->content_digest
            || ($audit->meta['binding_digest'] ?? null) !== $version->binding_digest
            || (array_diff(array_keys($result), self::KEYS) !== [] || array_diff(self::KEYS, array_keys($result)) !== []) || $result['root_id'] !== (int) $case->id || $result['root_type'] !== 'exposure_recovery'
            || $result['source_id'] !== $id || $result['source_version_id'] !== $id || $result['version'] !== $version->revision
            || $result['expected_version'] !== $version->expected_version || $result['actor_id'] !== $version->actor_id
            || $result['command'] !== $version->command || $result['idempotency_key'] !== $intent->idempotency_key
            || $result['source_type'] !== 'review' || $result['accepted'] !== true || $result['changed'] !== true) {
            throw new LogicException('Recovery immutable acceptance or original claim is damaged.');
        }
        return $version; // INTERNAL active callback handle only.
    }

    private function binding(ChemicalExposureRecoveryCase $case, int $revision, ?int $previous, string $decision, ?array $context): array
    {
        return ['case_id' => (int) $case->id, 'exposure_id' => $case->exposure_id, 'worker_id' => $case->worker_id,
            'site_id' => $case->site_id, 'substance_id' => $case->substance_id, 'sealed_digest' => $case->sealed_digest,
            'revision' => $revision, 'previous_version_id' => $previous, 'decision' => $decision, 'review_context' => $context,
            'association_origin' => 'later_reviewed', 'original_association_state' => 'unknown'];
    }

    public function save(\Illuminate\Database\Eloquent\Model $row): void
    {
        if (! $row->save()) { throw new LogicException('Recovery persistence was vetoed.'); }
    }
}
