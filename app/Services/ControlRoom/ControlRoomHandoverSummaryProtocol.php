<?php

namespace App\Services\ControlRoom;

use App\Models\AuditLog;
use App\Models\ControlRoom\Shift;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;

/** Durable format only. SourceContent's private original admission remains the authority. */
final class ControlRoomHandoverSummaryProtocol
{
    use ControlRoomCopiedFinalQueryOwner;

    public const FIELDS = ['context.title', 'context.summary', 'context.description',
        'context.normalized_data.title', 'context.normalized_data.description', 'notes'];

    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new \LogicException('Summary protocol uses only JSON integers, strings, booleans, null and arrays.');
            }
            return $value;
        }
        if (! array_is_list($value)) { ksort($value, SORT_STRING); }
        return array_map(self::canonical(...), $value);
    }

    /** Immutable projection semantics; neither future Str changes nor today's headline supplies provenance. */
    public static function project(string $projection, string $raw): string
    {
        if ($projection === 'trim_limit_160_v1') {
            $trimmed = trim($raw);
            return mb_strwidth($trimmed, 'UTF-8') <= 160 ? $trimmed
                : rtrim(mb_strimwidth($trimmed, 0, 160, '', 'UTF-8')).'...';
        }
        if ($projection !== 'generated_headline_v1') { throw new \LogicException('Unknown summary projection.'); }
        $parts = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $words = count($parts) > 1 ? $parts
            : preg_split('/(?=\p{Lu})/u', implode('_', $parts), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_map(static fn (string $word): string => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'), $words);
        return implode(' ', array_filter(explode('_', str_replace(['-', '_', ' '], '_', implode('_', $words)))));
    }

    /** Validation cannot register source authority; only the canonical capture callback calls this producer. */
    public static function binding(array $capture, array $target): array
    {
        self::keys($capture, ['projection', 'source', 'source_sha256', 'summary_sha256', 'precedence']);
        self::keys($target, ['shift_id', 'handover_version', 'ordinal', 'alert_id', 'site_id', 'client_id']);
        foreach (['shift_id', 'handover_version', 'ordinal', 'alert_id'] as $key) { self::id($target[$key]); }
        foreach (['site_id', 'client_id'] as $key) { if ($target[$key] !== null) { self::id($target[$key]); } }
        $source = $capture['source'];
        if (! is_array($source)) { throw new \LogicException('Missing canonical source identity.'); }
        $generated = ($source['kind'] ?? null) === 'generated_metadata';
        if (! $generated && ($source['kind'] ?? null) !== 'ordinary_alert_field') {
            throw new \LogicException('This protocol revision supports independent ordinary fields and generated metadata only.');
        }
        self::keys($source, [...['kind', 'alert_id', 'site_id', 'client_id', 'effective_site_id', 'created_at', 'field', 'revision_sha256'],
            ...($generated ? ['alert_type'] : [])]);
        self::id($source['alert_id']);
        foreach (['site_id', 'client_id', 'effective_site_id'] as $key) { if ($source[$key] !== null) { self::id($source[$key]); } }
        if ($source['alert_id'] !== $target['alert_id'] || $source['effective_site_id'] !== $target['site_id']
            || $source['client_id'] !== $target['client_id'] || ! is_string($source['created_at']) || $source['created_at'] === '') {
            throw new \LogicException('Summary target and canonical source differ.');
        }
        self::sha($source['revision_sha256']); self::sha($capture['source_sha256']); self::sha($capture['summary_sha256']);
        self::keys($capture['precedence'], ['version', 'earlier_absent']);
        $index = $generated ? count(self::FIELDS) : array_search($source['field'], self::FIELDS, true);
        if ($index === false || $capture['precedence']['version'] !== 1
            || $capture['precedence']['earlier_absent'] !== array_slice(self::FIELDS, 0, $index)
            || $capture['projection'] !== ($generated ? 'generated_headline_v1' : 'trim_limit_160_v1')
            || ($generated && ($source['field'] !== 'alert_type' || ! is_string($source['alert_type']) || $source['alert_type'] === ''))) {
            throw new \LogicException('Summary recipe or selection precedence differs.');
        }
        if ($generated && (! hash_equals($capture['source_sha256'], hash('sha256', $source['alert_type']))
            || ! hash_equals($capture['summary_sha256'], hash('sha256', self::project('generated_headline_v1', $source['alert_type']))))) {
            throw new \LogicException('Generated metadata does not match its fixed recipe.');
        }
        return ['version' => 1, 'target' => $target, ...$capture];
    }

    /** Preserve a validated binding without producing one for historical rows. Does not admit prose. */
    public static function preservedBinding(mixed $binding): ?array
    {
        if ($binding === null) { return null; }
        if (! is_array($binding)) { throw new \LogicException('Invalid saved summary binding.'); }
        self::keys($binding, ['version', 'target', 'projection', 'source', 'source_sha256', 'summary_sha256', 'precedence']);
        if ($binding['version'] !== 1 || ! is_array($binding['target'])) { throw new \LogicException('Unknown saved summary binding.'); }
        $capture = $binding; unset($capture['version'], $capture['target']);
        self::binding($capture, $binding['target']);
        return $binding;
    }

    public static function bindRows(array $rows, Shift $shift, int $version, User $viewer, HealthSafetyReadContext $context): array
    {
        $sources = app(ControlRoomSourceContentService::class);
        foreach ($rows as $index => &$row) {
            $target = ['shift_id' => (int) $shift->id, 'handover_version' => $version, 'ordinal' => $index + 1,
                'alert_id' => (int) $row['id'], 'site_id' => isset($row['site']['id']) ? (int) $row['site']['id'] : null,
                'client_id' => isset($row['person']['id']) ? (int) $row['person']['id'] : null];
            $admission = $sources->consumeHandoverSummaryForPreparation($viewer, $target['alert_id'], $context,
                function (array $capture, User $same, CurrentAuthorizationReads $reads, HealthSafetyReadContext $sameContext)
                    use ($viewer, $context, $target, $row): array {
                    if ($same !== $viewer || $sameContext !== $context) { throw new \LogicException('Use the original summary creator context.'); }
                    $reads->assertActive();
                    $binding = self::binding($capture, $target);
                    // Consistency only: the private source admission, not text equality, establishes provenance.
                    if (! is_string($row['summary']) || ! hash_equals($binding['summary_sha256'], hash('sha256', $row['summary']))) {
                        throw new \LogicException('Prepared row differs from the actual canonical summary selection.');
                    }
                    return $binding;
                });
            if (! is_array($admission) || ! is_bool($admission['available'] ?? null)
                || ! array_key_exists('result', $admission)
                || ($admission['available'] ? ! is_array($admission['result']) : $admission['result'] !== null)) {
                throw new \LogicException('Invalid canonical summary capture response.');
            }
            // Unavailable means an intentionally unsupported branch only. SourceContent must throw
            // on supported denial and retain every successful original graph for the final guard.
            // These rows already carry canonical scope/domain masking; adding null never admits prose.
            $row['summary_binding'] = $admission['available'] ? $admission['result'] : null;
        }
        unset($row);
        return $rows;
    }

    public static function manifest(array $rows, int $preparedVersion): array
    {
        self::id($preparedVersion);
        $entries = [];
        foreach (array_values($rows) as $index => $row) {
            $binding = self::preservedBinding($row['summary_binding'] ?? null);
            if ($binding !== null && ($binding['target']['ordinal'] !== $index + 1
                || $binding['target']['alert_id'] !== (int) $row['id']
                || $binding['target']['site_id'] !== (isset($row['site']['id']) ? (int) $row['site']['id'] : null)
                || $binding['target']['client_id'] !== (isset($row['person']['id']) ? (int) $row['person']['id'] : null)
                || $binding['target']['handover_version'] !== $preparedVersion)) { throw new \LogicException('Summary manifest target changed.'); }
            $entries[] = ['ordinal' => $index + 1, 'alert_id' => (int) $row['id'], 'binding' => $binding];
        }
        return ['version' => 1, 'prepared_version' => $preparedVersion, 'bindings_sha256' => self::digest($entries)];
    }

    public static function preservedManifest(mixed $manifest, array $rows, int $shiftId): ?array
    {
        if ($manifest === null) {
            foreach ($rows as $row) { if (($row['summary_binding'] ?? null) !== null) { throw new \LogicException('Bound rows require their original manifest.'); } }
            return null;
        }
        if (! is_array($manifest)) { throw new \LogicException('Invalid summary manifest.'); }
        self::keys($manifest, ['version', 'prepared_version', 'bindings_sha256']);
        self::sha($manifest['bindings_sha256']);
        if ($manifest['version'] !== 1 || self::digest($manifest) !== self::digest(self::manifest($rows, $manifest['prepared_version']))) {
            throw new \LogicException('Summary manifest changed.');
        }
        foreach ($rows as $row) { if (($row['summary_binding'] ?? null) !== null && $row['summary_binding']['target']['shift_id'] !== $shiftId) { throw new \LogicException('Summary belongs to another handover.'); } }
        return $manifest;
    }

    /** Capture once after the normal write, before outer tails; later proof never adopts a replacement. */
    public static function verifyPreparationReceipt(array $intent, array $expected): array
    {
        $receipts = AuditLog::query()->whereRaw('BINARY action = BINARY ?', ['controlRoom.shift.handoverPrepared'])
            ->whereRaw('BINARY auditable_type = BINARY ?', [$intent['model']])->where('auditable_id', $intent['shift_id'])
            ->whereRaw("(BINARY JSON_UNQUOTE(JSON_EXTRACT(meta,'$.handover_version')) = BINARY ?
                OR JSON_EXTRACT(meta,'$.handover_version') = CAST(? AS JSON))",
                [(string) $expected['handover_version'], (string) $expected['handover_version']])
            ->select(['id', 'user_id', 'client_id', 'meta'])
            ->selectRaw('CAST(created_at AS CHAR) as created_at, SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) as meta_sha256')
            ->limit(2)->toBase()->get();
        if ($receipts->count() !== 1 || (int) $receipts[0]->user_id !== $expected['actor_id']
            || $receipts[0]->client_id !== null
            || self::digest(json_decode($receipts[0]->meta, true, 512, JSON_THROW_ON_ERROR)) !== self::digest($expected)) {
            throw new \RuntimeException('The exact prepared-summary receipt was not persisted uniquely.');
        }
        // Database JSON text hashes preserve numeric types, unlike MySQL JSON value equality.
        // Compare with the ORIGINAL intended document, then retain only its database fingerprint.
        $snapshot = \Illuminate\Support\Facades\DB::table('control_room_shifts')->where('id', $intent['shift_id'])
            ->where('handover_version', $expected['handover_version'])->where('handover_status', Shift::HANDOVER_PREPARED)
            ->whereRaw('SHA2(CAST(handover_snapshot AS CHAR CHARACTER SET utf8mb4),256) = SHA2(CAST(CAST(? AS JSON) AS CHAR CHARACTER SET utf8mb4),256)',
                [json_encode($intent['snapshot'], JSON_THROW_ON_ERROR)])
            ->selectRaw('SHA2(CAST(handover_snapshot AS CHAR CHARACTER SET utf8mb4),256) as snapshot_sha256')->first();
        if ($snapshot === null) { throw new \RuntimeException('Prepared summary snapshot differs from its original write.'); }
        self::sha($snapshot->snapshot_sha256); self::sha($receipts[0]->meta_sha256);
        return ['id' => (int) $receipts[0]->id, 'user_id' => (int) $receipts[0]->user_id,
            'client_id' => null, 'created_at' => $receipts[0]->created_at, 'meta_sha256' => $receipts[0]->meta_sha256,
            'snapshot_sha256' => $snapshot->snapshot_sha256];
    }
    /** One actual final target, guarded by the sole source owner's collective original proof. */
    public static function finalizePreparation(array $intent, array $expected, User $current,
        CurrentAuthorizationReads $reads, HealthSafetyReadContext $context, mixed $privateParentVerifier, array $receiptAnchor,
        User $incomingLead, HealthSafetyReadContext $incomingContext): void
    {
        self::keys($receiptAnchor, ['id', 'user_id', 'client_id', 'created_at', 'meta_sha256', 'snapshot_sha256']);
        self::id($receiptAnchor['id']); self::sha($receiptAnchor['meta_sha256']); self::sha($receiptAnchor['snapshot_sha256']);
        if ($receiptAnchor['user_id'] !== $expected['actor_id'] || $receiptAnchor['client_id'] !== null
            || ! is_string($receiptAnchor['created_at']) || $receiptAnchor['created_at'] === '') {
            throw new \LogicException('Use the first verified normal preparation receipt.');
        }
        $governance = app(ControlRoomGovernanceReadService::class);
        $governance->validateAndReplayControlRoomFinalRead($current, $reads, $context, $privateParentVerifier);
        $actor = clone $context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, []);
        $persisted = \Illuminate\Support\Facades\DB::table('control_room_shifts')->where('id', $intent['shift_id'])
            ->where('handover_version', $expected['handover_version'])->where('handover_status', Shift::HANDOVER_PREPARED)
            ->whereRaw('SHA2(CAST(handover_snapshot AS CHAR CHARACTER SET utf8mb4),256) = ?', [$receiptAnchor['snapshot_sha256']]);
        $receipt = \Illuminate\Support\Facades\DB::table('audit_logs as cr_summary_prepare')
            ->whereRaw('BINARY cr_summary_prepare.action = BINARY ?', ['controlRoom.shift.handoverPrepared'])
            ->whereRaw('BINARY cr_summary_prepare.auditable_type = BINARY ?', [$intent['model']])
            ->where('cr_summary_prepare.auditable_id', $intent['shift_id'])->where('cr_summary_prepare.user_id', $expected['actor_id'])
            ->where('cr_summary_prepare.id', $receiptAnchor['id'])->whereNull('cr_summary_prepare.client_id')
            ->whereRaw('BINARY CAST(cr_summary_prepare.created_at AS CHAR) = BINARY ?', [$receiptAnchor['created_at']])
            ->whereRaw('SHA2(CAST(cr_summary_prepare.meta AS CHAR CHARACTER SET utf8mb4),256) = ?', [$receiptAnchor['meta_sha256']])
            ->whereRaw("(SELECT COUNT(*) FROM audit_logs cr_summary_count
                WHERE BINARY cr_summary_count.action = BINARY ? AND BINARY cr_summary_count.auditable_type = BINARY ?
                AND cr_summary_count.auditable_id = ?
                AND (BINARY JSON_UNQUOTE(JSON_EXTRACT(cr_summary_count.meta,'$.handover_version')) = BINARY ?
                    OR JSON_EXTRACT(cr_summary_count.meta,'$.handover_version') = CAST(? AS JSON))) = 1",
                ['controlRoom.shift.handoverPrepared', $intent['model'], (int) $intent['shift_id'],
                    (string) $expected['handover_version'], (string) $expected['handover_version']]);
        $actor->whereExists($persisted->selectRaw('1'))->whereExists($receipt->selectRaw('1'));
        // Freeze the original intended target/receipt predicate; each final replay gets a fresh plain carrier.
        $actor->select([])->selectRaw('1'); $baseSql = $actor->toSql(); $baseBindings = $actor->getBindings();
        app(\App\Support\Incidents\LinkedOperationalEvidencePresenter::class)->withinCopiedNoteFinalRead($current, $reads, $context,
            function () use ($baseSql, $baseBindings, $current, $reads, $context, $incomingLead, $incomingContext,
                $governance, $privateParentVerifier): array {
        $compose = function () use ($baseSql, $baseBindings, $current, $reads, $context, $incomingLead, $incomingContext): \Illuminate\Database\Eloquent\Builder {
            $context->assertViewer($current); $reads->assertActive();
            $plain = User::query()->whereKey($current->id)->whereRaw('exists ('.$baseSql.')', $baseBindings);
            app(ControlRoomHandoverIncomingReadService::class)->constrainOriginalRead($incomingLead, $incomingContext, $plain);
            app(ControlRoomOperatorNoteReadService::class)->constrainOriginalPreparationRead($current, $reads, $context, $plain);
            return $reads->query($plain);
        };
        $actual=self::prepareRegisteredCopiedFinal($compose(),'exists');
        app(ControlRoomSourceContentService::class)->guardHandoverSummaryOriginalRead($current, $reads, $context, $actual);
        ControlRoomOriginalReadOuterQuery::attach($actual,
            function () use ($governance, $current, $reads, $context, $privateParentVerifier, $compose): void {
                // Finish all actor/copied callback SQL, then obtain fresh FIRST plus ALL-use Note constraints.
                $governance->validateAndReplayControlRoomFinalRead($current, $reads, $context, $privateParentVerifier);
                app(ControlRoomOperatorNoteReadService::class)->verifyPreparationSourceTails($current, $reads, $context);
                $complete=self::prepareRegisteredCopiedFinal($compose(),'exists');
                // A NEW complete actual carrier is registered normally; no existing execution guard is stripped.
                app(ControlRoomSourceContentService::class)->guardHandoverSummaryOriginalRead($current, $reads, $context, $complete);
                abort_unless(self::executeRegisteredCopiedFinal($complete,'exists',$current,$reads,$context)['exists'],404,'The complete original handover preparation changed.');
            }, $reads);
        // The last verifier finishes on one complete Note/incoming/POST/receipt/summary-source query.
        abort_unless(self::executeRegisteredCopiedFinal($actual,'exists',$current,$reads,$context)['exists'],404,'The original prepared handover or source changed.');
        return [];
            });
    }

    /** Strict metadata projection. No summary text or unknown JSON member crosses this SQL boundary. */
    public static function bindingMetadataSql(string $entry): string
    {
        $binding = "JSON_EXTRACT($entry,'$.summary_binding')";
        $get = static fn (string $path): string => "JSON_EXTRACT($binding,'$.$path')";
        $text = static fn (string $path): string => "JSON_UNQUOTE(".$get($path).')';
        $object = static function (string $expression, array $keys): string {
            $paths = implode(',', array_map(static fn (string $key): string => "'$.$key'", $keys));
            return "JSON_TYPE($expression)='OBJECT' AND JSON_LENGTH($expression)=".count($keys)
                ." AND JSON_CONTAINS_PATH($expression,'all',$paths)=1";
        };
        $valid = [$object($binding, ['version','target','projection','source','source_sha256','summary_sha256','precedence']),
            "JSON_TYPE(".$get('version').")='INTEGER' AND ".$get('version').'=1',
            $object($get('target'), ['shift_id','handover_version','ordinal','alert_id','site_id','client_id']),
            $object($get('precedence'), ['version','earlier_absent']),
            "JSON_TYPE(".$get('precedence.version').")='INTEGER' AND ".$get('precedence.version').'=1',
            "JSON_TYPE(".$get('precedence.earlier_absent').")='ARRAY'",
            "JSON_TYPE(".$get('projection').")='STRING'",
            "JSON_TYPE(".$get('source.kind').")='STRING'",
            "JSON_TYPE(".$get('source.field').")='STRING'",
            "JSON_TYPE(".$get('source.created_at').")='STRING' AND ".$text('source.created_at')." REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?$'",
            $get('source.alert_id').'='.$get('target.alert_id'),
            $get('source.client_id').'='.$get('target.client_id'),
            $get('source.effective_site_id').'='.$get('target.site_id'),
            "BINARY ".$text('summary_sha256')."=BINARY SHA2(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.summary')),256)"];
        foreach (['target.shift_id','target.handover_version','target.ordinal','target.alert_id','source.alert_id'] as $path) {
            $valid[] = "JSON_TYPE(".$get($path).")='INTEGER' AND ".$get($path).'>0';
        }
        foreach (['target.site_id','target.client_id','source.site_id','source.client_id','source.effective_site_id'] as $path) {
            $valid[] = "(JSON_TYPE(".$get($path).")='NULL' OR (JSON_TYPE(".$get($path).")='INTEGER' AND ".$get($path).'>0))';
        }
        foreach (['source_sha256','summary_sha256','source.revision_sha256'] as $path) {
            $valid[] = "JSON_TYPE(".$get($path).")='STRING' AND CHAR_LENGTH(".$text($path).")=64 AND ".$text($path)." REGEXP '^[a-f0-9]{64}$' AND BINARY ".$text($path)." = BINARY LOWER(".$text($path).")";
        }
        $baseKeys = ['kind','alert_id','site_id','client_id','effective_site_id','created_at','field','revision_sha256'];
        $branches = [];
        foreach (self::FIELDS as $index => $field) {
            $earlier = implode(',', array_map(static fn (string $item): string => "'$item'", array_slice(self::FIELDS, 0, $index)));
            $branches[] = "(BINARY ".$text('source.field')."=BINARY '$field' AND ".$get('precedence.earlier_absent')."=JSON_ARRAY($earlier))";
        }
        $all = implode(',', array_map(static fn (string $item): string => "'$item'", self::FIELDS));
        $valid[] = "((BINARY ".$text('source.kind')."=BINARY 'ordinary_alert_field' AND BINARY ".$text('projection')."=BINARY 'trim_limit_160_v1' AND "
            .$object($get('source'), $baseKeys).' AND ('.implode(' OR ', $branches).')) OR ('
            ."BINARY ".$text('source.kind')."=BINARY 'generated_metadata' AND BINARY ".$text('projection')."=BINARY 'generated_headline_v1' AND "
            .$object($get('source'), [...$baseKeys, 'alert_type'])." AND BINARY ".$text('source.field')."=BINARY 'alert_type'"
            ." AND JSON_TYPE(".$get('source.alert_type').")='STRING' AND CHAR_LENGTH(".$text('source.alert_type').") BETWEEN 1 AND 255"
            ." AND BINARY ".$text('source_sha256')."=BINARY SHA2(".$text('source.alert_type').",256)"
            .' AND '.$get('precedence.earlier_absent')."=JSON_ARRAY($all)))";
        return 'CASE WHEN '.implode(' AND ', array_map(static fn (string $part): string => "($part)", $valid))." THEN $binding ELSE NULL END";
    }

    public static function manifestMetadataSql(string $snapshot): string
    {
        $manifest = "JSON_EXTRACT($snapshot,'$.summary_protocol')";
        return "CASE WHEN JSON_TYPE($manifest)='OBJECT' AND JSON_LENGTH($manifest)=3
            AND JSON_CONTAINS_PATH($manifest,'all','$.version','$.prepared_version','$.bindings_sha256')=1
            AND JSON_TYPE(JSON_EXTRACT($manifest,'$.version'))='INTEGER' AND JSON_EXTRACT($manifest,'$.version')=1
            AND JSON_TYPE(JSON_EXTRACT($manifest,'$.prepared_version'))='INTEGER' AND JSON_EXTRACT($manifest,'$.prepared_version')>0
            AND JSON_TYPE(JSON_EXTRACT($manifest,'$.bindings_sha256'))='STRING'
            AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT($manifest,'$.bindings_sha256')))=64
            AND JSON_UNQUOTE(JSON_EXTRACT($manifest,'$.bindings_sha256')) REGEXP '^[a-f0-9]{64}$'
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT($manifest,'$.bindings_sha256')) = BINARY LOWER(JSON_UNQUOTE(JSON_EXTRACT($manifest,'$.bindings_sha256')))
            THEN $manifest ELSE NULL END";
    }

    private static function keys(mixed $value, array $expected): void
    {
        if (! is_array($value)) { throw new \LogicException('Summary protocol object required.'); }
        $keys = array_keys($value); sort($keys); sort($expected);
        if ($keys !== $expected) { throw new \LogicException('Summary protocol keys differ.'); }
    }
    private static function id(mixed $value): void
    {
        if (! is_int($value) || $value < 1) { throw new \LogicException('Positive integer summary identity required.'); }
    }
    private static function sha(mixed $value): void
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) { throw new \LogicException('Summary SHA256 required.'); }
    }
}
