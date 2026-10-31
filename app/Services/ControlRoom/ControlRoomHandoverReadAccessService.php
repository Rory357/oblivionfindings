<?php

namespace App\Services\ControlRoom;

use App\Models\AuditLog;
use App\Models\ControlRoom\Shift;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;

/** The existing saved-handover participant/report-reader boundary, shared by entry points. */
final class ControlRoomHandoverReadAccessService
{
    use ControlRoomCopiedFinalQueryOwner;

    /** Static lookup holds no original graph, viewer, query, token or context strongly. */
    private static ?\WeakMap $originalReadOwners = null;

    private function originalReadOwner(\App\Services\HealthSafety\HealthSafetyReadContext $context): \stdClass
    {
        $context->assertActive();
        self::$originalReadOwners ??= new \WeakMap;
        if (isset(self::$originalReadOwners[$context])) {
            $owner = self::$originalReadOwners[$context]->get();
            if ($owner === null) { throw new \LogicException('The original read owner cannot be replaced.'); }
            return $owner;
        }
        $owner = (object) ['frames' => []];
        $context->retainControlRoomOriginalReadOwner($owner);
        self::$originalReadOwners[$context] = \WeakReference::create($owner);
        return $owner;
    }

    public function canViewSavedSnapshot(Shift $shift, User $user): bool
    {
        if ($user->canDo('reports.viewAny')) {
            return true;
        }

        $participantIds = collect([
            ...$shift->memberUserIds(),
            $shift->handed_over_to_user_id,
        ])->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)->unique();
        if ($participantIds->contains($user->id)) {
            return true;
        }

