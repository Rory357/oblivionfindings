<?php

namespace App\Services\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\IncidentSavedCopy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Incident-owned saved-copy seam. Destination authority and prospective producer wiring remain mandatory. */
final class IncidentSavedCopyService
{
    private static ?\WeakMap $preparationBodyTickets=null;
    public const PERMISSIONS = ['incidents.viewAny', 'incidents.viewAssigned', 'healthSafety.viewAllSites', 'hr.cases.view'];
    public const ALERT_NOTES_TARGET = 'control_room_alert_notes';
    private const TARGETS = ['control_room_operator_note', 'control_room_handover_note', 'incident_notification', self::ALERT_NOTES_TARGET];
    private const FIELDS = ['description', 'immediate_action_taken'];
    private const TUPLE = ['site_id', 'client_id', 'shift_id', 'reported_by', 'source', 'service_context_id', 'respite_stay_id'];
    private const META = ['id', 'copy_uuid', 'source_version', 'incident_id', 'source_family', 'source_tuple',
        'target_family', 'target_key', 'field', 'actor_id', 'recorded_at', 'content_digest', 'audit_id'];
    private const ACTION = 'incidents.savedCopy.captured';

    public function __construct(private readonly IncidentAccessService $access, private readonly IncidentIndependenceIntakeService $independence) {}

    /** Server-only command after ordinary destination authorization, in the same physical transaction as the copy writer.
     * Text comes exclusively from the admitted source field. UUID is caller-allocated stable copy intent.
     */
    public function capture(HealthSafetyReadContext $context, int $incidentId, string $copyUuid, string $targetFamily, string $targetKey, string $field): array
    {
        $this->assertIntent($copyUuid, $targetFamily, $targetKey, $field);
        return $context->consumeSourceViewer(self::PERMISSIONS, function (User $current, CurrentAuthorizationReads $reads) use ($incidentId, $copyUuid, $targetFamily, $targetKey, $field): array {
            $source = $this->source($incidentId, $current, $reads, $targetFamily);
            $root = $reads->query(clone $source)->first(['id', ...self::TUPLE, $field]);
            abort_if($root === null || ! is_string($root->getRawOriginal($field)), 404);
            $tuple = $this->tuple($root); $text = $root->getRawOriginal($field);
            abort_unless($this->validTuple($tuple), 404);
            $identity = $reads->query(IncidentSavedCopy::query()->where('copy_uuid', $copyUuid))->first(self::META);
            if ($identity !== null) {
                if ($identity->incident_id !== $incidentId || $identity->actor_id !== (int) $current->id
                    || $identity->target_family !== $targetFamily || $identity->target_key !== $targetKey || $identity->field !== $field
                    || ! $this->same($identity->source_tuple, $tuple)) { throw new LogicException('Copy identity belongs to different accepted work.'); }
                $saved = $this->projectInReads($reads, $current, $copyUuid, $targetFamily, $targetKey, $field, $identity->source_version, $text);
                if (! $saved['available'] || $saved['copy']['text'] !== $text) { throw new LogicException('Copy intent changed; allocate a new original copy identity.'); }
                return $this->descriptor($identity, $saved['copy']);
            }
            // A removed/rebound canonical row cannot turn a retained original identity into new intent.
            // Discover claims by identity only, including reclassified or otherwise malformed audit claims.
            if ($reads->query(DB::table('audit_logs')->where('meta->copy_uuid', $copyUuid))->exists()) {
                throw new LogicException('Copy identity has retained original evidence and requires repair.');
            }
            $copy = new IncidentSavedCopy(['copy_uuid' => $copyUuid, 'source_version' => (string) Str::uuid(),
                'incident_id' => $incidentId, 'source_family' => 'client_incident', 'source_tuple' => $tuple,
                'target_family' => $targetFamily, 'target_key' => $targetKey, 'field' => $field, 'actor_id' => (int) $current->id,
                'snapshot_text' => $text, 'recorded_at' => now('UTC')->toImmutable()]);
            $copy->content_digest = $this->digest($copy); IncidentRoutingSettings::saved($copy);
            $meta = $this->auditMeta($copy); $request = clone request(); $request->setUserResolver(fn () => $current);
            AuditLogger::logOrFail(self::ACTION, $copy, $meta, $request);
            $auditIdentities = $reads->query(DB::table('audit_logs')->where('meta->copy_uuid', $copyUuid))->get(['id', 'action', 'auditable_type', 'auditable_id', 'user_id']);
            if ($auditIdentities->count() !== 1 || $auditIdentities->sole()->action !== self::ACTION || $auditIdentities->sole()->auditable_type !== $copy->getMorphClass()
                || (int) $auditIdentities->sole()->auditable_id !== (int) $copy->id || (int) $auditIdentities->sole()->user_id !== (int) $current->id) {
                throw new LogicException('Original copy requires its exact mandatory capture audit.');
            }
            $audit = $reads->query(AuditLog::query()->whereKey($auditIdentities->sole()->id)->where('action', self::ACTION)
                ->where('auditable_type', $copy->getMorphClass())->where('auditable_id', $copy->id)->where('user_id', $current->id))->first(['id', 'meta']);
            if ($audit === null || ! $this->same($audit->meta, $meta)) { throw new LogicException('Original copy requires its exact mandatory capture audit.'); }
            $copy->audit_id = (int) $audit->id; IncidentRoutingSettings::saved($copy);
            $result = $this->projectInReads($reads, $current, $copyUuid, $targetFamily, $targetKey, $field, $copy->source_version, $text);
            if (! $result['available'] || $result['copy']['text'] !== $text) { throw new LogicException('Original copy did not survive its source/audit acceptance.'); }
            return $this->descriptor($copy, $result['copy']);
        });
    }

