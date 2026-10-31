<?php

namespace App\Services\ControlRoom;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder as IlluminateDatabaseEloquentBuilder;
use Illuminate\Database\Eloquent\Model as IlluminateDatabaseEloquentModel;
use App\Services\Incidents\IncidentRoutingSettings as AppServicesIncidentsIncidentRoutingSettings;
use LogicException;
use WeakMap;

/** Request-local canonical source context. Never serialize the raw models. */
final class ControlRoomGovernanceReadService
{
    public const PERMISSIONS = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
        ...InjuryAccessService::PERMISSIONS, 'controlRoom.alerts.manage', 'controlRoom.tasks.view', 'controlRoom.tasks.manage', 'reports.viewAny', 'controlRoom.alerts.assign', 'controlRoom.alerts.escalate',
        'controlRoom.alerts.create', 'controlRoom.handovers.override', 'compliance.view',
        'securityDevices.devices.view', 'securityDevices.devices.viewAllSites', 'securityDevices.devices.viewUnassigned',
        'staff.viewAny', 'fleet.viewAny', 'fleet.manage', 'fleet.bookings.approve', 'assets.viewAny', 'assets.viewAssigned',
        'it.view', 'it.manage', 'it.viewSensitive', 'it.organisationWide', 'it.request'];

    private const INCIDENT_METADATA_COLUMNS = ['id','reference_number','client_id','site_id','control_room_alert_id','hs_event_id','type','status',
        'created_at','submitted_at','reviewed_at','investigation_started_at','investigation_completed_at','reopened_at','closed_at','shift_id'];

    private const ALERT_METADATA_COLUMNS = [
            'id', 'reference_number', 'source', 'alert_type', 'severity', 'status',
            'asset_id', 'fleet_signal_id', 'origin_signal_id', 'device_id', 'queue_id', 'playbook_run_id', 'site_id', 'client_id',
            'triggered_at', 'acknowledged_at', 'acknowledged_by_user_id', 'resolved_at', 'resolved_by_user_id',
            'closed_at', 'closed_by_user_id', 'escalated_at', 'escalated_by_user_id', 'escalation_level',
            'assigned_to_user_id', 'assigned_at', 'assigned_by_user_id', 'created_by_user_id',
            'priority', 'due_at', 'category', 'resolution_code', 'time_spent_minutes', 'watchers_count',
            'snoozed_until', 'snoozed_by_user_id', 'created_at', 'updated_at',
        ];

    private function metadataColumns(): array
    {
        return self::ALERT_METADATA_COLUMNS;
    }

    private static ?\WeakMap $readTokens = null;

    private static ?\WeakMap $recordSeals = null;

    /** Original admitted event predicates are consumed only inside their active callback. */
    private static ?\WeakMap $eventDetailReads = null;

    private static ?\WeakMap $alertDetailReads = null;
    private static ?\WeakMap $sourceDetailReads = null;

    /** Callback-local, opt-in CR final read frame; never a grant or persisted proof. */
    private static ?WeakMap $controlRoomFinalReadFrames = null;
    private static ?\WeakMap $incidentMetadataReads = null;
    private static ?\WeakMap $incidentDetailReads = null;

    public function __construct(
        private readonly IncidentJourneyService $journeys,
        private readonly IncidentAccessService $incidents,
        private readonly HsEventAccessService $healthSafety,
        private readonly ControlRoomSourceContentService $sourceContent,
        private readonly ControlRoomAlertAccessService $alertsAccess,
        private readonly UserSiteAccessService $sites,
    ) {}

    /** Module admission is independent of source authority; the outer callback returns detached values only. */
    public function withCurrentViewer(User $viewer, callable $operation, ?HealthSafetyReadContext $context = null): mixed
    {
        if ($context === null) {
            return HealthSafetyReadContext::within($viewer, self::PERMISSIONS,
                fn (HealthSafetyReadContext $current) => $this->detachedResult($this->withCurrentViewer($viewer, $operation, $current)));
        }
        $context->assertViewer($viewer);

        return $context->consumeSourceViewer(self::PERMISSIONS,
            function (User $current, CurrentAuthorizationReads $reads) use ($operation, $context) {
                abort_unless($this->alertsAccess->canList($current), 403);

                // Equality of the original prepared actor, never a new admission or an H&S grant.
                $original = $context->originalSourceAuthorityQuery(self::PERMISSIONS, []);
                abort_unless((clone $original)->exists(), 403);
                $result = $operation($current, $context, $reads);
                abort_unless((clone $original)->exists(), 403);

                return $result;
            });
    }

    /**
     * @param  iterable<ControlRoomAlert>  $alerts
     * @return Collection<int, array<string, mixed>> Detached source facts; access flags never authorize a later command.
     */
    public function forAlerts(iterable $alerts, User $viewer, ?HealthSafetyReadContext $context = null): Collection
    {
        if ($context === null) {
            return collect($this->withCurrentViewer($viewer,
                fn (User $current, HealthSafetyReadContext $reads): array => $this->forAlerts($alerts, $current, $reads)->all()));
        }
        $context->assertViewer($viewer);

        return $this->withCurrentViewer($viewer, function (User $current, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads) use ($alerts): Collection {
            $alerts = new EloquentCollection(collect($alerts)->values()->all());
            if ($alerts->isEmpty()) {
                return collect();
            }
            $alerts = $this->currentAlerts($alerts, $current, $context);
            $alerts->loadMissing([
                'site:id,name', 'client:id,first_name,last_name,site_id', 'client.site:id,name',
                'asset.client.site', 'fleetSignal.asset.client.site', 'device',
            ]);
            $records = $this->journeys->governanceRecordsForAlerts($alerts, $reads);
            $incidentIds = $records->pluck('incident.id')->filter()->unique()->values();
            $eventIds = $records->pluck('hs_event.id')->filter()->unique()->values();
            $incidentMetadata = $records->pluck('incident')->filter()->unique('id');
            $eventMetadata = $records->pluck('hs_event')->filter()->unique('id');
            $incidentDetails = collect();
            $incidentQueries = [];
            $eventDetails = collect();
            if ($incidentIds->isNotEmpty()) {
                $incidentRead = $context->applyIncidentSourceScope($this->incidents
                    ->apply(ClientIncident::query()->whereIn('id', $incidentIds), $current, $reads));
                $incidentRead = $this->originalMetadataScope($incidentRead, $incidentMetadata, $context);
                // Readable flags come from the actual source-guarded body statement, never a prior ID whitelist.
                $incidentDetails = $context->query(clone $incidentRead)
                    ->get(['client_incidents.id', 'client_incidents.severity', 'client_incidents.title', 'client_incidents.occurred_at'])->keyBy('id');
                $incidentDetails = $incidentDetails->filter(function(ClientIncident $body)use($incidentRead,&$incidentQueries):bool{
                    $original=$this->originalBodyScope(clone$incidentRead,$body);if(!$original->exists()){return false;}
                    $incidentQueries[(int)$body->id]=$original;return true;
                });
            }
            self::$incidentDetailReads ??= new \WeakMap;$incidentFrames=self::$incidentDetailReads[$context]??[];
            foreach($incidentQueries as$id=>$original){$incidentFrames[$id]??=clone$original;}
            self::$incidentDetailReads[$context]=$incidentFrames;
            if ($eventIds->isNotEmpty()) {
                $eventCandidates = $context->applyEventSiteScope(HsEvent::query()->whereIn('id', $eventIds),
                    UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
                $eventRead = $this->healthSafety->applyReadableScope(clone $eventCandidates, $current, $context, $eventCandidates);
                $eventRead = $this->originalMetadataScope($eventRead, $eventMetadata, $context);
                $eventDetails = $context->query(clone $eventRead)->get(['hs_events.id', 'hs_events.severity', 'hs_events.owner_user_id',
                    'hs_events.accepted_by_user_id', 'hs_events.accepted_at'])->keyBy('id');
                self::$eventDetailReads ??= new WeakMap;
                $frames = self::$eventDetailReads[$context] ?? [];
                foreach ($eventDetails as $event) {
                    $originalBody = $this->originalBodyScope(clone $eventRead, $event);
                    if (! $originalBody->exists()) { $eventDetails->forget($event->id); continue; }
                    $frames[(int) $event->id] ??= clone $originalBody;
                    // People are source details too: each actual child read intersects the original admitted event.
                    $event->loadMissing([
                        'owner' => function ($owner) use ($context, $originalBody): void {
                            $parent = (clone $originalBody)->select('hs_events.id')->whereColumn('hs_events.owner_user_id', 'users.id');
                            $parent->toSql();
                            $context->query($owner->getQuery()->select(['users.id', 'users.name'])->whereExists($parent->toBase()));
                        },
                        'acceptedBy' => function ($actor) use ($context, $originalBody): void {
                            $parent = (clone $originalBody)->select('hs_events.id')->whereColumn('hs_events.accepted_by_user_id', 'users.id');
                            $parent->toSql();
                            $context->query($actor->getQuery()->select(['users.id', 'users.name'])->whereExists($parent->toBase()));
                        },
                    ]);
                    if (! $originalBody->exists()) { $eventDetails->forget($event->id); unset($frames[(int) $event->id]); }
                }
                self::$eventDetailReads[$context] = $frames;
            }
            $readableIncidentIds = $incidentDetails->keys()->flip();
            $readableEventIds = $eventDetails->keys()->flip();

            return $records->map(function (array $record, int $id) use ($alerts, $current, $context, $readableIncidentIds, $readableEventIds, $incidentDetails, $eventDetails, $incidentQueries): array {
                $alert = $alerts->find($id);
                // Independent injury-source integration is not mounted here.
                $injury = ['access' => $this->isInjuryAlert($alert) ? 'unavailable' : 'absent', 'source' => null];
                $restraint = $this->sourceContent->restraintForAlert($alert, $current, $context);
                if ($this->isInjuryAlert($alert) && $injury['access'] === 'absent') {
                    $injury = ['access' => 'unavailable', 'source' => null];
                }
                $unavailable = ($record['integrity'] ?? null) === 'unavailable';
                $incidentAccess = $unavailable ? 'unavailable' : ($record['incident'] === null ? 'absent'
                    : ($readableIncidentIds->has($record['incident']->id) ? 'readable' : 'restricted'));
                $eventAccess = $unavailable ? 'unavailable' : ($record['hs_event'] === null ? 'absent'
                    : ($readableEventIds->has($record['hs_event']->id) ? 'readable' : 'restricted'));

                $projected = [
                    'alert_id' => $id,
                    'incident' => $record['incident']?->only(['id', 'reference_number', 'status', 'client_id', 'site_id', 'hs_event_id']),
                    'hs_event' => $record['hs_event']?->only(['id', 'reference_number', 'status', 'handover_status',
                        'source_type', 'source_id', 'client_id', 'site_id', 'staff_id', 'event_category']),
                    'incident_access' => $incidentAccess, 'health_safety_access' => $eventAccess,
                    'incident_detail' => $incidentAccess === 'readable' ? [
                        'severity' => $incidentDetails->get($record['incident']->id)->severity, 'title' => $incidentDetails->get($record['incident']->id)->title,
                        'occurred_at' => $incidentDetails->get($record['incident']->id)->occurred_at?->toIso8601String(),
                    ] : null,
                    'hs_detail' => $eventAccess === 'readable' ? [
                        'severity' => $eventDetails->get($record['hs_event']->id)->severity,
                        'owner' => $eventDetails->get($record['hs_event']->id)->owner?->only(['id', 'name']),
                        'accepted_by' => $eventDetails->get($record['hs_event']->id)->acceptedBy?->only(['id', 'name']),
                        'accepted_at' => $eventDetails->get($record['hs_event']->id)->accepted_at?->toIso8601String(),
                    ] : null,
                    'integrity' => $unavailable ? 'unavailable' : 'available',
                    'injury_access' => $injury['access'], 'injury_source' => $injury['source'],
                    'workflow_changed_at' => $unavailable ? [] : $this->workflowTimestamps($record, $context),
                    '_read_context' => $this->readToken($context),
                ] + $restraint;
                self::$sourceDetailReads ??= new \WeakMap;$sourceReads=self::$sourceDetailReads[$context]??[];
                if(!isset($sourceReads[$id])){
                    $sourceReads[$id]=array_filter([
                        $record['incident']===null?null:($incidentQueries[(int)$record['incident']->id]??null),
                        $record['hs_event']===null?null:(self::$eventDetailReads[$context][(int)$record['hs_event']->id]??null),
                    ]);
                }
                self::$sourceDetailReads[$context]=$sourceReads;
                self::$recordSeals ??= new \WeakMap;
                $seals = self::$recordSeals[$context] ?? [];
                $seals[$id] = hash('sha256', serialize($projected));
                self::$recordSeals[$context] = $seals;
                return $projected;
            });
        }, $context);
    }

    /** Callback-only original event predicate; no query, context or proof is returned to its caller. */
    public function consumeReadableEventDetails(User $viewer, int $eventId, HealthSafetyReadContext $context, callable $project): array
    {
        return $this->withCurrentViewer($viewer, function (User $current, HealthSafetyReadContext $sameContext, CurrentAuthorizationReads $reads) use ($eventId, $project): array {
            $unavailable = ['available' => false, 'result' => null];
            $original = self::$eventDetailReads[$sameContext][$eventId] ?? null;
            if ($original === null || ! (clone $original)->exists()) { return $unavailable; }
            $result = $project(clone $original, $reads, $current, $sameContext);
            if (! is_array($result)) { throw new LogicException('Event detail projectors must return detached arrays.'); }
            AppServicesIncidentsIncidentRoutingSettings::detached($result);
            $sameContext->assertActive();
            return (clone $original)->exists() ? ['available' => true, 'result' => $result] : $unavailable;
        }, $context);
    }


    /** Independent incident-page read: CR module/list admission is deliberately not required. */
    public function consumeReadableIncidentDetails(User$viewer,int$incidentId,HealthSafetyReadContext$context,callable$project,?IlluminateDatabaseEloquentBuilder$originalReadableIncident=null):array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::PERMISSIONS,function(User$current,CurrentAuthorizationReads$reads)use($incidentId,$context,$project,$originalReadableIncident):array{
            $unavailable=['available'=>false,'result'=>null];self::$incidentDetailReads??=new \WeakMap;
            $frames=self::$incidentDetailReads[$context]??[];$original=$frames[$incidentId]??null;
            if($original===null&&$originalReadableIncident!==null){
                if(!$originalReadableIncident->getModel()instanceof ClientIncident){throw new LogicException('Standalone source frame must be the canonical Incident query.');}
                $source=$context->applyIncidentSourceScope($this->incidents->apply((clone$originalReadableIncident)->setEagerLoads([])->whereKey($incidentId),$current,$reads));
                $actor=$context->originalSourceAuthorityQuery(self::PERMISSIONS,[]);$actor->toSql();
                $source=$context->query($source->whereExists($actor->selectRaw('1')->toBase()));
                // Caller source metadata/effective-Site evidence and actual Incident policy are BOTH on this body SQL.
                $body=(clone$source)->first();if($body===null){return$unavailable;}
                $original=$this->originalBodyScope(clone$source,$body);$frames[$incidentId]=$original;self::$incidentDetailReads[$context]=$frames;
            }
            if($originalReadableIncident!==null){
                if(!$originalReadableIncident->getModel()instanceof ClientIncident){throw new LogicException('Standalone source frame must be the canonical Incident query.');}
                if($original!==null){$constraint=(clone$originalReadableIncident)->setEagerLoads([])->whereKey($incidentId)->reorder()->select([])->selectRaw('1');$constraint->toSql();$original=$context->query((clone$original)->whereExists($constraint->toBase()));}
            }
            if($original===null||!(clone$original)->exists()){return$unavailable;}
            $result=$project(clone$original,$reads,$current,$context);
            if(!is_array($result)){throw new LogicException('Incident projectors must return detached arrays.');}
            \App\Services\Incidents\IncidentRoutingSettings::detached($result);$context->assertActive();
            return(clone$original)->exists()?['available'=>true,'result'=>$result]:$unavailable;
        });
    }

    /** Metadata is an original identity constraint, never a source body grant. */
    private function originalMetadataScope(IlluminateDatabaseEloquentBuilder $query, iterable $metadata, HealthSafetyReadContext $context): IlluminateDatabaseEloquentBuilder
    {
        $table = $query->getModel()->getTable();
        $query->where(function ($same) use ($metadata, $table, $context): void {
            foreach ($metadata as $row) {
                $same->orWhere(function ($identity) use ($row, $table, $context): void {
                    $attributes=$row->getRawOriginal();
                    if($row instanceof ClientIncident){
                        foreach([...self::INCIDENT_METADATA_COLUMNS,'_incident_source_ownership']as$required){if(!array_key_exists($required,$attributes)){$identity->whereRaw('1=0');return;}}
                        $attributes=array_intersect_key($attributes,array_flip([...self::INCIDENT_METADATA_COLUMNS,'_incident_source_ownership']));
                        self::$incidentMetadataReads??=new \WeakMap;$first=self::$incidentMetadataReads[$context]??[];
                        $first[(int)$row->id]??=$attributes;self::$incidentMetadataReads[$context]=$first;
                        $attributes=$first[(int)$row->id]; // Never rebaseline later source discovery in the same callback.
                    }
                    foreach ($attributes as $column => $value) {
                        if($column==='_incident_source_ownership'){
                            $expression=$context->consumeSourceViewer(self::PERMISSIONS,fn(User$current,CurrentAuthorizationReads$reads):string=>IncidentJourneyService::incidentReadOwnershipExpression($reads));
                            $identity->whereRaw('BINARY CAST(('.$expression.') AS CHAR) = BINARY ?',[$value]);continue;
                        }
                        $qualified = $table.'.'.$column;
                        if ($value === null) { $identity->whereNull($qualified); }
                        elseif (is_string($value)) { $identity->whereRaw('BINARY '.$identity->getQuery()->getGrammar()->wrap($qualified).' = BINARY ?', [$value]); }
                        else { $identity->where($qualified, $value); }
                    }
                });
            }
        });
        $actor = $context->originalSourceAuthorityQuery(self::PERMISSIONS, []);
        $actor->toSql();
        return $context->query($query->whereExists($actor->selectRaw('1')->toBase()));
    }

    /** Replay the admitted body fields through the same guarded source SQL; never capture a later baseline. */
    private function originalBodyScope(IlluminateDatabaseEloquentBuilder $query, IlluminateDatabaseEloquentModel $body): IlluminateDatabaseEloquentBuilder
    {
        $table = $body->getTable();
        foreach ($body->getRawOriginal() as $column => $value) {
            $qualified = $table.'.'.$column;
            if ($value === null) { $query->whereNull($qualified); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getQuery()->getGrammar()->wrap($qualified).' = BINARY ?', [$value]); }
            else { $query->where($qualified, $value); }
        }
        return $query;
    }

    public function isInjuryAlert(ControlRoomAlert $alert): bool
    {
        return $alert->alert_type === 'operations.workplace_injury'
            || array_key_exists('workplace_injury_id', is_array($alert->context) ? $alert->context : [])
            || array_key_exists('workplace_injury_id', (array) data_get($alert->context, 'normalized_data', []));
    }

    /** Internal current operational rows only. The outer read callback rejects a live model result. */
    public function currentAlerts(iterable $alerts, User $viewer, HealthSafetyReadContext $context): EloquentCollection
    {
        $viewer = $this->withCurrentViewer($viewer, fn (User $current): User => $current, $context);
        $ids = collect($alerts)->map(fn (ControlRoomAlert $alert): int => (int) $alert->id)->unique()->values();
        if ($ids->isEmpty()) { return new EloquentCollection; }
        // Supplied models carry identity only; read bodies again in the original callback.
        $fresh = $this->projectCurrentAlertQuery(ControlRoomAlert::query()->whereKey($ids), $viewer, $context)->get()->keyBy('id');
        abort_unless($fresh->count() === $ids->count(), 404);
        return new EloquentCollection($ids->map(fn (int $id) => $fresh->get($id))->all());
    }

    /**
     * Actual pre-hydration query contract for page/chunk/get consumers in the active original frame.
     * Caller may add supported filters; it must not select raw notes/context after this projection.
     * Classifiers report identified private content, never certify a canonical saved-copy origin.
     */
    public function projectCurrentAlertQuery(IlluminateDatabaseEloquentBuilder $query, User $viewer, HealthSafetyReadContext $context): IlluminateDatabaseEloquentBuilder
    {
        $context->assertViewer($viewer);
        if (! $query->getModel() instanceof ControlRoomAlert) { throw new LogicException('Alert projection requires the canonical Alert query.'); }
        $viewer = $this->withCurrentViewer($viewer, fn (User $current): User => $current, $context);
        $query = $context->consumeSourceViewer(self::PERMISSIONS, fn(User $current, CurrentAuthorizationReads $reads) => $this->alertsAccess->applyVisibleScope($query, $current, $reads));
        $query->select(array_map(fn (string $column): string => 'control_room_alerts.'.$column, $this->metadataColumns()))
            ->selectRaw('NULL as notes')->selectRaw($this->alertContextMetadataProjection().' as context')
            ->selectRaw($context->consumeSourceViewer(self::PERMISSIONS, fn(User $current, CurrentAuthorizationReads $reads) => $this->sites->alertEffectiveSiteExpression($query, $reads)).' as _effective_site_id')
            ->selectRaw($context->consumeSourceViewer(self::PERMISSIONS, fn(User $current, CurrentAuthorizationReads $reads) => $this->alertSourceOwnershipProjection($reads)).' as _source_ownership')
            ->selectRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256) as _context_fingerprint");
        $actor = $context->originalSourceAuthorityQuery(self::PERMISSIONS, []); $actor->toSql();
        $query = $context->query($query->whereExists($actor->selectRaw('1')->toBase()));
        return $query->afterQuery(function ($result) use ($viewer, $context): void {
            foreach ($result as $row) { if ($row instanceof ControlRoomAlert) { $this->rememberOperationalAlertFrame($row, $viewer, $context); } }
        });
    }



    /** Relational ownership identities only; capture fallback and corroboration before any body read. */
    private function alertSourceOwnershipProjection(?CurrentAuthorizationReads $reads = null): string
    {
        $client = "(SELECT JSON_OBJECT('id', cr_owner_client.id, 'site_id', cr_owner_client.site_id) FROM clients cr_owner_client WHERE cr_owner_client.id = control_room_alerts.client_id AND cr_owner_client.deleted_at IS NULL LIMIT 1)";
        $device = "(SELECT JSON_OBJECT('id', cr_owner_device.id, 'site_id', cr_owner_device.site_id, 'client_id', cr_owner_device.client_id, 'asset_id', cr_owner_device.asset_id, 'canonical_device_id', cr_owner_device.canonical_device_id) FROM control_room_devices cr_owner_device WHERE cr_owner_device.id = control_room_alerts.device_id AND cr_owner_device.deleted_at IS NULL LIMIT 1)";
        $asset = "(SELECT JSON_OBJECT('id', cr_owner_asset.id, 'site_id', cr_owner_asset.site_id, 'home_site_id', cr_owner_asset.home_site_id, 'client_id', cr_owner_asset.client_id, 'client_site_id', (SELECT site_id FROM clients WHERE id = cr_owner_asset.client_id AND deleted_at IS NULL LIMIT 1)) FROM assets cr_owner_asset WHERE cr_owner_asset.id = control_room_alerts.asset_id LIMIT 1)";
        $fleet = "(SELECT JSON_OBJECT('id', cr_owner_fleet.id, 'device_id', cr_owner_fleet.device_id, 'asset_id', cr_owner_fleet.asset_id, 'asset_ownership', (SELECT JSON_OBJECT('id', cr_fleet_asset.id, 'site_id', cr_fleet_asset.site_id, 'home_site_id', cr_fleet_asset.home_site_id, 'client_id', cr_fleet_asset.client_id, 'client_site_id', (SELECT site_id FROM clients WHERE id = cr_fleet_asset.client_id AND deleted_at IS NULL LIMIT 1)) FROM assets cr_fleet_asset WHERE cr_fleet_asset.id = cr_owner_fleet.asset_id LIMIT 1)) FROM fleet_signals cr_owner_fleet WHERE cr_owner_fleet.id = control_room_alerts.fleet_signal_id LIMIT 1)";
        if ($reads !== null) {
            $reads->assertActive();
            foreach (['client','device','asset','fleet'] as $name) { ${$name}=str_replace('LIMIT 1)', 'LIMIT 1 FOR SHARE NOWAIT)', ${$name}); }
        }
        return "JSON_OBJECT('client', $client, 'device', $device, 'asset', $asset, 'fleet_signal', $fleet)";
    }

    private function rememberOperationalAlertFrame(ControlRoomAlert $row, User $viewer, HealthSafetyReadContext $context): void
    {
        $attributes=$row->getRawOriginal();
        foreach([...self::ALERT_METADATA_COLUMNS,'notes','context','_effective_site_id','_source_ownership','_context_fingerprint'] as $required){
            if(!array_key_exists($required,$attributes)){return;}
        }
        if(!is_numeric($attributes['id'])||(int)$attributes['id']<1||$attributes['notes']!==null){return;}
        $metadata=is_string($attributes['context'])?json_decode($attributes['context'],true):$attributes['context'];
        $ownership=is_string($attributes['_source_ownership'])?json_decode($attributes['_source_ownership'],true):$attributes['_source_ownership'];
        if(!is_array($metadata)||!is_array($ownership)){return;}
        foreach(['client','device','asset','fleet_signal']as$key){if(!array_key_exists($key,$ownership)){return;}}
        self::$alertDetailReads ??= new \WeakMap;
        $frames = self::$alertDetailReads[$context] ?? [];
        if (isset($frames[(int) $row->id])) { return; }
        // List permission is not body permission. The callback frame retains actual READ SQL.
        $frame = $context->consumeSourceViewer(self::PERMISSIONS, fn(User $current, CurrentAuthorizationReads $reads) => $this->alertsAccess->applyReadableScope(ControlRoomAlert::query()->whereKey($row->id), $current, $reads));
        // Computed worklist aliases are presentation facts, never root-column proof.
        foreach ([...self::ALERT_METADATA_COLUMNS, 'notes', 'context', '_effective_site_id', '_source_ownership', '_context_fingerprint'] as $column) {
            $value = $attributes[$column];
            if ($column === 'notes') { continue; }
            if ($column === 'context' || $column === '_source_ownership') {
                $expression = $column === 'context' ? $this->alertContextMetadataProjection() : $context->consumeSourceViewer(self::PERMISSIONS, fn(User $current, CurrentAuthorizationReads $reads) => $this->alertSourceOwnershipProjection($reads));
                $frame->whereRaw('BINARY CAST(('.$expression.') AS CHAR) = BINARY ?', [$value]);
            } elseif ($column === '_context_fingerprint') {
                $frame->whereRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256) = ?",[$value]);
            } elseif ($column === '_effective_site_id') {
                $expression = $context->consumeSourceViewer(self::PERMISSIONS, fn(User $current, CurrentAuthorizationReads $reads) => $this->sites->alertEffectiveSiteExpression($frame, $reads));
                if ($value === null) { $frame->whereRaw($expression.' IS NULL'); }
                else { $frame->whereRaw($expression.' = ?', [$value]); }
            } elseif ($value === null) { $frame->whereNull('control_room_alerts.'.$column); }
            else { $frame->where('control_room_alerts.'.$column, $value); }
        }
        $actor = $context->originalSourceAuthorityQuery(self::PERMISSIONS, []); $actor->toSql();
        $frames[(int) $row->id] = $context->query($frame->whereExists($actor->selectRaw('1')->toBase()));
        self::$alertDetailReads[$context] = $frames;
    }

    /** Exact metadata only: no raw root context, activity prose, resolution reason or normalized payload. */
    private function alertContextMetadataProjection(): string
    {
        $raw = 'COALESCE(control_room_alerts.context, JSON_OBJECT())';
        $copy = "JSON_CONTAINS_PATH($raw, 'one', '$.saved_incident_copy', '$.saved_incident_copy_audit_id') = 1";
        $operator = "JSON_CONTAINS_PATH($raw, 'one', '$.root_note_origin') = 1";
        $notes = "JSON_CONTAINS_PATH($raw, 'one', '$.notes_provenance') = 1";
        $creator = "control_room_alerts.source = 'incident' OR JSON_UNQUOTE(JSON_EXTRACT($raw, '$.provenance.source')) = 'incident_journey' OR JSON_UNQUOTE(JSON_EXTRACT($raw, '$.incident_source_type')) = 'client_incident'";
        $resolver = "JSON_UNQUOTE(JSON_EXTRACT($raw, '$.resolution.source')) = 'incident_lifecycle_signal' OR JSON_UNQUOTE(JSON_EXTRACT($raw, '$.resolution_source')) = 'incident_lifecycle_signal' OR JSON_CONTAINS_PATH($raw, 'one', '$.incident_lifecycle_signal_id', '$.incident_lifecycle_actor_user_id', '$.resolution.incident_lifecycle_signal_id') = 1 OR (JSON_EXTRACT($raw, '$.resolution.source') IS NULL AND JSON_SEARCH($raw, 'one', 'incident_lifecycle_signal', NULL, '$.resolution_history[*].source') IS NOT NULL)";
        $history = "JSON_CONTAINS_PATH($raw, 'one', '$.incident_lifecycle_signal_id', '$.incident_lifecycle_idempotency_key', '$.operational_reopen_history', '$.activity_log[*].incident_id') = 1 OR JSON_SEARCH($raw, 'one', 'incident_lifecycle_signal', NULL, '$.resolution_history[*].source') IS NOT NULL OR ($resolver)";
        $flags = "JSON_OBJECT('copy_content_present', CASE WHEN ($copy) THEN TRUE ELSE FALSE END,
            'operator_origin_present', CASE WHEN ($operator) THEN TRUE ELSE FALSE END,
            'notes_provenance_present', CASE WHEN ($notes) THEN TRUE ELSE FALSE END,
            'source_history_present', CASE WHEN ($history) THEN TRUE ELSE FALSE END,
            'private_source_creator', CASE WHEN ($creator) THEN TRUE ELSE FALSE END,
            'private_source_resolver', CASE WHEN ($resolver) THEN TRUE ELSE FALSE END,
            'notes_present', CASE WHEN control_room_alerts.notes IS NOT NULL THEN TRUE ELSE FALSE END,
            'activity_history_present', JSON_CONTAINS_PATH($raw, 'one', '$.activity_log'),
            'resolution_history_present', JSON_CONTAINS_PATH($raw, 'one', '$.resolution_history'),
            'signal_payload_present', JSON_CONTAINS_PATH($raw, 'one', '$.signal_payload', '$.normalized_data.signal_payload'))";
        $root = ['site_id','client_id','incident_id','restraint_event_id','workplace_injury_id','incident_source_type','incident_source_id',
            'incident_lifecycle_signal_id','incident_lifecycle_actor_user_id','hs_event_id'];
        $normalized = ['site_id','client_id','asset_id','device_id','incident_id','restraint_event_id','workplace_injury_id','canonical_device_id',
            'condition_episode_key','availability_episode_key','controlled_drug','client_medication_id','medication_id','source_module'];
        $parts = array_map(fn ($key) => "'$key', JSON_EXTRACT($raw, '$.$key')", $root);
        $nested = array_map(fn ($key) => "'$key', JSON_EXTRACT($raw, '$.normalized_data.$key')", $normalized);
        $routing = array_map(fn ($key) => "'$key', JSON_EXTRACT($raw, '$.notification_routing.$key')",
            ['schema_version', 'state', 'generation', 'eligible_count', 'reason']);
        $routingProjection = "CASE WHEN JSON_EXTRACT($raw, '$.notification_routing.schema_version') = 1
            AND JSON_UNQUOTE(JSON_EXTRACT($raw, '$.notification_routing.state')) IN ('no_eligible_recipients','history_preserved','current_work','retired')
            AND JSON_UNQUOTE(JSON_EXTRACT($raw, '$.notification_routing.reason')) IN ('no_eligible_recipients','sent_or_exhausted_history','routing_current','response_completed')
            AND JSON_UNQUOTE(JSON_EXTRACT($raw, '$.notification_routing.generation')) REGEXP '^[a-f0-9]{64}$'
            AND JSON_TYPE(JSON_EXTRACT($raw, '$.notification_routing.eligible_count')) = 'INTEGER'
            AND JSON_EXTRACT($raw, '$.notification_routing.eligible_count') >= 0
            THEN JSON_OBJECT(".implode(', ', $routing).") ELSE JSON_OBJECT() END";
        return "JSON_MERGE_PATCH($flags, JSON_OBJECT(".implode(', ', $parts).",
            'notification_routing', $routingProjection,
            'shift_context', JSON_MERGE_PATCH(JSON_OBJECT(), JSON_OBJECT('site', JSON_MERGE_PATCH(JSON_OBJECT(), JSON_OBJECT('id', JSON_EXTRACT($raw, '$.shift_context.site.id'))))),
            'shift', JSON_MERGE_PATCH(JSON_OBJECT(), JSON_OBJECT('site_id', JSON_EXTRACT($raw, '$.shift.site_id'), 'site', JSON_MERGE_PATCH(JSON_OBJECT(), JSON_OBJECT('id', JSON_EXTRACT($raw, '$.shift.site.id'))))),
            'site', JSON_MERGE_PATCH(JSON_OBJECT(), JSON_OBJECT('id', JSON_EXTRACT($raw, '$.site.id'))),
            'provenance', JSON_MERGE_PATCH(JSON_OBJECT(), JSON_OBJECT('source', JSON_EXTRACT($raw, '$.provenance.source'))),
            'resolution', JSON_MERGE_PATCH(JSON_OBJECT(), JSON_OBJECT('source', JSON_EXTRACT($raw, '$.resolution.source'),
                'incident_id', JSON_EXTRACT($raw, '$.resolution.incident_id'),
                'incident_lifecycle_signal_id', JSON_EXTRACT($raw, '$.resolution.incident_lifecycle_signal_id'))),
            'normalized_data', JSON_MERGE_PATCH(JSON_OBJECT(), JSON_OBJECT(".implode(', ', $nested)."))))";
    }

    /**
     * Parent authority and exact discovered metadata only. The callback must independently
     * admit ordinary/copy/source body SQL; this frame does not grant incident/H&S prose.
     * No Builder, context, model or proof can escape this callback.
     */
    public function consumeOperationalAlertDetails(User $viewer, int $alertId, HealthSafetyReadContext $context, callable $project): array
    {
        return $this->withCurrentViewer($viewer, function (User $current, HealthSafetyReadContext $same, CurrentAuthorizationReads $reads) use ($alertId, $project): array {
            $unavailable = ['available' => false, 'result' => null];
            $original = self::$alertDetailReads[$same][$alertId] ?? null;
            if ($original === null || ! (clone $original)->exists()) { return $unavailable; }
            $envelope = self::$controlRoomFinalReadFrames[$same] ?? null;
            if ($envelope !== null) {
                if ($envelope->viewer !== $current || $envelope->reads !== $reads || $envelope->context !== $same) {
                    throw new LogicException('Alert frame belongs to another original CR read envelope.');
                }
                $envelope->parents[$alertId] ??= clone $original;
            }
            $result = $project(clone $original, $reads, $current, $same);
            if (! is_array($result)) { throw new LogicException('Operational detail projectors must return detached arrays.'); }
            \App\Services\Incidents\IncidentRoutingSettings::detached($result);
            $same->assertActive();
            return (clone $original)->exists() ? ['available'=>true, 'result'=>$result] : $unavailable;
        }, $context);
    }


    /** Final admitted source-body replay, never a record seal or metadata-only body grant. */
    public function verifyCurrentSources(User$viewer,int$alertId,HealthSafetyReadContext$context):void
    {
        $this->withCurrentViewer($viewer,function(User$current,HealthSafetyReadContext$same,CurrentAuthorizationReads$reads)use($alertId):array{
            foreach(self::$sourceDetailReads[$same][$alertId]??[]as$original){abort_unless($reads->query(clone$original)->exists(),404);}
            return[];
        },$context);
    }

    /** Operational change criteria only; no copied descriptions, source actors or private medical fields. */
    private function workflowTimestamps(array $record, HealthSafetyReadContext $context): array
    {
        $timestamps = [];
        foreach (['incident' => ['created_at', 'submitted_at', 'reviewed_at', 'investigation_started_at',
            'investigation_completed_at', 'reopened_at', 'closed_at'],
            'hs_event' => ['created_at', 'accepted_at', 'worksafe_decided_at', 'worksafe_notified_at',
                'worksafe_acknowledged_at', 'closed_at']] as $key => $fields) {
            foreach ($fields as $field) {
                $value = $record[$key]?->getAttribute($field);
                if ($value !== null) {
                    $timestamps[] = (string) $value;
                }
            }
        }
        $actions = $record['hs_event'] === null ? [] : $context->query($record['hs_event']->correctiveActions()->getQuery())->get(['completed_at', 'verified_at', 'closed_at']);
        foreach ($actions as $action) {
            foreach (['completed_at', 'verified_at', 'closed_at'] as $field) {
                if ($action->{$field} !== null) {
                    $timestamps[] = (string) $action->{$field};
                }
            }
        }

        return $timestamps;
    }

    /** A batch may be reused only inside the callback that locked and projected it. */
    public function isCurrentRecord(array $record, HealthSafetyReadContext $context, ?int $alertId = null): bool
    {
        $context->assertActive();
        $id = $record['alert_id'] ?? null;
        $seal = self::$recordSeals[$context][$id] ?? null;
        return ($record['_read_context'] ?? null) === $this->readToken($context)
            && ($alertId === null || $id === $alertId)
            && is_string($seal) && hash_equals($seal, hash('sha256', serialize($record)));
    }

    private function readToken(HealthSafetyReadContext $context): string
    {
        $context->assertActive();
        self::$readTokens ??= new \WeakMap;

        return self::$readTokens[$context] ??= (string) Str::uuid();
    }

    /**
     * Re-check saved references against the current reader and canonical source.
     * Retain historical workflow facts without replacing the saved snapshot.
     *
     * @param  array<string, mixed>  $snapshot  A validated handover snapshot.
     * @return array<string, mixed>
     */
    public function forSnapshotViewer(array $snapshot, User $viewer, ?HealthSafetyReadContext $context = null, ?object $savedReference = null): array
    {
        if ($context === null) {
            if ($savedReference !== null) { throw new LogicException('Use the original saved reference inside its active context.'); }
            return $this->withCurrentViewer($viewer, fn (User $current, HealthSafetyReadContext $reads): array => $this->forSnapshotViewer($snapshot, $current, $reads));
        }
        $context->assertViewer($viewer);
        $viewer = $this->withCurrentViewer($viewer, fn (User $current): User => $current, $context);
        if ($savedReference !== null) {
            app(ControlRoomHandoverReadAccessService::class)->verifySavedReference($viewer,$savedReference,$context);
            // The canonical saved row/ordinal gate precedes any caller-row-driven current source query.
            $snapshot['alerts']=collect($snapshot['alerts'])->map(function(array $row,int $index)use($viewer,$context,$savedReference):array{
                return $this->sourceContent->snapshotSummary($row,null,[],$viewer,$context,$savedReference,$index+1);
            })->values()->all();
        }
        $alerts = $this->projectCurrentAlertQuery(ControlRoomAlert::query()
            ->whereIn('id', collect($snapshot['alerts'])->pluck('id')), $viewer, $context)->get()->keyBy('id');
        $records = $this->forAlerts($alerts, $viewer, $context);
        $snapshot['alerts'] = collect($snapshot['alerts'])->map(function (array $row) use ($records, $alerts, $viewer, $context, $savedReference): array {
            $record = $records->get((int) $row['id']);
            if ($savedReference === null) { $row = $this->sourceContent->snapshotSummary($row, $alerts->get((int) $row['id']), $record ?? [], $viewer, $context); }
            foreach (['incident' => ['incident', 'INC-'], 'health_safety' => ['hs_event', 'HS-']] as $domain => [$key, $prefix]) {
                $referenceKey = $domain.'_reference';
                $accessKey = $domain.'_access';
                $savedJourneyReference = $row['journey'][$referenceKey] ?? null;
                $savedAccess = $row['journey'][$accessKey] ?? null;
                $source = $record[$key] ?? null;
                $currentAccess = $record[$accessKey] ?? 'unavailable';
                $currentReference = $source ? (data_get($source, 'reference_number') ?: $prefix.data_get($source, 'id')) : null;

                if ($savedJourneyReference !== null && $currentAccess === 'readable' && $savedJourneyReference === $currentReference) {
                    $row['journey'][$accessKey] = 'readable';

                    continue;
                }

                $row['journey'][$referenceKey] = null;
                $row['journey'][$accessKey] = match (true) {
                    $savedAccess === 'absent' && $savedJourneyReference === null => 'absent',
                    $currentAccess === 'restricted' => 'restricted',
                    default => 'unavailable',
                };
            }

            return $row;
        })->values()->all();

        foreach($alerts as$alert){$this->sourceContent->verifyOperationalContent($viewer,(int)$alert->id,$context);$this->verifyCurrentSources($viewer,(int)$alert->id,$context);}
        if ($savedReference !== null) { app(ControlRoomHandoverReadAccessService::class)->verifySavedReference($viewer,$savedReference,$context); }
        return $snapshot;
    }
    /** Opt-in outer CR READ only; never wrap this inside another Governance read callback. */
    public function withFinalizedControlRoomRead(User $viewer, callable $consume, callable $finalize,
        ?HealthSafetyReadContext $context = null): array
    {
        if ($context === null) {
            return HealthSafetyReadContext::within($viewer, self::PERMISSIONS,
                fn (HealthSafetyReadContext $same): array => $this->withFinalizedControlRoomRead($viewer, $consume, $finalize, $same));
        }
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::PERMISSIONS,
            function (User $current, CurrentAuthorizationReads $reads) use ($consume, $finalize, $context): array {
                self::$controlRoomFinalReadFrames ??= new WeakMap;
                if (isset(self::$controlRoomFinalReadFrames[$context])) {
                    throw new LogicException('Use one outer CR final read envelope in the original context.');
                }
                $frame = (object) ['viewer' => $current, 'reads' => $reads, 'context' => $context,
                    'actor' => clone $context->originalSourceAuthorityQuery(self::PERMISSIONS, []), 'parents' => []];
                self::$controlRoomFinalReadFrames[$context] = $frame;
                try {
                    // All nested Alert/copy callbacks and this outer actor tail finish first.
                    $result = $this->withCurrentViewer($current, $consume, $context);
                    if (! is_array($result)) { throw new LogicException('CR read envelopes return detached arrays only.'); }
                    $verifyOriginal = $this->controlRoomFinalReadVerifier($frame);
                    $frame->verifier = $verifyOriginal;
                    $this->validateAndReplayControlRoomFinalRead($current, $reads, $context, $verifyOriginal);
                    // CR final original Note/receipt/author/Shift/saved-row proof follows those tails.
                    $result = $finalize($result, $current, $reads, $context, $verifyOriginal);
                    if (! is_array($result)) { throw new LogicException('CR read finalizers return detached arrays only.'); }
                    AppServicesIncidentsIncidentRoutingSettings::detached($result);
                    $context->assertActive();
                    // No additional source SQL here after CR finalization.
                    return $this->detachedResult($result);
                } finally {
                    unset(self::$controlRoomFinalReadFrames[$context]);
                }
            });
    }

    /** Validate the exact minted envelope capability and expected tuple BEFORE supplied invocation or SQL. */
    public function validateAndReplayControlRoomFinalRead(User $viewer, CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, mixed $verifier): void
    {
        $frame = self::$controlRoomFinalReadFrames[$context] ?? null;
        if (! $verifier instanceof \Closure || $frame === null || $frame->context !== $context || $frame->viewer !== $viewer
            || $frame->reads !== $reads || ! isset($frame->verifier) || $frame->verifier !== $verifier) {
            throw new LogicException('Use the exact privately minted active CR envelope verifier and tuple.');
        }
        $context->assertViewer($viewer); $reads->assertActive();
        // No arbitrary callable is invoked, even if it claims a matching actor/context.
        ($frame->verifier)();
    }

    /** Private first admitted parent/actor/Gov-source replay, NOT a Note certificate. */
    private function controlRoomFinalReadVerifier(object $frame): \Closure
    {
        $witnesses = [clone $frame->actor];
        foreach ($frame->parents as $alertId => $parent) {
            $witnesses[] = clone $parent;
            foreach (self::$sourceDetailReads[$frame->context][$alertId] ?? [] as $source) {
                $witnesses[] = clone $source;
            }
        }
        foreach ($witnesses as $query) {
            if (get_class($query->getQuery()) !== \Illuminate\Database\Query\Builder::class) {
                throw new LogicException('Review the original nonrecursive witness; never strip its execution guard.');
            }
        }
        return function () use ($frame, $witnesses): void {
            if (! isset(self::$controlRoomFinalReadFrames[$frame->context])
                || self::$controlRoomFinalReadFrames[$frame->context] !== $frame) {
                throw new LogicException('The original CR final read envelope has ended.');
            }
            $frame->context->assertViewer($frame->viewer); $frame->reads->assertActive();
            $frame->context->consumeSourceViewer(self::PERMISSIONS,
                function (User $current, CurrentAuthorizationReads $reads) use ($frame, $witnesses): void {
                    if ($current !== $frame->viewer || $reads !== $frame->reads) {
                        throw new LogicException('Use the exact original CR viewer/read/context.');
                    }
                    foreach ($witnesses as $original) {
                        abort_unless($reads->query(clone $original)->exists(), 404);
                    }
                });
        };
    }


    /** Internal source tokens never leave the outer materialisation callback. */
    private function detachedResult(mixed $result): mixed
    {
        if (! is_array($result)) { return $result; }
        unset($result['_read_context']);
        foreach ($result as $key => $value) { $result[$key] = $this->detachedResult($value); }
        return $result;
    }

}