        return AuditLog::query()->where('action', 'controlRoom.shift.handoverPrepared')
            ->where('auditable_type', $shift->getMorphClass())->where('auditable_id', $shift->id)
            ->where('user_id', $user->id)->exists();
    }

    /** Metadata-only original saved-row frame. Its opaque reference is backend-local. */
    public function discoverSavedSnapshot(User $viewer, int $savedShiftId, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($savedShiftId, $context): array {
                abort_unless($current->canDo('controlRoom.alerts.manage'), 403);
                
                $frames = $this->originalReadOwner($context)->frames['savedReadFrames'] ?? [];
                if (isset($frames[$savedShiftId])) {
                    $this->verifySavedFrame($frames[$savedShiftId], $current, $reads, $context);
                    return $frames[$savedShiftId]['public'];
                }
                $columns = ['id', 'status', 'handover_status', 'handover_version', 'shift_lead_user_id', 'team_members',
                    'handed_over_to_user_id', 'handover_prepared_at', 'handover_accepted_at', 'handed_over_at', 'starts_at', 'ends_at', 'updated_at'];
                $actor = $context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, []);
                $actor->toSql();
                $original = $reads->query(Shift::query()->whereKey($savedShiftId)
                    ->whereIn('handover_status', [Shift::HANDOVER_PREPARED, Shift::HANDOVER_ACCEPTED])
                    ->whereExists($actor->selectRaw('1')->toBase()));
                $metadataRow = (clone $original)->select($columns)
                    ->selectRaw('SHA2(CAST(team_members AS CHAR CHARACTER SET utf8mb4),256) as team_members_sha256')
                    ->selectRaw('SHA2(CAST(handover_snapshot AS CHAR CHARACTER SET utf8mb4),256) as snapshot_sha256')
                    ->selectRaw(ControlRoomHandoverSummaryProtocol::manifestMetadataSql('handover_snapshot').' as summary_protocol')
                    ->selectRaw("JSON_TYPE(JSON_EXTRACT(handover_snapshot,'$.alerts')) as saved_entry_type")
                    ->selectRaw("JSON_LENGTH(JSON_EXTRACT(handover_snapshot,'$.alerts')) as saved_entry_count")->toBase()->first();
                abort_unless($metadataRow !== null, 404);
                // Raw base rows bypass retrieved hooks; no mutable model manufactures participant or inventory evidence.
                $metadata = (new Shift)->setRawAttributes((array) $metadataRow, true);
                abort_unless(is_string($metadata->snapshot_sha256) && preg_match('/\A[a-f0-9]{64}\z/D', $metadata->snapshot_sha256)
                    && $this->canViewSavedSnapshot($metadata, $current), 403);
                $frozen = clone $original;
                foreach ($columns as $column) {
                    if ($column === 'team_members') {
                        $frozen->whereRaw('SHA2(CAST(control_room_shifts.team_members AS CHAR CHARACTER SET utf8mb4),256) <=> ?', [$metadata->team_members_sha256]);
                        continue;
                    }
                    $value = $metadata->getRawOriginal($column);
                    $value === null ? $frozen->whereNull($column)
                        : $frozen->whereRaw('CAST(control_room_shifts.'.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
                }
                $frozen->whereRaw('SHA2(CAST(handover_snapshot AS CHAR CHARACTER SET utf8mb4),256) = ?', [$metadata->snapshot_sha256]);
                $members = [...$metadata->memberUserIds(), $metadata->handed_over_to_user_id];
                $auditBranch = ! $current->canDo('reports.viewAny') && ! in_array((int) $current->id, array_map('intval', $members), true);
                $auditQuery = $auditBranch ? $reads->query(AuditLog::query()->where('action', 'controlRoom.shift.handoverPrepared')
                    ->where('auditable_type', $metadata->getMorphClass())->where('auditable_id', $metadata->id)->where('user_id', $current->id)) : null;
                $auditSet = $auditQuery === null ? [] : $this->savedActorAuditSet($auditQuery);
                // ANY original historical actor audit is still sufficient; zero is unavailable, not a unique/version rule.
                abort_unless(! $auditBranch || $auditSet !== [], 403);
                $reference = new \stdClass;
                $frame = ['reference' => $reference, 'context_id' => spl_object_id($context), 'reads' => $reads, 'viewer' => $current, 'viewer_id' => (int) $current->id,
                    'metadata' => $metadata, 'original' => $frozen, 'audit_query' => $auditQuery, 'audit_set' => $auditSet];
                $this->verifySavedFrame($frame, $current, $reads, $context);
                $entry = "JSON_EXTRACT(control_room_shifts.handover_snapshot,CONCAT('$.alerts[',cr_saved_entry.ordinal-1,']'))";
                $rows = $reads->query((clone $frozen)->crossJoin(\Illuminate\Support\Facades\DB::raw(
                    "JSON_TABLE(control_room_shifts.handover_snapshot,'$.alerts[*]' COLUMNS(ordinal FOR ORDINALITY)) as cr_saved_entry")))
                    ->selectRaw('cr_saved_entry.ordinal as ordinal')
                    ->selectRaw(ControlRoomHandoverSummaryProtocol::bindingMetadataSql($entry).' as summary_binding')
                    ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.id')) = 'INTEGER' THEN JSON_EXTRACT($entry,'$.id') ELSE NULL END as alert_id")
                    ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.summary_origin.type')) = 'STRING' AND CAST(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.summary_origin.type')) AS BINARY) IN (BINARY 'alert',BINARY 'incident',BINARY 'withheld',BINARY 'source_copy') THEN JSON_UNQUOTE(JSON_EXTRACT($entry,'$.summary_origin.type')) ELSE 'unknown' END as summary_origin_type")
                    ->selectRaw("SHA2(CAST(JSON_EXTRACT($entry,'$.summary_origin') AS CHAR CHARACTER SET utf8mb4),256) as origin_sha256")
                    ->selectRaw("SHA2(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.summary')),256) as summary_sha256")
                    ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.summary')) = 'STRING' AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.summary'))) > 0 THEN 1 ELSE 0 END as summary_valid")
                    ->selectRaw("SHA2(CAST($entry AS CHAR CHARACTER SET utf8mb4),256) as row_sha256")
                    ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.site.id')) = 'INTEGER' THEN JSON_EXTRACT($entry,'$.site.id') ELSE NULL END as saved_site_id")
                    ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.person.id')) = 'INTEGER' THEN JSON_EXTRACT($entry,'$.person.id') ELSE NULL END as saved_client_id")
                    ->orderBy('cr_saved_entry.ordinal')->toBase()->get()->map(fn ($row): array => [
                        'ordinal' => (int) $row->ordinal, 'alert_id' => $row->alert_id === null ? null : (int) $row->alert_id,
                        'summary_origin' => ['type' => $row->summary_origin_type, 'sha256' => $row->origin_sha256],
                        'summary_binding' => $row->summary_binding === null ? null : json_decode($row->summary_binding, true, 512, JSON_THROW_ON_ERROR),
                        'summary_sha256' => $row->summary_sha256, 'summary_valid' => (int) $row->summary_valid === 1, 'row_sha256' => $row->row_sha256,
                        'saved_site_id' => $row->saved_site_id === null ? null : (int) $row->saved_site_id,
                        'saved_client_id' => $row->saved_client_id === null ? null : (int) $row->saved_client_id,
                    ])->all();
                $ordinals = count($rows) === 0 ? [] : range(1, count($rows));
                abort_unless($metadata->saved_entry_type === 'ARRAY' && (int) $metadata->saved_entry_count === count($rows)
                    && array_column($rows, 'ordinal') === $ordinals, 404, 'The original saved-summary inventory is unavailable.');
                $this->verifySavedFrame($frame, $current, $reads, $context);
                $frame['public'] = ['reference' => $reference, 'shift' => ['id' => (int) $metadata->id,
                    'handover_version' => (int) $metadata->handover_version, 'status' => $metadata->status,
                    'handover_status' => $metadata->handover_status, 'snapshot_sha256' => $metadata->snapshot_sha256,
                    'summary_protocol' => $metadata->summary_protocol === null ? null : json_decode($metadata->summary_protocol, true, 512, JSON_THROW_ON_ERROR)], 'rows' => $rows];
                $frames[$savedShiftId] = $frame; $this->originalReadOwner($context)->frames['savedReadFrames'] = $frames;
                return $frame['public'];
            });
    }

    /** Existing parser/note consumers receive a summary-redacted snapshot under saved-row permission. */
    public function savedSnapshotForValidation(User $viewer, object $reference, HealthSafetyReadContext $context): Shift
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($reference, $context): Shift {
                $frame = null;
                foreach ($this->originalReadOwner($context)->frames['savedReadFrames'] ?? [] as $candidate) {
                    if ($candidate['reference'] === $reference) { $frame = $candidate; break; }
                }
                if ($frame === null) { throw new \LogicException('Use the original saved-row reference inside its own active context.'); }
                $this->verifySavedFrame($frame, $current, $reads, $context);
                abort_unless(array_all($frame['public']['rows'], fn (array $row): bool => $row['summary_valid']), 404,
                    'The original saved-summary shape is unavailable.');
                // Every summary is replaced in SQL before hydration; the original digest stays only in the private frame.
                // Direct ordinal paths preserve the stored array order; JSON_ARRAYAGG would not guarantee it.
                $paths = [];
                foreach ($frame['public']['rows'] as $row) {
                    $path = '$.alerts['.($row['ordinal'] - 1).']';
                    $entry = "JSON_EXTRACT(control_room_shifts.handover_snapshot,'$path')";
                    $paths[] = "'$path.summary','Saved summary details unavailable'";
                    $paths[] = "'$path.summary_binding',(".ControlRoomHandoverSummaryProtocol::bindingMetadataSql($entry).')';
                }
                $paths[] = "'$.summary_protocol',(".ControlRoomHandoverSummaryProtocol::manifestMetadataSql('control_room_shifts.handover_snapshot').')';
                $snapshot = 'JSON_SET(control_room_shifts.handover_snapshot,'.implode(',', $paths).')';
                $columns = ['id','name','starts_at','ends_at','status','shift_lead_user_id','team_members','open_alerts_at_start','open_alerts_at_end',
                    'alerts_created','alerts_resolved','alerts_escalated','handover_notes','priority_items','handed_over_to_user_id','handed_over_at',
                    'handover_status','handover_version','handover_prepared_at','handover_accepted_at','created_at','updated_at'];
                $noteInventory = $this->discoverSavedNoteMetadata($current, $reference, $context);
                $noteMask = $this->savedNoteMaskSql($snapshot, $noteInventory);
                $query = $reads->query((clone $frame['original'])->select($columns)
                    ->selectRaw($noteMask['sql'].' as handover_snapshot', $noteMask['bindings']));
                $verify = fn () => $this->verifySavedFrame($frame, $current, $reads, $context);
                ControlRoomOriginalReadOuterQuery::attach($query, $verify, $reads);
                $saved = $query->toBase()->first(); abort_unless($saved !== null, 404); $verify();
                $projection = (new Shift)->setRawAttributes((array) $saved, true);
                $this->originalReadOwner($context)->frames['savedProjectionProofs'] ??= new \WeakMap;
                $this->originalReadOwner($context)->frames['savedProjectionProofs'][$projection] = ['reference' => $reference, 'context_id' => spl_object_id($context),
                    'viewer_id' => (int) $current->id, 'attributes_sha256' => hash('sha256', serialize($projection->getRawOriginal()))];
                return $projection;
            });
    }

    /** Memory-only lookup: no query, permission refresh, public array or reference rebasing. */
    private function savedSummaryMemoryRow(User $viewer, User $current, \App\Services\CurrentAuthorizationReads $reads,
        object $reference, int $ordinal, int $alertId, HealthSafetyReadContext $context): array
    {
        $context->assertActive(); $reads->assertActive();
        if ($viewer !== $current || $ordinal < 1 || $alertId < 1) {
            throw new \LogicException('Use the exact original saved-summary viewer and row tuple.');
        }
        $owner = (self::$originalReadOwners[$context] ?? null)?->get();
        foreach ($owner?->frames['savedReadFrames'] ?? [] as $frame) {
            if ($frame['reference'] !== $reference) { continue; }
            if (($frame['viewer'] ?? null) !== $current || $frame['reads'] !== $reads
                || $frame['context_id'] !== spl_object_id($context) || $frame['viewer_id'] !== (int) $current->id) {
                throw new \LogicException('The original saved-summary tuple changed.');
            }
            $matches = array_values(array_filter($frame['public']['rows'],
                fn (array $row): bool => $row['ordinal'] === $ordinal && $row['alert_id'] === $alertId));
            if (count($matches) !== 1) { throw new \LogicException('Use the exact original saved-summary ordinal and Alert.'); }
            return [$frame, $matches[0]];
        }
        throw new \LogicException('Use the privately admitted original saved-row reference.');
    }

    /** Metadata/EXISTS constraints only. This seam never admits or hydrates a saved summary body. */
    public function consumeSavedSummaryRecipe(User $viewer, object $reference, int $ordinal, int $alertId,
        HealthSafetyReadContext $context, callable $consume): array
    {
        $context->assertActive();
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads)
                use ($viewer, $reference, $ordinal, $alertId, $context, $consume): array {
                [$frame, $row] = $this->savedSummaryMemoryRow($viewer, $current, $reads, $reference, $ordinal, $alertId, $context);
                $unavailable = ['available' => false, 'result' => null];
                $manifest = $frame['public']['shift']['summary_protocol'];
                if (! $row['summary_valid'] || $row['summary_binding'] === null || $manifest === null) { return $unavailable; }
                // Complete ordered inventory, including every intentionally null binding, is validated in memory.
                $inventory = [];
                foreach ($frame['public']['rows'] as $entry) {
                    if ($entry['alert_id'] === null || $entry['alert_id'] < 1) { return $unavailable; }
                    $inventory[] = ['id' => $entry['alert_id'], 'site' => ['id' => $entry['saved_site_id']],
                        'person' => ['id' => $entry['saved_client_id']], 'summary_binding' => $entry['summary_binding']];
                    if ($entry['summary_binding'] !== null && (! $entry['summary_valid']
                        || ! hash_equals($entry['summary_binding']['summary_sha256'], $entry['summary_sha256']))) { return $unavailable; }
                }
                try { ControlRoomHandoverSummaryProtocol::preservedManifest($manifest, $inventory, $frame['public']['shift']['id']); }
                catch (\LogicException $error) { return $unavailable; }
                $owner = $this->originalReadOwner($context); $shiftId = $frame['public']['shift']['id'];
                $anchor = $owner->frames['savedSummaryRecipes'][$shiftId] ?? null;
                if ($anchor === null) {
                    // Reserve FIRST before SQL; a failed admission can never discover a replacement receipt.
                    $anchor = (object) ['state' => 'pending', 'reference' => $reference, 'viewer' => $current,
                        'reads' => $reads, 'context' => $context, 'manifest' => $manifest];
                    $owner->frames['savedSummaryRecipes'][$shiftId] = $anchor;
                    try {
                        $this->verifySavedFrame($frame, $current, $reads, $context);
                        $original = $this->savedSummaryRecipeQuery($frame, $manifest, $reads);
                        $raw = (clone $original)->select([])->selectRaw('cr_recipe_receipt.id as receipt_id,
                            cr_recipe_receipt.user_id as receipt_user_id, cr_recipe_receipt.client_id as receipt_client_id,
                            CAST(cr_recipe_receipt.created_at AS CHAR) as receipt_created_at,
                            SHA2(CAST(cr_recipe_receipt.meta AS CHAR CHARACTER SET utf8mb4),256) as receipt_sha256')
                            ->limit(2)->toBase()->get();
                        if ($raw->count() !== 1) { $anchor->state = 'denied'; return $unavailable; }
                        $receipt = $raw[0];
                        if (! is_string($receipt->receipt_sha256) || preg_match('/^[a-f0-9]{64}$/D', $receipt->receipt_sha256) !== 1
                            || (int) $receipt->receipt_id < 1 || (int) $receipt->receipt_user_id < 1 || $receipt->receipt_client_id !== null) {
                            $anchor->state = 'denied'; return $unavailable;
                        }
                        $anchor->receipt = ['id' => (int) $receipt->receipt_id, 'user_id' => (int) $receipt->receipt_user_id,
                            'client_id' => null, 'created_at' => $receipt->receipt_created_at, 'meta_sha256' => $receipt->receipt_sha256];
                        $original->where('cr_recipe_receipt.id', $anchor->receipt['id'])
                            ->where('cr_recipe_receipt.user_id', $anchor->receipt['user_id'])->whereNull('cr_recipe_receipt.client_id')
                            ->whereRaw('cr_recipe_receipt.created_at <=> ?', [$anchor->receipt['created_at']])
                            ->whereRaw('BINARY SHA2(CAST(cr_recipe_receipt.meta AS CHAR CHARACTER SET utf8mb4),256) = BINARY ?', [$anchor->receipt['meta_sha256']]);
                        $anchor->original = $this->cloneSavedSummaryQuery($original->select([])->selectRaw('1'));
                        $anchor->state = 'ready';
                    } catch (\Throwable $error) { $anchor->state = 'denied'; throw $error; }
                }
                if ($anchor->state !== 'ready') { return $unavailable; }
                $verify = function () use ($anchor, $frame, $viewer, $current, $reads, $reference, $ordinal, $alertId, $context): void {
                    $this->savedSummaryMemoryRow($viewer, $current, $reads, $reference, $ordinal, $alertId, $context);
                    $owner = (self::$originalReadOwners[$context] ?? null)?->get();
                    if (($owner?->frames['savedSummaryRecipes'][$frame['public']['shift']['id']] ?? null) !== $anchor
                        || $anchor->state !== 'ready' || $anchor->reference !== $reference || $anchor->viewer !== $current
                        || $anchor->reads !== $reads || $anchor->context !== $context) {
                        throw new \LogicException('Use the original saved recipe admission in its own context.');
                    }
                    try { $this->verifySavedFrame($frame, $current, $reads, $context, $this->cloneSavedSummaryQuery($anchor->original)); }
                    catch (\Throwable $error) { $anchor->state = 'denied'; throw $error; }
                };
                $verify();
                $fixed = $this->cloneSavedSummaryQuery($anchor->original);
                $constraint = ['sql' => $fixed->toSql(), 'bindings' => $fixed->getBindings()];
                foreach ($constraint['bindings'] as $value) {
                    if ($value !== null && ! is_scalar($value)) { throw new \LogicException('Saved recipe constraints use scalar bindings only.'); }
                }
                $metadata = ['row' => $row, 'shift' => $frame['public']['shift'], 'manifest' => $anchor->manifest, 'receipt' => $anchor->receipt];
                try {
                    $result = $consume($metadata, $constraint, $verify, $current, $reads, $context);
                    if (! is_array($result)) { throw new \LogicException('Saved recipe consumers return detached arrays only.'); }
                    \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                    $verify();
                } catch (\Throwable $error) { $anchor->state = 'denied'; throw $error; }
                return ['available' => true, 'result' => $result];
            });
    }

    /** Exact normal receipt metadata compared in SQL; arbitrary snapshot or audit prose never crosses hydration. */
    private function savedSummaryRecipeQuery(array $frame, array $manifest, \App\Services\CurrentAuthorizationReads $reads): \Illuminate\Database\Eloquent\Builder
    {
        $snapshot = 'control_room_shifts.handover_snapshot';
        $get = static fn (string $path): string => "JSON_EXTRACT($snapshot,'$.$path')";
        $override = "CASE WHEN JSON_TYPE(".$get('override').")='NULL' THEN CAST('null' AS JSON) ELSE JSON_OBJECT('actor_id',"
            .$get('override.actor.id').",'reason',".$get('override.reason').",'at',".$get('override.at').') END';
        $expected = "JSON_OBJECT('actor_id',".$get('prepared_by.id').",'shift_id',control_room_shifts.id,'incoming_lead_user_id',"
            .$get('incoming_shift.lead.id').",'reviewed_alert_ids',".$get('reviewed_alert_ids').",'priority_alert_ids',".$get('priority_alert_ids')
            .",'required_alert_ids',".$get('required_alert_ids').",'carry_forward_total',".$get('carry_forward.total').",'criteria_at',".$get('criteria_at')
            .",'handover_version',".$get('summary_protocol.prepared_version').",'summary_protocol',".$get('summary_protocol').",'override',$override)";
        $query = $this->cloneSavedSummaryQuery($frame['original']);
        // Existing ANY actor admission remains separate, but its complete FIRST set belongs to this constraint too.
        if ($frame['audit_query'] !== null) {
            foreach ($frame['audit_set'] as $receipt) {
                $audit = $this->cloneSavedSummaryQuery($frame['audit_query'])->whereKey($receipt['id'])->where('user_id', $receipt['user_id'])
                    ->whereRaw('created_at <=> ?', [$receipt['created_at']])
                    ->whereRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) <=> ?', [$receipt['sha256']]);
                $query->whereExists($audit->select([])->selectRaw('1')->toBase());
            }
            $extras = $this->cloneSavedSummaryQuery($frame['audit_query'])->whereNotIn('id', array_column($frame['audit_set'], 'id'));
            $query->whereNotExists($extras->select([])->selectRaw('1')->toBase());
        }
        $query->join('audit_logs as cr_recipe_receipt', 'cr_recipe_receipt.auditable_id', '=', 'control_room_shifts.id')
            ->whereRaw('BINARY cr_recipe_receipt.action = BINARY ?', ['controlRoom.shift.handoverPrepared'])
            ->whereRaw('BINARY cr_recipe_receipt.auditable_type = BINARY ?', [$frame['metadata']->getMorphClass()])
            ->whereNull('cr_recipe_receipt.client_id')
            ->whereRaw("JSON_TYPE(".$get('prepared_by.id').")='INTEGER' AND ".$get('prepared_by.id').'>0')
            ->whereRaw('cr_recipe_receipt.user_id = '.$get('prepared_by.id'))
            ->whereRaw("JSON_TYPE(".$get('incoming_shift.lead.id').")='INTEGER' AND ".$get('incoming_shift.lead.id').'>0')
            ->whereRaw("JSON_TYPE(".$get('carry_forward.total').")='INTEGER' AND ".$get('carry_forward.total').'>=0')
            ->whereRaw("JSON_TYPE(".$get('criteria_at').")='STRING'")
            ->whereRaw("JSON_TYPE(JSON_EXTRACT(cr_recipe_receipt.meta,'$.handover_version'))='INTEGER'")
            ->whereRaw('JSON_EXTRACT(cr_recipe_receipt.meta,\'$.handover_version\') = ?', [$manifest['prepared_version']])
            ->whereRaw('CAST(cr_recipe_receipt.meta AS JSON) = '.$expected)
            ->whereRaw("(SELECT COUNT(*) FROM audit_logs cr_recipe_count WHERE BINARY cr_recipe_count.action = BINARY ?
                AND BINARY cr_recipe_count.auditable_type = BINARY ? AND cr_recipe_count.auditable_id = control_room_shifts.id
                AND (BINARY JSON_UNQUOTE(JSON_EXTRACT(cr_recipe_count.meta,'$.handover_version')) = BINARY ?
                    OR JSON_EXTRACT(cr_recipe_count.meta,'$.handover_version') = CAST(? AS JSON))) = 1",
                ['controlRoom.shift.handoverPrepared', $frame['metadata']->getMorphClass(),
                    (string) $manifest['prepared_version'], (string) $manifest['prepared_version']]);
        foreach (['reviewed_alert_ids', 'priority_alert_ids', 'required_alert_ids'] as $field) {
            $value = "JSON_EXTRACT($snapshot,CONCAT('$.{$field}[',cr_recipe_id.ordinal-1,']'))";
            $query->whereRaw("JSON_TYPE(".$get($field).")='ARRAY' AND NOT EXISTS (SELECT 1 FROM JSON_TABLE($snapshot,'$.{$field}[*]'
                COLUMNS(ordinal FOR ORDINALITY)) cr_recipe_id WHERE JSON_TYPE($value)<>'INTEGER' OR $value<1)");
        }
        $query->whereRaw("(JSON_TYPE(".$get('override').")='NULL' OR (JSON_TYPE(".$get('override').")='OBJECT'
            AND JSON_TYPE(".$get('override.actor.id').")='INTEGER' AND ".$get('override.actor.id').'>0'
            ." AND JSON_TYPE(".$get('override.reason').")='STRING' AND JSON_TYPE(".$get('override.at').")='STRING'))");
        // JSON numeric equality alone does not distinguish INTEGER from 1.0; validate receipt types themselves.
        $meta = 'cr_recipe_receipt.meta';
        $receiptGet = static fn (string $path): string => "JSON_EXTRACT($meta,'$.$path')";
        $normalKeys = ['actor_id','shift_id','incoming_lead_user_id','reviewed_alert_ids','priority_alert_ids',
            'required_alert_ids','carry_forward_total','criteria_at','handover_version','summary_protocol','override'];
        $paths = implode(',', array_map(static fn (string $key): string => "'$.$key'", $normalKeys));
        $query->whereRaw("JSON_TYPE($meta)='OBJECT' AND JSON_LENGTH($meta)=11 AND JSON_CONTAINS_PATH($meta,'all',$paths)=1")
            ->whereRaw('('.ControlRoomHandoverSummaryProtocol::manifestMetadataSql($meta).') IS NOT NULL')
            ->whereRaw("JSON_TYPE(".$receiptGet('criteria_at').")='STRING'");
        foreach (['actor_id','shift_id','incoming_lead_user_id','handover_version','carry_forward_total'] as $field) {
            $query->whereRaw("JSON_TYPE(".$receiptGet($field).")='INTEGER' AND ".$receiptGet($field).($field === 'carry_forward_total' ? '>=0' : '>0'));
        }
        foreach (['reviewed_alert_ids','priority_alert_ids','required_alert_ids'] as $field) {
            $value = "JSON_EXTRACT($meta,CONCAT('$.{$field}[',cr_receipt_id.ordinal-1,']'))";
            $query->whereRaw("JSON_TYPE(".$receiptGet($field).")='ARRAY' AND NOT EXISTS (SELECT 1 FROM JSON_TABLE($meta,'$.{$field}[*]'
                COLUMNS(ordinal FOR ORDINALITY)) cr_receipt_id WHERE JSON_TYPE($value)<>'INTEGER' OR $value<1)");
        }
        $receiptOverride = $receiptGet('override');
        $query->whereRaw("(JSON_TYPE($receiptOverride)='NULL' OR (JSON_TYPE($receiptOverride)='OBJECT'
            AND JSON_LENGTH($receiptOverride)=3 AND JSON_CONTAINS_PATH($receiptOverride,'all','$.actor_id','$.reason','$.at')=1
            AND JSON_TYPE(".$receiptGet('override.actor_id').")='INTEGER' AND ".$receiptGet('override.actor_id').">0
            AND JSON_TYPE(".$receiptGet('override.reason').")='STRING' AND JSON_TYPE(".$receiptGet('override.at').")='STRING'))");
        return $reads->query($query);
    }

    /** Recursively isolate plain query nodes; no guarded query is stripped or exposed to consumers. */
    private function cloneSavedSummaryQuery(\Illuminate\Database\Eloquent\Builder $original): \Illuminate\Database\Eloquent\Builder
    {
        $copies = new \SplObjectStorage;
        $copy = function (mixed $value) use (&$copy, $copies): mixed {
            if ($value instanceof \Illuminate\Database\Eloquent\Builder) {
                if (get_class($value) !== \Illuminate\Database\Eloquent\Builder::class) { throw new \LogicException('Saved recipe requires an original plain Eloquent query.'); }
                if ($copies->contains($value)) { return $copies[$value]; }
                $cloned = clone $value; $copies[$value] = $cloned;
                $query = $copy($value->getQuery()); $cloned->setModel(clone $value->getModel()); $cloned->setQuery($query); return $cloned;
            }
            if ($value instanceof \Illuminate\Database\Query\Builder) {
                if (! in_array(get_class($value), [\Illuminate\Database\Query\Builder::class, \Illuminate\Database\Query\JoinClause::class], true)) {
                    throw new \LogicException('Never strip an original saved recipe execution guard.');
                }
                if ($copies->contains($value)) { return $copies[$value]; }
                $cloned = clone $value; $copies[$value] = $cloned;
                foreach (get_object_vars($value) as $key => $part) {
                    if (! in_array($key, ['connection', 'grammar', 'processor'], true)) { $cloned->{$key} = $copy($part); }
                }
                return $cloned;
            }
            if (is_array($value)) { foreach ($value as $key => $part) { $value[$key] = $copy($part); } }
            return $value;
        };
        return $copy($original);
    }

    /** H&S must certify the actual original source AND historical summary recipe before prose SQL. */
    public function consumeSavedSummary(User $viewer, object $reference, int $ordinal, int $alertId, HealthSafetyReadContext $context,
        object $sourceAdmission, callable $project,
        ?\Illuminate\Database\Eloquent\Builder $actualOuter = null): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($viewer, $reference, $ordinal, $alertId, $context, $sourceAdmission, $project, $actualOuter): array {
                [$frame, $row] = $this->savedSummaryMemoryRow($viewer, $current, $reads, $reference, $ordinal, $alertId, $context);
                $unavailable = ['available' => false, 'result' => null];
                if (! $row['summary_valid'] || $sourceAdmission instanceof \Illuminate\Database\Eloquent\Builder) { return $unavailable; }
                $sources = app(ControlRoomSourceContentService::class);
                // Sole H&S owner attests its privately registered, original-context source/recipe admission.
                // This method's caller cannot supply a builder or boolean proof as a certificate.
                if (! method_exists($sources, 'consumeSavedSummarySourceAdmission')) { return $unavailable; }
                $admittedResult = $unavailable; $finalProof = null;
                $sources->consumeSavedSummarySourceAdmission($current, $context, $reference, $row, $sourceAdmission,
                    function (\Illuminate\Database\Eloquent\Builder $actualOriginalAlertRead, callable $fullOriginalSourceProof,
                        User $sourceCurrent, \App\Services\CurrentAuthorizationReads $sourceReads, HealthSafetyReadContext $sourceContext)
                    use ($frame, $current, $reads, $context, $row, $ordinal, $alertId, $project, $actualOuter, $unavailable, &$admittedResult, &$finalProof): array {
                        if ($sourceCurrent !== $current || $sourceReads !== $reads || $sourceContext !== $context) {
                            throw new \LogicException('Saved-summary source admission belongs to another original read.');
                        }
                        if (! $actualOriginalAlertRead->getModel() instanceof \App\Models\ControlRoomAlert
                            || $actualOriginalAlertRead->getQuery()->from !== 'control_room_alerts'
                            || $actualOriginalAlertRead->getConnection() !== $frame['original']->getConnection()) {
                            throw new \LogicException('Saved summary requires the actual canonical original Alert read.');
                        }
                        // A no-op verifier, type tag or today's ordinary Alert cannot certify historical prose.
                        // Sole H&S consumer returns TRUE only inside its actual original source admission.
                        if ($fullOriginalSourceProof($row, $current, $reads, $context) !== true) { return $unavailable; }
                        $verify = function () use ($frame, $current, $reads, $context, $row, $fullOriginalSourceProof): void {
                            $this->verifySavedFrame($frame, $current, $reads, $context);
                            if ($fullOriginalSourceProof($row, $current, $reads, $context) !== true) {
                                abort(404, 'The original saved-summary source read is unavailable.');
                            }
                        };
                        $entry = "JSON_EXTRACT(control_room_shifts.handover_snapshot,CONCAT('$.alerts[',cr_saved_entry.ordinal-1,']'))";
                        $source = (clone $actualOriginalAlertRead)->setEagerLoads([])->reorder()->select([])->selectRaw('1')
                            ->whereKey($alertId)
                            ->whereRaw("control_room_alerts.id = JSON_EXTRACT($entry,'$.id')")
                            ->whereRaw("control_room_alerts.client_id <=> NULLIF(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.person.id')),'null')");
                        $effectiveSite = app(\App\Services\UserSiteAccessService::class)->alertEffectiveSiteExpression($source, $reads);
                        $source->whereRaw("($effectiveSite) <=> NULLIF(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.site.id')),'null')");
                        $source->toSql();
                        $body = $reads->query((clone $frame['original'])->crossJoin(\Illuminate\Support\Facades\DB::raw(
                            "JSON_TABLE(control_room_shifts.handover_snapshot,'$.alerts[*]' COLUMNS(ordinal FOR ORDINALITY)) as cr_saved_entry")))
                            ->where('cr_saved_entry.ordinal', $ordinal)->whereRaw("JSON_TYPE(JSON_EXTRACT($entry,'$.summary')) = 'STRING'")
                            ->whereRaw("SHA2(CAST($entry AS CHAR CHARACTER SET utf8mb4),256) = ?", [$row['row_sha256']])
                            ->whereRaw("SHA2(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.summary')),256) = ?", [$row['summary_sha256']])
                            ->whereExists($source->toBase())->selectRaw("JSON_UNQUOTE(JSON_EXTRACT($entry,'$.summary')) as saved_summary");
                        ControlRoomOriginalReadOuterQuery::attach($body, $verify, $reads);
                        if ($actualOuter !== null) { ControlRoomOriginalReadOuterQuery::attach($actualOuter, $verify, $reads); }
                        $verify(); $record = $body->first(); $verify();
                        if ($record === null) { return $unavailable; }
                        $summary = $record->getRawOriginal('saved_summary');
                        if (! is_string($summary) || ! hash_equals($row['summary_sha256'], hash('sha256', $summary))) {
                            abort(404, 'The original saved-summary projection changed.');
                        }
                        $result = $project(['alert_id' => $alertId, 'ordinal' => $ordinal, 'summary' => $summary], $reads, $current, $context);
                        if (! is_array($result)) { throw new \LogicException('Saved-summary projectors return detached arrays only.'); }
                        \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                        $verify();
                        $finalProof = $verify;
                        return $admittedResult = ['available' => true, 'result' => $result];
                            });
                // Ignore adapter-returned payloads; only the owner's admitted projector can supply a result.
                if ($finalProof !== null) { $finalProof(); }
                return $admittedResult;
            });
    }

    /** Attest only the actual raw projection returned by this owner inside its original saved-row context. */
    /** Backend metadata only; no saved Note content/name or copied-source grant. */
    public function discoverSavedNoteMetadata(User $viewer, object $reference, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($reference, $context): array {
                foreach ($this->originalReadOwner($context)->frames['savedReadFrames'] ?? [] as $id => $frame) {
                    if ($frame['reference'] !== $reference) { continue; }
                    $this->verifySavedFrame($frame, $current, $reads, $context);
                    if (isset($frame['note_inventory'])) { return $frame['note_inventory']; }
                    $rows = []; $valid = true;
                    foreach (['pinned_notes','followup_notes'] as $family) {
                        $entry = "JSON_EXTRACT(control_room_shifts.handover_snapshot,CONCAT('$.{$family}[',cr_saved_note.ordinal-1,']'))";
                        $shape = (clone $frame['original'])->selectRaw("JSON_TYPE(JSON_EXTRACT(handover_snapshot,'$.{$family}')) as collection_type,
                            JSON_LENGTH(JSON_EXTRACT(handover_snapshot,'$.{$family}')) as entry_count")->toBase()->first();
                        $notes = $reads->query((clone $frame['original'])->crossJoin(\Illuminate\Support\Facades\DB::raw(
                            "JSON_TABLE(control_room_shifts.handover_snapshot,'$.{$family}[*]' COLUMNS(ordinal FOR ORDINALITY)) as cr_saved_note")))
                            ->selectRaw('cr_saved_note.ordinal as ordinal');
                        foreach (['id'=>'id','alert_id'=>'alert_id','user_id'=>'user.id'] as $name=>$path) {
                            $value = "JSON_EXTRACT($entry,'$.{$path}')";
                            // The existing parser permits positive numeric string identities as well as integers.
                            $notes->selectRaw("CASE WHEN (JSON_TYPE($value) = 'INTEGER' AND $value > 0)
                                OR (JSON_TYPE($value) = 'STRING' AND REGEXP_LIKE(JSON_UNQUOTE($value),'^[1-9][0-9]{0,17}$','c'))
                                THEN CAST(JSON_UNQUOTE($value) AS UNSIGNED) ELSE NULL END as {$name}");
                        }
                        $notes->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.alert_id')) = 'NULL' THEN 1 ELSE 0 END as alert_is_null")
                            ->selectRaw("CASE WHEN JSON_CONTAINS_PATH($entry,'one','$.purpose') THEN 1 ELSE 0 END as purpose_present")
                            ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.content')) = 'STRING' AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.content'))) > 0 THEN 1 ELSE 0 END as content_valid")
                            ->selectRaw("SHA2(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.content')),256) as content_sha256")
                            ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.user'))='OBJECT'
                                AND JSON_TYPE(JSON_EXTRACT($entry,'$.user.name'))='STRING'
                                AND REGEXP_LIKE(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.user.name')),'[^[:space:]]','c')
                                THEN 1 ELSE 0 END as author_name_valid")
                            ->selectRaw("SHA2(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.user.name')),256) as author_name_sha256")
                            ->selectRaw("SHA2(CAST(JSON_EXTRACT($entry,'$.operator_origin') AS CHAR CHARACTER SET utf8mb4),256) as origin_sha256")
                            ->selectRaw("SHA2(CAST($entry AS CHAR CHARACTER SET utf8mb4),256) as entry_sha256")
                            ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.created_at')) = 'STRING'
                                AND REGEXP_LIKE(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.created_at')),'^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}([.][0-9]+)?(Z|[+-][0-9]{2}:[0-9]{2})$','c')
                                THEN JSON_UNQUOTE(JSON_EXTRACT($entry,'$.created_at')) ELSE NULL END as created_at")
                            ->selectRaw("CASE WHEN JSON_EXTRACT($entry,'$.followup_at') IS NULL OR JSON_TYPE(JSON_EXTRACT($entry,'$.followup_at')) = 'NULL'
                                OR (JSON_TYPE(JSON_EXTRACT($entry,'$.followup_at')) = 'STRING'
                                AND REGEXP_LIKE(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.followup_at')),'^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}([.][0-9]+)?(Z|[+-][0-9]{2}:[0-9]{2})$','c'))
                                THEN 1 ELSE 0 END as followup_valid")
                            ->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.followup_at')) = 'STRING'
                                AND REGEXP_LIKE(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.followup_at')),'^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}([.][0-9]+)?(Z|[+-][0-9]{2}:[0-9]{2})$','c')
                                THEN JSON_UNQUOTE(JSON_EXTRACT($entry,'$.followup_at')) ELSE NULL END as followup_at")
                            ->orderBy('cr_saved_note.ordinal');
                        foreach (['type'=>['note','action','escalation','decision','handover'],
                            'purpose'=>['general','immediate_controls','escalation_handover']] as $field=>$values) {
                            $allowed = implode(',', array_map(fn ($value): string => "BINARY '{$value}'", $values));
                            $notes->selectRaw("CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.{$field}')) = 'STRING'
                                AND BINARY JSON_UNQUOTE(JSON_EXTRACT($entry,'$.{$field}')) IN ($allowed)
                                THEN JSON_UNQUOTE(JSON_EXTRACT($entry,'$.{$field}')) ELSE NULL END as {$field}");
                        }
                        // Only the ten already-established independent-origin fields may be discovered as values.
                        $origin = "JSON_EXTRACT($entry,'$.operator_origin')";
                        $notes->selectRaw(app(ControlRoomOperatorNoteReadService::class)->independentOriginMetadataSql($origin).' as independent_origin');
                        $found = $notes->toBase()->get()->map(fn ($raw): array => ['family'=>$family]+(array)$raw)->all();
                        $valid = $valid && $shape !== null && $shape->collection_type === 'ARRAY'
                            && (int)$shape->entry_count === count($found)
                            && array_map(fn($row):int=>(int)$row['ordinal'],$found) === (count($found) ? range(1,count($found)) : []);
                        array_push($rows, ...$found);
                    }
                    $this->verifySavedFrame($frame, $current, $reads, $context);
                    $frame['note_inventory'] = ['valid'=>$valid,'rows'=>$rows];
                    $frames = $this->originalReadOwner($context)->frames['savedReadFrames']; $frames[$id] = $frame; $this->originalReadOwner($context)->frames['savedReadFrames'] = $frames;
                    return $frame['note_inventory'];
                }
                throw new \LogicException('Use the original saved-row reference for Note metadata.');
            });
    }

    /** Only a privately registered original Note admission may reach a saved Note body SQL. */
    public function consumeSavedNote(User $viewer, object $reference, array $metadata, HealthSafetyReadContext $context,
        object $noteAdmission, callable $project): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($reference,$metadata,$context,$noteAdmission,$project): array {
                $unavailable=['available'=>false,'result'=>null]; $frame=null;
                foreach($this->originalReadOwner($context)->frames['savedReadFrames']??[] as $candidate){if($candidate['reference']===$reference){$frame=$candidate;break;}}
                if($frame===null){throw new \LogicException('Use the original saved Note row inside its own context.');}
                $inventory=$this->discoverSavedNoteMetadata($current,$reference,$context);
                if(!$inventory['valid']||!in_array($metadata,$inventory['rows'],true)){return$unavailable;}
                $result=$unavailable;$verifyFinal=null;
                app(ControlRoomOperatorNoteReadService::class)->consumeSavedNoteAdmission($current,$reference,$metadata,$context,$noteAdmission,
                    function(\Illuminate\Database\Eloquent\Builder$originalNote,callable$verifyNote,User$same,
                        \App\Services\CurrentAuthorizationReads$sameReads,HealthSafetyReadContext$sameContext,bool$authorNameReadable)
                    use($frame,$metadata,$context,$current,$reads,$project,&$result,&$verifyFinal,$unavailable):array{
                        if($same!==$current||$sameReads!==$reads||$sameContext!==$context){throw new \LogicException('Saved Note admission identity changed.');}
                        $verify=$verifyNote;$verifyFinal=$verify;
                        $family=$metadata['family'];$ordinal=(int)$metadata['ordinal'];
                        if(!in_array($family,['pinned_notes','followup_notes'],true)||$ordinal<1){return$unavailable;}
                        $entry="JSON_EXTRACT(control_room_shifts.handover_snapshot,'$.{$family}[".($ordinal-1)."]')";
                        $note=(clone$originalNote)->whereKey((int)$metadata['id'])->setEagerLoads([])->reorder()->select([])->selectRaw('1');
                        $note->toSql();
                        $body=$reads->query((clone$frame['original'])->whereRaw("SHA2(CAST($entry AS CHAR CHARACTER SET utf8mb4),256) = ?",[$metadata['entry_sha256']])
                            ->whereRaw("SHA2(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.content')),256) = ?",[$metadata['content_sha256']])
                            ->whereRaw("JSON_TYPE(JSON_EXTRACT($entry,'$.user.name'))='STRING'")
                            ->whereRaw("SHA2(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.user.name')),256) = ?",[$metadata['author_name_sha256']])
                            ->whereExists($note->toBase())->select([]));
                        $authorName=$authorNameReadable?"JSON_EXTRACT($entry,'$.user.name')":"'Name unavailable'";
                        // Only established fields and User id/name are consumed after original admission.
                        $body->select([])->selectRaw("JSON_OBJECT('id',JSON_EXTRACT($entry,'$.id'),'alert_id',JSON_EXTRACT($entry,'$.alert_id'),
                            'type',JSON_EXTRACT($entry,'$.type'),'purpose',JSON_EXTRACT($entry,'$.purpose'),'content',JSON_EXTRACT($entry,'$.content'),
                            'is_pinned',JSON_EXTRACT($entry,'$.is_pinned'),'requires_followup',JSON_EXTRACT($entry,'$.requires_followup'),
                            'followup_at',JSON_EXTRACT($entry,'$.followup_at'),
                            'user',JSON_OBJECT('id',JSON_EXTRACT($entry,'$.user.id'),'name',$authorName),
                            'created_at',JSON_EXTRACT($entry,'$.created_at')) as saved_note");
                        $body=self::prepareRegisteredCopiedFinal($body,'first_row');
                        ControlRoomOriginalReadOuterQuery::attach($body,$verify,$reads);
                        $rawResult=self::executeRegisteredCopiedFinal($body,'first_row',$current,$reads,$context);
                        $raw=$rawResult['row']===null?null:(object)$rawResult['row'];
                        $verify();if($raw===null){return$unavailable;}
                        $copy=json_decode($raw->saved_note,true);
                        if(!is_array($copy)||!is_string($copy['content']??null)||!hash_equals($metadata['content_sha256'],hash('sha256',$copy['content']))){abort(404,'The original saved Note projection changed.');}
                        $name=$copy['user']['name']??null;
                        if(!is_string($name)||trim($name)===''||($authorNameReadable
                            ?!hash_equals($metadata['author_name_sha256'],hash('sha256',$name)):$name!=='Name unavailable')){
                            abort(404,'The original saved Note author projection changed.');
                        }
                        $copy['id']=(int)$copy['id'];$copy['alert_id']=$copy['alert_id']===null?null:(int)$copy['alert_id'];$copy['user']['id']=(int)$copy['user']['id'];
                        $copy['is_pinned']=(bool)$copy['is_pinned'];$copy['requires_followup']=(bool)$copy['requires_followup'];
                        $projected=$project($copy);
                        if(!is_array($projected)){throw new \LogicException('Saved Note projectors return detached arrays only.');}
                        \App\Services\Incidents\IncidentRoutingSettings::detached($projected);$verify();
                        return$result=['available'=>true,'result'=>$projected];
                    });
                if($result['available']){$verifyFinal();}else{$this->verifySavedFrame($frame,$current,$reads,$context);}
                return$result;
            });
    }

    /** Preserve array order/parser identities while removing all unadmitted saved Note/author prose in SQL. */
    private function savedNoteMaskSql(string $snapshot, array $inventory): array
    {
        $bindings=[];
        foreach($inventory['rows']as$row){
            $family=$row['family'];$ordinal=(int)$row['ordinal'];
            if(!in_array($family,['pinned_notes','followup_notes'],true)||$ordinal<1){throw new \LogicException('Invalid original saved Note ordinal.');}
            $path="$.{$family}[".($ordinal-1)."]";$entry="JSON_EXTRACT(control_room_shifts.handover_snapshot,'$path')";
            $origin=app(ControlRoomOperatorNoteReadService::class)->independentOriginMetadataSql("JSON_EXTRACT($entry,'$.operator_origin')");
            $replacement="JSON_OBJECT('id',?,'alert_id',?,'type',?,'purpose',?,
                'content',CASE WHEN ".((int)$row['content_valid']===1?'1':'0')." THEN 'Saved note details unavailable' ELSE NULL END,
                'is_pinned',CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.is_pinned'))='BOOLEAN' THEN JSON_EXTRACT($entry,'$.is_pinned') ELSE FALSE END,
                'requires_followup',CASE WHEN JSON_TYPE(JSON_EXTRACT($entry,'$.requires_followup'))='BOOLEAN' THEN JSON_EXTRACT($entry,'$.requires_followup') ELSE FALSE END,
                'followup_at',?,'created_at',?,
                'user',CASE WHEN ? IS NULL THEN NULL ELSE JSON_OBJECT('id',?,'name',CASE WHEN ".((int)$row['author_name_valid']===1?'1':'0')." THEN 'Name unavailable' ELSE NULL END) END,
                'operator_origin',($origin))";
            if((int)$row['purpose_present']===0){$replacement="JSON_REMOVE($replacement,'$.purpose')";}
            array_push($bindings,$row['id']===null?null:(int)$row['id'],$row['alert_id']===null?null:(int)$row['alert_id'],
                $row['type'],$row['purpose'],$row['followup_at'],$row['created_at'],
                $row['user_id']===null?null:(int)$row['user_id'],$row['user_id']===null?null:(int)$row['user_id']);
            $snapshot="JSON_SET($snapshot,'$path',$replacement)";
        }
        return['sql'=>$snapshot,'bindings'=>$bindings];
    }

    /** Metadata constraints from actual private first saved/link reads; no caller Builder or body grant. */
    public function consumeOriginalSavedReadConstraints(User $current, \App\Services\CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, callable $consume): void
    {
        $context->assertViewer($current);$reads->assertActive();$constraints=[];
        foreach([$this->originalReadOwner($context)->frames['savedReadFrames']??[],$this->originalReadOwner($context)->frames['savedLinkFrames']??[]]as$frames){
            foreach($frames as$frame){
                if($frame['context_id']!==spl_object_id($context)||$frame['reads']!==$reads||$frame['viewer_id']!==(int)$current->id){
                    throw new \LogicException('Use the original saved metadata context.');
                }
                abort_unless($current->canDo('controlRoom.alerts.manage'),403);
                $original=clone$frame['original'];
                if($frame['audit_query']!==null){
                    foreach($frame['audit_set']as$receipt){
                        $present=(clone$frame['audit_query'])->whereKey($receipt['id'])->where('user_id',$receipt['user_id'])
                            ->whereRaw('created_at <=> ?',[$receipt['created_at']])
                            ->whereRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) <=> ?',[$receipt['sha256']]);
                        $original->whereExists($present->selectRaw('1')->toBase());
                    }
                    $extras=(clone$frame['audit_query'])->whereNotIn('id',array_column($frame['audit_set'],'id'));
                    $original->whereNotExists($extras->selectRaw('1')->toBase());
                }
                $constraints[]=$original;
            }
        }
        $consume($constraints);
    }

    public function verifySavedProjection(User $viewer, object $reference, HealthSafetyReadContext $context, Shift $projection, ?\Illuminate\Database\Eloquent\Builder $additionalRequiredRead = null): void
    {
        $this->verifySavedReference($viewer, $reference, $context, $additionalRequiredRead);
        $proof = $this->originalReadOwner($context)->frames['savedProjectionProofs'][$projection] ?? null;
        if ($proof === null || $proof['reference'] !== $reference || $proof['context_id'] !== spl_object_id($context)
            || $proof['viewer_id'] !== (int) $viewer->id
            || ! hash_equals($proof['attributes_sha256'], hash('sha256', serialize($projection->getRawOriginal())))
            || ! hash_equals($proof['attributes_sha256'], hash('sha256', serialize($projection->getAttributes())))) {
            throw new \LogicException('Use the original unchanged saved-row projection inside its own active context.');
        }
    }

    public function verifySavedReference(User $viewer, object $reference, HealthSafetyReadContext $context, ?\Illuminate\Database\Eloquent\Builder $additionalRequiredRead = null): void
    {
        $context->assertViewer($viewer);
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($reference, $context, $additionalRequiredRead): array {
                foreach ($this->originalReadOwner($context)->frames['savedReadFrames'] ?? [] as $frame) {
                    if ($frame['reference'] === $reference) { $this->verifySavedFrame($frame, $current, $reads, $context, $additionalRequiredRead); return []; }
                }
                throw new \LogicException('Use the original saved-row reference inside its own active context.');
            });
    }

    private function verifySavedFrame(array $frame, User $viewer, \App\Services\CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, ?\Illuminate\Database\Eloquent\Builder $additionalRequiredRead = null): void
    {
        $context->assertViewer($viewer); $reads->assertActive();
        if ($frame['context_id'] !== spl_object_id($context) || $frame['reads'] !== $reads || $frame['viewer_id'] !== (int) $viewer->id) {
            throw new \LogicException('Saved-row original read identity changed.');
        }
        abort_unless($viewer->canDo('controlRoom.alerts.manage'), 403);
        $final = clone $frame['original'];
        if ($frame['audit_query'] !== null) {
            if ($this->savedActorAuditSet($frame['audit_query']) !== $frame['audit_set']) {
                abort(403, 'The original saved handover actor evidence changed.');
            }
            // Retain ANY historical receipt admission. These predicates replay its entire original set,
            // including additions, after the fingerprint SELECT and its listeners have completed.
            foreach ($frame['audit_set'] as $receipt) {
                $present = (clone $frame['audit_query'])->whereKey($receipt['id'])->where('user_id', $receipt['user_id'])
                    ->whereRaw('created_at <=> ?', [$receipt['created_at']])
                    ->whereRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) <=> ?', [$receipt['sha256']]);
                $final->whereExists($present->selectRaw('1')->toBase());
            }
            $extras = (clone $frame['audit_query'])->whereNotIn('id', array_column($frame['audit_set'], 'id'));
            $final->whereNotExists($extras->selectRaw('1')->toBase());
        }
        if ($additionalRequiredRead !== null) {
            // An additional metadata constraint can only narrow the original owned read; it grants no authority.
            $extra = clone $additionalRequiredRead; $extra->toSql();
            $final->whereExists($extra->setEagerLoads([])->reorder()->selectRaw('1')->toBase());
        }
        if ($additionalRequiredRead !== null) {
            // The whole saved/Note actual, including empty copied membership, owns this final scope.
            app(\App\Support\Incidents\LinkedOperationalEvidencePresenter::class)->withinCopiedNoteFinalRead($viewer,$reads,$context,
                function()use($context,$viewer,$reads,$final):array{
            $verify = function () use ($context, $viewer, $reads, $final): void {
                $context->assertViewer($viewer); $reads->assertActive();
                $complete=self::prepareRegisteredCopiedFinal($reads->query(clone$final),'exists');
                abort_unless(self::executeRegisteredCopiedFinal($complete,'exists',$viewer,$reads,$context)['exists'],403,'The original saved handover composite changed.');
            };
            $actual=self::prepareRegisteredCopiedFinal($reads->query(clone$final),'exists');
            ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$reads);
            abort_unless(self::executeRegisteredCopiedFinal($actual,'exists',$viewer,$reads,$context)['exists'],403,'The original saved handover composite changed.');
            return [];
                });
            return;
        }
        abort_unless($reads->query($final)->exists(), 403, 'The original saved handover read changed.');
    }

    private function savedActorAuditSet(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return (clone $query)->select(['id', 'user_id', 'created_at'])
            ->selectRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) as receipt_sha256')->orderBy('id')->toBase()->get()
            ->map(fn ($row): array => ['id' => (string) $row->id, 'user_id' => (string) $row->user_id,
                'created_at' => $row->created_at, 'sha256' => $row->receipt_sha256])->all();
    }

    /** Entry links disclose no saved content; the record GET rechecks all references/source access. */
    /** Entry links read no snapshots or note prose and retain the original row/actor evidence until return. */
    public function historyLinks(User $viewer, array $ids, ?HealthSafetyReadContext $originalContext = null): array
    {
        return app(ControlRoomGovernanceReadService::class)->withCurrentViewer($viewer,
            function (User $current, HealthSafetyReadContext $context) use ($ids): array {
                return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
                    function (User $same, \App\Services\CurrentAuthorizationReads $reads) use ($ids, $context): array {
                        if (! $same->canDo('controlRoom.alerts.manage')) { return []; }
                        $authority = $context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, []);
                        $authority->toSql();
                        $base = $reads->query(Shift::query()->whereKey($ids)->where('status', 'completed')
                            ->where('handover_status', Shift::HANDOVER_ACCEPTED)->whereExists($authority->selectRaw('1')->toBase()));
                        $columns = ['id','status','handover_status','handover_version','shift_lead_user_id','team_members',
                            'handed_over_to_user_id','starts_at','ends_at','updated_at'];
                        $rows = (clone $base)->select($columns)
                            ->selectRaw('SHA2(CAST(handover_snapshot AS CHAR CHARACTER SET utf8mb4),256) as snapshot_sha256')->toBase()->get();
                        
                        $frames = $this->originalReadOwner($context)->frames['savedLinkFrames'] ?? []; $links = [];
                        foreach ($rows as $raw) {
                            $shift = (new Shift)->setRawAttributes((array) $raw, true); $id = (int) $raw->id;
                            if (isset($frames[$id])) {
                                $this->verifySavedFrame($frames[$id], $same, $reads, $context);
                            } else {
                                if (! $this->canViewSavedSnapshot($shift, $same)) { continue; }
                                $frozen = (clone $base)->whereKey($id);
                                foreach ($columns as $column) {
                                    $value = $shift->getRawOriginal($column);
                                    $value === null ? $frozen->whereNull($column)
                                        : $frozen->whereRaw('CAST(control_room_shifts.'.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
                                }
                                $frozen->whereRaw('SHA2(CAST(handover_snapshot AS CHAR CHARACTER SET utf8mb4),256) <=> ?', [$raw->snapshot_sha256]);
                                $members = [...$shift->memberUserIds(), $shift->handed_over_to_user_id];
                                $auditBranch = ! $same->canDo('reports.viewAny') && ! in_array((int) $same->id, array_map('intval', $members), true);
                                $auditQuery = $auditBranch ? $reads->query(AuditLog::query()->where('action', 'controlRoom.shift.handoverPrepared')
                                    ->where('auditable_type', $shift->getMorphClass())->where('auditable_id', $id)->where('user_id', $same->id)) : null;
                                $auditSet = $auditQuery === null ? [] : $this->savedActorAuditSet($auditQuery);
                                abort_unless(! $auditBranch || $auditSet !== [], 403);
                                $frames[$id] = ['context_id' => spl_object_id($context), 'reads' => $reads, 'viewer_id' => (int) $same->id,
                                    'original' => $frozen, 'audit_query' => $auditQuery, 'audit_set' => $auditSet];
                                $this->verifySavedFrame($frames[$id], $same, $reads, $context);
                            }
                            $links[$id] = "/control-room/shifts/{$id}/handover";
                        }
                        // A disappeared/replaced original row cannot be silently dropped and recaptured on a repeated read.
                        foreach ($frames as $id => $frame) {
                            if (in_array($id, array_map('intval', $ids), true)) { $this->verifySavedFrame($frame, $same, $reads, $context); }
                        }
                        $this->originalReadOwner($context)->frames['savedLinkFrames'] = $frames;
                        return $links;
                    });
            }, $originalContext);
    }
}