    /** Read-only, safely shaped historical field copy or explicit unavailability; never today's replacement text.
     * The destination must independently admit its own record/recipient and exact retained origin before calling.
     */
    public function project(HealthSafetyReadContext $context, mixed $copyUuid, mixed $targetFamily, mixed $targetKey, mixed $field, mixed $sourceVersion, mixed $savedText): array
    {
        return $context->consumeSourceViewer(self::PERMISSIONS, fn ($current, $reads) =>
            $this->projectInReads($reads, $current, $copyUuid, $targetFamily, $targetKey, $field, $sourceVersion, $savedText));
    }


    /**
     * Server-only destination materialisation. Origin is metadata, never copied prose.
     * Caller owns destination admission and full-byte target correlation; no composite is certified.
     * Callback receives (detached field copy, same active reads, exact protected copy Builder).
     */
    public function consumeProjectedCopy(HealthSafetyReadContext $context, array $origin, callable $materialize): array
    {
        return $context->consumeSourceViewer(self::PERMISSIONS, function (User $current, CurrentAuthorizationReads $reads) use ($context, $origin, $materialize): array {
            $unavailable = ['available' => false, 'result' => null];
            $keys = ['copy_uuid', 'source_family', 'source_version', 'target_family', 'target_key', 'field'];
            if (count($origin) !== count($keys) || array_diff($keys, array_keys($origin)) !== []
                || ($origin['source_family'] ?? null) !== 'client_incident') { return $unavailable; }
            $proof = $this->proofInReads($reads, $current, $origin['copy_uuid'], $origin['target_family'],
                $origin['target_key'], $origin['field'], $origin['source_version'], null, true, $context);
            if ($proof === null) { return $unavailable; }
            $result = $materialize($proof['copy'], $reads, clone $proof['predicate']);
            if (! is_array($result)) { throw new LogicException('Copy materializers must return detached arrays.'); }
            IncidentRoutingSettings::detached($result);
            $reads->assertActive();
            // Reuse the exact proof-used source, authority, metadata, audit, UUID and full field bytes.
            // Rebuilding a new baseline after callback mutation could falsely authenticate changed work.
            if (! $reads->query(clone $proof['predicate'])->exists()) { return $unavailable; }
            return ['available' => true, 'result' => $result];
        });
    }

