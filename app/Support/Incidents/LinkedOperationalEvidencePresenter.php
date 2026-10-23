<?php

namespace App\Support\Incidents;

use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\CurrentAuthorizationReads;
use App\Services\ControlRoom\ControlRoomGovernanceReadService;
use App\Services\ControlRoom\ControlRoomOriginalReadOuterQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\HealthSafety\HsEventAccessService;

final class LinkedOperationalEvidencePresenter
{
    private static ?\WeakMap $readProofs=null;
    private static ?\WeakMap $receiptProofs=null;
    private static ?\WeakMap $rawChildProofs=null;
    private static ?\WeakMap $copiedFinalFrames=null;
    private static ?\WeakMap $preparationBodyTickets=null;
    private static ?\WeakMap $copiedFinalHookReplacements=null;
    public function __construct(
        private readonly ControlRoomAlertAccessService $access,
        private readonly HsEventAccessService $hsAccess,
    ) {}

    /**
     * Parent access does not grant source access. Downloads recheck both
     * boundaries at request time, including the canonical journey binding.
     *
     * @param  callable(EvidenceItem): (?string)  $downloadUrl
     * @return array<string, mixed>
     */
    public function present(
        ControlRoomAlert $sourceAlert,
        User $viewer,
        callable $downloadUrl,
        ?HealthSafetyReadContext $context = null,
        ?callable $consumeCopiedNote = null,
    ): array {
        if ($context !== null) { return $this->presentInContext($sourceAlert, $viewer, $downloadUrl, $context, $consumeCopiedNote); }
        if (! $this->access->canView($sourceAlert, $viewer)) {
            return [
                'label' => 'Linked Control Room evidence',
                'read_only' => true,
                'access' => 'restricted',
                'source' => null,
                'notes' => [],
                'tasks' => [],
                'evidence_packs' => [],
                'communications' => [],
            ];
        }

        $alert = ControlRoomAlert::query()
            ->with([
                'site:id,name',
                'client:id,first_name,last_name',
            ])
            ->findOrFail($sourceAlert->getKey());

        $notes = $alert->operatorNotes()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $tasks = $alert->tasks()
            ->with([
                'assignedTo:id,name',
                'transferredCorrectiveAction.hsEvent',
                'legacyTransferredCorrectiveAction.hsEvent',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $evidencePacks = $alert->evidencePacks()
            ->with([
                'evidenceItems' => fn ($query) => $query
                    ->with('capturedBy:id,name')
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $communications = $alert->communications()
            ->conversational()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        return [
            'label' => 'Linked Control Room evidence',
            'read_only' => true,
            'access' => 'readable',
            'source' => [
                'id' => $alert->id,
                'reference' => $alert->reference_number,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'href' => "/control-room/alerts/{$alert->id}",
                'site' => $alert->site ? [
                    'id' => $alert->site->id,
                    'name' => $alert->site->name,
                ] : null,
                'client' => $alert->client ? [
                    'id' => $alert->client->id,
                    'name' => trim($alert->client->first_name.' '.$alert->client->last_name),
                ] : null,
                'triggered_at' => $alert->triggered_at?->toIso8601String(),
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                'closed_at' => $alert->closed_at?->toIso8601String(),
                'created_at' => $alert->created_at?->toIso8601String(),
                'updated_at' => $alert->updated_at?->toIso8601String(),
            ],
            'notes' => $notes->map(fn (OperatorNote $note): array => [
                'id' => $note->id,
                'type' => $note->type,
                'purpose' => $note->purpose,
                'purpose_label' => $this->purposeLabel($note->purpose),
                'content' => $note->content,
                'author' => $note->user ? [
                    'id' => $note->user->id,
                    'name' => $note->user->name,
                ] : null,
                'created_at' => $note->created_at?->toIso8601String(),
            ])->values()->all(),
            'tasks' => $tasks->map(function (AlertTask $task) use ($viewer): array {
                $correctiveAction = $task->transferredCorrectiveAction;
                $transferred = $correctiveAction !== null
                    || $task->transferred_to_hs_corrective_action_id !== null
                    || $task->transferred_at !== null
                    || $task->transferred_by_user_id !== null
                    || $task->status === AlertTask::STATUS_TRANSFERRED;
                $active = ! in_array($task->status, AlertTask::TERMINAL_STATUSES, true);

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'owner' => $task->assignedTo ? [
                        'id' => $task->assignedTo->id,
                        'name' => $task->assignedTo->name,
                    ] : null,
                    'assignee' => $task->assignedTo?->name,
                    'due_at' => $task->due_at?->toIso8601String(),
                    'overdue' => $active
                        && $task->due_at !== null
                        && $task->due_at->isPast(),
                    'transfer' => [
                        'state' => $transferred
                            ? 'transferred'
                            : ($active ? 'open' : 'retained'),
                        'corrective_action_reference' => $correctiveAction?->hsEvent
                            && $this->hsAccess->canView($correctiveAction->hsEvent, $viewer)
                                ? $correctiveAction->reference_number
                                : null,
                        'transferred_at' => $task->transferred_at?->toIso8601String(),
                    ],
                ];
            })->values()->all(),
            'evidence_packs' => $evidencePacks->map(fn ($pack): array => [
                'id' => $pack->id,
                'title' => $pack->title,
                'status' => $pack->status,
                'item_count' => $pack->evidenceItems->count(),
                'items' => $pack->evidenceItems->map(fn (EvidenceItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'title' => $item->title,
                    'description' => $item->description,
                    'mime_type' => $item->mime_type,
                    'file_size' => $item->file_size,
                    'captured_at' => $item->captured_at?->toIso8601String(),
                    'captured_by' => $item->capturedBy ? [
                        'id' => $item->capturedBy->id,
                        'name' => $item->capturedBy->name,
                    ] : null,
                    'download_url' => filled($item->storage_path)
                        ? $downloadUrl($item)
                        : null,
                    'created_at' => $item->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'communications' => $communications->map(fn ($communication): array => [
                'id' => $communication->id,
                'channel' => $communication->channel,
                'direction' => $communication->direction,
                'purpose' => $communication->purpose,
                'subject' => $communication->subject,
                'content' => $communication->content,
                'status' => $communication->status,
                'sent_at' => $communication->sent_at?->toIso8601String(),
                'delivered_at' => $communication->delivered_at?->toIso8601String(),
                'created_at' => $communication->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function presentInContext(ControlRoomAlert $sourceAlert, User $viewer, callable $downloadUrl, HealthSafetyReadContext $context, ?callable $consumeCopiedNote = null): array
    {
        $reader = app(ControlRoomGovernanceReadService::class);
        return $reader->withCurrentViewer($viewer, function (User $viewer) use ($sourceAlert, $downloadUrl, $context, $reader, $consumeCopiedNote): array {
            $identity = $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS, fn(User $current, CurrentAuthorizationReads $reads) => $this->access->applyVisibleScope(ControlRoomAlert::query()->whereKey($sourceAlert->id), $current, $reads))
                ->first(['id', 'site_id', 'client_id', 'asset_id', 'source', 'alert_type']);
            abort_unless($identity, 404);
            $alert = $reader->currentAlerts([$identity], $viewer, $context)->first();
            $details = $reader->consumeOperationalAlertDetails($viewer, (int) $alert->id, $context,
                function (Builder $original, CurrentAuthorizationReads $reads, User $viewer, HealthSafetyReadContext $same) use ($alert, $downloadUrl, $reader, $consumeCopiedNote): array {
                    $alert->load($this->guardedLoads(['site:id,name', 'client:id,first_name,last_name'], $same, $original));
                    $governance = $reader->forAlerts([$alert], $viewer, $same)->get((int) $alert->id);
                    $rows=$this->presentOperationalRows($alert, $viewer, $downloadUrl, $same, $original, $governance, $consumeCopiedNote);
                    $this->verifyReadProjections($same);return$rows;
                });
            return $details['available'] ? $details['result'] : [
                'label'=>'Linked Control Room evidence', 'read_only'=>true, 'access'=>'restricted',
                'source'=>null, 'notes'=>[], 'tasks'=>[], 'evidence_packs'=>[], 'communications'=>[],
            ];
        }, $context);
    }

    private function presentOperationalRows(ControlRoomAlert $alert, User $viewer, callable $downloadUrl, HealthSafetyReadContext $context, Builder $original, array $governance, ?callable $consumeCopiedNote = null): array
    {
        $notes = $this->notesInContext($alert, $context, $original, null, $consumeCopiedNote);

        $tasks = $this->guardRead($alert->tasks(), $context, $original)
            ->with($this->guardedLoads([
                'assignedTo:id,name',
                'transferredCorrectiveAction:id,hs_event_id,source_control_room_task_id',
                'legacyTransferredCorrectiveAction:id,hs_event_id',
            ], $context, $original, AlertTask::class))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $evidencePacks = $this->guardRead($alert->evidencePacks(), $context, $original)
            ->with($this->guardedLoads([
                'evidenceItems' => fn ($query) => $query
                    ->with($this->guardedLoads(['capturedBy:id,name'], $context, $original, EvidenceItem::class))
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ], $context, $original, \App\Models\ControlRoom\EvidencePack::class))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $communications = $this->guardRead($alert->communications(), $context, $original)
            ->conversational()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        return [
            'label' => 'Linked Control Room evidence',
            'read_only' => true,
            'access' => 'readable',
            'source' => [
                'id' => $alert->id,
                'reference' => $alert->reference_number,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'href' => "/control-room/alerts/{$alert->id}",
                'site' => $alert->site ? [
                    'id' => $alert->site->id,
                    'name' => $alert->site->name,
                ] : null,
                'client' => $alert->client ? [
                    'id' => $alert->client->id,
                    'name' => trim($alert->client->first_name.' '.$alert->client->last_name),
                ] : null,
                'triggered_at' => $alert->triggered_at?->toIso8601String(),
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                'closed_at' => $alert->closed_at?->toIso8601String(),
                'created_at' => $alert->created_at?->toIso8601String(),
                'updated_at' => $alert->updated_at?->toIso8601String(),
            ],
            'notes' => $notes,
            'tasks' => $tasks->map(function (AlertTask $task) use ($viewer, $context, $governance): array {
                $correctiveAction = $task->transferredCorrectiveAction;
                $transferred = $correctiveAction !== null
                    || $task->transferred_to_hs_corrective_action_id !== null
                    || $task->transferred_at !== null
                    || $task->transferred_by_user_id !== null
                    || $task->status === AlertTask::STATUS_TRANSFERRED;
                $active = ! in_array($task->status, AlertTask::TERMINAL_STATUSES, true);

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'owner' => $task->assignedTo ? [
                        'id' => $task->assignedTo->id,
                        'name' => $task->assignedTo->name,
                    ] : null,
                    'assignee' => $task->assignedTo?->name,
                    'due_at' => $task->due_at?->toIso8601String(),
                    'overdue' => $active
                        && $task->due_at !== null
                        && $task->due_at->isPast(),
                    'transfer' => [
                        'state' => $transferred
                            ? 'transferred'
                            : ($active ? 'open' : 'retained'),
                        'corrective_action_reference' => $correctiveAction
                            ? $this->readTransferReference($correctiveAction, $governance, $viewer, $context) : null,
                        'transferred_at' => $task->transferred_at?->toIso8601String(),
                    ],
                ];
            })->values()->all(),
            'evidence_packs' => $evidencePacks->map(fn ($pack): array => [
                'id' => $pack->id,
                'title' => $pack->title,
                'status' => $pack->status,
                'item_count' => $pack->evidenceItems->count(),
                'items' => $pack->evidenceItems->map(fn (EvidenceItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'title' => $item->title,
                    'description' => $item->description,
                    'mime_type' => $item->mime_type,
                    'file_size' => $item->file_size,
                    'captured_at' => $item->captured_at?->toIso8601String(),
                    'captured_by' => $item->capturedBy ? [
                        'id' => $item->capturedBy->id,
                        'name' => $item->capturedBy->name,
                    ] : null,
                    'download_url' => filled($item->storage_path)
                        ? $downloadUrl($item)
                        : null,
                    'created_at' => $item->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'communications' => $communications->map(fn ($communication): array => [
                'id' => $communication->id,
                'channel' => $communication->channel,
                'direction' => $communication->direction,
                'purpose' => $communication->purpose,
                'subject' => $communication->subject,
                'content' => $communication->content,
                'status' => $communication->status,
                'sent_at' => $communication->sent_at?->toIso8601String(),
                'delivered_at' => $communication->delivered_at?->toIso8601String(),
                'created_at' => $communication->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }



    /** Parent-owned ordinary notes remain independent of H&S. Identified copies require canonical original proof. */
    private function notesInContext(ControlRoomAlert $alert, HealthSafetyReadContext $context, Builder $original, ?int $onlyNoteId = null, ?callable $consumeCopiedNote = null): array
    {
        $knownCopy = "EXISTS (SELECT 1 FROM incident_saved_copies WHERE target_family = 'control_room_operator_note' AND target_key = CAST(control_room_operator_notes.id AS CHAR) FOR SHARE NOWAIT)";
        $noteQuery = $this->guardRead($alert->operatorNotes(), $context, $original)
            ->select(['id','alert_id','type','purpose','created_at'])
            ->selectRaw("CASE WHEN $knownCopy THEN NULL ELSE content END as content")
            ->selectRaw("CASE WHEN $knownCopy THEN NULL ELSE user_id END as user_id")
            ->selectRaw("CASE WHEN $knownCopy THEN 'unavailable' ELSE 'readable' END as body_access")
            ->with($this->guardedLoads(['user:id,name'], $context, $original, OperatorNote::class))
            ->orderBy('created_at')->orderBy('id')->when($onlyNoteId!==null,fn(Builder$query)=>$query->whereKey($onlyNoteId));
        // Classification itself can expose ordinary/reclassified content and trigger eager author hydration.
        // During preparation the ORIGINAL selected inventory must gate this FIRST SQL too.
        $prepared=$context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            fn(User$viewer,CurrentAuthorizationReads$reads)=>app(\App\Services\ControlRoom\ControlRoomOperatorNoteReadService::class)->requiresPreparationCopiedBodyAdmission($viewer,$reads,$context));
        if($prepared){
            if($onlyNoteId===null){throw new \LogicException('Preparation Note bodies require the exact lexical selected Note.');}
            $raw=$this->registeredPreparationRows((clone$noteQuery)->setEagerLoads([]),$context,(int)$alert->id,$onlyNoteId,['*'],'get');
            $notes=new \Illuminate\Database\Eloquent\Collection(array_map(fn($attributes)=>(new OperatorNote)->setRawAttributes($attributes,true),$raw['rows']));
            foreach($notes as$selected){
                if($selected->body_access==='unavailable' || $selected->user_id===null){$selected->setRelation('user',null);continue;}
                $originalNote=(clone$noteQuery)->setEagerLoads([])->whereKey($selected->id)->where('control_room_operator_notes.user_id',$selected->user_id)
                    ->reorder()->select([])->selectRaw('1');$originalNote->toSql();
                $authors=$this->guardRead(User::query()->whereKey($selected->user_id)->whereExists($originalNote->toBase()),$context,$original);
                $author=$this->preparationCopiedBodyFirst($authors,$context,(int)$alert->id,(int)$selected->id,['users.id','users.name']);
                $selected->setRelation('user',$author);
            }
        }else{$notes=(clone$noteQuery)->get();}
        foreach($notes as$note){$classified=(clone$noteQuery)->whereKey($note->id)->whereRaw($note->body_access==='unavailable'?$knownCopy:'NOT ('.$knownCopy.')');$this->retainRow($classified,$note,$context,$note->body_access==='unavailable'?['content','user_id','body_access']:['body_access']);}
        return $notes->map(function (OperatorNote $note) use ($alert, $context, $original, $consumeCopiedNote): array {
            $body = ['content' => $note->content, 'author' => $note->user?->only(['id','name']), 'body_access' => $note->body_access];
            if ($note->body_access === 'unavailable') {
                $this->assertCopiedAdmissionOpen($context,(int)$note->id);
                $inventory = $context->query(\App\Models\IncidentSavedCopy::query()
                    ->where('target_family', 'control_room_operator_note')->where('target_key', (string) $note->id));
                $identities = (clone $inventory)->get(['id','copy_uuid','source_family','source_version','target_family','target_key','field']);
                if ($identities->count() === 1) {
                    $identity = $identities->first();
                    $inventoryOriginal = (clone $inventory)->whereKey($identity->id);
                    foreach (['copy_uuid','source_family','source_version','target_family','target_key','field'] as $column) {
                        $value = $identity->getRawOriginal($column);
                        $value === null ? $inventoryOriginal->whereNull($column)
                            : $inventoryOriginal->whereRaw('BINARY '.$inventoryOriginal->getQuery()->getGrammar()->wrap($column).' = BINARY ?', [(string) $value]);
                    }
                    $other = (clone $inventory)->where('incident_saved_copies.id', '<>', $identity->id)
                        ->setEagerLoads([])->reorder()->select([])->selectRaw('1');
                    $other->toSql(); $inventoryOriginal->whereNotExists($other->toBase());
                    self::$readProofs ??= new \WeakMap; $inventoryProofs = self::$readProofs[$context] ?? [];
                    $inventoryKey = 'incident_saved_copies:'.$note->id.':targetInventory';
                    $inventoryProofs[$inventoryKey] ??= $context->query($inventoryOriginal);
                    self::$readProofs[$context] = $inventoryProofs;
                    $inventoryOriginal = clone $inventoryProofs[$inventoryKey];
                    abort_unless($context->query(clone $inventoryOriginal)->exists(), 404);
                    $origin = $identities->first()->only(['copy_uuid','source_family','source_version','target_family','target_key','field']);
                    $copy = app(\App\Services\Incidents\IncidentSavedCopyService::class)->consumeProjectedCopy($context, $origin,
                        function (array $field, CurrentAuthorizationReads $reads, Builder $copySource) use ($note, $alert, $context, $original, $inventoryOriginal): array {
                            $source = (clone $copySource)->select('incident_saved_copies.id')
                                ->whereColumn('incident_saved_copies.actor_id', 'control_room_operator_notes.user_id')
                                ->where('source_tuple->site_id', $alert->getRawOriginal('_effective_site_id') ?? $alert->site_id)->where('source_tuple->client_id', $alert->client_id);
                            $source->toSql();
                            $targetInventory = (clone $inventoryOriginal)->setEagerLoads([])->reorder()->select([])->selectRaw('1');
                            $targetInventory->toSql();
                            $query = $this->guardRead(OperatorNote::query()->whereKey($note->id)->where('alert_id',$alert->id), $context, $original)
                                ->whereRaw('BINARY content = BINARY ?', [$field['text']])->whereExists($source->toBase())->whereExists($targetInventory->toBase());
                            $destination=(clone$query)->first(['control_room_operator_notes.id','control_room_operator_notes.user_id','control_room_operator_notes.type','control_room_operator_notes.purpose']);
                            if($destination===null){return ['content'=>null,'author'=>null,'body_access'=>'unavailable'];}
                            $query->where('user_id',$destination->user_id)->where('type',$destination->type)->where('purpose',$destination->purpose);
                            $receipt=$context->query(\App\Models\AuditLog::query()
                                ->whereRaw('CAST(action AS BINARY) = CAST(? AS BINARY)',['controlRoom.alert.addNote'])
                                ->where('auditable_id',$alert->id)->whereRaw('CAST(auditable_type AS BINARY) = CAST(? AS BINARY)',[$alert->getMorphClass()])
                                ->whereRaw('client_id <=> ?',[$alert->client_id])->whereRaw('JSON_LENGTH(meta)=6')
                                ->whereRaw("JSON_CONTAINS_PATH(meta,'all','$.actor_id','$.alert_id','$.note_id','$.note_type','$.note_purpose','$.transition')=1")
                                ->whereRaw("JSON_TYPE(JSON_EXTRACT(meta,'$.actor_id'))='INTEGER' AND JSON_TYPE(JSON_EXTRACT(meta,'$.alert_id'))='INTEGER' AND JSON_TYPE(JSON_EXTRACT(meta,'$.note_id'))='INTEGER'")
                                ->where('meta->actor_id',$destination->user_id)->where('meta->alert_id',$alert->id)->where('meta->note_id',$note->id)
                                ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.note_type'))AS BINARY)=CAST(? AS BINARY)",[$destination->type])
                                ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.note_purpose'))AS BINARY)=CAST(? AS BINARY)",[$destination->purpose])
                                ->whereRaw("JSON_TYPE(JSON_EXTRACT(meta,'$.transition'))='STRING'")
                                ->whereExists(fn($actor)=>$actor->selectRaw('1')->from('users')->whereColumn('users.id','audit_logs.user_id'))
                                ->whereExists((clone$query)->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase()));
                            $receipts=(clone$receipt)->select(['id','user_id'])->selectRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) as receipt_fingerprint')->limit(2)->get();
                            if($receipts->count()!==1){return ['content'=>null,'author'=>null,'body_access'=>'unavailable'];}
                            $accepted=$receipts->first();$receiptOriginal=(clone$receipt)->whereKey($accepted->id)->where('user_id',$accepted->user_id)
                                ->whereRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256)=?',[$accepted->getRawOriginal('receipt_fingerprint')]);
                            self::$receiptProofs??=new \WeakMap;$receiptsOriginal=self::$receiptProofs[$context]??[];
                            $receiptsOriginal[(int)$note->id]??=[$receipt,$receiptOriginal,(int)$accepted->id];self::$receiptProofs[$context]=$receiptsOriginal;
                            $query->whereExists((clone$receiptOriginal)->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase());
                            $current = $this->preparationCopiedBodyFirst($query,$context,(int)$alert->id,(int)$note->id,
                                ['control_room_operator_notes.id','control_room_operator_notes.content','control_room_operator_notes.user_id']);
                            if ($current === null || ! (clone $query)->exists()) { return ['content'=>null,'author'=>null,'body_access'=>'unavailable']; }
                            $noteOriginal=$this->retainRow($query,$current,$context);
                            // Author hydration is guarded by the admitted COPY + destination note/receipt, not merely Alert READ.
                            $source=(clone$noteOriginal)->setEagerLoads([])->reorder()->select([])->selectRaw('1');$source->toSql();
                            $authorQuery=$this->retainActualChildQuery($reads->query(User::query()->whereKey($current->user_id)->whereExists($source->toBase())),$context);
                            $author=$this->preparationCopiedBodyFirst($authorQuery,$context,(int)$alert->id,(int)$note->id,['users.id','users.name']);
                            if($author!==null){$this->retainRow($authorQuery,$author,$context);}
                            return ['content'=>$current->content,'author'=>$author?->only(['id','name']),'body_access'=>'readable'];
                        });
                    if ($copy['available']) { $body = $copy['result']; }
                }
            }
            $row = ['id'=>$note->id,'type'=>$note->type,'purpose'=>$note->purpose,
                'purpose_label'=>$this->purposeLabel($note->purpose), ...$body,'created_at'=>$note->created_at?->toIso8601String()];
            if ($consumeCopiedNote !== null && $note->body_access === 'unavailable' && $body['body_access'] === 'readable') {
                $receipt = self::$receiptProofs[$context][(int) $note->id] ?? null;
                abort_unless($receipt !== null, 404, 'The original copied receipt is unavailable.');
                // Only the existing lifecycle note protocol is adapted. Resolution remains separate.
                $ordinaryNote = $context->query(clone $receipt[1])->where('meta->transition', 'note');
                if ($ordinaryNote->exists()) {
                    $row = $consumeCopiedNote($row, $original);
                    if (! is_array($row)) { throw new \LogicException('Copied Note consumers return detached arrays.'); }
                    \App\Services\Incidents\IncidentRoutingSettings::detached($row);
                    $context->assertActive();
                }
            }
            return $row;
        })->all();
    }
    /** Actual destination/author SELECT only; the independently built stable query is never decorated or stripped. */
    private function preparationCopiedBodyFirst(Builder $stable,HealthSafetyReadContext $context,int $alertId,int $noteId,array $columns): ?\Illuminate\Database\Eloquent\Model
    {
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User $viewer,CurrentAuthorizationReads $reads)use($stable,$context,$alertId,$noteId,$columns){
                $owner=app(\App\Services\ControlRoom\ControlRoomOperatorNoteReadService::class);
                if (!$owner->requiresPreparationCopiedBodyAdmission($viewer,$reads,$context)) { return (clone $stable)->first($columns); }
                $materialized=$this->registeredPreparationRows($stable,$context,$alertId,$noteId,$columns,'first');
                if (!array_key_exists('row',$materialized) || ($materialized['row']!==null && !is_array($materialized['row']))) { throw new \LogicException('Copied-body admission must return the actual detached physical row.'); }
                if ($materialized['row']===null) { return null; }
                $class=$stable->getModel()::class;
                return (new $class)->setRawAttributes($materialized['row'],true);
            });
    }

    private function registeredPreparationRows(Builder $original,HealthSafetyReadContext $context,int $alertId,int $noteId,array $columns,string $mode):array
    {
        if(!$original->getModel() instanceof OperatorNote && !$original->getModel() instanceof User){throw new \LogicException('Only private Note/author roots may mint preparation body tickets.');}
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,function(User$viewer,CurrentAuthorizationReads$reads)use($original,$context,$alertId,$noteId,$columns,$mode):array{
            $ticket=new \stdClass;self::$preparationBodyTickets??=new \WeakMap;
            $actual=$this->cloneCopiedPlainOriginal($original)->applyScopes()->withoutGlobalScopes();
            if($actual->getQuery()->columns===null){$actual->select($columns);}
            if($mode==='first'){$actual->limit(1);}
            self::$preparationBodyTickets[$ticket]=(object)['tuple'=>[$viewer,$alertId,$noteId,$reads,$context],
                'actual'=>$actual,'columns'=>$columns,'mode'=>$mode,'source_mode'=>$original->getModel() instanceof User?'note_author_body':'copied_note_body','active'=>true];
            try{return $this->executeRegisteredPreparationBody($ticket,$viewer,$alertId,$noteId,$reads,$context);}
            finally{self::$preparationBodyTickets[$ticket]->active=false;unset(self::$preparationBodyTickets[$ticket]);}
        });
    }

    /** Fixed materialisation of a privately registered query; no public factory, Builder input or executor. */
    public function executeRegisteredPreparationBody(object $ticket,User $viewer,int $alertId,int $noteId,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):array
    {
        $actual=$this->registeredPreparationBodyQuery($ticket,$viewer,$alertId,$noteId,$reads,$context);
        app(\App\Services\ControlRoom\ControlRoomOperatorNoteReadService::class)->decorateRegisteredPreparationBody($ticket,self::class,$viewer,$alertId,$noteId,$reads,$context);
        if(!$actual->getQuery() instanceof ControlRoomOriginalReadOuterQuery){throw new \LogicException('The canonical preparation owner must guard the registered actual Note/author query.');}
        $carrier=$actual->getQuery();
        $this->sealCopiedActualQuery($actual,fn()=>$this->registeredPreparationBodyQuery($ticket,$viewer,$alertId,$noteId,$reads,$context),$reads);
        $carrier=$actual->getQuery();
        $entry=self::$preparationBodyTickets[$ticket];
        if($this->registeredPreparationBodyQuery($ticket,$viewer,$alertId,$noteId,$reads,$context)!==$actual){throw new \LogicException('The original registered Note/author query changed.');}
        if($entry->mode==='get'){$result=['rows'=>$actual->get($entry->columns)->map(fn($row)=>$row->getRawOriginal())->all()];}
        elseif($entry->mode==='first'){$result=['row'=>$actual->first($entry->columns)?->getRawOriginal()];}
        else{throw new \LogicException('Unsupported private preparation materialisation mode.');}
        if($actual->getQuery()!==$carrier){throw new \LogicException('The registered Note/author execution carrier was replaced.');}
        $this->registeredPreparationBodyQuery($ticket,$viewer,$alertId,$noteId,$reads,$context);return $result;
    }

    public function registeredPreparationBodyQuery(object $ticket,User $viewer,int $alertId,int $noteId,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):Builder
    {
        $context->assertViewer($viewer);$reads->assertActive();$entry=self::$preparationBodyTickets[$ticket]??null;
        if($entry===null || !$entry->active || $entry->tuple!==[$viewer,$alertId,$noteId,$reads,$context]){throw new \LogicException('Use the exact active privately registered Note/author body ticket.');}
        return $entry->actual;
    }

    /** Exact registered actual query identity/structure is independent of its removable public predicates. */
    private function sealCopiedActualQuery(Builder $actual,callable $registered,CurrentAuthorizationReads $reads):array
    {
        $model=$actual->getModel();$connection=$actual->getConnection();$box=(object)['base'=>$actual->getQuery(),'graph'=>null,'hooks'=>[]];
        $verify=function()use($actual,$registered,$model,$connection,$box,$reads):void{
            if($registered()!==$actual || $actual->getModel()!==$model || $actual->getConnection()!==$connection || $actual->getQuery()!==$box->base){throw new \LogicException('The private protected query model or base changed.');}
            if($box->graph!==null){$this->verifyProtectedQueryHooks($box->hooks,$reads);if($this->protectedQueryGraph($actual,null,null,$reads)!==$box->graph){throw new \LogicException('The private protected query graph, target or execution hooks changed.');}}
        };
        ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$reads);$box->base=$actual->getQuery();
        $this->wrapProtectedQueryHooks($actual,$reads,$box->hooks);$box->graph=$this->protectedQueryGraph($actual,null,null,$reads);
        return $box->hooks;
    }

    private function readTransferReference(HsCorrectiveAction $action, array $governance, User $viewer, HealthSafetyReadContext $context): ?string
    {
        if (($governance['health_safety_access'] ?? null) !== 'readable'
            || (int) ($governance['hs_event']['id'] ?? 0) !== (int) $action->hs_event_id) { return null; }
        $frame = app(ControlRoomGovernanceReadService::class)->consumeReadableEventDetails($viewer, (int) $action->hs_event_id, $context,
            function (Builder $event) use ($action, $context): array {
                $source = (clone $event)->select('hs_events.id')->whereColumn('hs_events.id', 'hs_corrective_actions.hs_event_id');
                $source->toSql();
                $query = HsCorrectiveAction::query()->whereKey($action->id)
                    ->where('hs_event_id', $action->hs_event_id)->whereExists($source->toBase());
                if (array_key_exists('source_control_room_task_id', $action->getAttributes())) {
                    $query->where('source_control_room_task_id', $action->source_control_room_task_id);
                }
                $query = $this->retainActualChildQuery($context->query($query),$context);
                $row = $query->first(['id', 'reference_number']);
                if ($row === null) { return ['reference' => null]; }
                $this->retainRow($query,$row,$context);
                $same = $context->query(clone $query)->where('reference_number', $row->reference_number)->exists();
                return ['reference' => $same ? $row->reference_number : null];
            });
        return $frame['available'] ? $frame['result']['reference'] : null;
    }
    private function guardRead(Builder|Relation $query, HealthSafetyReadContext $context, Builder $original): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        $parent = (clone $original)->select('control_room_alerts.id');
        $parent->toSql();
        return $this->retainActualChildQuery($context->query($query->whereExists($parent->toBase())), $context);
    }

    private function guardedLoads(array $loads, HealthSafetyReadContext $context, Builder $original, string $parentClass = ControlRoomAlert::class): array
    {
        $prepared = ControlRoomAlert::query()->with($loads)->getEagerLoads();
        foreach ($prepared as $name => $constraints) {
            $prepared[$name] = function ($query) use ($constraints, $context, $original, $parentClass, $name): void {
                $constraints($query);
                $builder = $this->guardRead($query, $context, $original);
                $this->guardRetainedChildRelation($builder,$context,$parentClass,$name);
                $builder->setEagerLoads($this->guardedLoads($builder->getEagerLoads(), $context, $original, $builder->getModel()::class));
            };
        }
        return $prepared;
    }

    /** First actual child/source bytes survive until final detached return; never recapture a later row. */
    private function retainRow(Builder$query,\Illuminate\Database\Eloquent\Model$row,HealthSafetyReadContext$context,array$skip=[]):Builder
    {
        self::$readProofs??=new \WeakMap;$proofs=self::$readProofs[$context]??[];$key=$row->getTable().':'.$row->getKey().($skip===[]?':body':':metadata');
        if(isset($proofs[$key])){abort_unless($context->query(clone$proofs[$key])->exists(),404);return clone$proofs[$key];}
        $original=clone$query;
        foreach($row->getRawOriginal()as$column=>$value){if(in_array($column,$skip,true)){continue;}$qualified=$row->getTable().'.'.$column;
            if($value===null){$original->whereNull($qualified);}else{$original->whereRaw('BINARY CAST('.$original->getQuery()->getGrammar()->wrap($qualified).' AS CHAR) = BINARY ?',[(string)$value]);}}
        $proofs[$key]=$context->query($original);self::$readProofs[$context]=$proofs;return clone$original;
    }

    public function verifyReadProjections(HealthSafetyReadContext$context):void
    {
        $context->assertActive();foreach(self::$rawChildProofs[$context]??[]as$proof){abort_unless($context->query(clone$proof['query'])->exists(),404);}
        foreach(self::$readProofs[$context]??[]as$original){abort_unless($context->query(clone$original)->exists(),404);}
        foreach(self::$receiptProofs[$context]??[]as[$matching,$original,$id]){
            $rows=$context->query(clone$matching)->select('audit_logs.id')->limit(2)->get();
            abort_unless($rows->count()===1&&(int)$rows->first()->id===$id&&$context->query(clone$original)->exists(),404);
        }
    }

    /** Equality retention only, AFTER the caller's actual original read/source predicates. */
    public function retainActualChildQuery(Builder$query,HealthSafetyReadContext$context):Builder
    {
        $context->assertActive();$table=$query->getModel()->getTable();$executionRows=[];
        // Previously seen rows cannot acquire changed body/attribution bytes on a later SELECT.
        foreach(self::$rawChildProofs[$context]??[]as$proof){if($proof['table']!==$table){continue;}$attributes=$proof['attributes'];
            $query->where(function(Builder$allowed)use($table,$attributes):void{
                $allowed->where($table.'.id','<>',$attributes['id'])->orWhere(function(Builder$same)use($table,$attributes):void{$this->bindActualChildAttributes($same,$table,$attributes);});
            });
        }
        // Query Builder returns actual SQL rows before ORM hydration and nested eager loads.
        $query->getQuery()->afterQuery(function($rows)use($query,$context,$table,&$executionRows){
            $executionRows=[];foreach($rows as$actual){$executionRows[]=(array)$actual;}
            $canonical=(clone$query)->applyScopes();
            foreach($rows as$row){$attributes=(array)$row;if(!isset($attributes['id'])||!is_numeric($attributes['id'])||(int)$attributes['id']<1){continue;}
                $validation=$attributes;$skip=[];$classified=clone$canonical;
                if($table==='audit_logs'){
                    $skip=['meta','body_access'];if(($attributes['body_access']??null)==='unavailable'){$skip[]='user_id';}
                }
                if($table==='control_room_operator_notes'&&array_key_exists('body_access',$attributes)){
                    $copy="EXISTS(SELECT 1 FROM incident_saved_copies WHERE target_family='control_room_operator_note' AND target_key=CAST(control_room_operator_notes.id AS CHAR) FOR SHARE NOWAIT)";
                    $classified->whereRaw($attributes['body_access']==='unavailable'?$copy:'NOT ('.$copy.')');
                    $skip=['body_access'];if($attributes['body_access']==='unavailable'){$skip=[...$skip,'content','user_id'];}
                }
                foreach($skip as$column){unset($attributes[$column]);if($table!=='audit_logs'){unset($validation[$column]);}}
                $fields=array_keys($attributes);sort($fields);$key=$table.':'.$attributes['id'].':'.implode(',',$fields);
                self::$rawChildProofs??=new \WeakMap;$proofs=self::$rawChildProofs[$context]??[];
                if(isset($proofs[$key])){abort_unless($context->query(clone$proofs[$key]['query'])->exists(),404);continue;}
                $original=$classified;
                $this->bindActualChildAttributes($original,$table,$attributes);
                $proofs[$key]=['table'=>$table,'attributes'=>$attributes,'validation'=>$validation,'query'=>$context->query($original)];self::$rawChildProofs[$context]=$proofs;
            }return$rows;
        });
        // Bind hydrated identity and fields to THIS SQL execution, not a callback-modified model id.
        $query->afterQuery(function($models)use($context,$table,&$executionRows){
            $models=$models instanceof \Illuminate\Support\Collection?$models->values()->all():array_values($models);
            abort_unless(count($models)===count($executionRows),404);
            foreach($models as$index=>$model){
                abort_unless($model instanceof \Illuminate\Database\Eloquent\Model&&$model->getTable()===$table,404);
                $actual=$model->getAttributes();foreach($executionRows[$index]as$column=>$value){
                    abort_unless(array_key_exists($column,$actual)&&($value===null?$actual[$column]===null:($actual[$column]!==null&&(string)$actual[$column]===(string)$value)),404);
                }
            }
        });return$query;
    }

    private function bindActualChildAttributes(Builder$query,string$table,array$attributes):void
    {
        foreach($attributes as$column=>$value){
            if($column==='_audit_metadata_fingerprint'){
                $query->whereRaw("SHA2(CAST(JSON_OBJECT('user_id',audit_logs.user_id,'meta',audit_logs.meta) AS CHAR CHARACTER SET utf8mb4),256)=?",[$value]);continue;
            }
            $qualified=$table.'.'.$column;
            if($value===null){$query->whereNull($qualified);}else{$query->whereRaw('BINARY CAST('.$query->getQuery()->getGrammar()->wrap($qualified).' AS CHAR) = BINARY ?',[(string)$value]);}
        }
    }

    /** Bind eager child selection to the first actual parent-row FK footprint, before body hydration. */
    public function guardRetainedChildRelation(Builder$query,HealthSafetyReadContext$context,string$parentClass,string$relationName):void
    {
        if($parentClass===ControlRoomAlert::class){return;} // Original Alert frame already freezes its FK tuple.
        $name=explode('.',$relationName)[0];$parent=new$parentClass;
        if(!method_exists($parent,$name)){return;}$relation=$parent->{$name}();
        if($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo){$parentColumn=$relation->getForeignKeyName();$childColumn=$query->getModel()->qualifyColumn($relation->getOwnerKeyName());}
        elseif($relation instanceof \Illuminate\Database\Eloquent\Relations\HasOneOrMany){$parentColumn=$relation->getLocalKeyName();$childColumn=$relation->getQualifiedForeignKeyName();}
        else{return;}
        $proofs=array_filter(self::$rawChildProofs[$context]??[],fn($proof)=>$proof['table']===$parent->getTable()&&array_key_exists($parentColumn,$proof['attributes']));
        $query->where(function(Builder$allowed)use($proofs,$parentColumn,$childColumn,$parent):void{
            $allowed->whereRaw('1=0');foreach($proofs as$proof){
                $source=(clone$proof['query'])->setEagerLoads([])->reorder()->select([])->selectRaw('1')
                    ->whereColumn($parent->qualifyColumn($parentColumn),$childColumn);$source->toSql();$allowed->orWhereExists($source->toBase());
            }
        });$context->query($query);
    }

    /** Memory-only recursive shape check; a nested execution guard is never stripped. */
    private function assertCopiedPlainOriginalWitness(Builder $original): void
    {
        $seen = new \SplObjectStorage;
        $walk = function ($value) use (&$walk, $seen): void {
            if ($value instanceof Builder) { $value = $value->getQuery(); }
            if ($value instanceof \Illuminate\Database\Query\Builder) {
                if (! in_array(get_class($value), [\Illuminate\Database\Query\Builder::class, \Illuminate\Database\Query\JoinClause::class], true)) {
                    throw new \LogicException('Canonical copied plain witness contains an execution carrier; coordinate its original restrictor.');
                }
                if ($seen->contains($value)) { return; } $seen->attach($value);
                foreach (get_object_vars($value) as $property => $part) {
                    if (in_array($property, ['connection', 'grammar', 'processor'], true)) { continue; }
                    $walk($part);
                }
            } elseif (is_array($value)) { foreach ($value as $part) { $walk($part); } }
        };
        $walk($original);
    }

    /** Isolate existing query nodes, never reconstruct source authority or strip original callbacks. */
    private function cloneCopiedPlainOriginal(Builder $original): Builder
    {
        $this->assertCopiedPlainOriginalWitness($original);
        $copies = new \SplObjectStorage;
        $copy = function ($value) use (&$copy, $copies) {
            if ($value instanceof Builder) {
                if (get_class($value) !== Builder::class) { throw new \LogicException('Unsupported copied Eloquent witness node.'); }
                if ($copies->contains($value)) { return $copies[$value]; }
                $cloned = clone $value; $copies[$value] = $cloned;
                // setModel changes FROM; restore the fully cloned EXACT original query afterwards.
                $query = $copy($value->getQuery()); $cloned->setModel(clone $value->getModel());
                $cloned->setQuery($query); return $cloned;
            }
            if ($value instanceof \Illuminate\Database\Query\Builder) {
                if (! in_array(get_class($value), [\Illuminate\Database\Query\Builder::class, \Illuminate\Database\Query\JoinClause::class], true)) {
                    throw new \LogicException('A guarded copied carrier is never converted to a plain witness.');
                }
                if ($copies->contains($value)) { return $copies[$value]; }
                $cloned = clone $value; $copies[$value] = $cloned;
                foreach (get_object_vars($value) as $property => $part) {
                    if (in_array($property, ['connection', 'grammar', 'processor'], true)) { continue; }
                    $cloned->{$property} = $copy($part);
                }
                return $cloned;
            }
            if (is_array($value)) { foreach ($value as $key => $part) { $value[$key] = $copy($part); } }
            // Original Closure/guard Expression/service identity remains intact, including all callbacks.
            return $value;
        };
        return $copy($original);
    }

    /** NEW static weak registry never strongly owns its context through retained query callbacks. */
    private function copiedFinalReadOwner(HealthSafetyReadContext $context): \stdClass
    {
        $context->assertActive(); self::$copiedFinalFrames ??= new \WeakMap;
        $reference = self::$copiedFinalFrames[$context] ?? null;
        $owner = $reference?->get();
        if ($owner === null) {
            if ($reference !== null) { throw new \LogicException('The original copied owner cannot be reset in an active context.'); }
            $owner = (object) ['frames' => ['copied_state'=>(object)['frames'=>[],'revision'=>0,'tuple'=>null,'note_freezes'=>[],'final_freeze'=>null]]];
            // Explicit context-owned passive slot survives repeats; actual finally retires it.
            $context->retainControlRoomOriginalReadOwner($owner);
            self::$copiedFinalFrames[$context] = \WeakReference::create($owner);
        }
        return $owner->frames['copied_state'];
    }

    private function copiedFinalReadStateIfPresent(HealthSafetyReadContext $context):?\stdClass
    {
        return (self::$copiedFinalFrames[$context]??null)?->get()?->frames['copied_state']??null;
    }

    /** Canonical private complete PLAIN witness adapter for CR's fresh E61 whole composite. */
    public function consumeCopiedNoteOriginalWitness(User $viewer, int $alertId, int $noteId,
        CurrentAuthorizationReads $reads, HealthSafetyReadContext $context, callable $consume): array
    {
        throw new \LogicException('Executable copied predicates are not exported; use original identity admission and a canonical registered final query.');
    }

    /** Original identity admission only. The fixed canonical FIRST/ALL-use SQL never leaves this owner. */
    public function consumeCopiedNoteOriginalIdentity(User $viewer,int $alertId,int $noteId,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context,callable $consume):array
    {
        $unavailable = ['available' => false, 'result' => null];
        $owner = $this->copiedFinalReadStateIfPresent($context);
        $first = $owner === null ? null : ($owner->frames[$noteId] ?? null);
        if ($first === null) { $context->assertActive(); return $unavailable; }
        $this->assertCopiedFinalIdentity($viewer, $reads, $context, $owner, $first, $alertId, $noteId);
        // Capture-time exports freeze this Note only; a continuation may admit the next selected Note.
        return $this->withinCopiedNoteWitnessFreeze($viewer,$reads,$context,$owner,$first,$noteId,
            function(callable $fence)use($viewer,$alertId,$noteId,$reads,$context,$owner,$first,$consume):array{
                $original=$this->fenceCopiedQuery($this->copiedPlainOriginal($first),$fence);
                $verify=function()use($fence,$original,$reads):void{$fence();abort_unless($reads->query($this->cloneCopiedPlainOriginal($original))->exists(),404);};
                $actual=$context->query($this->cloneCopiedPlainOriginal($original));
                ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$reads); abort_unless($actual->exists(),404);
                $result=$consume($reads,$viewer,$context);
                if(!is_array($result)){throw new \LogicException('Copied witness consumers return detached arrays.');}
                \App\Services\Incidents\IncidentRoutingSettings::detached($result); $fence(); abort_unless($actual->exists(),404);
                return ['available'=>true,'result'=>$result];
            });
    }

    /** Canonical CR registers and executes its own final query. No borrowed predicate or executable query is exported. */
    public function decorateRegisteredCopiedNoteFinalQuery(object $ticket,string $ownerClass,User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):void
    {
        $owner=$this->copiedFinalReadStateIfPresent($context);
        if($owner===null || $owner->final_freeze===null){throw new \LogicException('Copied final decoration requires the exact live whole-context scope.');}
        $this->assertCopiedOwnerMemory($owner,$viewer,$reads,$context);$fence=$owner->final_freeze->assert;$fence();
        $allowed=[\App\Services\ControlRoom\ControlRoomOperatorNoteReadService::class,
            \App\Services\ControlRoom\ControlRoomHandoverReadAccessService::class,
            \App\Services\ControlRoom\ControlRoomHandoverSummaryProtocol::class];
        if(!in_array($ownerClass,$allowed,true)){throw new \LogicException('Only the exact canonical CR fixed-execution owners may decorate a final query.');}
        $actual=$ownerClass::registeredCopiedNoteFinalQuery($ticket,$viewer,$reads,$context);
        if(!$actual instanceof Builder){throw new \LogicException('The canonical CR owner must resolve its own privately registered actual final query.');}
        $original=$owner->frames===[]?null:$this->copiedPlainGraph($viewer,$reads,$context,$owner);
        if($original!==null){$predicate=$this->cloneCopiedPlainOriginal($original)->setEagerLoads([])->reorder()->select([])->selectRaw('1');$predicate->toSql();$actual->whereExists($predicate->toBase());}
        $verify=function()use($viewer,$reads,$context,$actual,$fence,$original):void{
            $fence();
            $this->assertCopiedOwnerMemory($this->copiedFinalReadStateIfPresent($context),$viewer,$reads,$context);
        };
        ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$reads);
        // CR's private fixed executor independently owns ticket lifetime and final physical identity.
        $hooks=$this->sealCopiedActualQuery($actual,fn()=>$actual,$reads);
        self::$copiedFinalHookReplacements??=new \WeakMap;
        self::$copiedFinalHookReplacements[$ticket]=(object)['ownerClass'=>$ownerClass,'tuple'=>[$viewer,$reads,$context],'actual'=>$actual,'freeze'=>$owner->final_freeze,'hooks'=>$hooks];
    }

    /** Attest only the exact wrappers minted by this source for this live CR ticket. Never resolves the full CR getter. */
    public function assertRegisteredCopiedFinalHookReplacement(object $ticket,string $ownerClass,User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context,\Closure $original,\Closure $replacement):void
    {
        $entry=self::$copiedFinalHookReplacements[$ticket]??null;
        $owner=$this->copiedFinalReadStateIfPresent($context);
        if($entry===null || $entry->ownerClass!==$ownerClass || $entry->tuple!==[$viewer,$reads,$context]
            || $owner===null || $owner->final_freeze!==$entry->freeze){throw new \LogicException('The exact live copied final hook ticket is required.');}
        $this->assertCopiedOwnerMemory($owner,$viewer,$reads,$context);$assert=$entry->freeze->assert;$assert();
        $ownerClass::assertRegisteredCopiedNoteFinalQueryIdentity($ticket,$viewer,$reads,$context,$entry->actual);
        foreach($entry->hooks as$hook){foreach($hook['states']as$state){
            if($state->original===$original && $state->replacement===$replacement){return;}
        }}
        throw new \LogicException('This hook replacement was not privately minted for the registered actual final query.');
    }
    /** CR calls this in its finally block before retiring its own ticket; break value-to-ticket graph cycles explicitly. */
    public function retireRegisteredCopiedFinalHookReplacements(object $ticket,string $ownerClass,User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):void
    {
        $entry=self::$copiedFinalHookReplacements[$ticket]??null;
        // Decoration may have failed before minting a mapping. Absence confers no authority.
        if($entry===null){return;}
        if($entry->ownerClass!==$ownerClass || $entry->tuple!==[$viewer,$reads,$context]){
            throw new \LogicException('Only the exact registered owner tuple may retire copied final hooks.');
        }
        try{
            $owner=$this->copiedFinalReadStateIfPresent($context);
            if($owner===null || $owner->final_freeze!==$entry->freeze){throw new \LogicException('The original copied final scope has expired.');}
            $this->assertCopiedOwnerMemory($owner,$viewer,$reads,$context);$assert=$entry->freeze->assert;$assert();
            $ownerClass::assertRegisteredCopiedNoteFinalQueryIdentity($ticket,$viewer,$reads,$context,$entry->actual);
        }finally{
            // Even a late/expired exact-owner retirement must release the retained graph while refusing authority.
            unset(self::$copiedFinalHookReplacements[$ticket]);
        }
    }
    /** Whole-context membership is frozen only after selected scopes have been admitted; includes honest emptiness. */
    public function withinCopiedNoteFinalRead(User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context,callable $consume):array
    {
        $context->assertViewer($viewer);$reads->assertActive();$owner=$this->copiedFinalReadOwner($context);
        $this->bindCopiedOwner($owner,$viewer,$reads,$context);
        $existing=$owner->final_freeze;
        if($existing!==null){($existing->assert)();return $this->copiedFrozenResult($consume,$viewer,$reads,$context,$existing->assert);}
        $snapshot=$this->copiedMembership($owner);$token=(object)[];
        $assert=function()use($owner,$viewer,$reads,$context,$snapshot,$token):void{
            $this->assertCopiedOwnerMemory($owner,$viewer,$reads,$context);
            if($owner->final_freeze!==$token || $this->copiedMembership($owner)!==$snapshot){throw new \LogicException('The original complete copied Note inventory changed or escaped its final scope.');}
        };
        $token->assert=$assert; $owner->final_freeze=$token;
        try{return $this->copiedFrozenResult($consume,$viewer,$reads,$context,$assert);}
        finally{if($owner->final_freeze!==$token){throw new \LogicException('The original copied final scope was replaced.');}$owner->final_freeze=null;}
    }

    private function copiedFrozenResult(callable $consume,User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context,callable $fence):array
    {
        $fence();$result=$consume($viewer,$reads,$context);
        if(!is_array($result)){throw new \LogicException('Copied final scopes return detached arrays only.');}
        \App\Services\Incidents\IncidentRoutingSettings::detached($result);$fence();return $result;
    }

    private function copiedMembership(\stdClass $owner):array
    {
        $membership=['revision'=>$owner->revision,'frames'=>[]];
        foreach($owner->frames as$id=>$first){$membership['frames'][]=[$id,$first,$first->query,$first->revision,$first->uses];}
        return $membership;
    }

    private function bindCopiedOwner(\stdClass $owner,User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):void
    {
        $context->assertViewer($viewer);$reads->assertActive();
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,function(User$current,CurrentAuthorizationReads$same)use($viewer,$reads):void{
            if($current!==$viewer || $same!==$reads){throw new \LogicException('Use the exact prepared copied Note tuple.');}
        });
        $tuple=[$viewer,$reads,$context];
        if($owner->tuple===null){$owner->tuple=$tuple;}
        elseif($owner->tuple!==$tuple){throw new \LogicException('The copied owner belongs to another original tuple.');}
        $this->assertCopiedOwnerMemory($owner,$viewer,$reads,$context);
    }

    private function assertCopiedOwnerMemory(\stdClass $owner,User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):void
    {
        $context->assertViewer($viewer);$reads->assertActive();
        if($this->copiedFinalReadStateIfPresent($context)!==$owner || $owner->tuple!==[$viewer,$reads,$context]){throw new \LogicException('The original copied owner expired or changed.');}
    }

    private function assertCopiedAdmissionOpen(HealthSafetyReadContext $context,int $noteId):void
    {
        $context->assertActive();$owner=$this->copiedFinalReadStateIfPresent($context);
        if($owner!==null && ($owner->final_freeze!==null || isset($owner->note_freezes[$noteId]))){throw new \LogicException('Copied Note admission is frozen before any additional body read.');}
    }

    private function withinCopiedNoteWitnessFreeze(User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context,\stdClass $owner,object $first,int $noteId,callable $consume):array
    {
        $this->bindCopiedOwner($owner,$viewer,$reads,$context);
        if($owner->final_freeze!==null){$fence=$owner->final_freeze->assert;$fence();return $consume($fence);}
        if(isset($owner->note_freezes[$noteId])){$fence=$owner->note_freezes[$noteId]->assert;$fence();return $consume($fence);}
        $snapshot=[$first,$first->query,$first->revision,$first->uses];$token=(object)[];
        $assert=function()use($owner,$viewer,$reads,$context,$first,$noteId,$snapshot,$token):void{
            $this->assertCopiedOwnerMemory($owner,$viewer,$reads,$context);
            if(($owner->note_freezes[$noteId]??null)!==$token || ($owner->frames[$noteId]??null)!==$first || [$first,$first->query,$first->revision,$first->uses]!==$snapshot){throw new \LogicException('The original copied Note FIRST/ALL-use export changed or escaped.');}
        };
        $token->assert=$assert;$owner->note_freezes[$noteId]=$token;
        try{$assert();$result=$consume($assert);$assert();return $result;}
        finally{if(($owner->note_freezes[$noteId]??null)!==$token){throw new \LogicException('The original copied Note export scope was replaced.');}unset($owner->note_freezes[$noteId]);}
    }

    /** The borrowed plain graph itself fails compilation outside its exact live scope, including embedded EXISTS. */
    private function fenceCopiedQuery(Builder $query,callable $assert):Builder
    {
        $assert();
        return $query->whereRaw(new class($assert) extends \Illuminate\Database\Query\Expression{
            public function __construct(private $assert){parent::__construct('1 = 1');}
            public function getValue(\Illuminate\Database\Grammar $grammar){($this->assert)();return parent::getValue($grammar);}
        });
    }

    /** Memory-only checks before query construction, SQL, or consumer invocation. */
    private function assertCopiedFinalIdentity(User $viewer, CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, \stdClass $owner, object $first, int $alertId, int $noteId): void
    {
        $context->assertActive(); $reads->assertActive();
        if ($this->copiedFinalReadStateIfPresent($context) !== $owner
            || ($owner->frames[$noteId] ?? null) !== $first
            || $first->viewer !== $viewer || $first->reads !== $reads || $first->context !== $context
            || $first->alert_id !== $alertId || $first->note_id !== $noteId || $first->uses === []) {
            throw new \LogicException('Use the exact admitted copied Alert/Note and original tuple.');
        }
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, CurrentAuthorizationReads $sameReads) use ($viewer, $reads): void {
                if ($current !== $viewer || $sameReads !== $reads) {
                    throw new \LogicException('Copied original witness requires the same prepared actor.');
                }
            });
    }

    /** Clone only privately admitted FIRST plain state plus ALL original additional use constraints. */
    private function copiedPlainOriginal(object $first): Builder
    {
        foreach ([$first->query, ...$first->uses] as $witness) { $this->assertCopiedPlainOriginalWitness($witness); }
        $original = $this->cloneCopiedPlainOriginal($first->query);
        foreach ($first->uses as $use) {
            $use = $this->cloneCopiedPlainOriginal($use)->setEagerLoads([])->reorder()->select([])->selectRaw('1');
            $use->toSql(); $original->whereExists($use->toBase());
        }
        return $original;
    }

    /** Private first full copied Note witness; never minted from caller metadata or a plain Note. */
    private function copiedFinalFrame(Builder $frame, int $alertId, int $noteId, int $authorId, array $receipt,
        User $viewer, CurrentAuthorizationReads $reads, HealthSafetyReadContext $context): Builder
    {
        $this->assertCopiedAdmissionOpen($context,$noteId);
        $owner = $this->copiedFinalReadOwner($context);
        $this->bindCopiedOwner($owner,$viewer,$reads,$context);
        $frames = $owner->frames;
        if (! isset($frames[$noteId])) {
            $complete = $this->cloneCopiedPlainOriginal($frame);
            // Keep the ENTIRE original matching set, including every accepted transition string.
            // Selected original receipt EXISTS + no other matching ID is exactly count==1.
            $extra = $this->cloneCopiedPlainOriginal($receipt[0])->where('audit_logs.id', '<>', $receipt[2])
                ->setEagerLoads([])->reorder()->select([])->selectRaw('1');
            $extra->toSql(); $complete->whereNotExists($extra->toBase());
            $author = self::$readProofs[$context]['users:'.$authorId.':body'] ?? null;
            if ($author === null) { throw new \LogicException('Copied final proof requires the actually admitted original author.'); }
            $witnesses = [$this->cloneCopiedPlainOriginal($author)];
            foreach (self::$rawChildProofs[$context] ?? [] as $proof) {
                if (($proof['table'] === 'users' && (int) ($proof['attributes']['id'] ?? 0) === $authorId)
                    || ($proof['table'] === 'control_room_operator_notes' && (int) ($proof['attributes']['id'] ?? 0) === $noteId)) {
                    $witnesses[] = $this->cloneCopiedPlainOriginal($proof['query']);
                }
            }
            foreach ($witnesses as $witness) {
                if (get_class($witness->getQuery()) !== \Illuminate\Database\Query\Builder::class) {
                    throw new \LogicException('Copied witnesses must remain original plain nonrecursive queries.');
                }
                $witness->setEagerLoads([])->reorder()->select([])->selectRaw('1');
                $witness->toSql(); $complete->whereExists($witness->toBase());
            }
            if (get_class($complete->getQuery()) !== \Illuminate\Database\Query\Builder::class) {
                throw new \LogicException('Do not strip a copied final execution guard.');
            }
            $this->assertCopiedPlainOriginalWitness($complete);
            $frames[$noteId] = (object) ['query' => $context->query($complete), 'viewer' => $viewer,
                'reads' => $reads, 'context' => $context, 'alert_id' => $alertId, 'note_id' => $noteId, 'uses' => [],'revision'=>0];
            $owner->frames = $frames;
            $owner->revision++;
        }
        $first = $frames[$noteId];
        if ($first->viewer !== $viewer || $first->reads !== $reads || $first->context !== $context
            || $first->alert_id !== $alertId || $first->note_id !== $noteId) {
            throw new \LogicException('Retain the same original copied Note viewer/read/context.');
        }
        // Later legitimate restrictions are additional only; never replace the FIRST full evidence.
        $original = $this->cloneCopiedPlainOriginal($first->query)->setEagerLoads([])->reorder()->select([])->selectRaw('1');
        $original->toSql(); $actual = $context->query($this->cloneCopiedPlainOriginal($frame)->whereExists($original->toBase()));
        $plain = $this->cloneCopiedPlainOriginal($actual);
        if (get_class($plain->getQuery()) !== \Illuminate\Database\Query\Builder::class) {
            throw new \LogicException('Do not retain an E61 carrier as a copied witness.');
        }
        $this->assertCopiedPlainOriginalWitness($plain);
        // Retain each actual additional root/history restrictor without replacing FIRST evidence.
        $this->assertCopiedAdmissionOpen($context,$noteId);
        $first->uses[] = $plain; $first->revision++; $owner->revision++;
        $verify = function () use ($first, $alertId, $noteId, $owner, $viewer, $reads, $context): void {
            $this->assertCopiedFinalIdentity($viewer, $reads, $context, $owner, $first, $alertId, $noteId);
            abort_unless($reads->query($this->copiedPlainOriginal($first))->exists(), 404);
        };
        ControlRoomOriginalReadOuterQuery::attach($actual, $verify, $reads);
        return $actual;
    }

    /** Full private copied graph replay at the OUTERMOST CR finalizer, after all Gov/Linked tails. */
    public function verifyCopiedNoteFinalProjections(User $viewer, CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context): void
    {
        $this->withinCopiedNoteFinalRead($viewer,$reads,$context,function()use($viewer,$reads,$context):array{
            $this->verifyCopiedFrozenFinalProjections($viewer,$reads,$context);return [];
        });
    }

    private function verifyCopiedFrozenFinalProjections(User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):void
    {
        $context->assertViewer($viewer); $reads->assertActive();
        $owner = $this->copiedFinalReadStateIfPresent($context);
        $frames = $owner?->frames ?? [];
        if ($owner===null || $owner->final_freeze===null) { throw new \LogicException('The final copied graph requires its whole-context freeze.'); }
        $fence=$owner->final_freeze->assert;$fence();
        if ($frames === []) { return; }
        $original = $this->fenceCopiedQuery($this->copiedPlainGraph($viewer, $reads, $context, $owner),$fence);
        // Cache only FIRST plain witnesses. This fresh E61 carrier is never stored in any proof map.
        $verify = function () use ($viewer, $reads, $context, $frames, $owner,$original,$fence): void {
            $fence();
            $context->assertActive(); $reads->assertActive();
            foreach ($frames as $noteId => $first) {
                if ($this->copiedFinalReadStateIfPresent($context) !== $owner
                    || ($owner->frames[$noteId] ?? null) !== $first) {
                    throw new \LogicException('The first copied graph cannot be replaced.');
                }
            }
            abort_unless($reads->query($this->cloneCopiedPlainOriginal($original))->exists(), 404);
        };
        $actual = $context->query(clone $original);
        ControlRoomOriginalReadOuterQuery::attach($actual, $verify, $reads);
        abort_unless($actual->exists(), 404);
    }

    private function copiedPlainGraph(User $viewer, CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, \stdClass $owner): Builder
    {
        $frames = $owner->frames;
        if ($frames === []) { throw new \LogicException('No actual copied Note final admission exists.'); }
        // Check EVERY private tuple/owner before constructing any original query graph.
        foreach ($frames as $noteId => $first) {
            $this->assertCopiedFinalIdentity($viewer, $reads, $context, $owner, $first, $first->alert_id, $noteId);
        }
        $original = $this->copiedPlainOriginal(reset($frames));
        foreach ($frames as $first) {
            $source = $this->copiedPlainOriginal($first)->setEagerLoads([])->reorder()->select([])->selectRaw('1');
            $source->toSql(); $original->whereExists($source->toBase());
        }
        return $original;
    }

    /** Known copied lifecycle_note/note only; never the independent resolution/R57 producer. */
    public function consumeCopiedNoteIdentity(User $viewer,int $alertId,int $noteId,HealthSafetyReadContext $context,callable $consume,?Builder $originalRootContext=null):array
    {
        return $this->consumeCopiedNoteDetails($viewer,$alertId,$noteId,$context,
            function(Builder $privateActual,CurrentAuthorizationReads $reads,User $current,HealthSafetyReadContext $same)use($viewer,$context,$alertId,$noteId,$consume):array{
                if($current!==$viewer || $same!==$context){throw new \LogicException('Use the exact original copied Note identity admission.');}
                $admitted=$this->consumeCopiedNoteOriginalIdentity($current,$alertId,$noteId,$reads,$same,
                    function(CurrentAuthorizationReads $sameReads,User $sameViewer,HealthSafetyReadContext $sameContext)use($current,$reads,$same,$alertId,$noteId,$consume):array{
                        if($sameReads!==$reads || $sameViewer!==$current || $sameContext!==$same){throw new \LogicException('Copied identity continuation changed its original tuple.');}
                        $result=$consume($reads,$current,$same);
                        if(!is_array($result)){throw new \LogicException('Copied identity consumers return detached arrays only.');}
                        \App\Services\Incidents\IncidentRoutingSettings::detached($result);return $result;
                    });
                abort_unless($admitted['available'],404);return $admitted['result'];
            },$originalRootContext);
    }

    public function consumeCopiedNoteDetails(User$viewer,int$alertId,int$noteId,HealthSafetyReadContext$context,callable$project,?Builder$originalRootContext=null):array
    {
        $this->assertCopiedAdmissionOpen($context,$noteId);
        $context->assertViewer($viewer);$unavailable=['available'=>false,'result'=>null];if($noteId<1){return$unavailable;}
        $parent=app(ControlRoomGovernanceReadService::class)->consumeOperationalAlertDetails($viewer,$alertId,$context,
            function(Builder$original,CurrentAuthorizationReads$reads,User$current,HealthSafetyReadContext$same)use($alertId,$noteId,$project,$originalRootContext,$unavailable):array{
                $activityKey='control_room_operator_notes:'.$noteId.':copiedActivity';
                $retained=self::$readProofs[$same][$activityKey]??null;
                if($retained!==null){
                    abort_unless($same->query(clone$retained)->exists(),404);
                    $prior=(clone$retained)->setEagerLoads([])->reorder()->select([])->selectRaw('1');$prior->toSql();
                    $original=$same->query((clone$original)->whereExists($prior->toBase()));
                }
                if($originalRootContext!==null){
                    if(!$originalRootContext->getModel()instanceof ControlRoomAlert){throw new \LogicException('Copied activity requires the actual original Alert context predicate.');}
                    $extra=(clone$originalRootContext)->whereKey($alertId)->setEagerLoads([])->reorder()->select([])->selectRaw('1');$extra->toSql();
                    $original=$same->query((clone$original)->whereExists($extra->toBase()));
                }
                if(!(clone$original)->exists()){return$unavailable;}
                $metadataQuery=(clone$original)->setEagerLoads([])->reorder()->select(['control_room_alerts.id','control_room_alerts.site_id','control_room_alerts.client_id']);
                $metadataQuery->selectRaw(app(\App\Services\UserSiteAccessService::class)->alertEffectiveSiteExpression($metadataQuery,$reads).' as _effective_site_id');
                $alert=$same->query($metadataQuery)->first();if($alert===null){return$unavailable;}
                $rows=$this->notesInContext($alert,$same,$original,$noteId);
                if(count($rows)!==1||$rows[0]['body_access']!=='readable'){return$unavailable;}
                $noteOriginal=self::$readProofs[$same]['control_room_operator_notes:'.$noteId.':body']??null;
                $receipt=self::$receiptProofs[$same][$noteId]??null;
                if($noteOriginal===null||$receipt===null){return$unavailable;} // Metadata or ordinary Note is never copied-source proof.
                $accepted=(clone$receipt[1])->where('meta->transition','note')->setEagerLoads([])->reorder()->select([])->selectRaw('1');$accepted->toSql();
                $frame=$same->query((clone$noteOriginal)->whereExists($accepted->toBase()));
                $root=(clone$original)->setEagerLoads([])->reorder()->select([])->selectRaw('1');$root->toSql();$frame->whereExists($root->toBase());
                if(!(clone$frame)->exists()){return$unavailable;}
                self::$readProofs??=new \WeakMap;$proofs=self::$readProofs[$same]??[];
                $proofs[$activityKey]??=$same->query(clone$frame);self::$readProofs[$same]=$proofs;
                $first=(clone$proofs[$activityKey])->setEagerLoads([])->reorder()->select([])->selectRaw('1');$first->toSql();
                $frame=$same->query($frame->whereExists($first->toBase()));
                if(!(clone$frame)->exists()){return$unavailable;}$this->verifyReadProjections($same);
                $authorId=(int)($rows[0]['author']['id']??0);
                if($authorId<1){return$unavailable;}
                $frame=$this->copiedFinalFrame($frame,$alertId,$noteId,$authorId,$receipt,$current,$reads,$same);
                $result=$project(clone$frame,$reads,$current,$same);
                if(!is_array($result)){throw new \LogicException('Copied-note projectors must return detached arrays.');}
                \App\Services\Incidents\IncidentRoutingSettings::detached($result);$same->assertActive();
                $this->verifyReadProjections($same);
                return(clone$frame)->exists()?['available'=>true,'result'=>$result]:$unavailable;
            });
        return$parent['available']?$parent['result']:$unavailable;
    }

    private function purposeLabel(?string $purpose): string
    {
        return match ($purpose) {
            OperatorNote::PURPOSE_IMMEDIATE_CONTROLS => 'Immediate controls',
            OperatorNote::PURPOSE_ESCALATION_HANDOVER => 'Escalation or handover',
            default => 'General update',
        };
    }
    /** Recursive identity/structure proof. Never compiles SQL or invokes query callbacks/Expressions. */
    private function protectedQueryGraph(mixed $value, ?\SplObjectStorage $seen=null, ?\Illuminate\Database\Query\Builder $executionRoot=null, ?CurrentAuthorizationReads $reads=null):mixed
    {
        $executionRoot??=$seen===null && $value instanceof Builder?$value->getQuery():null;
        $seen??=new \SplObjectStorage;
        if(is_array($value)){foreach($value as$key=>$part){$value[$key]=$this->protectedQueryGraph($part,$seen,$executionRoot,$reads);}return$value;}
        if(!is_object($value)){return$value;}
        if($value instanceof \DateTimeInterface){return [get_class($value),spl_object_id($value),$value->format('Y-m-d\TH:i:s.uP'),$value->getTimezone()->getName()];}
        if($seen->contains($value)){return ['seen',get_class($value),spl_object_id($value)];}$seen->attach($value);
        if($value instanceof Builder){
            $properties=[];
            foreach(['afterQueryCallbacks','onCloneCallbacks','eagerLoad','scopes','removedScopes']as$name){$property=new \ReflectionProperty(Builder::class,$name);$properties[$name]=$this->protectedQueryGraph($property->getValue($value),$seen,$executionRoot,$reads);}
            return ['eloquent',get_class($value),spl_object_id($value),spl_object_id($value->getModel()),$value->getModel()->getTable(),$this->protectedQueryGraph($value->getQuery(),$seen,$executionRoot,$reads),$properties];
        }
        if($value instanceof \Illuminate\Database\Query\Builder){
            $properties=[];
            foreach(get_object_vars($value)as$name=>$part){
                // Row-lock annotations may be applied by the exact canonical current-read callbacks.
                // They remain on the actual graph; no SQL text, literal or source predicate is normalised.
                if(in_array($name,['lock','useWritePdo','connection','grammar','processor'],true)){continue;}
                if($name==='beforeQueryCallbacks'){if($value===$executionRoot){continue;}$part=array_values(array_filter($part,fn($callback)=>$reads===null || !$this->canonicalReadCallback($callback,$reads)));}
                $properties[$name]=$this->protectedQueryGraph($part,$seen,$executionRoot,$reads);
            }
            $after=new \ReflectionProperty(\Illuminate\Database\Query\Builder::class,'afterQueryCallbacks');
            $properties['protected_after_query_callbacks']=$this->protectedQueryGraph($after->getValue($value),$seen,$executionRoot,$reads);
            return ['query',get_class($value),spl_object_id($value),spl_object_id($value->getConnection()),spl_object_id($value->getGrammar()),spl_object_id($value->getProcessor()),$properties];
        }
        if($value instanceof \Illuminate\Database\Query\Expression){
            $properties=[];$reflection=new \ReflectionObject($value);
            foreach($reflection->getProperties()as$property){if($property->isStatic() || !$property->isInitialized($value)){continue;}$properties[$property->getDeclaringClass()->getName().'::'.$property->getName()]=$this->protectedQueryGraph($property->getValue($value),$seen,$executionRoot,$reads);}
            return ['expression',get_class($value),spl_object_id($value),$properties];
        }
        // Closures, live reads/context, grammars and services retain exact identity, never a caller label.
        return ['object',get_class($value),spl_object_id($value)];
    }

    private function canonicalReadCallback(mixed $callback,CurrentAuthorizationReads $reads):bool
    {
        if(!$callback instanceof \Closure){return false;}
        $function=new \ReflectionFunction($callback);$method=new \ReflectionMethod(CurrentAuthorizationReads::class,'query');
        return $function->getClosureThis()===$reads && $function->getClosureScopeClass()?->getName()===CurrentAuthorizationReads::class
            && $function->getFileName()===$method->getFileName() && $function->getStartLine()>=$method->getStartLine() && $function->getEndLine()<=$method->getEndLine();
    }

    /** Preserve one-shot hooks and distinguish witnessed consumption from deletion/replacement. */
    private function wrapProtectedQueryHooks(mixed $value,CurrentAuthorizationReads $reads,array &$hooks,?\SplObjectStorage $seen=null):void
    {
        // Only the actual execution root consumes before-query hooks. Nested clauses are compiled,
        // not executed, and their original hook identities are frozen by protectedQueryGraph.
        // Do not mutate nested nodes which may also belong to another original private witness.
        if($value instanceof Builder){$value=$value->getQuery();}
        if(!$value instanceof \Illuminate\Database\Query\Builder){return;}
        $states=[];$expected=[];$callbacks=[];
        foreach($value->beforeQueryCallbacks as$callback){
            if($this->canonicalReadCallback($callback,$reads)){$callbacks[]=$callback;continue;}
            $state=(object)['ran'=>false,'original'=>$callback,'replacement'=>null];$states[]=$state;
            $wrapper=function($compiled)use($callback,$state){$result=$callback($compiled);$state->ran=true;return$result;};
            $state->replacement=$wrapper;$callbacks[]=$wrapper;$expected[]=$wrapper;
        }
        $value->beforeQueryCallbacks=$callbacks;$hooks[]=['query'=>$value,'callbacks'=>$expected,'states'=>$states];
    }

    private function verifyProtectedQueryHooks(array $hooks,CurrentAuthorizationReads $reads):void
    {
        foreach($hooks as$entry){
            $actual=array_values(array_filter($entry['query']->beforeQueryCallbacks,fn($callback)=>!$this->canonicalReadCallback($callback,$reads)));
            if($actual===$entry['callbacks']){continue;}
            $consumed=$actual===[];
            foreach($entry['states']as$state){$consumed=$consumed && $state->ran;}
            if(!$consumed){throw new \LogicException('An original protected query hook was removed or replaced without witnessed execution.');}
        }
    }

}
