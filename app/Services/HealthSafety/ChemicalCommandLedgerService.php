<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalSourceVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Infrastructure only: callers must admit their source family before any UUID/receipt lookup. */
final class ChemicalCommandLedgerService
{
    public const PUBLIC_SOURCE_TYPES = ['catalogue', 'sds', 'storage'];
    public const RECEIPT_KEYS = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id',
        'source_type', 'source_id', 'parent_id', 'expected_version', 'parent_expected_version', 'version',
        'source_version_id', 'changed'];

    public function claim(User $current, CurrentAuthorizationReads $reads, string $sourceType, ?int $sourceId,
        ?int $parentId, string $command, array $values): ChemicalCommandIntent
    {
        $reads->assertActive();
        if (! in_array($sourceType, self::PUBLIC_SOURCE_TYPES, true)) { throw new LogicException('This command has no public chemical admission.'); }
        $key = $values['idempotency_key'] ?? null;
        if (! is_string($key) || ! Str::isUuid($key) || $key !== strtolower($key)) {
            throw new LogicException('Chemical UUID intent must be validated and canonical before admission.');
        }
        $fingerprint = ChemicalCommandValues::fingerprint($sourceType, $sourceId, $parentId, (int) $current->id, $command, $values);
        $expected = $values['expected_version'] ?? null;
        $parentExpected = $values['parent_expected_version'] ?? null;
        // The UNIQUE constraint is the mutex for a missing UUID. A SELECT of a missing row is insufficient.
        DB::table('chemical_command_intents')->insertOrIgnore(['idempotency_key' => $key,
            'actor_id' => $current->id, 'actor_snapshot' => json_encode(['id' => (int) $current->id, 'name' => $current->name], JSON_THROW_ON_ERROR),
            'source_type' => $sourceType, 'source_id' => $sourceId, 'parent_id' => $parentId, 'command' => $command,
            'expected_version' => $expected, 'parent_expected_version' => $parentExpected, 'fingerprint' => $fingerprint,
            'created_at' => now()->utc()->format('Y-m-d H:i:s.u')]);
        $intent = ChemicalCommandIntent::where('idempotency_key', $key)->where('actor_id', $current->id)
            ->whereIn('source_type', self::PUBLIC_SOURCE_TYPES)->lockForUpdate()->first();
        if (! $intent) { ChemicalCommandValues::invalid('idempotency_key', 'This chemical UUID is unavailable for this command.'); }
        if ($intent->actor_id !== (int) $current->id || $intent->source_type !== $sourceType || $intent->source_id !== $sourceId
            || $intent->parent_id !== $parentId || $intent->command !== $command || $intent->expected_version !== $expected
            || $intent->parent_expected_version !== $parentExpected || ! hash_equals($intent->fingerprint, $fingerprint)) {
            ChemicalCommandValues::invalid('idempotency_key', 'This chemical UUID belongs to a different exact command.');
        }
        return $intent;
    }

    public function receipt(ChemicalCommandIntent $intent, CurrentAuthorizationReads $reads): ?ChemicalCommandReceipt
    {
        $reads->assertActive();
        if (! in_array($intent->source_type, self::PUBLIC_SOURCE_TYPES, true)) { throw new LogicException('Private chemical intent is not a public receipt admission.'); }
        return ChemicalCommandReceipt::where('idempotency_key', $intent->idempotency_key)->where('actor_id', $intent->actor_id)
            ->whereIn('source_type', self::PUBLIC_SOURCE_TYPES)->lockForUpdate()->first();
    }

    public function accept(ChemicalCommandIntent $intent, Model $root, Model $source, ?ChemicalSourceVersion $version,
        User $current, CurrentAuthorizationReads $reads, bool $changed): array
    {
        $reads->assertActive();
        if (($changed && $version === null) || (! $changed && $version !== null)) {
            throw new LogicException('Chemical version evidence must reflect a real recorded change.');
        }
        $request = Request::create('/');
        $request->setUserResolver(fn () => $current);
        $action = 'chemical.'.$intent->command;
        $meta = ['actor_id' => (int) $current->id, 'idempotency_key' => $intent->idempotency_key,
            'root_type' => $this->kind($root), 'root_id' => (int) $root->id, 'source_type' => $this->kind($source),
            'source_id' => (int) $source->id, 'parent_id' => $intent->parent_id,
            'expected_version' => $intent->expected_version, 'parent_expected_version' => $intent->parent_expected_version,
            'version' => (int) $root->source_revision, 'source_version_id' => $version?->id, 'changed' => $changed];
        $auditMeta = [...$meta, 'source_version_digest' => $version?->content_digest];
        AuditLogger::logOrFail($action, $source, $auditMeta, $request);
        $audits = AuditLog::where('user_id', $current->id)->where('action', $action)
            ->where('auditable_type', $source->getMorphClass())->where('auditable_id', $source->id)
            ->where('meta->idempotency_key', $intent->idempotency_key)->lockForUpdate()->get();
        if ($audits->count() !== 1 || ChemicalCommandValues::digest(array_intersect_key($audits[0]->meta, $auditMeta)) !== ChemicalCommandValues::digest($auditMeta)) {
            throw new LogicException('Chemical semantic audit acceptance was not persisted exactly.');
        }
        $result = ['accepted' => true, ...$meta, 'command' => $intent->command];
        $receipt = new ChemicalCommandReceipt(['idempotency_key' => $intent->idempotency_key, 'intent_id' => $intent->id,
            'actor_id' => $current->id, 'root_type' => $this->kind($root), 'root_id' => $root->id,
            'source_type' => $this->kind($source), 'source_id' => $source->id, 'parent_id' => $intent->parent_id,
            'command' => $intent->command, 'expected_version' => $intent->expected_version,
            'parent_expected_version' => $intent->parent_expected_version, 'fingerprint' => $intent->fingerprint,
            'source_version_id' => $version?->id, 'source_version_digest' => $version?->content_digest,
            'audit_log_id' => $audits[0]->id, 'accepted_result' => $result,
            'created_at' => now()->utc()]);
        if (! $receipt->save()) { throw new LogicException('Chemical receipt save was vetoed.'); }
        return $this->reconstruct($receipt->persisted(), $intent, $root, $source, $current, $reads);
    }

    public function reconstruct(ChemicalCommandReceipt $receipt, ChemicalCommandIntent $intent, Model $root, Model $source,
        User $current, CurrentAuthorizationReads $reads): array
    {
        $reads->assertActive();
        $r = $receipt->accepted_result;
        if ($intent->actor_id !== (int) $current->id || $intent->source_type !== $this->kind($source)
            || ($intent->source_id !== null && $intent->source_id !== (int) $source->id)
            || $receipt->intent_id !== (int) $intent->id || $receipt->actor_id !== (int) $current->id
            || $receipt->idempotency_key !== $intent->idempotency_key || $receipt->command !== $intent->command
            || ! hash_equals($receipt->fingerprint, $intent->fingerprint) || $receipt->root_type !== $this->kind($root)
            || $receipt->root_id !== (int) $root->id || $receipt->source_type !== $this->kind($source)
            || $receipt->source_id !== (int) $source->id || $receipt->parent_id !== $intent->parent_id
            || $receipt->expected_version !== $intent->expected_version || $receipt->parent_expected_version !== $intent->parent_expected_version
            || ! is_array($r) || array_diff(self::RECEIPT_KEYS, array_keys($r)) !== []
            || $r['accepted'] !== true || ! is_bool($r['changed']) || ! is_int($r['version']) || $r['version'] < 0) {
            throw new LogicException('The retained chemical acceptance identity is unconfirmed.');
        }
        $identity = ['actor_id' => $receipt->actor_id, 'command' => $receipt->command, 'idempotency_key' => $receipt->idempotency_key,
            'root_type' => $receipt->root_type, 'root_id' => $receipt->root_id, 'source_type' => $receipt->source_type,
            'source_id' => $receipt->source_id, 'parent_id' => $receipt->parent_id, 'expected_version' => $receipt->expected_version,
            'parent_expected_version' => $receipt->parent_expected_version, 'source_version_id' => $receipt->source_version_id];
        foreach ($identity as $key => $value) {
            if ($r[$key] !== $value) { throw new LogicException('The retained chemical receipt tuple was altered.'); }
        }
        $audit = AuditLog::whereKey($receipt->audit_log_id)->lockForUpdate()->first();
        $auditMeta = [...array_diff_key(array_intersect_key($r, array_flip(self::RECEIPT_KEYS)), ['accepted' => true, 'command' => true]),
            'source_version_digest' => $receipt->source_version_digest];
        if (! $audit || (int) $audit->user_id !== (int) $current->id || $audit->action !== 'chemical.'.$receipt->command
            || $audit->auditable_type !== $source->getMorphClass() || (int) $audit->auditable_id !== (int) $source->id
            || ChemicalCommandValues::digest(array_intersect_key($audit->meta ?? [], $auditMeta)) !== ChemicalCommandValues::digest($auditMeta)) {
            throw new LogicException('The retained chemical semantic audit is unconfirmed.');
        }
        if ($r['changed']) {
            $version = ChemicalSourceVersion::whereKey($receipt->source_version_id)->lockForUpdate()->first();
            if (! $version || $version->root_type !== $receipt->root_type || $version->root_id !== $receipt->root_id
                || $version->source_type !== $receipt->source_type || $version->source_id !== $receipt->source_id
                || $version->parent_id !== $receipt->parent_id || $version->aggregate_revision !== $r['version']
                || $version->command !== $receipt->command || $version->idempotency_key !== $receipt->idempotency_key
                || $version->actor_id !== (int) $current->id || $version->expected_version !== $receipt->expected_version
                || $version->parent_expected_version !== $receipt->parent_expected_version
                || $version->content_digest !== $receipt->source_version_digest
                || ($version->content['intent_fingerprint'] ?? null) !== $intent->fingerprint) {
                throw new LogicException('The retained chemical version identity is unconfirmed.');
            }
            $this->assertVersionDigest($version);
        } elseif ($receipt->source_version_id !== null || $receipt->source_version_digest !== null || $r['version'] !== $receipt->expected_version) {
            throw new LogicException('An unchanged chemical receipt cannot invent a new source version.');
        }
        return array_intersect_key($r, array_flip(self::RECEIPT_KEYS));
    }

    public function version(ChemicalCommandIntent $intent, Model $root, Model $source, User $current,
        CurrentAuthorizationReads $reads, ?array $rootBefore, ?array $sourceBefore, array $facts = []): ChemicalSourceVersion
    {
        $reads->assertActive();
        $tuple = ['root_type' => $this->kind($root), 'root_id' => (int) $root->id, 'source_type' => $this->kind($source),
            'source_id' => (int) $source->id, 'parent_id' => $intent->parent_id,
            'site_id' => $root instanceof \App\Models\SubstanceStorageLocation ? (int) $root->site_id : null,
            'aggregate_revision' => (int) $root->source_revision, 'expected_version' => $intent->expected_version,
            'parent_expected_version' => $intent->parent_expected_version, 'source_schema_version' => 1,
            'command' => $intent->command, 'idempotency_key' => $intent->idempotency_key, 'actor_id' => (int) $current->id];
        $actorSnapshot = ['id' => (int) $current->id, 'name' => $current->name];
        $recordedAt = now()->utc();
        $content = ['tuple' => $tuple, 'actor_snapshot' => $actorSnapshot, 'recorded_at' => $recordedAt->format('Y-m-d H:i:s.u'),
            'intent_fingerprint' => $intent->fingerprint, 'root_before' => $rootBefore, 'source_before' => $sourceBefore,
            'root_after' => $this->snapshot($root), 'source_after' => $this->snapshot($source), 'facts' => $facts];
        $row = new ChemicalSourceVersion([...$tuple, 'actor_snapshot' => $actorSnapshot,
            'content' => $content, 'content_digest' => ChemicalCommandValues::digest($content), 'recorded_at' => $recordedAt]);
        if (! $row->save()) { throw new LogicException('Chemical source version save was vetoed.'); }
        return $row->persisted();
    }

    public function assertVersionDigest(ChemicalSourceVersion $row): void
    {
        if ($row->source_schema_version !== 1 || ! is_array($row->content)
            || ! hash_equals($row->content_digest, ChemicalCommandValues::digest($row->content))) {
            throw new LogicException('Chemical immutable source content is damaged.');
        }
        $tuple = array_intersect_key($row->attributesToArray(), array_flip(['root_type', 'root_id', 'source_type', 'source_id',
            'parent_id', 'site_id', 'aggregate_revision', 'expected_version', 'parent_expected_version', 'source_schema_version',
            'command', 'idempotency_key', 'actor_id']));
        if (ChemicalCommandValues::digest($tuple) !== ChemicalCommandValues::digest($row->content['tuple'] ?? null)
            || ChemicalCommandValues::digest($row->actor_snapshot) !== ChemicalCommandValues::digest($row->content['actor_snapshot'] ?? null)
            || $row->recorded_at?->utc()->format('Y-m-d H:i:s.u') !== ($row->content['recorded_at'] ?? null)) {
            throw new LogicException('Chemical immutable source tuple is damaged.');
        }
        $root = $row->content['root_after'] ?? [];
        $source = $row->content['source_after'] ?? [];
        if (($root['id'] ?? null) !== $row->root_id || ($source['id'] ?? null) !== $row->source_id
            || ($root['source_revision'] ?? null) !== $row->aggregate_revision
            || ($source['source_revision'] ?? null) !== $row->aggregate_revision
            || ($root['source_schema_version'] ?? null) !== 1 || ($source['source_schema_version'] ?? null) !== 1
            || ($row->source_type === 'sds' && ($source['hazardous_substance_id'] ?? null) !== $row->parent_id)
            || ($row->root_type === 'storage' && (($root['site_id'] ?? null) !== $row->site_id
                || ($root['hazardous_substance_id'] ?? null) !== $row->parent_id))) {
            throw new LogicException('Chemical immutable recorded source association is damaged.');
        }
    }

    /** Superseded siblings share the same accepted root revision; their own version is not the receipt's target. */
    public function assertAcceptedVersion(ChemicalSourceVersion $version): void
    {
        $this->assertVersionDigest($version);
        $receipt = ChemicalCommandReceipt::where('idempotency_key', $version->idempotency_key)->lock('for share nowait')->first();
        $intent = $receipt ? ChemicalCommandIntent::whereKey($receipt->intent_id)->where('actor_id', $receipt->actor_id)
            ->whereIn('source_type', self::PUBLIC_SOURCE_TYPES)->lock('for share nowait')->first() : null;
        $result = $receipt?->accepted_result;
        if (! $receipt || ! $intent || ! is_array($result) || array_diff(self::RECEIPT_KEYS, array_keys($result))
            || ($result['accepted'] ?? null) !== true || ($result['changed'] ?? null) !== true
            || $receipt->actor_id !== $version->actor_id || $intent->actor_id !== $version->actor_id
            || $receipt->root_type !== $version->root_type || $receipt->root_id !== $version->root_id
            || $receipt->parent_id !== $version->parent_id || $receipt->command !== $version->command
            || $intent->idempotency_key !== $version->idempotency_key || $intent->command !== $version->command
            || $receipt->expected_version !== $version->expected_version || $receipt->parent_expected_version !== $version->parent_expected_version
            || ! hash_equals($receipt->fingerprint, $intent->fingerprint)
            || ($version->content['intent_fingerprint'] ?? null) !== $intent->fingerprint
            || ($result['version'] ?? null) !== $version->aggregate_revision) {
            throw new LogicException('Chemical current version has no exact retained acceptance.');
        }
        foreach (['actor_id', 'command', 'idempotency_key', 'root_type', 'root_id', 'source_type', 'source_id', 'parent_id',
            'expected_version', 'parent_expected_version', 'source_version_id'] as $key) {
            if (($result[$key] ?? null) !== $receipt->$key) { throw new LogicException('Chemical version receipt identity is damaged.'); }
        }
        $target = ChemicalSourceVersion::whereKey($receipt->source_version_id)->lock('for share nowait')->first();
        if (! $target || $target->root_type !== $version->root_type || $target->root_id !== $version->root_id
            || $target->source_type !== $receipt->source_type || $target->source_id !== $receipt->source_id
            || $target->aggregate_revision !== $version->aggregate_revision || $target->idempotency_key !== $version->idempotency_key
            || $target->content_digest !== $receipt->source_version_digest) {
            throw new LogicException('Chemical accepted root revision has no exact target version.');
        }
        $this->assertVersionDigest($target);
        $siblings = $target->content['facts']['superseded_versions'] ?? [];
        $boundSibling = is_array($siblings) && count(array_filter($siblings, fn ($sibling) => is_array($sibling)
            && ($sibling['id'] ?? null) === (int) $version->id && ($sibling['digest'] ?? null) === $version->content_digest)) === 1;
        if ($target->id !== $version->id && ! $boundSibling) {
            throw new LogicException('Chemical superseded sibling has no accepted immutable digest.');
        }
        $audit = AuditLog::whereKey($receipt->audit_log_id)->lock('for share nowait')->first();
        $morph = match ($receipt->source_type) { 'catalogue' => (new \App\Models\HazardousSubstance)->getMorphClass(),
            'storage' => (new \App\Models\SubstanceStorageLocation)->getMorphClass(), 'sds' => (new \App\Models\SafetyDataSheet)->getMorphClass(),
            default => null };
        $meta = [...array_diff_key(array_intersect_key($result, array_flip(self::RECEIPT_KEYS)), ['accepted' => true, 'command' => true]),
            'source_version_digest' => $receipt->source_version_digest];
        if (! $audit || $morph === null || (int) $audit->user_id !== $receipt->actor_id
            || $audit->action !== 'chemical.'.$receipt->command || $audit->auditable_type !== $morph
            || (int) $audit->auditable_id !== $receipt->source_id
            || ChemicalCommandValues::digest(array_intersect_key($audit->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('Chemical current version semantic audit is damaged.');
        }
    }

    public function snapshot(Model $source): array { return $source->attributesToArray(); }

    public function kind(Model $source): string
    {
        return match (true) {
            $source instanceof \App\Models\HazardousSubstance => 'catalogue',
            $source instanceof \App\Models\SafetyDataSheet => 'sds',
            $source instanceof \App\Models\SubstanceStorageLocation => 'storage',
            default => throw new LogicException('This chemical source family has no allocated ledger adapter.'),
        };
    }
}
