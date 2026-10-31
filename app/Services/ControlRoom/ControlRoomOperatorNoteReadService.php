<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Support\Incidents\LinkedOperationalEvidencePresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One independent CR note admission for live duty and saved handovers; no H&S policy fork. */
final class ControlRoomOperatorNoteReadService
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

    /**
     * Owned R34 discovery component only. IDs, typed origin and fingerprints are backend metadata,
     * never a note-body/source admission. The eventual body/continuation must run in canonical callbacks.
     */
    private function currentReadMetadata(Shift $shift, User $viewer, HealthSafetyReadContext $context, ?array $onlyNoteIds = null): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($shift, $context, $onlyNoteIds): array {
                abort_unless($current->canDo('controlRoom.viewAny') || $current->canDo('controlRoom.alerts.manage'), 403);
                
                $frames = $this->originalReadOwner($context)->frames['currentNoteMetadataFrames'] ?? []; $id = (int) $shift->getRawOriginal('id');
                if ($onlyNoteIds !== null) { $onlyNoteIds = array_values(array_unique(array_map('intval', $onlyNoteIds))); sort($onlyNoteIds); }
                $key = $id.':'.hash('sha256', serialize($onlyNoteIds));
                if (isset($frames[$key])) {
                    $this->verifyCurrentNoteMetadata($frames[$key], $current, $reads, $context);
                    return $frames[$key];
                }
                $authority = $context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, []);
                $authority->toSql();
                $originalShift = $reads->query(Shift::query()->whereKey($id)->whereExists($authority->selectRaw('1')->toBase()));
                $tuple = (clone $originalShift)->select(['id','status','shift_lead_user_id','team_members','handover_status','handover_version','updated_at'])
                    ->toBase()->first();
                abort_unless($tuple !== null, 404);
                foreach ((array) $tuple as $column => $value) {
                    $value === null ? $originalShift->whereNull($column)
                        : $originalShift->whereRaw('CAST(control_room_shifts.'.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
                }
                $parent = clone $originalShift; $parent->toSql();
                $notes = $reads->query(OperatorNote::query()->where('shift_id', $id)->when($onlyNoteIds !== null, fn ($query) => $query->whereKey($onlyNoteIds))
                    ->whereExists($parent->selectRaw('1')->toBase())->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id'));
                $rows = $this->currentNoteMetadataRows($notes);
                $authors = $reads->query(User::query()->whereKey(array_values(array_unique(array_column($rows, 'user_id')))));
                $authorSet = $this->currentNoteAuthorSet($authors);
                $audits = $reads->query(DB::table('audit_logs')->whereRaw('BINARY action = BINARY ?', ['controlRoom.shift.noteAdded'])
                    ->whereRaw('BINARY auditable_type = BINARY ?', [$shift->getMorphClass()])->where('auditable_id', $id)
                    ->whereRaw("JSON_TYPE(JSON_EXTRACT(meta,'$.note_id')) = 'INTEGER'")
                    ->whereRaw("JSON_TYPE(JSON_EXTRACT(meta,'$.note_type')) = 'STRING'")
                    ->whereIn('meta->note_id', array_column($rows, 'id'))
                    ->whereIn('meta->note_type', OperatorNote::TYPES));
                $auditSet = $this->currentNoteAuditSet($audits);
                $frame = ['context_id' => spl_object_id($context), 'reads' => $reads, 'viewer' => $current,
                    'shift_id' => $id, 'shift_query' => $originalShift, 'notes_query' => $notes, 'rows' => $rows,
                    'authors_query' => $authors, 'authors' => $authorSet, 'audit_query' => $audits, 'audits' => $auditSet];
                $this->verifyCurrentNoteMetadata($frame, $current, $reads, $context);
                $frames[$key] = $frame; $this->originalReadOwner($context)->frames['currentNoteMetadataFrames'] = $frames;
                return $frame;
            });
    }

    private function currentNoteMetadataRows(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $origin = 'control_room_operator_notes.operator_origin';
        $knownCopy = "EXISTS (SELECT 1 FROM incident_saved_copies WHERE target_family = 'control_room_operator_note'
            AND target_key = CAST(control_room_operator_notes.id AS CHAR) FOR SHARE NOWAIT)";
        $keys = ['version','source','alert_id','shift_id','site_id','client_id','user_id','type','purpose','text_sha256'];
        $paths = implode(',', array_map(fn ($key): string => "'$.{$key}'", $keys));
        $valid = "JSON_TYPE($origin) = 'OBJECT' AND JSON_LENGTH($origin) = 10 AND JSON_CONTAINS_PATH($origin,'all',$paths)
            AND JSON_TYPE(JSON_EXTRACT($origin,'$.version')) = 'INTEGER' AND JSON_EXTRACT($origin,'$.version') = 1
            AND JSON_TYPE(JSON_EXTRACT($origin,'$.source')) = 'STRING'
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT($origin,'$.source')) = BINARY 'independent_operator'";
        foreach (['shift_id','user_id'] as $key) {
            $valid .= " AND JSON_TYPE(JSON_EXTRACT($origin,'$.{$key}')) = 'INTEGER' AND JSON_EXTRACT($origin,'$.{$key}') > 0";
        }
        foreach (['alert_id','site_id','client_id'] as $key) {
            $valid .= " AND (JSON_TYPE(JSON_EXTRACT($origin,'$.{$key}')) = 'NULL'
                OR (JSON_TYPE(JSON_EXTRACT($origin,'$.{$key}')) = 'INTEGER' AND JSON_EXTRACT($origin,'$.{$key}') > 0))";
        }
        $valid .= " AND JSON_TYPE(JSON_EXTRACT($origin,'$.type')) = 'STRING'
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT($origin,'$.type')) IN (BINARY 'note',BINARY 'action',BINARY 'escalation',BINARY 'decision',BINARY 'handover')
            AND JSON_TYPE(JSON_EXTRACT($origin,'$.purpose')) = 'STRING'
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT($origin,'$.purpose')) IN (BINARY 'general',BINARY 'immediate_controls',BINARY 'escalation_handover')
            AND JSON_TYPE(JSON_EXTRACT($origin,'$.text_sha256')) = 'STRING'
            AND REGEXP_LIKE(JSON_UNQUOTE(JSON_EXTRACT($origin,'$.text_sha256')),'^[a-f0-9]{64}$','c')";
        $originFields = implode(',', array_map(fn ($key): string => "'{$key}',JSON_EXTRACT($origin,'$.{$key}')", $keys));
        return (clone $query)->select(['id','alert_id','shift_id','user_id','is_pinned','requires_followup','followup_at','created_at','updated_at'])
            ->selectRaw("CASE WHEN type IN ('note','action','escalation','decision','handover') THEN type ELSE NULL END as type")
            ->selectRaw("CASE WHEN purpose IN ('general','immediate_controls','escalation_handover') THEN purpose ELSE NULL END as purpose")
            ->selectRaw("CASE WHEN ($valid) THEN JSON_OBJECT($originFields) ELSE NULL END as independent_origin")
            ->selectRaw("SHA2(CAST($origin AS CHAR CHARACTER SET utf8mb4),256) as origin_sha256")
            ->selectRaw('SHA2(content,256) as content_sha256')
            ->selectRaw('CASE WHEN content IS NOT NULL AND CHAR_LENGTH(content) > 0 THEN 1 ELSE 0 END as content_valid')
            ->selectRaw("CASE WHEN ($knownCopy) THEN 1 ELSE 0 END as known_copy")
            ->toBase()->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function currentNoteAuthorSet(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return (clone $query)->select('id')->selectRaw('SHA2(name,256) as name_sha256')->orderBy('id')
            ->toBase()->get()->map(fn ($row): array => (array) $row)->all();
    }

    /** Historical compatibility is the existing typed note_id/type + physical actor/morph/Shift recipe, not an invented six-key receipt. */
    private function currentNoteAuditSet(\Illuminate\Database\Query\Builder $query): array
    {
        return (clone $query)->select(['id','user_id','created_at'])
            ->selectRaw("JSON_EXTRACT(meta,'$.note_id') as note_id, JSON_UNQUOTE(JSON_EXTRACT(meta,'$.note_type')) as note_type")
            ->selectRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) as receipt_sha256')->orderBy('id')
            ->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function verifyCurrentNoteMetadata(array $frame, User $current, \App\Services\CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context): void
    {
        $context->assertViewer($current); $reads->assertActive();
        if ($frame['context_id'] !== spl_object_id($context) || $frame['reads'] !== $reads || $frame['viewer'] !== $current) {
            throw new \LogicException('Use the original current-note metadata inside its own active context.');
        }
        abort_unless($this->currentNoteMetadataRows($frame['notes_query']) === $frame['rows']
            && $this->currentNoteAuthorSet($frame['authors_query']) === $frame['authors']
            && $this->currentNoteAuditSet($frame['audit_query']) === $frame['audits'], 403,
            'The original current-note discovery changed.');
        // Metadata replay is not source/body authorization. Complete a composite final note/author/receipt
        // proof inside the canonical parent callback before wiring any body consumer to this component.
        abort_unless((clone $frame['shift_query'])->exists(), 403);
    }

    /** The entire read continuation remains inside all admitted original Alert/Note callbacks. */
    /** Original plain witnesses only; no envelope verifier or guarded execution carrier is cached. */

    private function retainOriginalNoteRead(array $frame, array $admitted, HealthSafetyReadContext $context): void
    {
        $context->assertViewer($frame['viewer']);$frame['reads']->assertActive();
        $key=$frame['shift_id'].':'.hash('sha256',serialize($frame['rows']));
        $entries=$this->originalReadOwner($context)->frames['originalNoteFinalReads']??[];
        $entry=$entries[$key]??['viewer'=>$frame['viewer'],'reads'=>$frame['reads'],'context_id'=>spl_object_id($context),'frame'=>$frame,'scopes'=>[],'known_copy'=>false];
        if($entry['viewer']!==$frame['viewer']||$entry['reads']!==$frame['reads']||$entry['context_id']!==spl_object_id($context)){
            throw new \LogicException('Retain the same original Note viewer/read/context.');
        }
        foreach($admitted as$id=>$scope){
            $this->assertPlainOriginalNoteWitness($scope);
            // A repeated projection may add a first admission, but cannot replace an original witness.
            $entry['scopes'][$id]??=clone$scope;
            foreach($frame['rows']as$row){if((int)$row['id']===(int)$id&&(int)$row['known_copy']===1){$entry['known_copy']=true;}}
        }
        $this->assertPlainOriginalNoteWitness($this->currentProjectionConstraint($entry['frame'],$entry['scopes']));
        $entries[$key]=$entry;$this->originalReadOwner($context)->frames['originalNoteFinalReads']=$entries;
    }

    private function assertPlainOriginalNoteWitness(\Illuminate\Database\Eloquent\Builder $original): void
    {
        $walk=function($value)use(&$walk):void{
            if($value instanceof \Illuminate\Database\Eloquent\Builder){$value=$value->getQuery();}
            if($value instanceof \Illuminate\Database\Query\Builder){
                if(!in_array(get_class($value),[\Illuminate\Database\Query\Builder::class,\Illuminate\Database\Query\JoinClause::class],true)){
                    throw new \LogicException('Retain the original plain witness before attaching its execution guard.');
                }
                foreach(['wheres','joins','unions']as$property){$walk($value->{$property});}
            }elseif(is_array($value)){foreach($value as$part){$walk($part);}}
        };
        $walk($original);
    }

    /** SQL replay of the exact first metadata sets; this narrows admission and never authorizes a body. */
    private function originalNoteMetadataConstraint(array $frame): \Illuminate\Database\Eloquent\Builder
    {
        $final=clone$frame['shift_query'];
        foreach($frame['rows']as$row){
            $same=(clone$frame['notes_query'])->whereKey($row['id'])->reorder();
            foreach(['id','alert_id','shift_id','user_id','is_pinned','requires_followup','followup_at','created_at','updated_at']as$column){
                $value=$row[$column];$value===null?$same->whereNull('control_room_operator_notes.'.$column)
                    :$same->whereRaw('CAST(control_room_operator_notes.'.$column.' AS BINARY) = CAST(? AS BINARY)',[$value]);
            }
            foreach(['type'=>['note','action','escalation','decision','handover'],
                'purpose'=>['general','immediate_controls','escalation_handover']]as$column=>$allowed){
                $same->whereRaw('BINARY (CASE WHEN '.$column.' IN ('.implode(',',array_fill(0,count($allowed),'?')).') THEN '.$column.' ELSE NULL END) <=> BINARY ?',
                    [...$allowed,$row[$column]]);
            }
            $same->whereRaw('SHA2(content,256) <=> ?',[$row['content_sha256']])
                ->whereRaw('SHA2(CAST(operator_origin AS CHAR CHARACTER SET utf8mb4),256) <=> ?',[$row['origin_sha256']]);
            $known=$frame['reads']->query(DB::table('incident_saved_copies')->where('target_family','control_room_operator_note')
                ->where('target_key',(string)$row['id'])->selectRaw('1'));
            (int)$row['known_copy']===1?$same->whereExists($known):$same->whereNotExists($known);
            $same->toSql();$final->whereExists($same->select([])->selectRaw('1')->toBase());
        }
        $extra=(clone$frame['notes_query'])->whereNotIn('id',array_column($frame['rows'],'id'));
        $extra->toSql();$final->whereNotExists($extra->selectRaw('1')->toBase());
        foreach($frame['authors']as$author){
            $same=(clone$frame['authors_query'])->whereKey($author['id'])->whereRaw('SHA2(name,256) <=> ?',[$author['name_sha256']]);
            $same->toSql();$final->whereExists($same->select([])->selectRaw('1')->toBase());
        }
        $extra=(clone$frame['authors_query'])->whereNotIn('id',array_column($frame['authors'],'id'));
        $extra->toSql();$final->whereNotExists($extra->selectRaw('1')->toBase());
        foreach($frame['audits']as$receipt){
            $same=(clone$frame['audit_query'])->where('id',$receipt['id'])->where('user_id',$receipt['user_id'])
                ->whereRaw('created_at <=> ?',[$receipt['created_at']])
                ->whereRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) <=> ?',[$receipt['receipt_sha256']]);
            $final->whereExists($same->selectRaw('1'));
        }
        $extra=(clone$frame['audit_query'])->whereNotIn('id',array_column($frame['audits'],'id'));
        $final->whereNotExists($extra->selectRaw('1'));return$final;
    }

    /** Only the actual outermost Governance envelope supplies this private parent verifier. */
    public function finalizeOriginalNoteRead(User $current, \App\Services\CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, mixed $privateParentVerifier): void
    {
        // Reject an invalid owned tuple in memory; canonical acceptance then precedes every owned SQL.
        $context->assertViewer($current);$reads->assertActive();
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User$same,\App\Services\CurrentAuthorizationReads$sameReads)use($current,$reads,$context,$privateParentVerifier):void{
                if($same!==$current||$sameReads!==$reads){throw new \LogicException('Finalize the exact original Note read.');}
                app(ControlRoomGovernanceReadService::class)->validateAndReplayControlRoomFinalRead($current,$reads,$context,$privateParentVerifier);
                app(LinkedOperationalEvidencePresenter::class)->withinCopiedNoteFinalRead($current,$reads,$context,
                    function()use($current,$reads,$context,$privateParentVerifier):array{
                $compose=function()use($current,$reads,$context):\Illuminate\Database\Eloquent\Builder{
                $final=clone$context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS,[]);
                $this->assertPlainOriginalNoteWitness($final);$knownCopy=false;
                foreach($this->originalReadOwner($context)->frames['originalNoteFinalReads']??[]as$entry){
                    if($entry['viewer']!==$current||$entry['reads']!==$reads||$entry['context_id']!==spl_object_id($context)){
                        throw new \LogicException('Original Note final witness belongs to another read.');
                    }
                    $constraint=$this->currentProjectionConstraint($entry['frame'],$entry['scopes']);
                    $metadata=$this->originalNoteMetadataConstraint($entry['frame']);$this->assertPlainOriginalNoteWitness($metadata);$metadata->toSql();
                    $constraint->whereExists($metadata->select([])->selectRaw('1')->toBase());
                    $this->assertPlainOriginalNoteWitness($constraint);$constraint->toSql();
                    $final->whereExists($constraint->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase());
                    $knownCopy=$knownCopy||$entry['known_copy'];
                }
                if($knownCopy&&($this->originalReadOwner($context)->frames['copiedAlertNotes']??[])===[]){
                    throw new \LogicException('Copied Note output requires an actual privately admitted copied ledger.');
                }
                $add=function(array$constraints)use($final):void{
                    foreach($constraints as$constraint){$this->assertPlainOriginalNoteWitness($constraint);$constraint->toSql();
                        $final->whereExists($constraint->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase());}
                };
                app(ControlRoomHandoverReadAccessService::class)->consumeOriginalSavedReadConstraints($current,$reads,$context,$add);
                app(ControlRoomAcceptedHandoverHistoryService::class)->consumeOriginalAcceptedReadConstraints($current,$reads,$context,$add);
                app(\App\Http\Controllers\ControlRoom\ControlRoomShiftController::class)->consumeOriginalDutyCardConstraints($current,$reads,$context,$add);
                app(\App\Http\Controllers\ControlRoom\ControlRoomHandoverController::class)->consumeOriginalCurrentDutyConstraints($current,$reads,$context,$add);
                app(ControlRoomSourceContentService::class)->constrainOriginalSavedSummaryRead($current,$reads,$context,$final);
                $this->assertPlainOriginalNoteWitness($final);
                return$final;
                };
                $verify=function()use($current,$reads,$context,$privateParentVerifier,$compose):void{
                    app(ControlRoomGovernanceReadService::class)->validateAndReplayControlRoomFinalRead($current,$reads,$context,$privateParentVerifier);
                    $context->assertViewer($current);$reads->assertActive();
                    if(($this->originalReadOwner($context)->frames['copiedAlertNotes']??[])!==[]){
                        app(LinkedOperationalEvidencePresenter::class)->verifyCopiedNoteFinalProjections($current,$reads,$context);
                    }
                    // Reacquire every copied ALL-use graph and finish with the whole original CR composite.
                    $complete=$compose();
                    $complete=self::prepareRegisteredCopiedFinal($reads->query($complete),'exists');
                    abort_unless(self::executeRegisteredCopiedFinal($complete,'exists',$current,$reads,$context)['exists'],404,'The original complete Note response changed.');
                };
                // Every envelope gets a new carrier. The closure is consumed synchronously and never retained.
                $final=$compose();
                $actual=self::prepareRegisteredCopiedFinal($reads->query(clone$final),'exists');
                ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$reads);
                abort_unless(self::executeRegisteredCopiedFinal($actual,'exists',$current,$reads,$context)['exists'],404,'The original complete Note response changed.');
                return [];
                    });
            });
    }

    /** Only canonical identities survive a copied capture callback; Linked owns FIRST and ALL-use. */
    public function consumeCopiedAlertNoteDetails(User $viewer, int $alertId, int $noteId,
        HealthSafetyReadContext $context, callable $consume,
        ?\Illuminate\Database\Eloquent\Builder $originalRootContext = null): array
    {
        $linked = app(LinkedOperationalEvidencePresenter::class);
        return $linked->consumeCopiedNoteDetails($viewer, $alertId, $noteId, $context,
            function (\Illuminate\Database\Eloquent\Builder $actual, \App\Services\CurrentAuthorizationReads $reads,
                User $current, HealthSafetyReadContext $same) use ($linked, $alertId, $noteId, $context, $consume): array {
                if ($same !== $context) { throw new \LogicException('Retain the original copied Alert context.'); }
                $admitted = $linked->consumeCopiedNoteOriginalIdentity($current, $alertId, $noteId, $reads, $same,
                    function ($sameReads, $sameViewer, $sameContext)
                        use ($current, $reads, $same, $alertId, $noteId, $consume): array {
                        if ($sameReads !== $reads || $sameViewer !== $current || $sameContext !== $same) {
                            throw new \LogicException('Retain the exact canonical copied Note tuple.');
                        }
                        $owner = $this->originalReadOwner($same);
                        $identity = ['viewer' => $current, 'reads' => $reads, 'alert_id' => $alertId, 'note_id' => $noteId];
                        $entry = $owner->frames['copiedAlertNotes'][$noteId] ?? null;
                        if ($entry !== null && $entry !== $identity) {
                            throw new \LogicException('The first copied Alert Note cannot be rebound.');
                        }
                        $owner->frames['copiedAlertNotes'][$noteId] ??= $identity;
                        // Stay inside the canonical per-Note identity freeze through recursive capture.
                        // No source executable graph is forwarded to the CR consumer.
                        return $consume($reads, $current, $same);
                    });
                abort_unless($admitted['available'], 404, 'The original copied Alert witness is unavailable.');
                return $admitted['result'];
            }, $originalRootContext);
    }

    /** Separate command projection: PRE admission and stable original Note cores are never interchangeable. */
    public function withPreparationProjection(Shift $shift, User $viewer, HealthSafetyReadContext $context, callable $continuation): array
    {
        return app(ControlRoomShiftHandoverService::class)->consumePreparationShiftWitness($shift, $viewer, $context,
            function (array $transition, User $current, \App\Services\CurrentAuthorizationReads $reads, HealthSafetyReadContext $same)
                use ($shift, $viewer, $context, $continuation): array {
                if ($current !== $viewer || $same !== $context || $transition['phase'] !== 'pre') {
                    throw new \LogicException('Selected Notes require the actual original preparation precondition.');
                }
                $owner = $this->originalReadOwner($context); $id = (int) $shift->id;
                if (isset($owner->frames['preparationNotes'])) { throw new \LogicException('Selected preparation Notes cannot be recaptured.'); }
                $owner->frames['preparationNotes'] = ['shift' => $shift, 'viewer' => $viewer, 'reads' => $reads, 'context' => $context, 'state' => 'capturing'];
                try {
                    $stable = $reads->query(Shift::query()->whereKey($id)
                        ->whereRaw('exists ('.$transition['stable']['sql'].')', $transition['stable']['bindings']));
                    $pre = $reads->query(Shift::query()->whereKey($id)
                        ->whereRaw('exists ('.$transition['pre']['sql'].')', $transition['pre']['bindings']));
                    $stable->toSql(); $pre->toSql();
                    $notes = $reads->query(OperatorNote::query()->where('shift_id', $id)
                        ->where(fn ($query) => $query->where('is_pinned', true)->orWhere('requires_followup', true))
                        ->whereExists((clone $stable)->selectRaw('1')->toBase())->orderByDesc('created_at')->orderByDesc('id'));
                    // Discover under the FULL PRE predicate; retain the independently built broad stable selector.
                    $rows = $this->currentNoteMetadataRows((clone $notes)->whereExists((clone $pre)->selectRaw('1')->toBase()));
                    abort_unless((clone $pre)->exists(), 404, 'The original preparation precondition changed.');
                    $authors = $reads->query(User::query()->whereKey(array_values(array_unique(array_column($rows, 'user_id')))));
                    $audits = $reads->query(DB::table('audit_logs')->whereRaw('BINARY action = BINARY ?', ['controlRoom.shift.noteAdded'])
                        ->whereRaw('BINARY auditable_type = BINARY ?', [$shift->getMorphClass()])->where('auditable_id', $id)
                        ->whereRaw("JSON_TYPE(JSON_EXTRACT(meta,'$.note_id')) = 'INTEGER'")
                        ->whereRaw("JSON_TYPE(JSON_EXTRACT(meta,'$.note_type')) = 'STRING'")
                        ->whereIn('meta->note_id', array_column($rows, 'id'))->whereIn('meta->note_type', OperatorNote::TYPES));
                    $frame = ['context_id' => spl_object_id($context), 'reads' => $reads, 'viewer' => $current,
                        'shift_id' => $id, 'shift_query' => $stable, 'notes_query' => $notes, 'rows' => $rows,
                        'authors_query' => $authors, 'authors' => $this->currentNoteAuthorSet($authors),
                        'audit_query' => $audits, 'audits' => $this->currentNoteAuditSet($audits)];
                    $frame['copy_metadata'] = $this->preparationCopyMetadata($rows, $reads);
                    // The complete original metadata set exists before any copied source can hydrate prose.
                    $owner->frames['preparationNotes']['frame'] = $frame;
                    $owner->frames['preparationNotes']['active_copies'] = [];
                    $owner->frames['preparationNotes']['state'] = 'admitting_sources';
                    $alertIds = array_values(array_unique(array_map('intval', array_filter(array_column($rows, 'alert_id'), fn ($id): bool => $id !== null))));
                    if ($alertIds !== []) { app(ControlRoomGovernanceReadService::class)
                        ->projectCurrentAlertQuery(ControlRoomAlert::query()->whereKey($alertIds), $current, $context)->get(); }
                    return $this->withinNoteParents($alertIds, 0, [], $frame, $context,
                        function (array $parents) use ($frame, $context, $shift, $owner, $continuation): array {
                            return $this->withinPreparationCopiedNotes($frame['rows'], 0, [], $parents, $frame, $context,
                                function (array $copied) use ($parents, $frame, $context, $shift, $owner, $continuation): array {
                                    $scopes = [];
                                    foreach ($frame['rows'] as $meta) {
                                        $id = (int) $meta['id']; $alertId = $meta['alert_id'] === null ? null : (int) $meta['alert_id'];
                                        $parent = $alertId === null ? null : ($parents[$alertId] ?? null);
                                        abort_unless($alertId === null || $parent !== null, 404, 'A selected Note Alert is unavailable.');
                                        $scope = (int) $meta['known_copy'] === 1
                                            ? (($copied[$id] ?? false) ? $this->frozenCurrentNoteScope($meta, $frame, clone $frame['notes_query']) : null)
                                            : $this->independentCurrentNoteScope($meta, $frame, $parent);
                                        abort_unless($scope !== null, 404, 'A selected Note has no verified original source.');
                                        $this->assertPlainOriginalNoteWitness($scope); $scopes[$id] = $scope;
                                    }
                                    // All selected sources are admitted before the FIRST Note/author body query.
                                    $owner->frames['preparationNotes']['frame'] = $frame;
                                    $owner->frames['preparationNotes']['scopes'] = $scopes;
                                    $owner->frames['preparationNotes']['state'] = 'ready';
                                    return app(LinkedOperationalEvidencePresenter::class)->withinCopiedNoteFinalRead(
                                        $frame['viewer'], $frame['reads'], $context,
                                        function () use ($frame, $context, $shift, $scopes, $continuation): array {
                                    $projected = [];
                                    foreach ($frame['rows'] as $meta) {
                                        $id = (int) $meta['id'];
                                        $body = (clone $scopes[$id])->select(['control_room_operator_notes.id','control_room_operator_notes.alert_id',
                                            'control_room_operator_notes.type','control_room_operator_notes.purpose','control_room_operator_notes.content',
                                            'control_room_operator_notes.is_pinned','control_room_operator_notes.requires_followup',
                                            'control_room_operator_notes.followup_at','control_room_operator_notes.user_id','control_room_operator_notes.created_at'])
                                            ->selectRaw('(SELECT cr_note_author.name FROM users cr_note_author WHERE cr_note_author.id = control_room_operator_notes.user_id FOR SHARE NOWAIT) as author_name');
                                        $complete = $this->preparationNoteComposite($shift, $frame['viewer'], $context, ['pre']);
                                        $complete->toSql(); $body->whereExists($complete->select([])->selectRaw('1')->toBase());
                                        $body=self::prepareRegisteredCopiedFinal($body,'first_row');
                                        ControlRoomOriginalReadOuterQuery::attach($body,
                                            fn () => $this->verifyPreparationNoteProjection($shift, $frame['viewer'], $context, ['pre']), $frame['reads']);
                                        $rawResult=self::executeRegisteredCopiedFinal($body,'first_row',$frame['viewer'],$frame['reads'],$context);
                            $raw=$rawResult['row']===null?null:(object)$rawResult['row']; abort_unless($raw !== null, 404);
                                        $projected[] = ['id' => (int) $raw->id, 'alert_id' => $raw->alert_id === null ? null : (int) $raw->alert_id,
                                            'type' => $raw->type, 'purpose' => $raw->purpose, 'content' => $raw->content,
                                            'is_pinned' => (bool) $raw->is_pinned, 'requires_followup' => (bool) $raw->requires_followup,
                                            'followup_at' => $raw->followup_at === null ? null : Carbon::parse($raw->followup_at)->toIso8601String(),
                                            'user' => ['id' => (int) $raw->user_id, 'name' => $raw->author_name],
                                            'created_at' => Carbon::parse($raw->created_at)->toIso8601String(),
                                            'operator_origin' => $meta['independent_origin'] === null ? null : json_decode($meta['independent_origin'], true, 512, JSON_THROW_ON_ERROR),
                                            '_created' => $raw->created_at, '_due' => $raw->followup_at];
                                    }
                                    $pinned = array_values(array_filter($projected, fn ($row): bool => $row['is_pinned']));
                                    $followup = array_values(array_filter($projected, fn ($row): bool => $row['requires_followup']));
                                    usort($followup, static fn ($left, $right): int => ($left['_due'] === null ? ($right['_due'] === null ? 0 : -1)
                                        : ($right['_due'] === null ? 1 : strcmp($left['_due'], $right['_due'])))
                                        ?: strcmp($right['_created'], $left['_created']) ?: ($right['id'] <=> $left['id']));
                                    $clean = static function (array $row): array { unset($row['_created'], $row['_due']); return $row; };
                                    $this->verifyPreparationNoteProjection($shift, $frame['viewer'], $context, ['pre']);
                                    $result = $continuation(['pinned_notes' => array_map($clean, $pinned), 'followup_notes' => array_map($clean, $followup)]);
                                    if (! is_array($result)) { throw new \LogicException('Preparation Note consumers return detached arrays.'); }
                                    \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                                    $this->verifyPreparationNoteProjection($shift, $frame['viewer'], $context, ['written']);
                                    return $result;
                                        });
                                });
                        });
                } catch (\Throwable $exception) {
                    $owner->frames['preparationNotes']['state'] = 'denied';
                    throw $exception;
                }
            });
    }

    /** Memory-only routing. An existing preparation frame may never silently use the ordinary path. */
    public function requiresPreparationCopiedBodyAdmission(User $viewer, \App\Services\CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context): bool
    {
        $context->assertViewer($viewer); $reads->assertActive();
        $context->consumeSourceViewer([],
            function (User $sameViewer, \App\Services\CurrentAuthorizationReads $sameReads) use ($viewer, $reads): void {
                if ($sameViewer !== $viewer || $sameReads !== $reads) {
                    throw new \LogicException('Use the exact prepared body routing tuple.');
                }
            });
        $owner = (self::$originalReadOwners[$context] ?? null)?->get();
        $entry = $owner?->frames['preparationNotes'] ?? null;
        if ($entry === null) { return false; }
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS, static function (): void {});
        if ($entry['viewer'] !== $viewer || $entry['reads'] !== $reads || $entry['context'] !== $context) {
            throw new \LogicException('Use the exact original preparation body tuple.');
        }
        return true;
    }

    /** Only the source owner's private ticket resolves an actual body query; CR narrows, never materializes it. */
    public function decorateRegisteredPreparationBody(object $ticket, string $ownerClass,
        User $viewer, int $alertId, int $noteId,
        \App\Services\CurrentAuthorizationReads $reads, HealthSafetyReadContext $context): void
    {
        if (! in_array($ownerClass, [\App\Services\Incidents\IncidentSavedCopyService::class,
            LinkedOperationalEvidencePresenter::class], true)) {
            throw new \LogicException('Only the canonical source owners may decorate their registered preparation body.');
        }
        $entry = $this->preparationCopiedBodyEntry($viewer, $alertId, $noteId, $reads, $context);
        $sourceOwner = app($ownerClass);
        $actual = $sourceOwner->registeredPreparationBodyQuery($ticket, $viewer, $alertId, $noteId, $reads, $context);
        $model = $actual->getModel();
        if ($ownerClass === \App\Services\Incidents\IncidentSavedCopyService::class
            && get_class($model) === \App\Models\IncidentSavedCopy::class) {
            $actual->where('incident_saved_copies.target_family', 'control_room_operator_note')
                ->where('incident_saved_copies.target_key', (string) $noteId);
        } elseif ($ownerClass === LinkedOperationalEvidencePresenter::class && get_class($model) === OperatorNote::class) {
            $actual->whereKey($noteId)->where('control_room_operator_notes.alert_id', $alertId);
        } elseif ($ownerClass === LinkedOperationalEvidencePresenter::class && get_class($model) === User::class) {
            $actual->whereKey($entry['metadata']['user_id']);
        } else {
            throw new \LogicException('The registered source body has an unsupported original model.');
        }
        $compose = fn () => $this->preparationCopiedBodyConstraint($viewer, $alertId, $noteId, $reads, $context);
        $gate = $compose(); $gate->toSql();
        $actual->whereExists($gate->select([])->selectRaw('1')->toBase());
        ControlRoomOriginalReadOuterQuery::attach($actual,
            function () use ($sourceOwner, $ticket, $viewer, $alertId, $noteId, $reads, $context, $actual, $model, $compose): void {
            if ($sourceOwner->registeredPreparationBodyQuery($ticket, $viewer, $alertId, $noteId, $reads, $context) !== $actual
                || $actual->getModel() !== $model) {
                throw new \LogicException('The registered source body changed its original query or model.');
            }
            abort_unless($compose()->exists(), 404, 'The original preparation body admission changed.');
        }, $reads);
        // Source owns fixed selection/mode, complete graph integrity and finally retirement.
        // No caller Builder, callback, executable query result or source SQL leaves this decorator.
    }

    private function preparationCopiedBodyEntry(User $viewer, int $alertId, int $noteId,
        \App\Services\CurrentAuthorizationReads $reads, HealthSafetyReadContext $context): array
    {
        if (! $this->requiresPreparationCopiedBodyAdmission($viewer, $reads, $context)) {
            throw new \LogicException('A preparation body cannot mint its own command admission.');
        }
        $owner = self::$originalReadOwners[$context]->get(); $entry = $owner->frames['preparationNotes'];
        if ($entry['state'] !== 'admitting_sources' || ($entry['active_copies'][$noteId] ?? 0) !== 1) {
            throw new \LogicException('Copy body admission belongs to its lexical original source phase.');
        }
        $rows = array_values(array_filter($entry['frame']['rows'], static fn ($row): bool => (int) $row['id'] === $noteId));
        if (count($rows) !== 1 || (int) $rows[0]['known_copy'] !== 1 || $rows[0]['alert_id'] === null
            || (int) $rows[0]['alert_id'] !== $alertId) {
            throw new \LogicException('Use the exact originally selected copied Alert and Note.');
        }
        $entry['metadata'] = $rows[0];
        return $entry;
    }

    private function preparationCopiedBodyConstraint(User $viewer, int $alertId, int $noteId,
        \App\Services\CurrentAuthorizationReads $reads, HealthSafetyReadContext $context): \Illuminate\Database\Eloquent\Builder
    {
        $entry = $this->preparationCopiedBodyEntry($viewer, $alertId, $noteId, $reads, $context); $constraint = null;
        app(ControlRoomShiftHandoverService::class)->consumePreparationShiftWitness($entry['shift'], $viewer, $context,
            function (array $transition, User $sameViewer, \App\Services\CurrentAuthorizationReads $sameReads, HealthSafetyReadContext $same)
                use ($entry, $viewer, $reads, $context, &$constraint): array {
                if ($sameViewer !== $viewer || $sameReads !== $reads || $same !== $context || $transition['phase'] !== 'pre') {
                    throw new \LogicException('Copied body transport requires the canonical original PRE phase.');
                }
                $constraint = $context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, []);
                $constraint->whereRaw('exists ('.$transition['pre']['sql'].')', $transition['pre']['bindings']);
                $inventory = $this->originalNoteMetadataConstraint($entry['frame']);
                $this->assertPlainOriginalNoteWitness($inventory); $inventory->toSql();
                $constraint->whereExists($inventory->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase());
                $this->appendPreparationCopyMetadata($constraint, $entry['frame']['copy_metadata']);
                return [];
            });
        return $reads->query($constraint);
    }

    /** Source-copy identities and both receipt families are metadata, never copied text. */
    private function preparationCopyMetadata(array $notes, \App\Services\CurrentAuthorizationReads $reads): array
    {
        $ids = array_map('intval', array_column($notes, 'id'));
        $copies = $reads->query(DB::table('incident_saved_copies')->where('target_family', 'control_room_operator_note')
            ->whereIn('target_key', array_map('strval', $ids)));
        $copyFields = ['id','copy_uuid','source_version','incident_id','source_family','source_tuple','target_family',
            'target_key','field','actor_id','recorded_at','content_digest','audit_id'];
        $fingerprint = static function (array $columns): string {
            return 'SHA2(CAST(JSON_OBJECT('.implode(',', array_map(static fn ($column): string => "'".$column."',".$column, $columns))
                .') AS CHAR CHARACTER SET utf8mb4),256)';
        };
        $copyFingerprint = 'SHA2(CONCAT('.$fingerprint($copyFields).",':',SHA2(snapshot_text,256)),256)";
        $copyRows = (clone $copies)->select(['id','audit_id'])->selectRaw($copyFingerprint.' as fingerprint')->orderBy('id')
            ->get()->map(static fn ($row): array => (array) $row)->all();
        $receipts = $reads->query(DB::table('audit_logs')->where(function ($query) use ($ids, $copyRows): void {
            $query->whereIn('id', array_values(array_filter(array_column($copyRows, 'audit_id'))));
            if ($ids !== []) {
                $query->orWhere(function ($normal) use ($ids): void {
                    $normal->whereRaw('BINARY action = BINARY ?', ['controlRoom.alert.addNote'])
                        ->where(function ($notes) use ($ids): void {
                            foreach ($ids as $id) {
                                $notes->orWhereRaw("(BINARY JSON_UNQUOTE(JSON_EXTRACT(meta,'$.note_id')) = BINARY ?
                                    OR JSON_EXTRACT(meta,'$.note_id') = CAST(? AS JSON))", [(string) $id, (string) $id]);
                            }
                        });
                });
            }
        }));
        $receiptFingerprint = $fingerprint(['id','user_id','client_id','action','auditable_type','auditable_id','meta','created_at']);
        $receiptRows = (clone $receipts)->select('id')->selectRaw($receiptFingerprint.' as fingerprint')->orderBy('id')
            ->get()->map(static fn ($row): array => (array) $row)->all();
        return [
            ['query' => $copies, 'fingerprint' => $copyFingerprint, 'rows' => $copyRows],
            ['query' => $receipts, 'fingerprint' => $receiptFingerprint, 'rows' => $receiptRows],
        ];
    }

    private function appendPreparationCopyMetadata(\Illuminate\Database\Eloquent\Builder $target, array $sets): void
    {
        foreach ($sets as $set) {
            foreach ($set['rows'] as $row) {
                $same = (clone $set['query'])->where('id', $row['id'])->whereRaw($set['fingerprint'].' = ?', [$row['fingerprint']]);
                $target->whereExists($same->selectRaw('1'));
            }
            $extra = (clone $set['query'])->whereNotIn('id', array_column($set['rows'], 'id'));
            $target->whereNotExists($extra->selectRaw('1'));
        }
    }

    /** Separate recursion retains existing read-only Note call sites and never reuses PRE as a permanent copied root. */
    private function withinPreparationCopiedNotes(array $rows, int $offset, array $copied, array $parents, array $frame,
        HealthSafetyReadContext $context, callable $continuation): array
    {
        if ($offset === count($rows)) { return $continuation($copied); }
        $meta = $rows[$offset]; $id = (int) $meta['id']; $alertId = $meta['alert_id'] === null ? null : (int) $meta['alert_id'];
        if ((int) $meta['known_copy'] !== 1) {
            return $this->withinPreparationCopiedNotes($rows, $offset + 1, $copied, $parents, $frame, $context, $continuation);
        }
        abort_unless($alertId !== null && ($parents[$alertId] ?? null) !== null, 404, 'A selected copied Note parent is unavailable.');
        $owner = $this->originalReadOwner($context);
        if ($owner->frames['preparationNotes']['state'] !== 'admitting_sources'
            || isset($owner->frames['preparationNotes']['active_copies'][$id])) {
            throw new \LogicException('A selected copy receives one original lexical preparation scope.');
        }
        $owner->frames['preparationNotes']['active_copies'][$id] = 1;
        try {
            $result = $this->consumeCopiedAlertNoteDetails($frame['viewer'], $alertId, $id, $context,
                function (\App\Services\CurrentAuthorizationReads $reads,
                    User $current, HealthSafetyReadContext $same)
                    use ($rows, $offset, $copied, $parents, $frame, $context, $continuation, $id): array {
                    if ($reads !== $frame['reads'] || $current !== $frame['viewer'] || $same !== $context) {
                        throw new \LogicException('Selected copied admission changed its original tuple.');
                    }
                    $copied[$id] = true;
                    return $this->withinPreparationCopiedNotes($rows, $offset + 1, $copied, $parents, $frame, $context, $continuation);
                }, $parents[$alertId]);
            abort_unless($result['available'], 404, 'The original preparation copy is unavailable.');
            return $result['result'];
        } finally {
            unset($owner->frames['preparationNotes']['active_copies'][$id]);
        }
    }

    private function clonePreparationBodyQuery(\Illuminate\Database\Eloquent\Builder $original): \Illuminate\Database\Eloquent\Builder
    {
        $seen = new \SplObjectStorage;
        $clone = function ($value) use (&$clone, $seen) {
            if ($value instanceof \Illuminate\Database\Eloquent\Builder) {
                if ($seen->contains($value)) { return $seen[$value]; }
                $copy = clone $value; $seen[$value] = $copy;
                $copy->setModel(clone $value->getModel()); $copy->setQuery($clone($value->getQuery()));
                return $copy;
            }
            if ($value instanceof \Illuminate\Database\Query\Builder) {
                if ($seen->contains($value)) { return $seen[$value]; }
                $copy = clone $value; $seen[$value] = $copy;
                foreach (get_object_vars($value) as $property => $part) {
                    if (! in_array($property, ['connection', 'grammar', 'processor'], true)) { $copy->{$property} = $clone($part); }
                }
                return $copy;
            }
            if (is_array($value)) { foreach ($value as $key => $part) { $value[$key] = $clone($part); } }
            return $value;
        };
        return $clone($original);
    }

    private function preparationNoteEntry(Shift $shift, User $viewer, HealthSafetyReadContext $context): array
    {
        $owner = (self::$originalReadOwners[$context] ?? null)?->get(); $entry = $owner?->frames['preparationNotes'] ?? null;
        if ($entry === null || $entry['state'] !== 'ready' || $entry['shift'] !== $shift
            || $entry['viewer'] !== $viewer || $entry['context'] !== $context) {
            throw new \LogicException('Use the original selected preparation Note admission.');
        }
        $context->assertViewer($viewer); $entry['reads']->assertActive();
        return $entry;
    }

    private function preparationNoteComposite(Shift $shift, User $viewer, HealthSafetyReadContext $context, array $phases): \Illuminate\Database\Eloquent\Builder
    {
        $entry = $this->preparationNoteEntry($shift, $viewer, $context); $composite = null;
        app(ControlRoomShiftHandoverService::class)->consumePreparationShiftWitness($shift, $viewer, $context,
            function (array $transition, User $current, \App\Services\CurrentAuthorizationReads $reads, HealthSafetyReadContext $same)
                use ($entry, $viewer, $context, $phases, &$composite): array {
                if ($current !== $viewer || $reads !== $entry['reads'] || $same !== $context || ! in_array($transition['phase'], $phases, true)) {
                    throw new \LogicException('Selected Note proof must use the canonical preparation phase.');
                }
                $ids = array_map('intval', array_column($entry['frame']['rows'], 'id')); sort($ids);
                $admitted = array_keys($entry['scopes']); sort($admitted);
                if ($ids !== $admitted) { throw new \LogicException('Every originally selected Note remains mandatory.'); }
                $final = $context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, []);
                $final->whereRaw('exists ('.$transition['current']['sql'].')', $transition['current']['bindings']);
                foreach ([$this->currentProjectionConstraint($entry['frame'], $entry['scopes']),
                    $this->originalNoteMetadataConstraint($entry['frame'])] as $constraint) {
                    $this->assertPlainOriginalNoteWitness($constraint); $constraint->toSql();
                    $final->whereExists($constraint->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase());
                }
                // The shared owner supplies fresh ALL-use constraints as well as privately retained FIRST witnesses.
                $this->appendPreparationCopyMetadata($final, $entry['frame']['copy_metadata']);
                $this->assertPlainOriginalNoteWitness($final); $composite = $final;
                return [];
            });
        return $composite;
    }

    private function verifyPreparationNoteProjection(Shift $shift, User $viewer, HealthSafetyReadContext $context, array $phases): void
    {
        $entry = $this->preparationNoteEntry($shift, $viewer, $context);
        $verify = function () use ($shift, $viewer, $context, $phases, $entry): void {
            $this->verifyPreparationSourceTails($viewer, $entry['reads'], $context);
            $complete=self::prepareRegisteredCopiedFinal($entry['reads']->query($this->preparationNoteComposite($shift,$viewer,$context,$phases)),'exists');
            abort_unless(self::executeRegisteredCopiedFinal($complete,'exists',$viewer,$entry['reads'],$context)['exists'], 404,
                'The original selected preparation Notes changed.');
        };
        $actual=self::prepareRegisteredCopiedFinal($entry['reads']->query($this->preparationNoteComposite($shift,$viewer,$context,$phases)),'exists');
        ControlRoomOriginalReadOuterQuery::attach($actual, $verify, $entry['reads']);
        abort_unless(self::executeRegisteredCopiedFinal($actual,'exists',$viewer,$entry['reads'],$context)['exists'],404,'The original selected preparation Notes changed.');
    }

    public function verifyPreparationBeforeWrite(Shift $shift, User $viewer, HealthSafetyReadContext $context): void
    {
        $this->verifyPreparationNoteProjection($shift, $viewer, $context, ['sealed']);
    }

    public function verifyPreparationSourceTails(User $viewer, \App\Services\CurrentAuthorizationReads $reads, HealthSafetyReadContext $context): void
    {
        $owner = (self::$originalReadOwners[$context] ?? null)?->get(); $entry = $owner?->frames['preparationNotes'] ?? null;
        if ($entry === null || $entry['viewer'] !== $viewer || $entry['reads'] !== $reads || $entry['context'] !== $context || $entry['state'] !== 'ready') {
            throw new \LogicException('Replay the original preparation Note tuple.');
        }
        $context->assertViewer($viewer); $reads->assertActive();
        if (($owner->frames['copiedAlertNotes'] ?? []) !== []) {
            app(LinkedOperationalEvidencePresenter::class)->verifyCopiedNoteFinalProjections($viewer, $reads, $context);
        }
    }

    public function constrainOriginalPreparationRead(User $viewer, \App\Services\CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, \Illuminate\Database\Eloquent\Builder $target): void
    {
        $owner = (self::$originalReadOwners[$context] ?? null)?->get(); $entry = $owner?->frames['preparationNotes'] ?? null;
        if ($entry === null || $entry['viewer'] !== $viewer || $entry['reads'] !== $reads || $entry['context'] !== $context) {
            throw new \LogicException('Final preparation requires its original selected Note ledger, including an empty selection.');
        }
        $this->assertPlainOriginalNoteWitness($target);
        $complete = $this->preparationNoteComposite($entry['shift'], $viewer, $context, ['written']);
        $complete->toSql(); $target->whereExists($complete->select([])->selectRaw('1')->toBase());
    }


    public function withCurrentProjection(Shift $shift, User $viewer, HealthSafetyReadContext $context, callable $continuation): array
    {
        $frame = $this->currentReadMetadata($shift, $viewer, $context);
        $governance = app(ControlRoomGovernanceReadService::class);
        $alertIds = array_values(array_unique(array_map('intval', array_filter(array_column($frame['rows'], 'alert_id'), fn ($id): bool => $id !== null))));
        // The actual complete metadata get registers canonical parent frames; ID pluck cannot do so.
        if ($alertIds !== []) { $governance->projectCurrentAlertQuery(ControlRoomAlert::query()->whereKey($alertIds), $frame['viewer'], $context)->get(); }
        return $this->withinNoteParents($alertIds, 0, [], $frame, $context,
            function (array $parents) use ($frame, $context, $continuation): array {
                return $this->withinCopiedNotes($frame['rows'], 0, [], $parents, $frame, $context,
                    function (array $copied) use ($parents, $frame, $context, $continuation): array {
                        return app(LinkedOperationalEvidencePresenter::class)->withinCopiedNoteFinalRead($frame['viewer'], $frame['reads'], $context,
                            function () use ($copied, $parents, $frame, $context, $continuation): array {
                        $scopes = []; $available = []; $unavailable = false;
                        foreach ($frame['rows'] as $meta) {
                            $id = (int) $meta['id']; $alertId = $meta['alert_id'] === null ? null : (int) $meta['alert_id'];
                            $parent = $alertId === null ? null : ($parents[$alertId] ?? null);
                            if ($alertId !== null && $parent === null) { $unavailable = true; continue; }
                            if ((int) $meta['known_copy'] === 1) {
                                $scope = ($copied[$id] ?? false)
                                    ? $this->frozenCurrentNoteScope($meta, $frame, clone $frame['notes_query']) : null;
                            } else { $scope = $this->independentCurrentNoteScope($meta, $frame, $parent); }
                            if ($scope === null) { $unavailable = true; continue; }
                            $scopes[$id] = $scope;
                            $verify = fn () => $this->verifyCurrentProjectionFrame($frame, $scopes, $context);
                            $body = (clone $scope)->select(['control_room_operator_notes.id','control_room_operator_notes.alert_id',
                                'control_room_operator_notes.type','control_room_operator_notes.purpose','control_room_operator_notes.content',
                                'control_room_operator_notes.is_pinned','control_room_operator_notes.requires_followup',
                                'control_room_operator_notes.followup_at','control_room_operator_notes.user_id','control_room_operator_notes.created_at']);
                            // The same author predicate is on this SQL; no eager full User read precedes admission.
                            $body->selectRaw('(SELECT cr_note_author.name FROM users cr_note_author WHERE cr_note_author.id = control_room_operator_notes.user_id FOR SHARE NOWAIT) as author_name');
                            $body=self::prepareRegisteredCopiedFinal($body,'first_row');
                            ControlRoomOriginalReadOuterQuery::attach($body, $verify, $frame['reads']);
                            $rawResult=self::executeRegisteredCopiedFinal($body,'first_row',$frame['viewer'],$frame['reads'],$context);
                            $raw=$rawResult['row']===null?null:(object)$rawResult['row'];
                            if ($raw === null) { abort(404, 'The original operator-note body is unavailable.'); }
                            $note = ['id' => (int) $raw->id, 'alert_id' => $raw->alert_id === null ? null : (int) $raw->alert_id,
                                'type' => $raw->type, 'purpose' => $raw->purpose, 'content' => $raw->content,
                                'is_pinned' => (bool) $raw->is_pinned, 'requires_followup' => (bool) $raw->requires_followup,
                                'followup_at' => $raw->followup_at === null ? null : Carbon::parse($raw->followup_at)->toIso8601String(),
                                'user' => ['id' => (int) $raw->user_id, 'name' => $raw->author_name],
                                'created_at' => Carbon::parse($raw->created_at)->toIso8601String()];
                            if ($alertId === null) { $note['alert_reference'] = null; }
                            else {
                                $reference = (clone $parent)->select('control_room_alerts.reference_number')->toBase()->first();
                                abort_unless($reference !== null, 404); $note['alert_reference'] = $reference->reference_number;
                            }
                            $verify(); $available[] = $note;
                        }
                        $projection = ['notes' => $available,
                            'status' => $unavailable ? ($available === [] ? 'unavailable' : 'partially_available') : 'available',
                            'message' => $unavailable ? 'Some notes are unavailable because their original context or current access cannot be verified.' : null];
                        $this->verifyCurrentProjectionFrame($frame, $scopes, $context);
                        $this->retainOriginalNoteRead($frame, $scopes, $context);
                        $result = $continuation($projection);
                        if (! is_array($result)) { throw new \LogicException('Note read continuations return detached arrays only.'); }
                        \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                        $this->verifyCurrentProjectionFrame($frame, $scopes, $context);
                        return $result;
                            });
                    });
            });
    }

    private function withinNoteParents(array $ids, int $offset, array $parents, array $frame,
        HealthSafetyReadContext $context, callable $continuation): array
    {
        if ($offset === count($ids)) { return $continuation($parents); }
        $id = $ids[$offset]; $entered = false;
        $result = app(ControlRoomGovernanceReadService::class)->consumeOperationalAlertDetails($frame['viewer'], $id, $context,
            function (\Illuminate\Database\Eloquent\Builder $original, \App\Services\CurrentAuthorizationReads $reads,
                User $current, HealthSafetyReadContext $same) use ($ids, $offset, $parents, $frame, $context, $continuation, $id, &$entered): array {
                if ($reads !== $frame['reads'] || $current !== $frame['viewer'] || $same !== $context) {
                    throw new \LogicException('Note parent belongs to another original read.');
                }
                $entered = true; $parents[$id] = $original;
                return $this->withinNoteParents($ids, $offset + 1, $parents, $frame, $context, $continuation);
            });
        if ($result['available']) { return $result['result']; }
        // Late withdrawal after projection aborts the whole original read; no recapture as a new partial baseline.
        if ($entered) { abort(404, 'The original note Alert read changed.'); }
        $parents[$id] = null;
        return $this->withinNoteParents($ids, $offset + 1, $parents, $frame, $context, $continuation);
    }

    private function withinCopiedNotes(array $rows, int $offset, array $copied, array $parents, array $frame,
        HealthSafetyReadContext $context, callable $continuation): array
    {
        if ($offset === count($rows)) { return $continuation($copied); }
        $meta = $rows[$offset]; $id = (int) $meta['id']; $alertId = $meta['alert_id'] === null ? null : (int) $meta['alert_id'];
        if ((int) $meta['known_copy'] !== 1 || $alertId === null || ($parents[$alertId] ?? null) === null) {
            return $this->withinCopiedNotes($rows, $offset + 1, $copied, $parents, $frame, $context, $continuation);
        }
        $entered = false;
        $result = $this->consumeCopiedAlertNoteDetails($frame['viewer'], $alertId, $id, $context,
            function (\App\Services\CurrentAuthorizationReads $reads,
                User $current, HealthSafetyReadContext $same) use ($rows, $offset, $copied, $parents, $frame, $context, $continuation, $id, &$entered): array {
                if ($reads !== $frame['reads'] || $current !== $frame['viewer'] || $same !== $context) {
                    throw new \LogicException('Copied note belongs to another original read.');
                }
                $entered = true; $copied[$id] = true;
                return $this->withinCopiedNotes($rows, $offset + 1, $copied, $parents, $frame, $context, $continuation);
            }, $parents[$alertId]);
        if ($result['available']) { return $result['result']; }
        if ($entered) { abort(404, 'The original copied-note read changed.'); }
        $copied[$id] = null;
        return $this->withinCopiedNotes($rows, $offset + 1, $copied, $parents, $frame, $context, $continuation);
    }

    private function independentCurrentNoteScope(array $meta, array $frame, ?\Illuminate\Database\Eloquent\Builder $parent): ?\Illuminate\Database\Eloquent\Builder
    {
        if ((int) $meta['known_copy'] !== 0 || (int) $meta['content_valid'] !== 1 || $meta['independent_origin'] === null) { return null; }
        $origin = json_decode($meta['independent_origin'], true);
        if (! is_array($origin) || $origin['shift_id'] !== $frame['shift_id'] || $origin['user_id'] !== (int) $meta['user_id']
            || $origin['type'] !== $meta['type'] || $origin['purpose'] !== $meta['purpose']
            || $origin['alert_id'] !== ($meta['alert_id'] === null ? null : (int) $meta['alert_id'])
            || $origin['text_sha256'] !== $meta['content_sha256']) { return null; }
        if ($parent === null && ($origin['alert_id'] !== null || $origin['site_id'] !== null || $origin['client_id'] !== null)) { return null; }
        $receipts = array_values(array_filter($frame['audits'], fn ($receipt): bool => (int) $receipt['note_id'] === (int) $meta['id']
            && (int) $receipt['user_id'] === $origin['user_id'] && $receipt['note_type'] === $origin['type']));
        if (count($receipts) !== 1) { return null; }
        $scope = $this->frozenCurrentNoteScope($meta, $frame, clone $frame['notes_query']);
        if ($scope === null) { return null; }
        if ($parent !== null) {
            $root = (clone $parent)->whereKey($origin['alert_id']);
            foreach (['site_id','client_id'] as $column) { $value = $origin[$column];
                $value === null ? $root->whereNull('control_room_alerts.'.$column) : $root->where('control_room_alerts.'.$column, $value); }
            $root->toSql(); $scope->whereExists($root->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase());
        }
        $matching = (clone $frame['audit_query'])->where('user_id', $origin['user_id'])
            ->where('meta->note_id', (int) $meta['id'])->whereRaw("BINARY JSON_UNQUOTE(JSON_EXTRACT(meta,'$.note_type')) = BINARY ?", [$origin['type']]);
        $receipt = $receipts[0]; $present = (clone $matching)->where('id', $receipt['id'])
            ->whereRaw('created_at <=> ?', [$receipt['created_at']])->whereRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) <=> ?', [$receipt['receipt_sha256']]);
        $scope->whereExists($present->selectRaw('1'))->whereNotExists((clone $matching)->where('id', '<>', $receipt['id'])->selectRaw('1'));
        return $scope;
    }

    private function frozenCurrentNoteScope(array $meta, array $frame, \Illuminate\Database\Eloquent\Builder $query): ?\Illuminate\Database\Eloquent\Builder
    {
        $authors = array_values(array_filter($frame['authors'], fn ($author): bool => (int) $author['id'] === (int) $meta['user_id']));
        if (count($authors) !== 1) { return null; }
        $query = $frame['reads']->query($query->whereKey($meta['id'])->reorder());
        foreach (['id','alert_id','shift_id','user_id','is_pinned','requires_followup','followup_at','created_at','updated_at'] as $column) {
            $value = $meta[$column]; $value === null ? $query->whereNull('control_room_operator_notes.'.$column)
                : $query->whereRaw('CAST(control_room_operator_notes.'.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
        }
        foreach (['type','purpose'] as $column) { $value = $meta[$column];
            if ($value === null) { return null; } $query->whereRaw('BINARY control_room_operator_notes.'.$column.' = BINARY ?', [$value]); }
        $query->whereRaw('SHA2(content,256) <=> ?', [$meta['content_sha256']])
            ->whereRaw('SHA2(CAST(operator_origin AS CHAR CHARACTER SET utf8mb4),256) <=> ?', [$meta['origin_sha256']]);
        $known = $frame['reads']->query(DB::table('incident_saved_copies')->where('target_family', 'control_room_operator_note')->where('target_key', (string) $meta['id'])->selectRaw('1'));
        (int) $meta['known_copy'] === 1 ? $query->whereExists($known) : $query->whereNotExists($known);
        $author = (clone $frame['authors_query'])->whereKey($meta['user_id'])->whereRaw('SHA2(name,256) <=> ?', [$authors[0]['name_sha256']]);
        $author->toSql(); $query->whereExists($author->selectRaw('1')->toBase());
        $shift = clone $frame['shift_query']; $shift->toSql(); $query->whereExists($shift->selectRaw('1')->toBase());
        return $query;
    }

    private function verifyCurrentProjectionFrame(array $frame, array $admitted, HealthSafetyReadContext $context): void
    {
        $this->verifyCurrentNoteMetadata($frame, $frame['viewer'], $frame['reads'], $context);
        $final = $this->currentProjectionConstraint($frame, $admitted);
        $verify = function () use ($frame, $context, $final): void {
            $context->assertViewer($frame['viewer']); $frame['reads']->assertActive();
            $complete=self::prepareRegisteredCopiedFinal($frame['reads']->query(clone$final),'exists');
            abort_unless(self::executeRegisteredCopiedFinal($complete,'exists',$frame['viewer'],$frame['reads'],$context)['exists'],404,'The original operator-note projection changed.');
        };
        $actual=self::prepareRegisteredCopiedFinal($frame['reads']->query(clone$final),'exists');
        ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$frame['reads']);
        abort_unless(self::executeRegisteredCopiedFinal($actual,'exists',$frame['viewer'],$frame['reads'],$context)['exists'],404,'The original operator-note projection changed.');
    }

    /** Frozen narrowing constraint only; it cannot mint a saved-row or source admission. */
    private function currentProjectionConstraint(array $frame, array $admitted): \Illuminate\Database\Eloquent\Builder
    {
        $final = clone $frame['shift_query'];
        foreach ($admitted as $scope) { $same = clone $scope; $same->toSql();
            $final->whereExists($same->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase()); }
        $extra = (clone $frame['notes_query'])->whereNotIn('id', array_column($frame['rows'], 'id'));
        $extra->toSql(); $final->whereNotExists($extra->selectRaw('1')->toBase());
        return $final;
    }


    /** Metadata discovery only; this expression never certifies a body or copied source. */
    public function independentOriginMetadataSql(string $origin): string
    {
        $keys=['version','source','alert_id','shift_id','site_id','client_id','user_id','type','purpose','text_sha256'];
        $paths=implode(',',array_map(fn($key):string=>"'$.{$key}'",$keys));
        $valid="JSON_TYPE($origin)='OBJECT' AND JSON_LENGTH($origin)=10 AND JSON_CONTAINS_PATH($origin,'all',$paths)
            AND JSON_TYPE(JSON_EXTRACT($origin,'$.version'))='INTEGER' AND JSON_EXTRACT($origin,'$.version')=1
            AND JSON_TYPE(JSON_EXTRACT($origin,'$.source'))='STRING'
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT($origin,'$.source'))=BINARY 'independent_operator'";
        foreach(['shift_id','user_id']as$key){$valid.=" AND JSON_TYPE(JSON_EXTRACT($origin,'$.{$key}'))='INTEGER' AND JSON_EXTRACT($origin,'$.{$key}')>0";}
        foreach(['alert_id','site_id','client_id']as$key){$valid.=" AND (JSON_TYPE(JSON_EXTRACT($origin,'$.{$key}'))='NULL'
            OR (JSON_TYPE(JSON_EXTRACT($origin,'$.{$key}'))='INTEGER' AND JSON_EXTRACT($origin,'$.{$key}')>0))";}
        $valid.=" AND JSON_TYPE(JSON_EXTRACT($origin,'$.type'))='STRING'
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT($origin,'$.type')) IN (BINARY 'note',BINARY 'action',BINARY 'escalation',BINARY 'decision',BINARY 'handover')
            AND JSON_TYPE(JSON_EXTRACT($origin,'$.purpose'))='STRING'
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT($origin,'$.purpose')) IN (BINARY 'general',BINARY 'immediate_controls',BINARY 'escalation_handover')
            AND JSON_TYPE(JSON_EXTRACT($origin,'$.text_sha256'))='STRING'
            AND REGEXP_LIKE(JSON_UNQUOTE(JSON_EXTRACT($origin,'$.text_sha256')),'^[a-f0-9]{64}$','c')";
        $fields=implode(',',array_map(fn($key):string=>"'{$key}',JSON_EXTRACT($origin,'$.{$key}')",$keys));
        return"CASE WHEN ($valid) THEN JSON_OBJECT($fields) ELSE NULL END";
    }

    public function withSavedProjection(Shift $shift, object $reference, User $viewer, HealthSafetyReadContext $context, callable $continuation): array
    {
        $owner=app(ControlRoomHandoverReadAccessService::class);
        $owner->verifySavedProjection($viewer,$reference,$context,$shift);
        $inventory=$owner->discoverSavedNoteMetadata($viewer,$reference,$context);
        if(!$inventory['valid']){
            $projection=['notes'=>[],'status'=>'unavailable','message'=>'Some notes are unavailable because their original context or current access cannot be verified.'];
            $result=$continuation($projection);$owner->verifySavedProjection($viewer,$reference,$context,$shift);return$result;
        }
        $ids=array_values(array_unique(array_map('intval',array_filter(array_column($inventory['rows'],'id'),fn($id):bool=>$id!==null))));
        $frame=$this->currentReadMetadata($shift,$viewer,$context,$ids);
        $alertIds=array_values(array_unique(array_map('intval',array_filter(array_column($frame['rows'],'alert_id'),fn($id):bool=>$id!==null))));
        $governance=app(ControlRoomGovernanceReadService::class);
        if($alertIds!==[]){$governance->projectCurrentAlertQuery(ControlRoomAlert::query()->whereKey($alertIds),$frame['viewer'],$context)->get();}
        return$this->withinNoteParents($alertIds,0,[],$frame,$context,
            function(array$parents)use($frame,$inventory,$reference,$context,$continuation,$shift,$owner):array{
                return$this->withinCopiedNotes($frame['rows'],0,[],$parents,$frame,$context,
                    function(array$copied)use($parents,$frame,$inventory,$reference,$context,$continuation,$shift,$owner):array{
                        return app(LinkedOperationalEvidencePresenter::class)->withinCopiedNoteFinalRead($frame['viewer'],$frame['reads'],$context,
                            function()use($copied,$parents,$frame,$inventory,$reference,$context,$continuation,$shift,$owner):array{
                        $groups=[];foreach($inventory['rows']as$row){$groups[(string)$row['id']][]=$row;}
                        $notes=[];$scopes=[];$unavailable=false;
                        foreach($groups as$rows){
                            $row=$rows[0];$id=(int)$row['id'];
                            // Both pinned/followup occurrences of the same Note must be the same whole saved entry.
                            if($id<1||array_filter($rows,fn($other):bool=>$other['entry_sha256']!==$row['entry_sha256'])){$unavailable=true;continue;}
                            $matches=array_values(array_filter($frame['rows'],fn($meta):bool=>(int)$meta['id']===$id));
                            if(count($matches)!==1){$unavailable=true;continue;}$meta=$matches[0];
                            $alertId=$meta['alert_id']===null?null:(int)$meta['alert_id'];$parent=$alertId===null?null:($parents[$alertId]??null);
                            if($alertId!==null&&$parent===null){$unavailable=true;continue;}
                            if((int)$meta['known_copy']===1){
                                $scope=($copied[$id]??false)?$this->frozenCurrentNoteScope($meta,$frame,clone$frame['notes_query']):null;
                            }else{
                                $scope=$this->independentCurrentNoteScope($meta,$frame,$parent);
                                $savedOrigin=$row['independent_origin']===null?null:json_decode($row['independent_origin'],true);
                                $currentOrigin=$meta['independent_origin']===null?null:json_decode($meta['independent_origin'],true);
                                if(!app(ControlRoomOperatorNoteOriginService::class)->sameOrigin($savedOrigin,$currentOrigin)){$scope=null;}
                            }
                            $savedAlert=$row['alert_id']===null?null:(int)$row['alert_id'];
                            if($scope===null||(int)$row['content_valid']!==1||(int)$row['author_name_valid']!==1||$row['content_sha256']!==$meta['content_sha256']
                                ||$row['user_id']===null||(int)$row['user_id']!==(int)$meta['user_id']||$row['type']!==$meta['type']
                                ||$row['purpose']!==$meta['purpose']||$savedAlert!==$alertId
                                ||($savedAlert===null&&(int)$row['alert_is_null']!==1)||$row['created_at']===null||(int)$row['followup_valid']!==1){$unavailable=true;continue;}
                            try{if(!Carbon::parse($meta['created_at'])->equalTo(Carbon::parse($row['created_at']))){$unavailable=true;continue;}}
                            catch(\Throwable){$unavailable=true;continue;}
                            $scopes[$id]=$scope;
                            $authors=array_values(array_filter($frame['authors'],fn($author):bool=>(int)$author['id']===(int)$meta['user_id']));
                            // The ordinary producer has no durable historical-name receipt. Preserve the Note when a rename
                            // prevents certification of its saved name, but never hydrate or replace that unadmitted name.
                            $authorNameReadable=count($authors)===1&&is_string($row['author_name_sha256'])
                                &&hash_equals($authors[0]['name_sha256'],$row['author_name_sha256']);
                            $verify=fn()=>$this->verifySavedNoteProjectionFrame($frame,$scopes,$context,$owner,$reference,$shift);
                            $admission=new \stdClass;$this->originalReadOwner($context)->frames['savedNoteAdmissions']??=new \WeakMap;
                            $this->originalReadOwner($context)->frames['savedNoteAdmissions'][$admission]=['reference'=>$reference,'metadata'=>$row,'context_id'=>spl_object_id($context),
                                'viewer'=>$frame['viewer'],'reads'=>$frame['reads'],'scope'=>$scope,'verify'=>$verify,'author_name_readable'=>$authorNameReadable];
                            $projected=$owner->consumeSavedNote($frame['viewer'],$reference,$row,$context,$admission,fn(array$copy):array=>$copy);
                            if(!$projected['available']){abort(404,'The original saved Note body is unavailable.');}
                            $notes[]=$projected['result'];
                        }
                        $projection=['notes'=>$notes,'status'=>$unavailable?($notes===[]?'unavailable':'partially_available'):'available',
                            'message'=>$unavailable?'Some notes are unavailable because their original context or current access cannot be verified.':null];
                        $this->verifySavedNoteProjectionFrame($frame,$scopes,$context,$owner,$reference,$shift);
                        $this->retainOriginalNoteRead($frame,$scopes,$context);
                        $result=$continuation($projection);
                        if(!is_array($result)){throw new \LogicException('Saved Note continuations return detached arrays only.');}
                        \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                        $this->verifySavedNoteProjectionFrame($frame,$scopes,$context,$owner,$reference,$shift);
                        return$result;
                            });
                    });
            });
    }

    private function verifySavedNoteProjectionFrame(array $frame, array $admitted, HealthSafetyReadContext $context,
        ControlRoomHandoverReadAccessService $owner, object $reference, Shift $projection): void
    {
        $this->verifyCurrentNoteMetadata($frame,$frame['viewer'],$frame['reads'],$context);
        $complete=$this->currentProjectionConstraint($frame,$admitted);
        $owner->verifySavedProjection($frame['viewer'],$reference,$context,$projection,$complete);
    }

    /** Private original admission registry; fabricated objects/builders cannot authorize a saved body. */
    public function consumeSavedNoteAdmission(User $viewer, object $reference, array $metadata, HealthSafetyReadContext $context,
        object $admission, callable $consume): array
    {
        $context->assertViewer($viewer);$proof=$this->originalReadOwner($context)->frames['savedNoteAdmissions'][$admission]??null;
        if($proof===null||$proof['reference']!==$reference||$proof['metadata']!==$metadata||$proof['context_id']!==spl_object_id($context)){
            throw new \LogicException('Use the privately registered original saved Note admission.');
        }
        return$context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User$current,\App\Services\CurrentAuthorizationReads$reads)use($proof,$context,$consume):array{
                if($current!==$proof['viewer']||$reads!==$proof['reads']){throw new \LogicException('Saved Note admission belongs to another current read.');}
                ($proof['verify'])();
                // Metadata-only narrowing; ReadAccess registers and executes the actual saved body.
                $bodyScope=clone$proof['scope'];
                $result=$consume($bodyScope,$proof['verify'],$current,$reads,$context,$proof['author_name_readable']);($proof['verify'])();
                if(!is_array($result)){throw new \LogicException('Saved Note consumers return detached arrays only.');}
                \App\Services\Incidents\IncidentRoutingSettings::detached($result);return$result;
            });
    }

    public function current(Shift $shift, User $viewer, HealthSafetyReadContext $context): array
    {
        return $this->withCurrentProjection($shift, $viewer, $context, fn (array $projection): array => $projection);
    }

    /** Retired raw compatibility entry: preparation requires the canonical command's private transition. */
    public function forPreparation(Shift $shift, User $viewer, HealthSafetyReadContext $context): array
    {
        throw new \LogicException('Use the canonical handover preparation command and its original Note projection.');
    }

    public function assertSaved(Shift $shift, array $snapshot, User $viewer, HealthSafetyReadContext $context): void
    {
        throw new \LogicException('Use the original saved-row reference and canonical saved Note projection.');
    }

    public function saved(Shift $outgoing, array $saved, User $viewer, HealthSafetyReadContext $context, ?array $rawSnapshot = null): array
    {
        throw new \LogicException('Use the original saved-row reference and canonical saved Note projection.');
    }
}