    private function projectInReads(CurrentAuthorizationReads $reads, User $current, mixed $copyUuid, mixed $targetFamily, mixed $targetKey, mixed $field, mixed $sourceVersion, mixed $savedText): array
    {
        if (! is_string($savedText)) { return ['available' => false, 'copy' => null]; }
        $proof = $this->proofInReads($reads, $current, $copyUuid, $targetFamily, $targetKey, $field, $sourceVersion, $savedText);
        return $proof === null ? ['available' => false, 'copy' => null] : ['available' => true, 'copy' => $proof['copy']];
    }

    private function proofInReads(CurrentAuthorizationReads $reads, User $current, mixed $copyUuid, mixed $targetFamily, mixed $targetKey, mixed $field, mixed $sourceVersion, mixed $savedText = null, bool $stableAuthorization = false, ?HealthSafetyReadContext $materializationContext = null): ?array
    {
        $reads->assertActive();
        if (! $this->validIntent($copyUuid, $targetFamily, $targetKey, $field) || ! $this->uuid($sourceVersion) || ($savedText !== null && ! is_string($savedText))) { return null; }
        $identity = $reads->query(IncidentSavedCopy::query()->where('copy_uuid', $copyUuid)->where('source_version', $sourceVersion)
            ->where('source_family', 'client_incident')->where('target_family', $targetFamily)->where('target_key', $targetKey)->where('field', $field))
            ->select(self::META)->selectRaw($this->metadataFingerprint().' as metadata_fp')->first();
        if ($identity === null || $identity->audit_id === null || $identity->actor_id < 1 || ! $this->validTuple($identity->source_tuple)) { return null; }
        $source = $this->source($identity->incident_id, $current, $reads, $targetFamily);
        if ($stableAuthorization) {
            $source = $this->authorizationGuard($source, $current, $reads, $identity);
            // Reconsume the same canonical policy after evidence capture; changed observations must satisfy BOTH snapshots.
            $source->whereIn('client_incidents.id', $this->source($identity->incident_id, $current, $reads, $targetFamily)->select('client_incidents.id'));
        }
        foreach ($identity->source_tuple as $column => $value) {
            $source = $value === null ? $source->whereNull('client_incidents.'.$column)
                : ($column === 'source' ? $source->whereRaw('BINARY `client_incidents`.`source` = ?', [$value]) : $source->where('client_incidents.'.$column, $value));
        }
        if (! (clone $source)->exists()) { return null; }
        $audits = $reads->query(AuditLog::query()->where('action', self::ACTION)
            ->where('auditable_type', $identity->getMorphClass())->where('auditable_id', $identity->id))
            ->select(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta'])
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('audit', 'audit_logs').' as proof_fp')
            ->when($stableAuthorization, fn ($query) => $query->whereExists((clone $source)->reorder()->select('client_incidents.id')->toBase()))->get();
        if ($audits->count() !== 1) { return null; }
        $audit = $audits->sole();
        if ((int) $audit->id !== $identity->audit_id || (int) $audit->user_id !== $identity->actor_id
            || $audit->action !== self::ACTION || $audit->auditable_type !== $identity->getMorphClass() || (int) $audit->auditable_id !== $identity->id
            || ! $this->same($audit->meta, $this->auditMeta($identity))) { return null; }
        $claims = $reads->query(DB::table('audit_logs')->where('meta->copy_uuid', $copyUuid))->pluck('id');
        if ($claims->count() !== 1 || (int) $claims->sole() !== (int) $audit->id) { return null; }
        $actor = $reads->query(User::query()->whereKey($identity->actor_id))->exists();
        if (! $actor) { return null; }
        $query = $reads->query(IncidentSavedCopy::query()->whereKey($identity->id));
        // Exact metadata consumed above must still be true at the body query, including version, target and actor.
        foreach (self::META as $column) {
            if ($column === 'source_tuple' || $column === 'recorded_at') { continue; }
            $value = $identity->getRawOriginal($column);
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
        if ($savedText !== null) { $query->whereRaw('BINARY `snapshot_text` = ?', [$savedText]); }
        $query->whereIn('incident_id', (clone $source)->reorder()->select('client_incidents.id'))
            ->whereRaw($this->metadataFingerprint().' = ?', [$identity->metadata_fp])
            ->whereExists(fn ($guard) => $guard->selectRaw('1')->from('audit_logs as copy_audit')->where('copy_audit.id', $audit->id)
                ->whereRaw(IncidentIndependenceBindingSql::fingerprint('audit', 'copy_audit').' = ?', [$audit->proof_fp]))
            ->whereNotExists(fn ($guard) => $guard->selectRaw('1')->from('audit_logs as other_copy_audit')
                ->where(fn ($claims) => $claims->where('other_copy_audit.meta->copy_uuid', $copyUuid)
                    ->orWhere(fn ($typed) => $typed->where('other_copy_audit.action', self::ACTION)
                        ->where('other_copy_audit.auditable_type', $identity->getMorphClass())->where('other_copy_audit.auditable_id', $identity->id)))
                ->where('other_copy_audit.id', '!=', $audit->id))
            ->whereExists(fn ($guard) => $guard->selectRaw('1')->from('users as copy_actor')->where('copy_actor.id', $identity->actor_id));
        // This independently built protected source query remains the stable original.
        // The preparation owner's PRE is used only by a separate actual materialisation clone.
        $copy = $this->materializeProtectedCopy($query, $current, $reads, $materializationContext, $identity);
        if ($copy === null || ($savedText !== null && $copy->snapshot_text !== $savedText) || ! $this->same($copy->source_tuple, $identity->source_tuple) || $copy->recorded_at != $identity->recorded_at
            || ! hash_equals($copy->content_digest, $this->digest($copy))) { return null; }
        $reads->assertActive();
        return ['copy' => ['text' => $copy->snapshot_text, 'recorded_at' => $copy->recorded_at->format('Y-m-d\TH:i:s.u\Z')],
            'predicate' => $reads->query($query->whereRaw('BINARY `snapshot_text` = ?', [$copy->snapshot_text]))];
    }

    private function materializeProtectedCopy(Builder $protected, User $viewer, CurrentAuthorizationReads $reads,
        ?HealthSafetyReadContext $context, IncidentSavedCopy $identity): ?IncidentSavedCopy
    {
        if ($context === null) { return (clone $protected)->first(); }
        $owner = app(\App\Services\ControlRoom\ControlRoomOperatorNoteReadService::class);
        // Memory-only routing is not authority. A present mismatched/expired preparation frame throws.
        if (!$owner->requiresPreparationCopiedBodyAdmission($viewer,$reads,$context)) { return (clone $protected)->first(); }
        if ($identity->target_family !== 'control_room_operator_note'
            || !is_string($identity->target_key) || preg_match('/\A[1-9][0-9]*\z/D',$identity->target_key)!==1
            || strlen($identity->target_key)>strlen((string)PHP_INT_MAX)
            || (strlen($identity->target_key)===strlen((string)PHP_INT_MAX) && strcmp($identity->target_key,(string)PHP_INT_MAX)>0)) {
            throw new LogicException('Preparation materialisation requires the exact supported copied Note target.');
        }
        $noteId=(int)$identity->target_key;
        // Destination identity metadata only; the CR owner must independently match the private selected tuple/author.
        $note=$reads->query(\App\Models\ControlRoom\OperatorNote::query()->whereKey($noteId))->first(['id','alert_id','user_id']);
        if ($note===null || $note->alert_id===null || (int)$note->user_id!==$identity->actor_id) { return null; }
        $ticket=new \stdClass;self::$preparationBodyTickets??=new \WeakMap;
        $actual=$this->clonePreparationBodyQuery($protected)->applyScopes()->withoutGlobalScopes()->limit(1);
        if($actual->getQuery()->columns===null){$actual->select(['*']);}
        self::$preparationBodyTickets[$ticket]=(object)['tuple'=>[$viewer,(int)$note->alert_id,$noteId,$reads,$context],
            'actual'=>$actual,'active'=>true];
        try{$materialized=$this->executeRegisteredPreparationBody($ticket,$viewer,(int)$note->alert_id,$noteId,$reads,$context);}
        finally{self::$preparationBodyTickets[$ticket]->active=false;unset(self::$preparationBodyTickets[$ticket]);}
        if (!array_key_exists('row',$materialized) || ($materialized['row']!==null && !is_array($materialized['row']))) { throw new LogicException('Copied-body admission must return the actual detached physical row.'); }
        return $materialized['row']===null?null:(new IncidentSavedCopy)->setRawAttributes($materialized['row'],true);
    }

    /** Internal opaque-ticket transport. There is no public registration, Builder input, or caller-supplied executor. */
    public function executeRegisteredPreparationBody(object $ticket,User $viewer,int $alertId,int $noteId,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):array
    {
        $actual=$this->registeredPreparationBodyQuery($ticket,$viewer,$alertId,$noteId,$reads,$context);
        app(\App\Services\ControlRoom\ControlRoomOperatorNoteReadService::class)->decorateRegisteredPreparationBody($ticket,self::class,$viewer,$alertId,$noteId,$reads,$context);
        if(!$actual->getQuery() instanceof \App\Services\ControlRoom\ControlRoomOriginalReadOuterQuery){throw new LogicException('The canonical preparation owner must guard the registered actual source query.');}
        $carrier=$actual->getQuery();
        $this->sealPreparationActualQuery($actual,fn()=>$this->registeredPreparationBodyQuery($ticket,$viewer,$alertId,$noteId,$reads,$context),$reads);
        $carrier=$actual->getQuery();
        if($this->registeredPreparationBodyQuery($ticket,$viewer,$alertId,$noteId,$reads,$context)!==$actual){throw new LogicException('The original registered source query changed.');}
        $row=$actual->first();
        if($actual->getQuery()!==$carrier){throw new LogicException('The registered source execution carrier was replaced.');}
        $this->registeredPreparationBodyQuery($ticket,$viewer,$alertId,$noteId,$reads,$context);
        return ['row'=>$row?->getRawOriginal()];
    }

    /** Canonical CR decorator lookup only; a caller cannot mint a ticket or substitute its own same-target query. */
    public function registeredPreparationBodyQuery(object $ticket,User $viewer,int $alertId,int $noteId,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):Builder
    {
        $context->assertViewer($viewer);$reads->assertActive();
        $entry=self::$preparationBodyTickets[$ticket]??null;
        if($entry===null || !$entry->active || $entry->tuple!==[$viewer,$alertId,$noteId,$reads,$context]){throw new LogicException('Use the exact active privately registered saved-copy body ticket.');}
        return $entry->actual;
    }

    /** Independent actual query nodes; source guards/callback identities remain intact, never stripped. */
    private function clonePreparationBodyQuery(Builder $original):Builder
    {
        $seen=new \SplObjectStorage;
        $copy=function($value)use(&$copy,$seen){
            if($value instanceof Builder){
                if(get_class($value)!==Builder::class){throw new LogicException('Preparation source query requires its original plain source owner.');}
                if($seen->contains($value)){return $seen[$value];}$clone=clone$value;$seen[$value]=$clone;
                $query=$copy($value->getQuery());$clone->setModel(clone$value->getModel());$clone->setQuery($query);return$clone;
            }
            if($value instanceof QueryBuilder){
                if(!in_array(get_class($value),[QueryBuilder::class,\Illuminate\Database\Query\JoinClause::class],true)){throw new LogicException('Do not strip a protected preparation source execution carrier.');}
                if($seen->contains($value)){return$seen[$value];}$clone=clone$value;$seen[$value]=$clone;
                foreach(get_object_vars($value)as$key=>$part){if(in_array($key,['connection','grammar','processor'],true)){continue;}$clone->{$key}=$copy($part);}return$clone;
            }
            if(is_array($value)){foreach($value as$key=>$part){$value[$key]=$copy($part);}}return$value;
        };
        return$copy($original);
    }

    /** Independent execution identity/shape proof survives removal of public WHERE predicates. */
    private function sealPreparationActualQuery(Builder $actual,callable $registered,CurrentAuthorizationReads $reads):void
    {
        $model=$actual->getModel();$connection=$actual->getConnection();$box=(object)['base'=>$actual->getQuery(),'graph'=>null,'hooks'=>[]];
        $verify=function()use($actual,$registered,$model,$connection,$box,$reads):void{
            if($registered()!==$actual || $actual->getModel()!==$model || $actual->getConnection()!==$connection || $actual->getQuery()!==$box->base){throw new LogicException('The private protected source query model or base changed.');}
            if($box->graph!==null){$this->verifyProtectedQueryHooks($box->hooks,$reads);if($this->protectedQueryGraph($actual,null,null,$reads)!==$box->graph){throw new LogicException('The private protected source graph, target or execution hooks changed.');}}
        };
        \App\Services\ControlRoom\ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$reads);$box->base=$actual->getQuery();
        $this->wrapProtectedQueryHooks($actual,$reads,$box->hooks);$box->graph=$this->protectedQueryGraph($actual,null,null,$reads);
    }

    private function source(int $id, User $current, CurrentAuthorizationReads $reads, string $target): Builder
    {
        return $this->independence->applySourceScopeInReads($this->access->apply(ClientIncident::query()->whereKey($id), $current, $reads),
            $current, $reads, $target === 'incident_notification' ? 'notification' : 'read');
    }


    /** Equality only: no permission evaluation, new grant or global/cross-callback whitelist. */
    private function authorizationGuard(Builder $source, User $current, CurrentAuthorizationReads $reads, IncidentSavedCopy $identity): Builder
    {
        $reads->assertActive();
        if (! $current->relationLoaded('roles') || ! $current->relationLoaded('permissionOverrides')
            || ! $current->roles->every(fn ($role) => $role->relationLoaded('permissions'))) {
            throw new LogicException('Copy materialisation requires the prepared canonical authorization evidence.');
        }
        $guard = clone $source;
        $this->expectedSet($guard, DB::table('users')->where('id', $current->id),
            [array_intersect_key($current->getRawOriginal(), array_flip(['id', 'role', 'approved_at', 'external_clinical_account']))], ['id']);
        $definitions = $reads->query(DB::table('permissions')->whereIn('key', self::PERMISSIONS))->get(['id', 'key'])
            ->map(fn ($row) => (array) $row)->all();
        $this->expectedSet($guard, DB::table('permissions')->whereIn('key', self::PERMISSIONS), $definitions, ['id']);
        $permissionIds = array_map(fn ($row) => (int) $row['id'], $definitions);
        $roles = []; $assignments = []; $grants = []; $overrides = []; $usedDefinitions = [];
        foreach ($current->roles as $role) {
            $roles[] = ['id' => (int) $role->id, 'name' => $role->getRawOriginal('name')];
            $assignments[] = ['role_id' => (int) $role->id, 'user_id' => (int) $current->id];
            foreach ($role->permissions as $permission) {
                if (! in_array($permission->key, self::PERMISSIONS, true)) { continue; }
                $usedDefinitions[(int) $permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
                $grants[] = ['role_id' => (int) $role->id, 'permission_id' => (int) $permission->id];
            }
        }
        foreach ($current->permissionOverrides as $permission) {
            if (! in_array($permission->key, self::PERMISSIONS, true)) { continue; }
            $usedDefinitions[(int) $permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
            $overrides[] = ['permission_id' => (int) $permission->id, 'user_id' => (int) $current->id,
                'allowed' => (int) (bool) $permission->pivot->allowed];
        }
        // A definition renamed/deleted after actor preparation cannot become a new snapshot baseline.
        foreach ($usedDefinitions as $definition) {
            $guard->whereExists($this->exactRow(DB::table('permissions')->where('id', $definition['id']), $definition)->selectRaw('1'));
        }
        $roleIds = array_map(fn ($role) => $role['id'], $roles);
        $permissionIds = array_values(array_unique([...$permissionIds, ...array_keys($usedDefinitions)]));
        $this->expectedSet($guard, DB::table('role_user')->where('user_id', $current->id), $assignments, ['role_id', 'user_id']);
        $this->expectedSet($guard, DB::table('roles')->whereIn('id', $roleIds), $roles, ['id']);
        $this->expectedSet($guard, DB::table('role_permission')->whereIn('role_id', $roleIds)->whereIn('permission_id', $permissionIds), $grants, ['role_id', 'permission_id']);
        $this->expectedSet($guard, DB::table('permission_user')->where('user_id', $current->id)->whereIn('permission_id', $permissionIds), $overrides, ['permission_id', 'user_id']);
        $profileScope = DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at');
        $profileColumns = ['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at'];
        $profiles = $reads->query(clone $profileScope)->get($profileColumns)->map(fn ($row) => (array) $row)->all();
        $this->expectedSet($guard, $profileScope, $profiles, ['id']);
        $siteScope = DB::table('sites')->where('id', $identity->source_tuple['site_id']);
        $sites = $reads->query(clone $siteScope)->get(['id', 'is_active', 'archived', 'archived_at'])->map(fn ($row) => (array) $row)->all();
        $this->expectedSet($guard, $siteScope, $sites, ['id']);
        // Relevant assigned-Client audience only. Full ordinary source SQL still decides whether it is required.
        $membershipScope = DB::table('client_user')->where('user_id', $current->id)->where('client_id', $identity->source_tuple['client_id']);
        $memberships = $reads->query(clone $membershipScope)->get(['id', 'client_id', 'user_id'])->map(fn ($row) => (array) $row)->all();
        $this->expectedSet($guard, $membershipScope, $memberships, ['id']);
        $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')); $day = now($timezone)->toDateString();
        // Nested EXISTS compilation does not execute an embedded Builder's beforeQuery callbacks.
        // The expression keeps the ORIGINAL read lifetime, even if another viewer wraps it in new reads.
        $guard->whereRaw(new class($reads, $timezone, $day) extends Expression {
            public function __construct(private readonly CurrentAuthorizationReads $originalReads, private readonly string $timezone, private readonly string $day)
            {
                parent::__construct('1 = 1');
            }

            public function getValue(Grammar $grammar)
            {
                $this->originalReads->assertActive();
                if ((string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')) !== $this->timezone || now($this->timezone)->toDateString() !== $this->day) {
                    throw new LogicException('Copy authorization date evidence changed during materialisation.');
                }
                return parent::getValue($grammar);
            }
        });
        return $reads->query($guard);
    }

    /** Bind every observed value and require the same complete scoped membership, including honest absence. */
    private function expectedSet(Builder $guard, QueryBuilder $selector, array $rows, array $identity): void
    {
        foreach ($rows as $row) { $guard->whereExists($this->exactRow(clone $selector, $row)->selectRaw('1')); }
        $extra = clone $selector;
        if ($rows !== []) {
            $extra->whereNot(function (QueryBuilder $known) use ($rows, $identity): void {
                foreach ($rows as $row) {
                    $known->orWhere(function (QueryBuilder $entry) use ($row, $identity): void {
                        $this->exactRow($entry, array_intersect_key($row, array_flip($identity)));
                    });
                }
            });
        }
        $guard->whereNotExists($extra->selectRaw('1'));
    }

    private function exactRow(QueryBuilder $query, array $values): QueryBuilder
    {
        foreach ($values as $column => $value) {
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            elseif ($value instanceof \DateTimeInterface) { $query->where($column, $value); }
            else { $query->where($column, $value); }
        }
        return $query;
    }

    private function tuple(ClientIncident $root): array
    {
        $tuple = [];
        foreach (self::TUPLE as $key) { $value = $root->getRawOriginal($key); $tuple[$key] = $value === null ? null : ($key === 'source' ? $value : (int) $value); }
        return $tuple;
    }

    private function validTuple(mixed $tuple): bool
    {
        if (! is_array($tuple) || count($tuple) !== count(self::TUPLE) || array_diff(self::TUPLE, array_keys($tuple)) !== []) { return false; }
        foreach ($tuple as $key => $value) {
            if ($key === 'source') { if ($value !== null && ! is_string($value)) { return false; } }
            elseif ($value !== null && (! is_int($value) || $value < 1)) { return false; }
        }
        // Missing original occurrence context is unavailable, not inferred from today's Client/Shift/review.
        return is_int($tuple['site_id']) && $tuple['reported_by'] !== null;
    }

    private function auditMeta(IncidentSavedCopy $copy): array
    {
        return ['copy_uuid' => $copy->copy_uuid, 'source_version' => $copy->source_version, 'content_digest' => $copy->content_digest,
            'actor_id' => $copy->actor_id];
    }

    private function digest(IncidentSavedCopy $copy): string
    {
        return IncidentIndependenceValues::digest(['copy_uuid' => $copy->copy_uuid, 'source_version' => $copy->source_version,
            'incident_id' => $copy->incident_id, 'source_family' => $copy->source_family, 'source_tuple' => $copy->source_tuple,
            'target_family' => $copy->target_family, 'target_key' => $copy->target_key, 'field' => $copy->field,
            'actor_id' => $copy->actor_id, 'text' => $copy->snapshot_text, 'recorded_at' => $copy->recorded_at->format('Y-m-d\TH:i:s.u\Z')]);
    }

    private function descriptor(IncidentSavedCopy $copy, array $saved): array
    {
        return ['copy_uuid' => $copy->copy_uuid, 'source_family' => 'client_incident', 'source_version' => $copy->source_version,
            'target_family' => $copy->target_family, 'target_key' => $copy->target_key, 'field' => $copy->field, 'copy' => $saved];
    }

    private function same(mixed $left, mixed $right): bool
    {
        return IncidentIndependenceValues::digest($left) === IncidentIndependenceValues::digest($right);
    }

    private function metadataFingerprint(): string
    {
        $parts = array_map(fn ($column) => "'".$column."', `incident_saved_copies`.`".$column.'`', self::META);
        return 'SHA2(CAST(JSON_OBJECT('.implode(', ', $parts).') AS CHAR CHARACTER SET utf8mb4), 256)';
    }

    private function assertIntent(mixed $uuid, mixed $family, mixed $key, mixed $field): void
    {
        if (! $this->validIntent($uuid, $family, $key, $field)) { throw new LogicException('Unsupported incident original-copy intent.'); }
    }

    private function validIntent(mixed $uuid, mixed $family, mixed $key, mixed $field): bool
    {
        return ($family !== self::ALERT_NOTES_TARGET || (is_string($key) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $key) === 1
            && (strlen($key) < strlen((string) PHP_INT_MAX) || strlen($key) === strlen((string) PHP_INT_MAX) && strcmp($key, (string) PHP_INT_MAX) <= 0)))
            && $this->uuid($uuid) && is_string($family) && in_array($family, self::TARGETS, true)
            && is_string($field) && in_array($field, self::FIELDS, true) && is_string($key)
            && (preg_match('/\A[1-9][0-9]{0,18}\z/D', $key) === 1 || $this->uuid($key));
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1;
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
            $state=(object)['ran'=>false];$states[]=$state;
            $wrapper=function($compiled)use($callback,$state){$result=$callback($compiled);$state->ran=true;return$result;};
            $callbacks[]=$wrapper;$expected[]=$wrapper;
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
