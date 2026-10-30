<?php

namespace App\Services\ControlRoom;

use App\Models\ClientIncident;
use App\Models\AuditLog;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentIndependenceBindingSql;
use App\Services\Incidents\IncidentSavedCopyService;
use App\Services\UserSiteAccessService;
use App\Models\ControlRoomAlert;
use App\Models\RestraintEvent;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\Incidents\IncidentAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only disclosure of incident material copied into an operational record. */
final class ControlRoomSourceContentService
{
    public function __construct(private readonly IncidentAccessService $incidents) {}

    /** Metadata discovery for the EXISTING Site-less role exception only.
     * Never a named-recipient grant or copied-body certificate. The delivery
     * owner separately proves actual role nomination and current staff authority.
     */
    public function siteLessRoleNotificationMetadata(User $viewer, int $alertId, CurrentAuthorizationReads $reads): ?array
    {
        return $this->withoutNotificationMetadataObservers($reads,
            fn (): ?array => $this->readSiteLessRoleNotificationMetadata($viewer, $alertId, $reads));
    }

    /** Same source-free role exception; only fixed operational escalation metadata is additional. */
    public function siteLessRoleEscalationMetadata(User $viewer, int $alertId, CurrentAuthorizationReads $reads, array $event): ?array
    {
        return $this->withoutNotificationMetadataObservers($reads,
            fn (): ?array => $this->readSiteLessRoleNotificationMetadata($viewer, $alertId, $reads, $event));
    }

    /** Absence of a note is not a source grant; caller still owns full workspace authority. */
    public function notificationNoteAbsent(User $viewer, int $alertId, CurrentAuthorizationReads $reads): bool
    {
        return $this->withoutNotificationMetadataObservers($reads, function () use ($viewer, $alertId, $reads): bool {
            $query = app(ControlRoomAlertAccessService::class)->applyReadableScope(
                ControlRoomAlert::query()->whereKey($alertId), $viewer, $reads)
                ->whereNull('notes')->whereRaw("JSON_CONTAINS_PATH(COALESCE(context,JSON_OBJECT()),'one',
                    '$.saved_incident_copy','$.saved_incident_copy_audit_id','$.root_note_origin','$.notes_provenance')=0");
            if (! $reads->query($query)->exists()) { return false; }
            return ! $this->notificationNoteClaimExists($alertId, (new ControlRoomAlert)->getMorphClass(), $reads, false);
        });
    }

    private function withoutNotificationMetadataObservers(CurrentAuthorizationReads $reads, callable $read): mixed
    {
        $reads->assertActive();
        $connection = \Illuminate\Support\Facades\DB::connection();
        $hooks = new \ReflectionProperty(\Illuminate\Database\Connection::class, 'beforeExecutingCallbacks');
        if ($hooks->getValue($connection) !== []) {
            throw new \LogicException('Notification metadata cannot execute connection before-query callbacks.');
        }
        $events = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();
        try {
            return \Illuminate\Database\Eloquent\Model::withoutEvents($read);
        } finally {
            if ($events === null) { $connection->unsetEventDispatcher(); }
            else { $connection->setEventDispatcher($events); }
        }
    }

    /** Fixed internal queries only; no consumer callback or query escapes. */
    private function readSiteLessRoleNotificationMetadata(User $viewer, int $alertId, CurrentAuthorizationReads $reads, ?array $event = null): ?array
    {
        $ownedPaths = $event !== null
            ? "'$.notification_routing','$.auto_escalated_from','$.auto_escalated_to','$.last_escalation_reason','$.last_escalation_at','$.sla_breaches'"
            : "'$.notification_routing'";
        $query = $this->applyOrdinaryRootScope(ControlRoomAlert::query()->whereKey($alertId))
            ->whereIn('source', ['external', 'manual'])->whereNull('site_id')->whereNull('client_id')
            ->whereNull('asset_id')->whereNull('device_id')->whereNull('fleet_signal_id')
            ->whereNull('origin_signal_id')->whereNull('playbook_run_id')->whereNull('notes')
            ->whereRaw("JSON_LENGTH(JSON_REMOVE(COALESCE(context,JSON_OBJECT()),$ownedPaths))=0");
        if ($event !== null) {
            $expected = $event['expected_alert']['context'] ?? null;
            if (($event['alert_id'] ?? null) !== $alertId || ! in_array($event['kind'] ?? null, ['queue', 'sla'], true)
                || ! is_array($expected) || ! is_string($expected['last_escalation_at'] ?? null)
                || $expected['last_escalation_at'] !== ($event['at_iso'] ?? null)
                || ($expected['last_escalation_reason'] ?? null) !== ($event['kind'] === 'queue' ? 'queue_time_exceeded' : 'sla_breach')) { return null; }
            if ($event['kind'] === 'queue' && (! is_string($expected['auto_escalated_from'] ?? null)
                || ! is_string($expected['auto_escalated_to'] ?? null))) { return null; }
            if (isset($expected['sla_breaches']) && (! is_array($expected['sla_breaches'])
                || ! array_is_list($expected['sla_breaches'])
                || array_diff($expected['sla_breaches'], ['acknowledge', 'response', 'resolution']) !== [])) { return null; }
            unset($expected['notification_routing']);
            // Exact ORIGINAL intended context, not permission by key name. The
            // event owner separately proves original assignment/SLA cycle/audit.
            $query->whereRaw("JSON_REMOVE(COALESCE(context,JSON_OBJECT()),'$.notification_routing') = CAST(? AS JSON)",
                [json_encode($expected, JSON_THROW_ON_ERROR)]);
        }
        $alert = $reads->query($query)->first(['id', 'site_id', 'client_id', 'asset_id', 'device_id',
            'fleet_signal_id', 'origin_signal_id', 'source', 'alert_type', 'severity', 'status', 'triggered_at', 'escalation_level']);
        if ($alert === null) { return null; }
        $access = app(ControlRoomAlertAccessService::class);
        if ($access->isMedicationAlert($alert) || $access->canView($alert, $viewer)
            || ! $access->canReceiveRoleRoutedNotification($alert, $viewer)) { return null; }
        // Canonical reverse/legacy journey discovery owns absence and conflicts;
        // the ordinary classifier alone cannot certify no copied source.
        $record = app(\App\Services\Incidents\IncidentJourneyService::class)
            ->governanceRecordsForAlerts([$alert], $reads)->get($alertId);
        if (! is_array($record) || ($record['integrity'] ?? null) === 'unavailable'
            || ($record['incident'] ?? null) !== null || ($record['hs_event'] ?? null) !== null) { return null; }
        if ($this->notificationNoteClaimExists($alertId, $alert->getMorphClass(), $reads)) { return null; }
        $payload = [
            'alert_id' => (int) $alert->id, 'severity' => $alert->severity, 'status' => $alert->status,
            'alert_type' => $alert->alert_type, 'source' => $alert->source,
            'triggered_at' => $alert->triggered_at?->toISOString(), 'escalation_level' => $alert->escalation_level,
        ];
        return ['payload' => $payload, 'source_state' => 'source_free_role_exception',
            'source_fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
    }

    private function notificationNoteClaimExists(int $alertId, string $morphClass, CurrentAuthorizationReads $reads, bool $includeOperatorInventory = true): bool
    {
        return $reads->query(\App\Models\IncidentSavedCopy::query())
            ->where('target_family', IncidentSavedCopyService::ALERT_NOTES_TARGET)->where('target_key', (string) $alertId)->exists()
            || ($includeOperatorInventory && $reads->query(\App\Models\ControlRoom\OperatorNote::query())->where('alert_id', $alertId)->exists())
            || $reads->query(\Illuminate\Support\Facades\DB::table('audit_logs'))->where(function ($claims) use ($alertId, $morphClass): void {
                $claims->where(fn ($target) => $target
                    ->where('meta->target_family', IncidentSavedCopyService::ALERT_NOTES_TARGET)
                    ->where('meta->target_key', (string) $alertId))
                    ->orWhere(fn ($target) => $target
                        ->where('meta->origin->target_family', IncidentSavedCopyService::ALERT_NOTES_TARGET)
                        ->where('meta->origin->target_key', (string) $alertId))
                    ->orWhere(fn ($target) => $target->where('auditable_type', $morphClass)
                        ->where('auditable_id', $alertId)->where('action', self::ACTION));
            })->exists();
    }

    public const RESTRICTED = 'Incident details restricted';

    public const UNAVAILABLE = 'Incident details unavailable';

    private const ACTION = 'controlRoom.alert.sourceNoteCopied';
    private const ORIGIN_KEYS = ['copy_uuid', 'source_family', 'source_version', 'target_family', 'target_key', 'field'];
    private const TARGET_COLUMNS = ['id', 'site_id', 'client_id', 'asset_id', 'source', 'alert_type'];
    private static ?\WeakMap $copyQueries = null;

    private static ?\WeakMap $ordinaryQueries = null;
    private static ?\WeakMap $operatorQueries = null;
    private static ?\WeakMap $contextQueries = null;
    private static ?\WeakMap $contextValues = null;

    /** New capture index owns no context, query, actor or callback strongly. */
    private static ?\WeakMap $handoverSummaryOwners = null;

    /** Detached private source entry; operational response access remains independent. */
    public function restraintForAlert(ControlRoomAlert $alert, User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        $raw = is_array($alert->context) ? $alert->context : [];
        if (! $this->isRestraintAlert($alert)) {
            return ['restraint_access' => 'absent', 'restraint_source' => null];
        }
        $result = ['restraint_access' => 'unavailable', 'restraint_source' => null];
        $id = $this->positiveId($raw['restraint_event_id'] ?? null);
        if ($id === null) {
            return $result;
        }
        $context?->assertViewer($viewer);
        $access = app(RestraintAccessService::class);
        try {
            $consume = function ($current, $reads, $sites) use ($access, $alert, $id, $context): array {
                $freshAlert = $context
                    ? app(ControlRoomGovernanceReadService::class)->projectCurrentAlertQuery(ControlRoomAlert::query()->whereKey($alert->id),$current,$context)->first()
                    : $reads->query(ControlRoomAlert::query()->whereKey($alert->id))->select(['id','client_id','site_id'])->selectRaw("JSON_OBJECT('restraint_event_id',JSON_EXTRACT(context,'$.restraint_event_id')) as context")->first();
                $source = $reads->query(RestraintEvent::query())->find($id,['id','client_id','site_id','stay_id','source_schema_version','recorded_client_id','recorded_site_id','recorded_stay_id','behaviour_support_plan_id','behaviour_support_plan_version_id']);
                if (! $freshAlert || ! $source || $this->positiveId($freshAlert->context['restraint_event_id'] ?? null) !== $id
                    || (int) $freshAlert->client_id !== (int) $source->client_id
                    || ($freshAlert->site_id === null ? null : (int) $freshAlert->site_id) !== ($source->site_id === null ? null : (int) $source->site_id)) {
                    return ['restraint_access' => 'unavailable', 'restraint_source' => null];
                }
                $access->assertEvent($source, $current, $reads, $sites, 'view');

                return ['restraint_access' => 'readable', 'restraint_source' => ['id' => (int) $source->id, 'href' => '/health-safety/restraints/events/'.$source->id]];
            };

            return $context ? $access->withReadContext($context, $consume) : $access->withCurrentViewer($viewer, $consume);
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return ['restraint_access' => 'restricted', 'restraint_source' => null];
        }
    }

    private function isRestraintAlert(ControlRoomAlert $alert): bool
    {
        return $alert->alert_type === 'operations.restraint_event' || array_key_exists('restraint_event_id', is_array($alert->context) ? $alert->context : []);
    }

    /**
     * The canonical records and access states are supplied once per list by
     * ControlRoomGovernanceReadService. This projector performs no queries.
     */
    public function present(ControlRoomAlert $alert, array $governance, array $context, ?HealthSafetyReadContext $readContext = null): array
    {
        if ($readContext !== null) { return $this->presentInContext($alert, $governance, $readContext); }
        $raw = is_array($alert->context) ? $alert->context : [];
        $restraintOrigin = $this->isRestraintAlert($alert);
        if ($restraintOrigin) {
            // Retain response work; source facts and command/delivery provenance belong to its private page.
            $context = Arr::except($context, ['restraint_event_id', 'restraint_id', 'source_revision', 'creation_source_revision',
                'source_deliveries', 'delivery_id', 'source_id', 'source_type', 'provenance', 'description', 'title', 'summary',
                'trigger_description', 'restraint_description', 'de_escalation_attempted', 'person_response', 'post_incident_support',
                'injury_details', 'staff_involved', 'authorised_by', 'within_support_plan', 'behaviour_support_plan_id',
                'behaviour_support_plan_version_id', 'plan_authorization_snapshot', 'normalized_data', 'signal_payload',
                'restraint_type', 'duration_minutes', 'started_at', 'ended_at', 'occurred_at', 'review_notes', 'reviewed_by', 'reviewed_at',
                'lessons_learned', 'deviation_reason', 'injury_occurred', 'monitoring', 'observations', 'debrief', 'followups',
                'creation_key', 'creation_fingerprint', 'command_key', 'idempotency_key', 'actor_snapshot', 'staff_id', 'creator_id', 'created_by']);
            $context['restraint_content_access'] = $governance['restraint_access'] ?? 'unavailable';
            $context['restraint_source'] = ($governance['restraint_access'] ?? null) === 'readable' ? ($governance['restraint_source'] ?? null) : null;
        }
        $incidentOrigin = $alert->source === 'incident'
            || data_get($raw, 'provenance.source') === 'incident_journey'
            || ($raw['incident_source_type'] ?? null) === 'client_incident';
        $rootAccess = $this->incidentAccess($this->entryIncidentId($raw), $governance);
        if ($incidentOrigin && $rootAccess !== 'readable') {
            $context = Arr::except($context, [
                'incident_id', 'incident_type', 'type', 'shift_id', 'occurred_at',
                'description', 'title', 'summary', 'reported_by', 'reason', 'provenance',
                'incident_source_type', 'incident_source_id',
            ]);
            $context['incident_content_access'] = $rootAccess;
        }

        if (data_get($raw, 'normalized_data.incident_id') !== null
            && $this->incidentAccess(data_get($raw, 'normalized_data.incident_id'), $governance) !== 'readable') {
            unset($context['normalized_data'], $context['signal_payload']);
            $context['incident_content_access'] = $this->incidentAccess(data_get($raw, 'normalized_data.incident_id'), $governance);
        }

        foreach (['resolution', 'journey_attention'] as $key) {
            if (is_array($context[$key] ?? null) && $this->isIncidentEntry($context[$key])) {
                $context[$key] = $this->entry($context[$key], $governance);
            }
        }
        foreach (['resolution_history', 'operational_reopen_history'] as $key) {
            if (is_array($context[$key] ?? null)) {
                $context[$key] = array_map(fn ($entry) => is_array($entry) && $this->isIncidentEntry($entry)
                    ? $this->entry($entry, $governance) : $entry, $context[$key]);
            }
        }
        if (is_array($context['activity_log'] ?? null)) {
            $context['activity_log'] = array_map(function ($entry) use ($raw, $governance) {
                if (! is_array($entry) || ($entry['transition'] ?? null) !== 'incident_reopen') {
                    return $entry;
                }
                $sourceId = $entry['incident_id'] ?? null;
                if ($sourceId === null) {
                    // Older activity copies did not record the source ID. Only
                    // an exact matching source-history entry can establish it.
                    $matches = collect($raw['operational_reopen_history'] ?? [])->filter(fn ($history) => is_array($history)
                        && ($history['reason'] ?? null) === ($entry['content'] ?? null)
                        && $this->sameInstant($history['reopened_at'] ?? null, $entry['created_at'] ?? null)
                        && ($history['actor_id'] ?? null) === ($entry['user_id'] ?? null));
                    $ids = $matches->pluck('incident_id')->map(fn ($id) => $this->positiveId($id))->unique();
                    $sourceId = $ids->count() === 1 ? $ids->first() : null;
                }

                return $this->entry($entry + ['incident_id' => $sourceId], $governance);
            }, $context['activity_log']);
        }
        $context = $this->references($context, $governance);
        unset($context['notes_provenance']);

        $notesSourceIds = $this->noteSourceIds($alert);
        $notesAccess = $this->combinedAccess($notesSourceIds, $governance);
        // Canonical bridge receipts explicitly describe minimal operational provenance, never copied notes.
        if ($restraintOrigin && ! is_array($raw['source_deliveries'] ?? null) && ($governance['restraint_access'] ?? null) !== 'readable') {
            $notesAccess = $governance['restraint_access'] ?? 'unavailable';
        }
        $notes = $notesAccess === 'readable' ? $alert->notes : null;
        if (array_key_exists('saved_incident_copy', $raw) || array_key_exists('saved_incident_copy_audit_id', $raw) || ($raw['copy_content_present'] ?? false)) {
            $projection = $readContext === null ? ['notes_access' => 'unavailable', 'notes' => null] : $this->readCopiedNote($alert, $readContext);
            $notesAccess = $projection['notes_access'];
            $notes = $projection['notes'];
        }
        // Copy origins and receipt identifiers are server evidence, never disclosure metadata.
        $copiedRoot = array_key_exists('saved_incident_copy', $raw) || array_key_exists('saved_incident_copy_audit_id', $raw) || ($raw['copy_content_present'] ?? false);
        if ($copiedRoot) {
            $context = Arr::except($context, ['title', 'summary', 'description', 'normalized_data', 'signal_payload']);
        }
        unset($context['saved_incident_copy'], $context['saved_incident_copy_audit_id'], $context['root_note_origin'], $context['copy_content_present']);
        $candidates = [
            [$context['title'] ?? null, $incidentOrigin],
            [$context['summary'] ?? null, $incidentOrigin],
            [$context['description'] ?? null, $incidentOrigin],
            [data_get($context, 'normalized_data.title'), data_get($raw, 'normalized_data.incident_id') !== null],
            [data_get($context, 'normalized_data.description'), data_get($raw, 'normalized_data.incident_id') !== null],
            [$notes, $notesSourceIds !== []],
        ];
        if ($restraintOrigin) {
            $candidates = [];
        } elseif ($copiedRoot) {
            // Only the exact admitted full field may become a copied-source summary; no composite/context prose is certified.
            $candidates = [[$notes, false]];
        }
        $summary = Str::headline((string) $alert->alert_type);
        $origin = ['type' => 'alert'];
        $acceptedCopy = (array_key_exists('saved_incident_copy', $raw) || array_key_exists('saved_incident_copy_audit_id', $raw) || ($raw['copy_content_present'] ?? false)) && $notesAccess === 'readable';
        foreach ($candidates as [$candidate, $fromIncident]) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $summary = Str::limit(trim($candidate), 160);
                $origin = $acceptedCopy && $candidate === $notes ? ['type' => 'source_copy']
                    : ($fromIncident ? ['type' => 'incident', 'incident_id' => data_get($governance, 'incident.id')] : ['type' => 'alert']);
                break;
            }
        }

        $resolverVisible = data_get($raw, 'resolution.source') !== 'incident_lifecycle_signal'
            || $this->incidentAccess(data_get($raw, 'resolution.incident_id'), $governance) === 'readable';
        $acknowledgerFromIncident = data_get($raw, 'resolution.acknowledged_from_incident')
            ?? ($alert->acknowledged_by_user_id !== null
                && $alert->acknowledged_by_user_id === ($alert->resolved_by_user_id ?? data_get($raw, 'resolution.incident_lifecycle_actor_user_id'))
                && $this->sameInstant($alert->acknowledged_at?->toIso8601String(), data_get($raw, 'resolution.resolved_at')));

        return ['context' => $context, 'notes' => $notes, 'notes_access' => $notesAccess,
            'source_creator_visible' => (! $incidentOrigin || $rootAccess === 'readable') && (! $restraintOrigin || ($governance['restraint_access'] ?? null) === 'readable'),
            'source_resolver_visible' => $resolverVisible,
            'source_acknowledger_visible' => $resolverVisible || ! $acknowledgerFromIncident,
            'summary' => $summary, 'summary_origin' => $origin];
    }

    /** Keep SQL search/count/pagination on the same side of the copied-note boundary. */
    public function applyReadableNotes(Builder $query, User $viewer, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null, ?string $notesLikePattern = null, ?Builder $executionQuery = null): Builder
    {
        // The caller supplies the same bounded, parameter-bound LIKE pattern as reference/type search.
        // It is applied to copied text only after admission, inside the canonical copy callback.
        if ($notesLikePattern !== null && ($context === null || strlen($notesLikePattern) > 4096)) {
            throw new \LogicException('Notes search requires an active context and a LIKE pattern of at most 4096 bytes.');
        }
        if ($context !== null) {
            if ($sourceCandidates === null) { throw new \LogicException('Copied-note search requires independent metadata candidates.'); }
            if ($notesLikePattern !== null && $executionQuery === null) {
                throw new \LogicException('Notes search must bind verification to the executed outer query.');
            }
            return $this->applyPreparedNoteScope($query, $viewer, $context, $sourceCandidates, $notesLikePattern, $executionQuery ?? $query);
        }
        // Retained context-free legacy boundary; it is not a canonical saved-copy certificate.
        $this->applyReadableRestraintNotes($query, $viewer);
        $readable = $this->incidents->apply(ClientIncident::query(), $viewer)->select('client_incidents.id')
            ->where(function (Builder $source): void {
                $source->whereColumn('client_incidents.control_room_alert_id', 'control_room_alerts.id')
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('client_incidents.control_room_alert_id')
                            ->whereRaw("client_incidents.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(control_room_alerts.context, '$.incident_id')) AS UNSIGNED)")
                            ->whereNotExists(fn (QueryBuilder $direct) => $direct->selectRaw('1')
                                ->from('client_incidents as direct_incident')
                                ->whereColumn('direct_incident.control_room_alert_id', 'control_room_alerts.id'));
                    });
            });
        $copies = <<<'SQL'
JSON_TABLE(
    JSON_MERGE_PRESERVE(
        CASE WHEN JSON_TYPE(JSON_EXTRACT(control_room_alerts.context, '$.resolution_history')) = 'ARRAY'
            THEN JSON_EXTRACT(control_room_alerts.context, '$.resolution_history') ELSE JSON_ARRAY() END,
        JSON_ARRAY(JSON_EXTRACT(control_room_alerts.context, '$.resolution'), JSON_EXTRACT(control_room_alerts.context, '$.notes_provenance'))
    ), '$[*]' COLUMNS (
        source VARCHAR(80) PATH '$.source' NULL ON EMPTY NULL ON ERROR,
        incident_id BIGINT PATH '$.incident_id' NULL ON EMPTY NULL ON ERROR,
        reason LONGTEXT PATH '$.reason' NULL ON EMPTY NULL ON ERROR,
        text_sha256 VARCHAR(64) PATH '$.text_sha256' NULL ON EMPTY NULL ON ERROR
    )
) AS copied_note
SQL;

        return $query->whereNotExists(function (QueryBuilder $hidden) use ($copies, $readable): void {
            $hidden->selectRaw('1')->fromRaw($copies)
                ->where('copied_note.source', 'incident_lifecycle_signal')
                ->where(function (QueryBuilder $matches): void {
                    $matches->where(function (QueryBuilder $reason): void {
                        $reason->whereNotNull('copied_note.reason')->where('copied_note.reason', '!=', '')
                            ->whereRaw('LOCATE(CAST(copied_note.reason AS BINARY), CAST(control_room_alerts.notes AS BINARY)) > 0');
                    })->orWhereRaw('copied_note.text_sha256 = SHA2(control_room_alerts.notes, 256)');
                })->where(fn (QueryBuilder $denied) => $denied->whereNull('copied_note.incident_id')
                ->orWhereNotIn('copied_note.incident_id', $readable->toBase()));
        })->whereNot(function (Builder $unknown): void {
            $unknown->where('control_room_alerts.source', 'compliance')
                ->where('control_room_alerts.alert_type', 'Incident Reported')
                ->where('control_room_alerts.notes', 'like', 'New incident reported - %');
        });
    }

    /** Search/count cannot reveal a copied legacy restraint note that its source read conceals. */
    private function applyReadableRestraintNotes(Builder $query, User $viewer): void
    {
        $ids = [];
        $access = app(RestraintAccessService::class);
        try {
            $ids = $access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($access): array {
                if (! $current->canDo('restraints.view')) {
                    return [];
                }
                $ids = [];
                foreach ($reads->query(RestraintEvent::query())->select(['id', 'client_id', 'site_id', 'stay_id', 'source_schema_version',
                    'recorded_client_id', 'recorded_site_id', 'recorded_stay_id', 'behaviour_support_plan_id', 'behaviour_support_plan_version_id'])->get() as $row) {
                    try {
                        $access->assertEvent($row, $current, $reads, $sites, 'view');
                        $ids[] = (int) $row->id;
                    } catch (HttpException $error) {
                        if (! in_array($error->getStatusCode(), [403, 404], true)) {
                            throw $error;
                        }
                    }
                }

                return $ids;
            });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }
        }
        $query->where(function (Builder $notes) use ($ids): void {
            $notes->whereRaw("JSON_EXTRACT(control_room_alerts.context, '$.restraint_event_id') IS NULL")
                ->where('control_room_alerts.alert_type', '!=', 'operations.restraint_event')
                ->orWhereRaw("JSON_TYPE(JSON_EXTRACT(control_room_alerts.context, '$.source_deliveries')) = 'ARRAY'")
                ->orWhere(function (Builder $source) use ($ids): void {
                    $source->whereExists(function (QueryBuilder $event) use ($ids): void {
                        $event->selectRaw('1')->from('restraint_events')->whereIn('restraint_events.id', $ids)
                            ->whereRaw("restraint_events.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(control_room_alerts.context, '$.restraint_event_id')) AS UNSIGNED)")
                            ->whereColumn('restraint_events.client_id', 'control_room_alerts.client_id')
                            ->whereRaw('restraint_events.site_id <=> control_room_alerts.site_id');
                    });
                });
        });
    }

    /** @return list<int|null> Empty means independently authored operational notes. */
    public function noteSourceIds(ControlRoomAlert $alert): array
    {
        if (! is_string($alert->notes) || $alert->notes === '') {
            return [];
        }
        $context = is_array($alert->context) ? $alert->context : [];
        $copies = [($context['resolution'] ?? null), ...array_values(is_array($context['resolution_history'] ?? null) ? $context['resolution_history'] : [])];
        $ids = [];
        foreach ($copies as $copy) {
            if (is_array($copy) && ($copy['source'] ?? null) === 'incident_lifecycle_signal'
                && is_string($copy['reason'] ?? null) && $copy['reason'] !== ''
                && str_contains($alert->notes, $copy['reason'])) {
                $ids[] = $this->positiveId($copy['incident_id'] ?? null);
            }
        }
        $provenance = $context['notes_provenance'] ?? null;
        if (is_array($provenance) && ($provenance['source'] ?? null) === 'incident_lifecycle_signal'
            && hash_equals((string) ($provenance['text_sha256'] ?? ''), hash('sha256', $alert->notes))) {
            $ids[] = $this->positiveId($provenance['incident_id'] ?? null);
        }
        if ($alert->source === 'compliance' && $alert->alert_type === 'Incident Reported'
            && str_starts_with($alert->notes, 'New incident reported - ')) {
            // This legacy writer accepted any Model, so a numeric ID alone
            // cannot prove that it was a ClientIncident rather than another source.
            $ids[] = null;
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    public function audit(array $row, array $governance, array $context = []): array
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        if (array_key_exists('restraint_event_id', $meta) || ($row['action'] ?? null) === 'control_room.restraint_source_accepted') {
            $row['meta'] = Arr::only($meta, ['severity', 'new_response']);
            $row['meta']['restraint_content_access'] = $governance['restraint_access'] ?? 'unavailable';
            if (($governance['restraint_access'] ?? null) !== 'readable') {
                $row['user'] = null;
            }

            return $row;
        }
        if (! isset($meta['incident_id']) && ($meta['resolution_source'] ?? null) === 'incident_lifecycle_signal') {
            $matches = collect($context['resolution_history'] ?? [])->filter(fn ($entry) => is_array($entry)
                && ($entry['source'] ?? null) === 'incident_lifecycle_signal'
                && isset($meta['transitioned_at'])
                && $this->sameInstant($entry['resolved_at'] ?? null, $meta['transitioned_at']));
            $ids = $matches->pluck('incident_id')->map(fn ($id) => $this->positiveId($id))->unique();
            $meta['incident_id'] = $ids->count() === 1 ? $ids->first() : null;
        }
        if ($this->isIncidentEntry($meta)
            || ($meta['resolution_source'] ?? null) === 'incident_lifecycle_signal'
            || in_array($row['action'] ?? null, ['controlRoom.alert.reopenForIncident', 'controlRoom.alert.reopenFromIncidentSignal'], true)) {
            $access = $this->incidentAccess($this->entryIncidentId($meta), $governance);
            $row['meta'] = $this->entry($meta, $governance);
            if ($access !== 'readable') {
                $row['user'] = null;
            }
        } else {
            $row['meta'] = $this->references($meta, $governance);
        }

        return $row;
    }

    public function snapshotSummary(array $row, ?ControlRoomAlert $alert, array $governance, ?User $viewer = null, ?HealthSafetyReadContext $readContext = null, ?object $savedReference = null, ?int $savedOrdinal = null): array
    {
        if ($savedReference !== null) {
            if ($readContext===null||$viewer===null||$savedOrdinal===null||$savedOrdinal<1||!is_int($row['id']??null)||$row['id']<1) {
                throw new \LogicException('Saved summaries require the original active viewer/reference/ordinal/Alert tuple.');
            }
            $result=$this->savedSnapshotSummary($viewer,$savedReference,$savedOrdinal,$row['id'],$readContext);
            $row['summary']=$result['available']?$result['result']['summary']:'Saved summary details unavailable';
            $row['summary_body_access']=$result['available']?'readable':'unavailable';
            if (!$result['available']) { $row['summary_origin']=['type'=>'withheld']; }
            return $row;
        }
        if ($readContext !== null) {
            if ($alert === null || $viewer === null || ! array_key_exists('summary',$row)) { return $row; }
            $origin=$row['summary_origin']['type']??null;
            $proof=app(ControlRoomGovernanceReadService::class)->consumeOperationalAlertDetails($viewer,(int)$alert->id,$readContext,
                function(Builder$original,CurrentAuthorizationReads$reads)use($alert):array{
                    $ordinary=$this->applyOrdinaryRootScope($this->intersectParent(ControlRoomAlert::query()->whereKey($alert->id),$original,$reads));
                    return ['ordinary'=>$ordinary->exists()];
                });
            if($proof['available']&&$proof['result']['ordinary']&&$origin==='alert'){return$row;}
            // Snapshot source prose lacks its own retained canonical copy identity/target receipt.
            // Today's root/copy/source can never manufacture the historical saved-summary lifetime.
            $row['summary']='Saved summary details unavailable';$row['summary_origin']=['type'=>'withheld'];
            $row['summary_body_access']='unavailable';return$row;
        }
        if (! array_key_exists('summary', $row)) {
            return $row;
        }
        if ($alert !== null && $this->isRestraintAlert($alert)) {
            $row['summary'] = Str::headline((string) $alert->alert_type);
            $row['summary_origin'] = ['type' => 'alert'];

            return $row;
        }
        $origin = $row['summary_origin'] ?? null;
        if (($origin['type'] ?? null) === 'alert' || ($origin['type'] ?? null) === 'withheld') {
            return $row;
        }
        $access = ($origin['type'] ?? null) === 'incident'
            ? $this->incidentAccess($origin['incident_id'] ?? null, $governance)
            : 'unavailable';
        if ($access === 'readable') {
            return $row;
        }
        if ($origin === null && $alert !== null
            && ($governance['incident_access'] ?? 'unavailable') === 'absent'
            && ($governance['health_safety_access'] ?? 'unavailable') === 'absent'
            && $this->noteSourceIds($alert) === [] && $alert->source !== 'incident') {
            return $row;
        }
        $row['summary'] = $access === 'restricted' ? self::RESTRICTED : 'Saved summary details unavailable';
        $row['summary_origin'] = ['type' => 'withheld'];

        return $row;
    }

    private function entry(array $entry, array $governance): array
    {
        $access = $this->incidentAccess($this->entryIncidentId($entry), $governance);
        if ($access === 'readable') {
            return $this->references($entry, $governance);
        }
        $minimal = Arr::only($entry, ['type', 'transition', 'resolved_at', 'reopened_at', 'created_at',
            'from_status', 'to_status', 'requires_operational_reopen']);
        $minimal['access'] = $access;
        foreach (['reason', 'content'] as $key) {
            if (array_key_exists($key, $entry)) {
                $minimal[$key] = $access === 'restricted' ? self::RESTRICTED : self::UNAVAILABLE;
            }
        }

        return $minimal;
    }

    private function references(array $data, array $governance): array
    {
        if ((array_key_exists('incident_id', $data) || ($data['incident_source_type'] ?? null) === 'client_incident')
            && $this->incidentAccess($this->entryIncidentId($data), $governance) !== 'readable') {
            $data = Arr::except($data, ['incident_id', 'incident_reference', 'incident_reference_number', 'incident_source_type', 'incident_source_id']);
        }
        if (array_key_exists('hs_event_id', $data)
            && (($governance['health_safety_access'] ?? null) !== 'readable'
                || $this->positiveId($data['hs_event_id']) !== data_get($governance, 'hs_event.id'))) {
            $data = Arr::except($data, ['hs_event_id', 'hs_event_reference', 'health_safety_reference']);
        }

        return $data;
    }

    private function isIncidentEntry(array $entry): bool
    {
        return array_key_exists('incident_id', $entry)
            || ($entry['incident_source_type'] ?? null) === 'client_incident'
            || ($entry['source'] ?? null) === 'incident_lifecycle_signal'
            || ($entry['type'] ?? null) === 'incident_reopened';
    }

    private function combinedAccess(array $ids, array $governance): string
    {
        $access = array_map(fn ($id) => $this->incidentAccess($id, $governance), $ids);

        return in_array('unavailable', $access, true) ? 'unavailable'
            : (in_array('restricted', $access, true) ? 'restricted' : 'readable');
    }

    private function entryIncidentId(array $entry): ?int
    {
        if (($entry['incident_source_type'] ?? null) === 'client_incident') {
            $id = $this->positiveId($entry['incident_source_id'] ?? null);
            if (array_key_exists('incident_id', $entry) && $this->positiveId($entry['incident_id']) !== $id) {
                return null;
            }

            return $id;
        }

        return $this->positiveId($entry['incident_id'] ?? null);
    }

    private function incidentAccess(mixed $id, array $governance): string
    {
        $id = $this->positiveId($id);
        if ($id === null || $id !== (data_get($governance, 'incident.id'))) {
            return 'unavailable';
        }

        return ($governance['incident_access'] ?? null) === 'readable' ? 'readable' : 'restricted';
    }

    private function positiveId(mixed $id): ?int
    {
        return filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0 ? (int) $id : null;
    }

    private function sameInstant(mixed $left, mixed $right): bool
    {
        if (! is_string($left) || trim($left) === '' || ! is_string($right) || trim($right) === '') {
            return false;
        }
        try {
            return Carbon::parse($left)->equalTo(Carbon::parse($right));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Original parent READ frame is mandatory for any canonical copied-note body. */
    private function readCopiedNote(ControlRoomAlert $alert, HealthSafetyReadContext $context): array
    {
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current) use ($alert, $context): array {
                $result = app(ControlRoomGovernanceReadService::class)->consumeOperationalAlertDetails($current, (int) $alert->id, $context,
                    fn (Builder $original, CurrentAuthorizationReads $reads, User $viewer): array =>
                        $this->copiedNoteInParent($alert, $viewer, $context, $reads, $original));
                return $result['available'] ? $result['result'] : ['notes_access'=>'unavailable','notes'=>null];
            });
    }

    private function copiedNoteInParent(ControlRoomAlert $alert, User $viewer, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads, Builder $original, ?string $pattern = null): array
    {
        if(isset(self::$copyQueries[$context][(int)$alert->id])&&!$reads->query(clone self::$copyQueries[$context][(int)$alert->id])->exists()){return ['notes_access'=>'unavailable','notes'=>null];}
        $scope = fn (): Builder => app(ControlRoomAlertAccessService::class)->applyReadableScope(ControlRoomAlert::query()->whereKey($alert->id), $viewer, $reads);
        $identity = $this->intersectParent($scope(), $original, $reads);
        $metadata = $this->metadata($identity->setEagerLoads([])->select(array_map(fn ($column) => 'control_room_alerts.'.$column, self::TARGET_COLUMNS)), $reads)->first();
        if ($metadata === null) { return ['notes_access'=>'unavailable','notes'=>null]; }
        return $this->copiedNoteProjection($metadata, $scope, $viewer, $context, $reads, $original, $pattern);
    }

    private function intersectParent(Builder $query, Builder $original, CurrentAuthorizationReads $reads): Builder
    {
        $parent = (clone $original)->setEagerLoads([])->reorder()->select([])->selectRaw('1');
        $parent->toSql();
        return $reads->query($query->whereExists($parent->toBase()));
    }

    /** Identified historical/private origins cannot become ordinary text merely because a source FK is missing. */
    private function applyOrdinaryRootScope(Builder $query): Builder
    {
        return $query->whereRaw($this->ordinaryCorePredicate())
            ->whereRaw("JSON_CONTAINS_PATH(COALESCE(control_room_alerts.context, JSON_OBJECT()), 'one', '$.root_note_origin', '$.notes_provenance', '$.operational_reopen_history', '$.activity_log[*].incident_id') = 0")
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(control_room_alerts.context,'$.resolution.source')),'') <> 'incident_lifecycle_signal'")
            ->whereRaw("JSON_SEARCH(COALESCE(control_room_alerts.context,JSON_OBJECT()),'one','incident_lifecycle_signal',NULL,'$.resolution_history[*].source') IS NULL")
            ->whereNot(fn (Builder $legacy) => $legacy->where('source','compliance')->where('alert_type','Incident Reported'));
    }

    private function ordinaryCorePredicate(): string
    {
        $raw = 'COALESCE(control_room_alerts.context, JSON_OBJECT())';
        return "control_room_alerts.source <> 'incident'
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($raw, '$.provenance.source')), '') <> 'incident_journey'
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($raw, '$.incident_source_type')), '') <> 'client_incident'
            AND JSON_CONTAINS_PATH($raw, 'one', '$.saved_incident_copy', '$.saved_incident_copy_audit_id', '$.notes_provenance',
                '$.incident_id', '$.normalized_data.incident_id', '$.restraint_event_id', '$.workplace_injury_id', '$.normalized_data.workplace_injury_id') = 0
            AND control_room_alerts.alert_type NOT IN ('operations.restraint_event','operations.workplace_injury')";
    }

    /** Ordinary body SELECT executes only after parent READ/Site/source-ownership admission. */
    private function ordinaryNoteInParent(ControlRoomAlert $alert, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads, Builder $original, ?string $pattern = null): array
    {
        if(isset(self::$ordinaryQueries[$context][(int)$alert->id])&&!$reads->query(clone self::$ordinaryQueries[$context][(int)$alert->id])->exists()){return ['notes_access'=>'unavailable','notes'=>null];}
        $query = $this->applyOrdinaryRootScope($this->intersectParent(ControlRoomAlert::query()->whereKey($alert->id), $original, $reads));
        if ($pattern !== null) { $query->where('control_room_alerts.notes','like',$pattern); }
        $row = $query->first(['control_room_alerts.id','control_room_alerts.notes']);
        if ($row === null) { return ['notes_access'=>'unavailable','notes'=>null]; }
        $same = clone $query;
        if ($row->notes === null) { $same->whereNull('control_room_alerts.notes'); }
        else { $same->whereRaw('BINARY control_room_alerts.notes = BINARY ?',[$row->notes]); }
        if (! $reads->query(clone $same)->exists()) { return ['notes_access'=>'unavailable','notes'=>null]; }
        self::$ordinaryQueries ??= new \WeakMap; $queries=self::$ordinaryQueries[$context] ?? [];
        $queries[(int)$alert->id]=$same;self::$ordinaryQueries[$context]=$queries;
        return ['notes_access'=>'readable','notes'=>$row->notes];
    }

    /** Exact independent operator resolve proof, lazily resolved to avoid Governance/SourceContent DI cycle. */
    private function operatorNoteInParent(ControlRoomAlert $alert, User $viewer, HealthSafetyReadContext $context,
        CurrentAuthorizationReads $reads, ?string $pattern = null, ?Builder $executionQuery = null): array
    {
        $unavailable=['notes_access'=>'unavailable','notes'=>null];
        $retained=self::$operatorQueries[$context][(int)$alert->id]??null;
        if($retained!==null&&!$reads->query(clone$retained)->exists()){return$unavailable;}
        $result=app(ControlRoomAlertOperatorNoteReadService::class)->consume($viewer,(int)$alert->id,$context,
            function(Builder$admitted,CurrentAuthorizationReads$sameReads)use($alert,$context,$pattern,$unavailable):array{
                $query=clone$admitted;
                if($pattern!==null){$query->where('control_room_alerts.notes','like',$pattern);}
                $row=$sameReads->query($query)->first(['control_room_alerts.id','control_room_alerts.notes']);
                if($row===null){return$unavailable;}
                self::$operatorQueries??=new \WeakMap;$frames=self::$operatorQueries[$context]??[];
                // Retain reader hooks: final exists replays original unique audit + all body fingerprints.
                $frames[(int)$alert->id]??=$admitted;self::$operatorQueries[$context]=$frames;
                return['notes_access'=>'readable','notes'=>$row->notes];
            },$executionQuery);
        return$result['available']?$result['result']:$unavailable;
    }

    private function presentInContext(ControlRoomAlert $alert, array $governance, HealthSafetyReadContext $context): array
    {
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $viewer) use ($alert,$governance,$context): array {
                $result=app(ControlRoomGovernanceReadService::class)->consumeOperationalAlertDetails($viewer,(int)$alert->id,$context,
                    function(Builder $original,CurrentAuthorizationReads $reads,User $current)use($alert,$governance,$context):array{
                        $metadata=is_array($alert->context)?$alert->context:[];
                        $privateCreator=(bool)($metadata['private_source_creator']??false);
                        $privateResolver=(bool)($metadata['private_source_resolver']??false);
                        $copy=(bool)($metadata['copy_content_present']??false);
                        $operator=(bool)($metadata['operator_origin_present']??false);
                        $notes=$copy ? $this->copiedNoteInParent($alert,$current,$context,$reads,$original)
                            : ($operator ? $this->operatorNoteInParent($alert,$current,$context,$reads)
                                : $this->ordinaryNoteInParent($alert,$context,$reads,$original));
                        // R55 read proof is staged independently of the unresolved writer dependency.
                        $body=$this->readOperationalContext($alert,$context,$reads,$original);
                        $summary=Str::headline((string)$alert->alert_type);$origin=['type'=>'alert'];
                        $selection=['field'=>'alert_type','raw'=>(string)$alert->alert_type,'projection'=>'generated_headline_v1'];
                        foreach(['context.title'=>$body['title']??null,'context.summary'=>$body['summary']??null,
                            'context.description'=>$body['description']??null,'context.normalized_data.title'=>data_get($body,'normalized_data.title'),
                            'context.normalized_data.description'=>data_get($body,'normalized_data.description'),'notes'=>$notes['notes']] as $field=>$candidate){
                            if(is_string($candidate)&&trim($candidate)!==''){
                                $summary=Str::limit(trim($candidate),160);$origin=['type'=>$copy&&$candidate===$notes['notes']?'source_copy':'alert'];
                                $selection=['field'=>$field,'raw'=>$candidate,'projection'=>'trim_limit_160_v1'];break;
                            }
                        }
                        $reader=app(ControlRoomGovernanceReadService::class);
                        if($origin['type']==='alert'&&$summary===Str::headline((string)$alert->alert_type)
                            &&$reader->isCurrentRecord($governance,$context,(int)$alert->id)
                            &&($governance['incident_access']??null)==='readable'
                            &&is_string($governance['incident_detail']['title']??null)){
                            $summary=Str::limit(trim($governance['incident_detail']['title']),160);
                            $origin=['type'=>'incident','incident_id'=>$governance['incident']['id']];
                            $selection=['field'=>'incident.title','raw'=>$governance['incident_detail']['title'],'projection'=>'trim_limit_160_v1'];
                        }
                        $this->retainHandoverSummarySelection($alert,$current,$reads,$context,$original,$selection,$summary,$origin,
                            $copy||$operator||$privateCreator||$privateResolver||$this->isRestraintAlert($alert));
                        $ackFromSource=$privateResolver&&($alert->acknowledged_by_user_id===$alert->resolved_by_user_id
                            &&$this->sameInstant($alert->acknowledged_at?->toIso8601String(),$alert->resolved_at?->toIso8601String()));
                        return ['context'=>$body,'notes'=>$notes['notes'],'notes_access'=>$notes['notes_access'],
                            'source_creator_visible'=>!$privateCreator&&!$this->isRestraintAlert($alert),
                            'source_resolver_visible'=>!$privateResolver,'source_acknowledger_visible'=>!$ackFromSource,
                            'summary'=>$summary,'summary_origin'=>$origin];
                    });
                return $result['available']?$result['result']:['context'=>[],'notes'=>null,'notes_access'=>'unavailable',
                    'source_creator_visible'=>false,'source_resolver_visible'=>false,'source_acknowledger_visible'=>false,
                    'summary'=>Str::headline((string)$alert->alert_type),'summary_origin'=>['type'=>'withheld']];
            });
    }

    private function assertHandoverSummaryPlainWitness(Builder $original): void
    {
        $seen = new \SplObjectStorage;
        $walk = function ($value) use (&$walk, $seen): void {
            if ($value instanceof Builder) { $value = $value->getQuery(); }
            if ($value instanceof \Illuminate\Database\Query\Builder) {
                if (! in_array(get_class($value), [\Illuminate\Database\Query\Builder::class, \Illuminate\Database\Query\JoinClause::class], true)) {
                    throw new \LogicException('Canonical summary plain witness contains an execution carrier; coordinate its original restrictor.');
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
    private function cloneHandoverSummaryOriginal(Builder $original): Builder
    {
        $this->assertHandoverSummaryPlainWitness($original);
        $copies = new \SplObjectStorage;
        $copy = function ($value) use (&$copy, $copies) {
            if ($value instanceof Builder) {
                if (get_class($value) !== Builder::class) { throw new \LogicException('Unsupported summary Eloquent witness node.'); }
                if ($copies->contains($value)) { return $copies[$value]; }
                $cloned = clone $value; $copies[$value] = $cloned;
                // setModel changes FROM; restore the fully cloned EXACT original query afterwards.
                $query = $copy($value->getQuery()); $cloned->setModel(clone $value->getModel());
                $cloned->setQuery($query); return $cloned;
            }
            if ($value instanceof \Illuminate\Database\Query\Builder) {
                if (! in_array(get_class($value), [\Illuminate\Database\Query\Builder::class, \Illuminate\Database\Query\JoinClause::class], true)) {
                    throw new \LogicException('A guarded summary carrier is never converted to a plain witness.');
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

    private function handoverSummaryOwner(HealthSafetyReadContext $context): \stdClass
    {
        $context->assertActive();
        self::$handoverSummaryOwners ??= new \WeakMap;
        if (isset(self::$handoverSummaryOwners[$context])) {
            $owner=self::$handoverSummaryOwners[$context]->get();
            if ($owner===null) { throw new \LogicException('The original summary owner cannot be replaced.'); }
            return $owner;
        }
        $owner=(object)['frames'=>[]];
        $context->retainControlRoomOriginalReadOwner($owner);
        self::$handoverSummaryOwners[$context]=\WeakReference::create($owner);
        return $owner;
    }

    /** Callback-only actual selection metadata; callers provide no body, recipe, query or certificate. */
    public function consumeHandoverSummaryForPreparation(User $viewer, int $alertId,
        HealthSafetyReadContext $context, callable $consume): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User $current,CurrentAuthorizationReads $reads)use($viewer,$alertId,$context,$consume):array{
                if ($current!==$viewer) { throw new \LogicException('Summary capture requires the exact prepared current actor.'); }
                $unavailable=['available'=>false,'result'=>null];
                if ($alertId<1) { throw new \LogicException('A summary capture requires a positive canonical Alert id.'); }
                $owner=$this->handoverSummaryOwner($context);
                $owner->frames['summaryRequests'][$alertId]=true;
                try {
                $first=$owner->frames['summaryCaptures'][$alertId]??null;
                if ($first!==null) {
                    $this->assertHandoverSummaryIdentity($owner,$current,$reads,$context);
                    if (!$first->available) { return $unavailable; }
                    $this->verifyHandoverSummaryOriginal($owner,$current,$reads,$context);
                }
                $owner->frames['summaryActor']??=$this->cloneHandoverSummaryOriginal($context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS,[]));
                $reader=app(ControlRoomGovernanceReadService::class);
                $alert=$reader->projectCurrentAlertQuery(ControlRoomAlert::query()->whereKey($alertId),$current,$context)->first();
                if ($alert===null) {
                    $owner->frames['summaryDenials'][$alertId]=true;
                    abort(404,'The requested canonical summary source is unavailable.');
                }
                $records=$reader->forAlerts([$alert],$current,$context);
                // The actual existing selector records its FINAL winner, including every existing override.
                $this->presentInContext($alert,$records->get($alertId)??[],$context);
                $first=$owner->frames['summaryCaptures'][$alertId]??null;
                if ($first===null) {
                    $owner->frames['summaryDenials'][$alertId]=true;
                    abort(404,'The attempted supported summary source was not admitted.');
                }
                if (!$first->available) { return $unavailable; }
                $this->assertHandoverSummaryIdentity($owner,$current,$reads,$context);
                $this->verifyHandoverSummaryOriginal($owner,$current,$reads,$context);
                $result=$consume($first->capture,$current,$reads,$context);
                if (!is_array($result)) { throw new \LogicException('Summary consumers return detached arrays only.'); }
                \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                $this->verifyHandoverSummaryOriginal($owner,$current,$reads,$context);
                return ['available'=>true,'result'=>$result];
                } catch (\Throwable $error) {
                    // A caught failed supported request may never be omitted from a later collective guard.
                    $owner->frames['summaryDenials'][$alertId]=true;
                    throw $error;
                }
            });
    }

    /** Execution target only: never accept an outer Builder as source authority or export private query nodes. */
    public function guardHandoverSummaryOriginalRead(User $viewer,CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context,Builder $actualOuter):void
    {
        $context->assertActive();$reads->assertActive();
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User $current,CurrentAuthorizationReads $sameReads)use($viewer,$reads):void{
                if ($current!==$viewer||$sameReads!==$reads) { throw new \LogicException('Use the exact original summary final tuple.'); }
            });
        // A genuinely empty preparation claims no summary authority, but still guards its ORIGINAL actor.
        // The CR actual execution target retains its persisted target/receipt/scope/parent/Note proofs.
        $owner=$this->handoverSummaryOwner($context);
        $this->assertHandoverSummaryIdentity($owner,$viewer,$reads,$context);
        $owner->frames['summaryActor']??=$this->cloneHandoverSummaryOriginal($context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS,[]));
        $owner->frames['summaryOuterTargets']??=new \WeakMap;
        $target=$owner->frames['summaryOuterTargets'][$actualOuter]??null;
        if ($target===null) {
            // FIRST call must precede any E61 attach. A guarded caller carrier can never register its own plain proof.
            if (get_class($actualOuter->getQuery())!==QueryBuilder::class) {
                throw new \LogicException('Register the complete original PLAIN execution target before its first guard.');
            }
            $target=(object)['outer'=>\WeakReference::create($actualOuter),'viewer'=>$viewer,'reads'=>$reads,'context'=>$context,
                'query'=>$this->cloneHandoverSummaryOriginal($actualOuter)];
            $owner->frames['summaryOuterTargets'][$actualOuter]=$target;
        }
        $this->assertHandoverSummaryTarget($owner,$target,$actualOuter,$viewer,$reads,$context);
        $original=$this->handoverSummaryOriginal($owner,$viewer,$reads,$context);
        if ($actualOuter->getConnection()!==$original->getConnection()) {
            throw new \LogicException('The summary final execution uses another connection.');
        }
        // Flat SQL and scalar bindings expose no shallow aliases into the private FIRST/ALL graph.
        $sql=$original->toSql();$bindings=$original->getBindings();
        $actualOuter->whereRaw('exists ('.$sql.')',$bindings);
        $verify=function()use($owner,$target,$viewer,$reads,$context):void{
            $outer=$target->outer->get();
            if ($outer===null) { throw new \LogicException('The actual original summary execution target ended.'); }
            $this->assertHandoverSummaryTarget($owner,$target,$outer,$viewer,$reads,$context);
            if (!$reads->query($this->handoverSummaryFinalOriginal($owner,$target,$viewer,$reads,$context))->exists()) {
                $owner->frames['summaryDenials']['original_graph']=true;
                abort(404,'The complete original summary target/receipt/source proof changed.');
            }
        };
        // CR: plain FIRST source guard -> canonical Gov E61 guard -> SAME target source guard LAST -> actual exists.
        // The last full replay therefore follows Gov verifier SQL/listeners; no E61 carrier is ever stripped.
        ControlRoomOriginalReadOuterQuery::attach($actualOuter,$verify,$reads);
    }

    private function assertHandoverSummaryTarget(\stdClass $owner,object $target,Builder $actual,
        User $viewer,CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):void
    {
        $this->assertHandoverSummaryIdentity($owner,$viewer,$reads,$context);
        if (($owner->frames['summaryOuterTargets'][$actual]??null)!==$target||$target->outer->get()!==$actual
            ||$target->viewer!==$viewer||$target->reads!==$reads||$target->context!==$context
            ||$target->query->getConnection()!==$actual->getConnection()) {
            throw new \LogicException('Use the same original summary actual target and canonical tuple.');
        }
    }

    /** The original persisted target/unique receipt predicates AND dynamic entire private source graph. */
    private function handoverSummaryFinalOriginal(\stdClass $owner,object $target,User $viewer,
        CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):Builder
    {
        $outer=$target->outer->get();
        if ($outer===null) { throw new \LogicException('The actual summary execution target ended.'); }
        $this->assertHandoverSummaryTarget($owner,$target,$outer,$viewer,$reads,$context);
        $source=$this->handoverSummaryOriginal($owner,$viewer,$reads,$context);
        $complete=$this->cloneHandoverSummaryOriginal($target->query);
        return $reads->query($complete->whereRaw('exists ('.$source->toSql().')',$source->getBindings()));
    }
    private function assertHandoverSummaryIdentity(\stdClass $owner,User $viewer,CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context):void
    {
        $context->assertActive();$reads->assertActive();
        if ((self::$handoverSummaryOwners[$context]??null)?->get()!==$owner) {
            throw new \LogicException('Use the original summary context owner.');
        }
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User $same,CurrentAuthorizationReads $sameReads)use($viewer,$reads):void{
                if ($same!==$viewer||$sameReads!==$reads) { throw new \LogicException('Summary capture tuple changed.'); }
            });
        foreach($owner->frames['summaryCaptures']??[]as$id=>$first){
            if ($first->viewer!==$viewer||$first->reads!==$reads||$first->context!==$context||$first->alert_id!==$id) {
                throw new \LogicException('Use the exact original summary actor/read/context.');
            }
        }
    }

    /** A single collective conjunction; all private FIRST and ALL later admitted uses remain mandatory. */
    private function handoverSummaryOriginal(\stdClass $owner,User $viewer,CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context):Builder
    {
        $this->assertHandoverSummaryIdentity($owner,$viewer,$reads,$context);
        $captures=$owner->frames['summaryCaptures']??[];
        if (($owner->frames['summaryDenials']??[])!==[]) { abort(404,'An attempted supported summary read was denied.'); }
        $witnesses=[$owner->frames['summaryActor']];
        foreach($owner->frames['summaryRequests']??[]as$id=>$requested){
            if (!isset($captures[$id])) {
                $owner->frames['summaryDenials'][$id]=true;abort(404,'An original summary capture request did not complete.');
            }
        }
        foreach($captures as$first){
            // Null binding asserts no historical source authority. Preserve its original operational parent.
            if (!$first->available) { $witnesses[]=$first->parent; continue; }
            if ($first->uses===[]) { abort(404,'The original summary source is unavailable.'); }
            $witnesses[]=$first->query;
            foreach($first->uses as$use){$witnesses[]=$use;}
        }
        $complete=$reads->query(User::query()->whereKey($viewer->id))->selectRaw('1');
        foreach($witnesses as$witness){
            if (get_class($witness->getQuery())!==QueryBuilder::class) {
                throw new \LogicException('Original ordinary summary witnesses must remain plain; never strip a source guard.');
            }
            $part=$this->cloneHandoverSummaryOriginal($witness)->setEagerLoads([])->reorder()->select([])->selectRaw('1');
            $part->toSql();$complete->whereExists($part->toBase());
        }
        $this->appendSavedSummaryOriginal($complete,$owner,$viewer,$reads,$context);
        return $complete;
    }

    private function verifyHandoverSummaryOriginal(\stdClass $owner,User $viewer,CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context):void
    {
        $actual=$this->handoverSummaryOriginal($owner,$viewer,$reads,$context);
        $verify=function()use($owner,$viewer,$reads,$context):void{
            if (!$reads->query($this->handoverSummaryOriginal($owner,$viewer,$reads,$context))->exists()) {
                $owner->frames['summaryDenials']['original_graph']=true;
                abort(404,'The original collective summary proof changed.');
            }
        };
        ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$reads);
        abort_unless($actual->exists(),404,'The original collective summary read changed.');
    }

    /** Called only at the actual selector's final winner; unsupported source protocols stay explicitly unavailable. */
    private function retainHandoverSummarySelection(ControlRoomAlert $alert,User $viewer,CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context,Builder $parent,array $selection,string $summary,array $origin,bool $uncertain):void
    {
        $owner=(self::$handoverSummaryOwners[$context]??null)?->get();$id=(int)$alert->id;
        if ($owner===null||!isset($owner->frames['summaryRequests'][$id])) { return; }
        $this->assertHandoverSummaryIdentity($owner,$viewer,$reads,$context);
        $first=$owner->frames['summaryCaptures'][$id]??null;
        if ($first!==null&&!$first->available) { return; }
        $paths=['context.title','context.summary','context.description','context.normalized_data.title','context.normalized_data.description','notes'];
        $field=$selection['field'];
        $contextQuery=self::$contextQueries[$context][$id]??null;
        $notesQuery=self::$ordinaryQueries[$context][$id]??null;
        if ($uncertain||($origin['type']??null)!=='alert'||!in_array($field,['alert_type',...$paths],true)) {
            if ($first!==null) { $owner->frames['summaryDenials'][$id]=true; abort(404,'The original ordinary summary source changed.'); }
            $owner->frames['summaryCaptures'][$id]=(object)['available'=>false,'reason'=>'unsupported_source_protocol',
                'viewer'=>$viewer,'reads'=>$reads,'context'=>$context,'alert_id'=>$id,'parent'=>$this->cloneHandoverSummaryOriginal($parent)];
            return;
        }
        if ($contextQuery===null||$notesQuery===null) {
            $owner->frames['summaryDenials'][$id]=true;abort(404,'The attempted ordinary summary proof is unavailable.');
        }
        try {
            // Isolate every incoming original before ANY later public operational-context clone can mutate it.
            $parent=$this->cloneHandoverSummaryOriginal($parent);
            $contextQuery=$this->cloneHandoverSummaryOriginal($contextQuery);
            $notesQuery=$this->cloneHandoverSummaryOriginal($notesQuery);
        } catch (\LogicException $error) {
            // This branch was classified ordinary/supported; an invalid carrier is a failed proof, not an unsupported domain.
            $owner->frames['summaryDenials'][$id]=true;
            throw $error;
        }
        $query=$this->applyOrdinaryRootScope($this->intersectParent(ControlRoomAlert::query()->whereKey($id),$parent,$reads));
        $effective=app(UserSiteAccessService::class)->alertEffectiveSiteExpression($query,$reads);
        $revision="SHA2(CAST(JSON_ARRAY(control_room_alerts.id,control_room_alerts.site_id,control_room_alerts.client_id,control_room_alerts.asset_id,control_room_alerts.device_id,control_room_alerts.fleet_signal_id,control_room_alerts.source,control_room_alerts.alert_type,control_room_alerts.created_at,($effective),SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256),SHA2(control_room_alerts.notes,256),SHA2(control_room_alerts.alert_type,256)) AS CHAR CHARACTER SET utf8mb4),256)";
        $selected=$field==='alert_type'?'control_room_alerts.alert_type':($field==='notes'?'control_room_alerts.notes':"JSON_UNQUOTE(JSON_EXTRACT(control_room_alerts.context,'$.".substr($field,8)."'))");
        $query->select(['control_room_alerts.id','control_room_alerts.site_id','control_room_alerts.client_id'])
            ->selectRaw("CAST(control_room_alerts.created_at AS CHAR) as source_created_at, ($effective) as effective_site_id, $revision as summary_revision_sha256, SHA2($selected,256) as selected_sha256, control_room_alerts.alert_type as original_alert_type");
        $row=$query->toBase()->first();
        if ($row===null||!is_string($row->summary_revision_sha256)||!is_string($row->selected_sha256)
            ||!hash_equals(hash('sha256',$selection['raw']),$row->selected_sha256)
            ||($field==='alert_type'&&$selection['raw']!==$row->original_alert_type)) {
            $owner->frames['summaryDenials'][$id]=true;abort(404,'The actual selected summary source changed.');
        }
        $expected=ControlRoomHandoverSummaryProtocol::project($selection['projection'],$selection['raw']);
        if ($expected!==$summary) { abort(404,'The actual final summary recipe changed.'); }
        $source=['kind'=>$field==='alert_type'?'generated_metadata':'ordinary_alert_field','alert_id'=>$id,
            'site_id'=>$row->site_id===null?null:(int)$row->site_id,'client_id'=>$row->client_id===null?null:(int)$row->client_id,
            'effective_site_id'=>$row->effective_site_id===null?null:(int)$row->effective_site_id,'created_at'=>$row->source_created_at,
            'field'=>$field,'revision_sha256'=>$row->summary_revision_sha256];
        if($field==='alert_type'){$source['alert_type']=$row->original_alert_type;}
        $capture=['projection'=>$selection['projection'],'source'=>$source,'source_sha256'=>$row->selected_sha256,
            'summary_sha256'=>hash('sha256',$summary),'precedence'=>['version'=>1,
                'earlier_absent'=>$field==='alert_type'?$paths:array_slice($paths,0,array_search($field,$paths,true))]];
        $same=(clone$query)->whereRaw($revision.' = ?',[$row->summary_revision_sha256])->whereRaw('SHA2('.$selected.',256) = ?',[$row->selected_sha256]);
        $uses=[$parent,$contextQuery,$notesQuery,$this->cloneHandoverSummaryOriginal($same)];
        if ($first===null) {
            $first=(object)['available'=>true,'viewer'=>$viewer,'reads'=>$reads,'context'=>$context,'alert_id'=>$id,
                'capture'=>$capture,'query'=>$this->cloneHandoverSummaryOriginal($same),'uses'=>[]];
            $owner->frames['summaryCaptures'][$id]=$first;
        } elseif ($first->capture!==$capture) { abort(404,'The original summary recipe cannot be rebased.'); }
        foreach($uses as$use){$first->uses[]=$use;}
        $this->verifyHandoverSummaryOriginal($owner,$viewer,$reads,$context);
    }
    /** Only this canonical owner callback may create a saved-summary source admission. */
    private function savedSnapshotSummary(User $viewer,object $reference,int $ordinal,int $alertId,
        HealthSafetyReadContext $context):array
    {
        $context->assertActive();
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User $current,CurrentAuthorizationReads $reads)use($viewer,$reference,$ordinal,$alertId,$context):array{
                if ($viewer!==$current) { throw new \LogicException('Use the exact prepared saved-summary reader.'); }
                $unavailable=['available'=>false,'result'=>null];
                $ownerResult=app(ControlRoomHandoverReadAccessService::class)->consumeSavedSummaryRecipe($current,$reference,$ordinal,$alertId,$context,
                    function(array $metadata,array $constraint,callable $recipeProof,User $same,CurrentAuthorizationReads $sameReads,HealthSafetyReadContext $sameContext)
                        use($current,$reads,$reference,$ordinal,$alertId,$context,$unavailable):array{
                        if ($same!==$current||$sameReads!==$reads||$sameContext!==$context||!$recipeProof instanceof \Closure) {
                            throw new \LogicException('Saved recipes require the original privately owned tuple and closure.');
                        }
                        if (array_keys($metadata)!==['row','shift','manifest','receipt']||array_keys($constraint)!==['sql','bindings']
                            ||!is_string($constraint['sql'])||preg_match('/^\s*select\s+1\b/i',$constraint['sql'])!==1
                            ||!is_array($constraint['bindings'])||!array_is_list($constraint['bindings'])) {
                            throw new \LogicException('Use the canonical metadata-only original saved recipe constraint.');
                        }
                        foreach($constraint['bindings']as$value){
                            if ($value!==null&&!is_string($value)&&!is_int($value)&&!is_bool($value)) {
                                throw new \LogicException('Saved recipe constraints contain scalar metadata only.');
                            }
                        }
                        $row=$metadata['row'];
                        if (!is_array($row)||($row['ordinal']??null)!==$ordinal||($row['alert_id']??null)!==$alertId) {
                            throw new \LogicException('The original saved recipe row changed.');
                        }
                        $binding=ControlRoomHandoverSummaryProtocol::preservedBinding($row['summary_binding']??null);
                        if ($binding===null||!hash_equals($binding['summary_sha256'],$row['summary_sha256'])
                            ||$binding['target']['ordinal']!==$ordinal||$binding['target']['alert_id']!==$alertId) { return $unavailable; }
                        $owner=$this->handoverSummaryOwner($context);$key=spl_object_id($reference).':'.$ordinal;
                        $first=$owner->frames['savedSummaryFirst'][$key]??null;
                        $metadataHash=ControlRoomHandoverSummaryProtocol::digest($metadata);
                        if ($first===null) {
                            $first=(object)['state'=>'pending','reference'=>$reference,'ordinal'=>$ordinal,'alert_id'=>$alertId,
                                'viewer'=>$current,'viewer_id'=>(int)$current->id,'reads'=>$reads,'context'=>$context,'row'=>$row,'metadata_sha256'=>$metadataHash,
                                'constraint'=>$constraint,'uses'=>[],'binding'=>$binding,'token'=>null];
                            $owner->frames['savedSummaryFirst'][$key]=$first;
                        } else {
                            $this->assertSavedSummaryEntry($owner,$first,$current,$reads,$context);
                            if ($first->reference!==$reference||$first->row!==$row||$first->metadata_sha256!==$metadataHash
                                ||$first->constraint!==$constraint) {
                                $first->state='denied';abort(404,'The original saved recipe cannot be rebased.');
                            }
                        }
                        $first->uses[]=['constraint'=>$constraint,'proof'=>$recipeProof];
                        try {
                            $actual=$this->consumeHandoverSummaryForPreparation($current,$alertId,$context,
                                function(array $capture,User $captureCurrent,CurrentAuthorizationReads $captureReads,HealthSafetyReadContext $captureContext)
                                    use($owner,$first,$current,$reads,$context,$reference,$ordinal,$alertId,$binding,$unavailable):array{
                                    if ($captureCurrent!==$current||$captureReads!==$reads||$captureContext!==$context) {
                                        throw new \LogicException('The current source capture belongs to another original read.');
                                    }
                                    $recorded=array_intersect_key($binding,array_flip(['projection','source','source_sha256','summary_sha256','precedence']));
                                    if (!hash_equals(ControlRoomHandoverSummaryProtocol::digest($recorded),ControlRoomHandoverSummaryProtocol::digest($capture))) {
                                        if ($first->state==='ready') { $first->state='denied';abort(404,'The originally admitted saved source changed.'); }
                                        $first->state='withheld';return $unavailable;
                                    }
                                    $source=$owner->frames['summaryCaptures'][$alertId]??null;
                                    if ($source===null||!$source->available) { throw new \LogicException('The actual canonical source capture is missing.'); }
                                    if ($first->token===null) {
                                        $first->source=$source;$first->token=new \stdClass;
                                        $owner->frames['savedSummaryAdmissions']??=new \WeakMap;
                                        $owner->frames['savedSummaryAdmissions'][$first->token]=$first;
                                    } elseif ($first->source!==$source) { $first->state='denied';abort(404,'The original source capture identity changed.'); }
                                    $first->state='ready';
                                    $this->verifySavedSummaryOriginal($owner,$current,$reads,$context);
                                    return app(ControlRoomHandoverReadAccessService::class)->consumeSavedSummary($current,$reference,$ordinal,$alertId,$context,$first->token,
                                        static fn(array $body):array=>['summary'=>$body['summary']]);
                                });
                            if (!$actual['available']) {
                                if ($first->state==='ready') { $first->state='denied';abort(404,'The admitted source protocol became unavailable.'); }
                                $first->state='unsupported';return $unavailable;
                            }
                            return $actual['result'];
                        } catch (\Throwable $error) { $first->state='denied';throw $error; }
                    });
                return $ownerResult['available']?$ownerResult['result']:$unavailable;
            });
    }

    /** Exact opaque token/row/reference/current tuple gate, before ANY SQL or supplied callback. */
    public function consumeSavedSummarySourceAdmission(User $viewer,HealthSafetyReadContext $context,
        object $reference,array $originalRow,object $admission,callable $consume):array
    {
        $context->assertActive();$unavailable=['available'=>false,'result'=>null];
        $owner=(self::$handoverSummaryOwners[$context]??null)?->get();
        $entry=$owner===null?null:($owner->frames['savedSummaryAdmissions'][$admission]??null);
        if ($entry===null||$entry->state!=='ready'||$entry->token!==$admission||$entry->reference!==$reference
            ||$entry->row!==$originalRow||$entry->viewer!==$viewer||$entry->viewer_id!==(int)$viewer->id||$entry->context!==$context) { return $unavailable; }
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User $current,CurrentAuthorizationReads $reads)use($owner,$entry,$viewer,$context,$reference,$originalRow,$admission,$consume,$unavailable):array{
                if ($current!==$viewer||$entry->reads!==$reads) { return $unavailable; }
                try {
                $this->assertSavedSummaryEntry($owner,$entry,$current,$reads,$context);
                $this->verifySavedSummaryOriginal($owner,$current,$reads,$context);
                $fullProof=function(array $row,User $same,CurrentAuthorizationReads $sameReads,HealthSafetyReadContext $sameContext)
                    use($owner,$admission,$reference,$context):bool{
                    $found=$owner->frames['savedSummaryAdmissions'][$admission]??null;
                    $context->assertActive();$sameReads->assertActive();
                    if ($found===null||$found->token!==$admission||$found->state!=='ready'||$found->reference!==$reference
                        ||$found->row!==$row||$found->viewer!==$same||$found->viewer_id!==(int)$same->id||$found->reads!==$sameReads||$found->context!==$sameContext||$sameContext!==$context) {
                        throw new \LogicException('Use the exact originally admitted saved source and tuple.');
                    }
                    $this->assertSavedSummaryEntry($owner,$found,$same,$sameReads,$sameContext);
                    $this->verifySavedSummaryOriginal($owner,$same,$sameReads,$sameContext);return true;
                };
                $source=$this->cloneHandoverSummaryOriginal($entry->source->query);
                $all=$this->handoverSummaryOriginal($owner,$current,$reads,$context);
                $source->whereRaw('exists ('.$all->toSql().')',$all->getBindings());
                    // Isolate source nodes on every outward use; callbacks/authority carriers remain intact.
                    $result=$consume($this->cloneHandoverSummaryOriginal($source),$fullProof,$current,$reads,$context);
                    if (!is_array($result)) { throw new \LogicException('Saved source consumers return detached arrays only.'); }
                    \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                    $fullProof($originalRow,$current,$reads,$context);
                    return ['available'=>true,'result'=>$result];
                } catch (\Throwable $error) { $entry->state='denied';throw $error; }
            });
    }

    private function assertSavedSummaryEntry(\stdClass $owner,object $entry,User $viewer,
        CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):void
    {
        $this->assertHandoverSummaryIdentity($owner,$viewer,$reads,$context);
        $key=spl_object_id($entry->reference).':'.$entry->ordinal;
        if (($owner->frames['savedSummaryFirst'][$key]??null)!==$entry||$entry->viewer!==$viewer||$entry->reads!==$reads
            ||$entry->context!==$context||$entry->viewer_id!==(int)$viewer->id||$entry->alert_id!==$entry->row['alert_id']||$entry->ordinal!==$entry->row['ordinal']) {
            throw new \LogicException('Use the immutable original saved-summary frame.');
        }
        if ($entry->state==='denied') { abort(404,'The original saved-summary source admission was denied.'); }
    }

    /** Original metadata constraints are private; no saved body or raw source graph is exported. */
    private function appendSavedSummaryOriginal(Builder $complete,\stdClass $owner,User $viewer,
        CurrentAuthorizationReads $reads,HealthSafetyReadContext $context):void
    {
        foreach($owner->frames['savedSummaryFirst']??[]as$entry){
            $this->assertSavedSummaryEntry($owner,$entry,$viewer,$reads,$context);
            foreach([$entry->constraint,...array_column($entry->uses,'constraint')]as$constraint){
                $complete->whereRaw('exists ('.$constraint['sql'].')',$constraint['bindings']);
            }
        }
    }

    private function replaySavedRecipeOwners(\stdClass $owner,User $viewer,CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context,bool $final=false):void
    {
        foreach($owner->frames['savedSummaryFirst']??[]as$entry){
            $this->assertSavedSummaryEntry($owner,$entry,$viewer,$reads,$context);
            if ($final&&$entry->state==='pending') { $entry->state='denied';abort(404,'An original saved-summary admission did not finish.'); }
            try {
                foreach($entry->uses as$use){
                    if (($use['proof'])()!==null) { throw new \LogicException('Use the privately owned void saved-recipe replay.'); }
                }
            } catch (\Throwable $error) { $entry->state='denied';throw $error; }
        }
    }

    private function verifySavedSummaryOriginal(\stdClass $owner,User $viewer,CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context):void
    {
        $verify=function()use($owner,$viewer,$reads,$context):void{
            $this->replaySavedRecipeOwners($owner,$viewer,$reads,$context);
            if (!$reads->query($this->handoverSummaryOriginal($owner,$viewer,$reads,$context))->exists()) {
                $owner->frames['summaryDenials']['saved_original_graph']=true;abort(404,'The complete saved recipe/source graph changed.');
            }
        };
        $this->replaySavedRecipeOwners($owner,$viewer,$reads,$context);
        $actual=$this->handoverSummaryOriginal($owner,$viewer,$reads,$context);
        ControlRoomOriginalReadOuterQuery::attach($actual,$verify,$reads);
        abort_unless($actual->exists(),404,'The original saved-summary graph is unavailable.');
    }

    /** Called LAST by the canonical dynamic CR compose, before its existing E61 whole-response final SQL. */
    public function constrainOriginalSavedSummaryRead(User $viewer,CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context,Builder $actualPlain):void
    {
        $context->assertActive();$reads->assertActive();
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User $same,CurrentAuthorizationReads $sameReads)use($viewer,$reads):void{
                if ($same!==$viewer||$sameReads!==$reads) { throw new \LogicException('Use the exact original saved-response tuple.'); }
            });
        if (get_class($actualPlain->getQuery())!==QueryBuilder::class) {
            throw new \LogicException('Constrain the actual original PLAIN CR compose; never strip an E61 carrier.');
        }
        $registered=isset(self::$handoverSummaryOwners[$context]);
        $owner=(self::$handoverSummaryOwners[$context]??null)?->get();
        if ($owner===null) {
            if ($registered) { throw new \LogicException('The original saved-summary owner was lost.'); }
            return; // No source admission is claimed for a legitimate never-admitted empty/unbound response.
        }
        $this->assertHandoverSummaryIdentity($owner,$viewer,$reads,$context);
        $this->replaySavedRecipeOwners($owner,$viewer,$reads,$context,true);
        $original=$this->handoverSummaryOriginal($owner,$viewer,$reads,$context);
        $actualPlain->whereRaw('exists ('.$original->toSql().')',$original->getBindings());
    }
    /** Source history is materialized as typed metadata before SQL hydration; no prose masking after retrieval. */
    private function historyProjection(string $key, bool $metadataOnly = false): string
    {
        $path='$.'.$key;
        $private="JSON_CONTAINS_PATH(history_item.entry,'one','$.incident_id','$.incident_source_type') = 1
            OR JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.source'))='incident_lifecycle_signal'
            OR JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='incident_reopen'
            OR JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.type'))='incident_reopened'";
        if($key==='operational_reopen_history'){$private='1=1';}
        $allowed=['type','transition','content','user_id','user_name','created_at','purpose','operator_note_id'];
        $keyCount=implode(' + ',array_map(fn($field)=>"JSON_CONTAINS_PATH(history_item.entry,'one','$.$field')",$allowed));
        // Lifecycle DATE_ATOM history has second precision; ISO audit instant may have fractional seconds.
        $instant = static fn (string $value): string => "CONVERT_TZ(STR_TO_DATE(LEFT($value,19),'%Y-%m-%dT%H:%i:%s'),CASE WHEN RIGHT($value,1)='Z' THEN '+00:00' ELSE RIGHT($value,6) END,'+00:00')";
        $entryAt = $instant("JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.created_at'))");
        $auditAt = $instant("JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.transitioned_at'))");
        $sameLifecycleInstant = "($entryAt IS NOT NULL AND $auditAt IS NOT NULL AND $entryAt=$auditAt)";
        $morph = bin2hex((new ControlRoomAlert)->getMorphClass());
        $auditMatch="ordinary_activity_audit.auditable_id=control_room_alerts.id
                AND BINARY ordinary_activity_audit.auditable_type=BINARY CONVERT(UNHEX('$morph') USING utf8mb4)
                AND JSON_EXTRACT(ordinary_activity_audit.meta,'$.actor_id')=CAST(JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.user_id')) AS UNSIGNED)
                AND ordinary_activity_audit.user_id IS NOT NULL
                AND EXISTS(SELECT 1 FROM users ordinary_history_request_actor WHERE ordinary_history_request_actor.id=ordinary_activity_audit.user_id FOR SHARE NOWAIT)
                AND (ordinary_activity_audit.client_id <=> control_room_alerts.client_id)
                AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.actor_id'))='INTEGER'
                AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.alert_id'))='INTEGER'
                AND JSON_EXTRACT(ordinary_activity_audit.meta,'$.alert_id')=control_room_alerts.id
                
                AND JSON_CONTAINS_PATH(ordinary_activity_audit.meta,'one','$.incident_id','$.resolution_source','$.origin','$.saved_incident_copy')=0
                AND ((BINARY ordinary_activity_audit.action=BINARY 'controlRoom.alert.addNote'
                        AND JSON_LENGTH(ordinary_activity_audit.meta)=6
                        AND JSON_CONTAINS_PATH(ordinary_activity_audit.meta,'all','$.actor_id','$.alert_id','$.note_id','$.note_type','$.note_purpose','$.transition')=1
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.note_id'))='INTEGER'
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.note_type'))='STRING'
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.note_purpose'))='STRING'
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.transition'))='STRING'
                        AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.type'))='lifecycle_note'
                        AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='note'
                        AND JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.transition'))='note'
                        AND JSON_TYPE(JSON_EXTRACT(history_item.entry,'$.operator_note_id'))='INTEGER'
                        AND JSON_EXTRACT(ordinary_activity_audit.meta,'$.note_id')=JSON_EXTRACT(history_item.entry,'$.operator_note_id')
                        AND EXISTS(SELECT 1 FROM control_room_operator_notes ordinary_history_note
                            WHERE ordinary_history_note.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.operator_note_id')) AS UNSIGNED)
                            AND ordinary_history_note.alert_id=control_room_alerts.id
                            AND ordinary_history_note.user_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.user_id')) AS UNSIGNED)
                            AND ordinary_history_note.shift_id IS NULL
                            AND ordinary_history_note.type='note'
                            AND BINARY ordinary_history_note.content=BINARY JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.content'))
                            AND BINARY ordinary_history_note.purpose=BINARY JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.purpose'))
                            AND BINARY ordinary_history_note.type=BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.note_type'))
                            AND BINARY ordinary_history_note.purpose=BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.note_purpose')) FOR SHARE NOWAIT))
                    OR (BINARY ordinary_activity_audit.action=BINARY 'controlRoom.alert.assign'
                        AND JSON_LENGTH(ordinary_activity_audit.meta)=5
                        AND JSON_CONTAINS_PATH(ordinary_activity_audit.meta,'all','$.actor_id','$.alert_id','$.assigned_to','$.assigned_by','$.assignment_note')=1
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.assigned_by'))='INTEGER'
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.assignment_note'))='STRING'
                        AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.type'))='assignment_note'
                        AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='assignment'
                        AND JSON_CONTAINS_PATH(history_item.entry,'one','$.operator_note_id','$.purpose')=0
                        AND JSON_EXTRACT(ordinary_activity_audit.meta,'$.assigned_by')=JSON_EXTRACT(ordinary_activity_audit.meta,'$.actor_id')
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.assigned_to'))='INTEGER'
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.assignment_note'))=BINARY JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.content')))
                    OR (BINARY ordinary_activity_audit.action=BINARY 'controlRoom.alert.acknowledge'
                        AND JSON_LENGTH(ordinary_activity_audit.meta)=6
                        AND JSON_CONTAINS_PATH(ordinary_activity_audit.meta,'all','$.actor_id','$.alert_id','$.from_status','$.to_status','$.operator_note','$.transitioned_at')=1
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.operator_note'))='STRING'
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.transitioned_at'))='STRING'
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.from_status'))=BINARY 'open'
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.to_status'))=BINARY 'ack' AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='acknowledge'
                        AND $sameLifecycleInstant
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.operator_note'))=BINARY JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.content')))
                    OR (BINARY ordinary_activity_audit.action=BINARY 'controlRoom.alert.triage'
                        AND JSON_LENGTH(ordinary_activity_audit.meta)=6
                        AND JSON_CONTAINS_PATH(ordinary_activity_audit.meta,'all','$.actor_id','$.alert_id','$.from_status','$.to_status','$.operator_note','$.transitioned_at')=1
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.operator_note'))='STRING'
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.transitioned_at'))='STRING'
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.from_status'))=BINARY 'ack'
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.to_status'))=BINARY 'triaging' AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='triage'
                        AND $sameLifecycleInstant
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.operator_note'))=BINARY JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.content')))
                    OR (FALSE /* Pending canonical exact10-note/exact8-resolve activity reader: root6 is insufficient when prior Notes retained. */ AND ordinary_activity_audit.action='controlRoom.alert.resolve' AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='resolution'
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.resolution_notes'))=BINARY JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.content')))
                    OR (BINARY ordinary_activity_audit.action=BINARY 'controlRoom.alert.close'
                        AND JSON_LENGTH(ordinary_activity_audit.meta)=6
                        AND JSON_CONTAINS_PATH(ordinary_activity_audit.meta,'all','$.actor_id','$.alert_id','$.from_status','$.to_status','$.closure_notes','$.transitioned_at')=1
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.closure_notes'))='STRING'
                        AND JSON_TYPE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.transitioned_at'))='STRING'
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.from_status'))=BINARY 'resolved'
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.to_status'))=BINARY 'closed' AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='closure'
                        AND $sameLifecycleInstant
                        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(ordinary_activity_audit.meta,'$.closure_notes'))=BINARY JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.content'))))";
        $receiptMatch=str_replace('ordinary_activity_audit','history_first_receipt',$auditMatch);
        $known="JSON_TYPE(history_item.entry)='OBJECT'
            AND JSON_LENGTH(history_item.entry)=($keyCount)
            AND EXISTS(SELECT 1 FROM users ordinary_activity_author WHERE ordinary_activity_author.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.user_id'))AS UNSIGNED) FOR SHARE NOWAIT)
            AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.type')) IN ('lifecycle_note','assignment_note')
            AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition')) IN ('acknowledge','triage','resolution','closure','assignment','note')
            AND ((JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.type'))='lifecycle_note'
                    AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='note' AND JSON_LENGTH(history_item.entry)=8)
                OR (JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.type'))='lifecycle_note'
                    AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition')) IN ('acknowledge','triage','resolution','closure')
                    AND JSON_LENGTH(history_item.entry)=6 AND JSON_CONTAINS_PATH(history_item.entry,'one','$.purpose','$.operator_note_id')=0)
                OR (JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.type'))='assignment_note'
                    AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.transition'))='assignment' AND JSON_LENGTH(history_item.entry)=6
                    AND JSON_CONTAINS_PATH(history_item.entry,'one','$.purpose','$.operator_note_id')=0))
            AND JSON_TYPE(JSON_EXTRACT(history_item.entry,'$.user_id'))='INTEGER'
            AND JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.user_id')) REGEXP '^[1-9][0-9]{0,18}$'
            AND JSON_TYPE(JSON_EXTRACT(history_item.entry,'$.content'))='STRING'
            AND JSON_TYPE(JSON_EXTRACT(history_item.entry,'$.created_at'))='STRING'
            AND JSON_CONTAINS_PATH(history_item.entry,'one','$.saved_incident_copy','$.notes_provenance','$.root_note_origin',
                '$.operator_origin','$.normalized_data.incident_id','$.provenance','$.source','$.incident_source_id')=0
            AND EXISTS(SELECT 1 FROM audit_logs ordinary_activity_audit WHERE ($auditMatch) FOR SHARE NOWAIT)";
        // Destination copy classification is metadata only and precedes ordinary note content/author SQL.
        // Positive saved-copy history awaits the canonical source-owned projected-copy hookup; never downgrade it to ordinary.
        $knownDestinationCopy = "EXISTS(SELECT 1 FROM incident_saved_copies ordinary_history_copy
            WHERE ordinary_history_copy.target_family='control_room_operator_note'
            AND ordinary_history_copy.target_key=CAST(JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.operator_note_id')) AS CHAR) FOR SHARE NOWAIT)";
        $originalReceipts = "COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('id',history_first_receipt.id,
                'fingerprint',SHA2(CAST(JSON_ARRAY(history_first_receipt.user_id,history_first_receipt.client_id,
                    history_first_receipt.action,history_first_receipt.auditable_type,history_first_receipt.auditable_id,history_first_receipt.meta) AS CHAR CHARACTER SET utf8mb4),256)))
                OVER (ORDER BY history_first_receipt.id ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)
            FROM audit_logs history_first_receipt WHERE ($receiptMatch)
            ORDER BY history_first_receipt.id LIMIT 1 FOR SHARE NOWAIT),JSON_ARRAY())";
        $originalAuthor = "(SELECT SHA2(CAST(JSON_ARRAY(history_first_author.id,history_first_author.name) AS CHAR CHARACTER SET utf8mb4),256)
            FROM users history_first_author WHERE history_first_author.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.user_id')) AS UNSIGNED) LIMIT 1 FOR SHARE NOWAIT)";
        $originalNote = "(SELECT SHA2(CAST(JSON_ARRAY(history_first_note.id,history_first_note.alert_id,history_first_note.shift_id,
                history_first_note.user_id,history_first_note.type,history_first_note.purpose,history_first_note.content,history_first_note.created_at) AS CHAR CHARACTER SET utf8mb4),256)
            FROM control_room_operator_notes history_first_note WHERE history_first_note.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.operator_note_id')) AS UNSIGNED)
            AND history_first_note.alert_id=control_room_alerts.id LIMIT 1 FOR SHARE NOWAIT)";
        if ($metadataOnly) {
            // Metadata identities only: copied/unknown entries cannot seed an ordinary author/source trace.
            return "COALESCE((SELECT JSON_ARRAYAGG(CASE WHEN $knownDestinationCopy THEN JSON_OBJECT('access','unavailable')
                WHEN ($known) AND NOT COALESCE(($private),FALSE) THEN JSON_OBJECT('ordinal',history_item.ordinal,
                    'actor_id',JSON_EXTRACT(history_item.entry,'$.user_id'),'operator_note_id',JSON_EXTRACT(history_item.entry,'$.operator_note_id'),
                    'receipt_set',$originalReceipts,'author_fp',$originalAuthor,'note_fp',$originalNote)
                ELSE JSON_OBJECT('access','unavailable') END) OVER (ORDER BY history_item.ordinal ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)
                FROM JSON_TABLE(CASE WHEN JSON_TYPE(JSON_EXTRACT(control_room_alerts.context,'$path'))='ARRAY'
                    THEN JSON_EXTRACT(control_room_alerts.context,'$path') ELSE JSON_ARRAY() END,
                    '$[*]' COLUMNS(ordinal FOR ORDINALITY,entry JSON PATH '$')) history_item ORDER BY history_item.ordinal LIMIT 1),JSON_ARRAY())";
        }
        $fields=['type','transition','resolved_at','reopened_at','created_at','from_status','to_status','requires_operational_reopen'];
        $minimal=implode(', ',array_map(fn($field)=>"'$field',JSON_EXTRACT(history_item.entry,'$.$field')",$fields));
        return "COALESCE((SELECT JSON_ARRAYAGG(CASE WHEN $knownDestinationCopy THEN JSON_MERGE_PATCH(JSON_OBJECT('access','unavailable','body_access','unavailable'),JSON_OBJECT($minimal)) WHEN ($known) AND NOT COALESCE(($private),FALSE)
            THEN JSON_MERGE_PATCH(JSON_OBJECT(),JSON_OBJECT('type',JSON_EXTRACT(history_item.entry,'$.type'),'transition',JSON_EXTRACT(history_item.entry,'$.transition'),'content',JSON_EXTRACT(history_item.entry,'$.content'),'user_id',JSON_EXTRACT(history_item.entry,'$.user_id'),'user_name',(SELECT ordinary_history_author.name FROM users ordinary_history_author WHERE ordinary_history_author.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(history_item.entry,'$.user_id'))AS UNSIGNED) LIMIT 1 FOR SHARE NOWAIT),'created_at',JSON_EXTRACT(history_item.entry,'$.created_at'),'purpose',JSON_EXTRACT(history_item.entry,'$.purpose'),'operator_note_id',JSON_EXTRACT(history_item.entry,'$.operator_note_id'))) ELSE JSON_MERGE_PATCH(JSON_OBJECT('access','unavailable','body_access','unavailable'),JSON_OBJECT($minimal)) END) OVER (ORDER BY history_item.ordinal ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)
            FROM JSON_TABLE(CASE WHEN JSON_TYPE(JSON_EXTRACT(control_room_alerts.context,'$path'))='ARRAY'
                THEN JSON_EXTRACT(control_room_alerts.context,'$path') ELSE JSON_ARRAY() END,
                '$[*]' COLUMNS(ordinal FOR ORDINALITY,entry JSON PATH '$')) history_item ORDER BY history_item.ordinal LIMIT 1), JSON_ARRAY())";
    }


    private function resolutionProjection(): string
    {
        $entry="JSON_EXTRACT(control_room_alerts.context,'$.resolution')";
        return "CASE WHEN JSON_TYPE($entry)='OBJECT' THEN JSON_MERGE_PATCH(JSON_OBJECT('body_access','unavailable'),JSON_OBJECT(
            'resolved_at',JSON_EXTRACT($entry,'$.resolved_at'),'source',JSON_EXTRACT($entry,'$.source'))) ELSE NULL END";
    }

    /** Additional original trace; independent current Alert READ remains the admission authority. */
    private function retainOrdinaryHistoryTrace(ControlRoomAlert $alert, HealthSafetyReadContext $context,
        CurrentAuthorizationReads $reads, Builder $original, Builder $body): Builder
    {
        $reads->assertActive();
        $metadata = $this->intersectParent(ControlRoomAlert::query()->whereKey($alert->id),$original,$reads)
            ->select('control_room_alerts.id')->selectRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256) as history_root_fp");
        foreach (['resolution_history','operational_reopen_history','activity_log'] as $key) {
            $metadata->selectRaw($this->historyProjection($key,true).' as '.$key);
        }
        $first = $metadata->first();
        abort_unless($first,404);
        $body->whereRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256)=?",[$first->getRawOriginal('history_root_fp')]);
        foreach (['resolution_history','operational_reopen_history','activity_log'] as $key) {
            $body->whereRaw('BINARY CAST(('.$this->historyProjection($key,true).') AS CHAR)=BINARY ?',[$first->getRawOriginal($key)]);
        }
        $actors=[];$notes=[];$receiptIds=[];
        foreach (['resolution_history','operational_reopen_history','activity_log'] as $key) {
            foreach ($this->decode($first->getRawOriginal($key))??[] as $entry) {
                if (!is_array($entry)||isset($entry['access'])) {continue;}
                if (($id=$this->positiveId($entry['actor_id']??null))!==null) {$actors[]=$id;}
                if (($id=$this->positiveId($entry['operator_note_id']??null))!==null) {$notes[]=$id;}
                foreach ($entry['receipt_set']??[] as $receipt) {
                    if (is_array($receipt)&&($id=$this->positiveId($receipt['id']??null))!==null) {$receiptIds[]=$id;}
                }
            }
        }
        $actors=array_values(array_unique($actors));$notes=array_values(array_unique($notes));$receiptIds=array_values(array_unique($receiptIds));
        // Hash source/author bytes only after the metadata CASE has positively admitted the ordinary entry.
        $authors=$this->intersectParent(User::query()->whereIn('users.id',$actors),$original,$reads);
        $body=$this->retainHistoryRows($body,$authors,$reads,"SHA2(CAST(JSON_ARRAY(users.id,users.name) AS CHAR CHARACTER SET utf8mb4),256)");
        $morph=bin2hex($alert->getMorphClass());
        $audits=$this->intersectParent(AuditLog::query()->whereIn('audit_logs.id',$receiptIds)->where('auditable_id',$alert->id)
            ->whereRaw("BINARY auditable_type=BINARY CONVERT(UNHEX('$morph') USING utf8mb4)")
            ->whereRaw('client_id <=> ?',[$alert->client_id])
            ->whereIn('action',['controlRoom.alert.addNote','controlRoom.alert.assign','controlRoom.alert.acknowledge','controlRoom.alert.triage','controlRoom.alert.close'])
            ->whereIn('meta->actor_id',$actors),$original,$reads);
        // Keep the full original matching candidate set; lawful duplicate events are not made a uniqueness rule.
        $body=$this->retainHistoryRows($body,$audits,$reads,"SHA2(CAST(JSON_ARRAY(audit_logs.id,audit_logs.user_id,audit_logs.client_id,audit_logs.action,audit_logs.auditable_type,audit_logs.auditable_id,audit_logs.meta) AS CHAR CHARACTER SET utf8mb4),256)");
        $noteQuery=$this->intersectParent(\App\Models\ControlRoom\OperatorNote::query()->where('alert_id',$alert->id)->whereIn('id',$notes),$original,$reads)
            ->whereRaw("NOT EXISTS(SELECT 1 FROM incident_saved_copies history_trace_copy WHERE history_trace_copy.target_family='control_room_operator_note'
                AND history_trace_copy.target_key=CAST(control_room_operator_notes.id AS CHAR) FOR SHARE NOWAIT)");
        $body=$this->retainHistoryRows($body,$noteQuery,$reads,"SHA2(CAST(JSON_ARRAY(control_room_operator_notes.id,control_room_operator_notes.alert_id,control_room_operator_notes.shift_id,control_room_operator_notes.user_id,control_room_operator_notes.type,control_room_operator_notes.purpose,control_room_operator_notes.content,control_room_operator_notes.created_at) AS CHAR CHARACTER SET utf8mb4),256)");
        return $body;
    }

    /** First actual current source set/bytes, replayed by the retained body query before body and final wire. */
    private function retainHistoryRows(Builder $body, Builder $source, CurrentAuthorizationReads $reads, string $fingerprint): Builder
    {
        $reads->assertActive();
        $key=$source->getModel()->getQualifiedKeyName();
        $rows=$reads->query(clone $source)->select($key)->selectRaw($fingerprint.' as history_trace_fp')->orderBy($key)->get();
        $ids=$rows->modelKeys();
        $newRows=(clone $source)->whereNotIn($key,$ids)->setEagerLoads([])->reorder()->select([])->selectRaw('1');
        $newRows->toSql();$body->whereNotExists($newRows->toBase());
        foreach ($rows as $row) {
            $retained=(clone $source)->whereKey($row->getKey())->whereRaw('('.$fingerprint.')=?',[$row->getRawOriginal('history_trace_fp')])
                ->setEagerLoads([])->reorder()->select([])->selectRaw('1');
            $retained->toSql();$body->whereExists($retained->toBase());
        }
        return $body;
    }

    /** Resolution identities only; unknown/source/copied entries never become ordinary history. */
    private function resolutionActivityDiscoveryExpression(): string
    {
        $entry='resolution_identity.entry';
        $keys=['type','transition','content','user_id','user_name','created_at','purpose','operator_note_id'];
        $paths=implode(',',array_map(fn($key)=>"'$.$key'",$keys));
        return "COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('ordinal',resolution_identity.ordinal,
                'operator_note_id',JSON_EXTRACT($entry,'$.operator_note_id'),'entry_fp',SHA2(CAST($entry AS CHAR CHARACTER SET utf8mb4),256)))
                OVER (ORDER BY resolution_identity.ordinal ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)
            FROM JSON_TABLE(CASE WHEN JSON_TYPE(JSON_EXTRACT(control_room_alerts.context,'$.activity_log'))='ARRAY'
                THEN JSON_EXTRACT(control_room_alerts.context,'$.activity_log') ELSE JSON_ARRAY() END,
                '$[*]' COLUMNS(ordinal FOR ORDINALITY,entry JSON PATH '$')) resolution_identity
            WHERE JSON_TYPE($entry)='OBJECT' AND JSON_LENGTH($entry)=8 AND JSON_CONTAINS_PATH($entry,'all',$paths)=1
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.type'))='STRING' AND BINARY JSON_UNQUOTE(JSON_EXTRACT($entry,'$.type'))=BINARY 'lifecycle_note'
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.transition'))='STRING' AND BINARY JSON_UNQUOTE(JSON_EXTRACT($entry,'$.transition'))=BINARY 'resolution'
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.purpose'))='STRING' AND BINARY JSON_UNQUOTE(JSON_EXTRACT($entry,'$.purpose'))=BINARY 'general'
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.user_id'))='INTEGER' AND JSON_EXTRACT($entry,'$.user_id')>0
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.operator_note_id'))='INTEGER' AND JSON_EXTRACT($entry,'$.operator_note_id')>0
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.content'))='STRING' AND JSON_TYPE(JSON_EXTRACT($entry,'$.user_name'))='STRING'
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.created_at'))='STRING'
            AND NOT EXISTS(SELECT 1 FROM incident_saved_copies resolution_copy_identity
                WHERE resolution_copy_identity.target_family='control_room_operator_note'
                AND resolution_copy_identity.target_key=CAST(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.operator_note_id')) AS CHAR) FOR SHARE NOWAIT)
            ORDER BY resolution_identity.ordinal LIMIT 1),JSON_ARRAY())";
    }

    /** The canonical R57 reader owns source/note/receipt proof; this collector owns original ordinal/root/body. */
    private function resolutionActivitiesInParent(ControlRoomAlert $alert, HealthSafetyReadContext $context,
        CurrentAuthorizationReads $reads, Builder $actualBody): array
    {
        $reads->assertActive();
        $expression=$this->resolutionActivityDiscoveryExpression();
        $discovery=(clone$actualBody)->setEagerLoads([])->reorder()->select('control_room_alerts.id')
            ->selectRaw($expression.' as resolution_activity_identities')
            ->selectRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256) as resolution_activity_root_fp");
        $first=$reads->query($discovery)->first();abort_unless($first,404);
        $actualBody->whereRaw('BINARY CAST(('.$expression.') AS CHAR)=BINARY ?',[$first->getRawOriginal('resolution_activity_identities')])
            ->whereRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256)=?",[$first->getRawOriginal('resolution_activity_root_fp')]);
        $entries=[];
        foreach($this->decode($first->getRawOriginal('resolution_activity_identities'))??[]as$identity){
            $ordinal=$this->positiveId($identity['ordinal']??null);$noteId=$this->positiveId($identity['operator_note_id']??null);
            if($ordinal===null||$noteId===null){continue;}
            $result=$context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
                fn(User$current)=>app(ControlRoomAlertOperatorNoteReadService::class)->consumeResolutionActivity($current,(int)$alert->id,$noteId,$context,
                    function(\Illuminate\Database\Query\Builder$activity,CurrentAuthorizationReads$sameReads,User$current,HealthSafetyReadContext$same):array{
                        $row=$activity->select(['operator_note_id','type','transition','purpose','user_id','user_name','content','created_at'])->first();
                        if($row===null){return['entry'=>null];}
                        $entry=(array)$row;$entry['operator_note_id']=(int)$entry['operator_note_id'];$entry['user_id']=(int)$entry['user_id'];
                        return['entry'=>$entry];
                    },$actualBody));
            if($result['available']&&is_array($result['result']['entry']??null)){$entries[$ordinal-1]=$result['result']['entry'];}
        }
        return $entries;
    }

    /** Known copy identities only; the shared NOTE callback independently admits all private source/body fields. */
    private function copiedActivityDiscoveryExpression():string
    {
        $entry='copied_identity.entry';$keys=['type','transition','content','user_id','user_name','created_at','purpose','operator_note_id'];
        $paths=implode(',',array_map(fn($key)=>"'$.$key'",$keys));
        return "COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('ordinal',copied_identity.ordinal,
                'operator_note_id',JSON_EXTRACT($entry,'$.operator_note_id'),'entry_fp',SHA2(CAST($entry AS CHAR CHARACTER SET utf8mb4),256)))
                OVER (ORDER BY copied_identity.ordinal ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)
            FROM JSON_TABLE(CASE WHEN JSON_TYPE(JSON_EXTRACT(control_room_alerts.context,'$.activity_log'))='ARRAY'
                THEN JSON_EXTRACT(control_room_alerts.context,'$.activity_log') ELSE JSON_ARRAY() END,
                '$[*]' COLUMNS(ordinal FOR ORDINALITY,entry JSON PATH '$')) copied_identity
            WHERE JSON_TYPE($entry)='OBJECT' AND JSON_LENGTH($entry)=8 AND JSON_CONTAINS_PATH($entry,'all',$paths)=1
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.type'))='STRING' AND BINARY JSON_UNQUOTE(JSON_EXTRACT($entry,'$.type'))=BINARY 'lifecycle_note'
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.transition'))='STRING' AND BINARY JSON_UNQUOTE(JSON_EXTRACT($entry,'$.transition'))=BINARY 'note'
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.purpose'))='STRING'
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.user_id'))='INTEGER' AND JSON_EXTRACT($entry,'$.user_id')>0
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.operator_note_id'))='INTEGER' AND JSON_EXTRACT($entry,'$.operator_note_id')>0
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.content'))='STRING' AND JSON_TYPE(JSON_EXTRACT($entry,'$.user_name'))='STRING'
            AND JSON_TYPE(JSON_EXTRACT($entry,'$.created_at'))='STRING'
            AND EXISTS(SELECT 1 FROM incident_saved_copies copied_history_identity
                WHERE copied_history_identity.target_family='control_room_operator_note'
                AND copied_history_identity.target_key=CAST(JSON_UNQUOTE(JSON_EXTRACT($entry,'$.operator_note_id')) AS CHAR) FOR SHARE NOWAIT)
            ORDER BY copied_identity.ordinal LIMIT 1),JSON_ARRAY())";
    }

    private function copiedActivitiesInParent(ControlRoomAlert$alert,HealthSafetyReadContext$context,
        CurrentAuthorizationReads$reads,Builder$actualBody):array
    {
        $reads->assertActive();$expression=$this->copiedActivityDiscoveryExpression();
        $metadata=(clone$actualBody)->setEagerLoads([])->reorder()->select('control_room_alerts.id')
            ->selectRaw($expression.' as copied_activity_identities')
            ->selectRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256) as copied_activity_root_fp");
        $first=$reads->query($metadata)->first();abort_unless($first,404);
        $actualBody->whereRaw('BINARY CAST(('.$expression.') AS CHAR)=BINARY ?',[$first->getRawOriginal('copied_activity_identities')])
            ->whereRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256)=?",[$first->getRawOriginal('copied_activity_root_fp')]);
        // This ONE predicate retains existing R57 guards but NEVER captures the copied global verifier.
        // All canonical callbacks finish before attaching their full final proof to actualBody.
        $originalRootBeforeCopiedProof=clone$actualBody;$entries=[];$noteProofs=[];
        $linked=app(\App\Support\Incidents\LinkedOperationalEvidencePresenter::class);
        foreach($this->decode($first->getRawOriginal('copied_activity_identities'))??[]as$identity){
            $ordinal=$this->positiveId($identity['ordinal']??null);$noteId=$this->positiveId($identity['operator_note_id']??null);
            if($ordinal===null||$noteId===null){continue;}$entry="JSON_EXTRACT(control_room_alerts.context,'$.activity_log[".($ordinal-1)."]')";
            $result=$context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
                fn(User$current)=>$linked->consumeCopiedNoteDetails($current,(int)$alert->id,$noteId,$context,
                    function(Builder$note,CurrentAuthorizationReads$sameReads,User$current,HealthSafetyReadContext$same)use($originalRootBeforeCopiedProof,$ordinal,$noteId,$entry,&$noteProofs):array{
                        $sameReads->assertActive();
                        // Correlate admitted NOTE identity/actor/type/purpose/content; canonical author name is projected below.
                        $source=(clone$note)->whereKey($noteId)->where('control_room_operator_notes.type','note')
                            ->whereRaw('control_room_operator_notes.id=CAST(JSON_UNQUOTE(JSON_EXTRACT('.$entry.",'$.operator_note_id')) AS UNSIGNED)")
                            ->whereRaw('control_room_operator_notes.user_id=CAST(JSON_UNQUOTE(JSON_EXTRACT('.$entry.",'$.user_id')) AS UNSIGNED)")
                            ->whereRaw('BINARY control_room_operator_notes.purpose=BINARY JSON_UNQUOTE(JSON_EXTRACT('.$entry.",'$.purpose'))")
                            ->whereRaw('BINARY control_room_operator_notes.content=BINARY JSON_UNQUOTE(JSON_EXTRACT('.$entry.",'$.content'))")
                            ->setEagerLoads([])->reorder()->select([])->selectRaw('1');$source->toSql();
                        $root=(clone$originalRootBeforeCopiedProof)->whereExists($source->toBase());
                        $row=$sameReads->query($root)->select('control_room_alerts.id')->selectRaw($entry.' as copied_activity_entry')->first();
                        if($row===null){return['entry'=>null];}
                        $value=$this->decode($row->getRawOriginal('copied_activity_entry'));
                        if(!is_array($value)){return['entry'=>null];}
                        // Name is current canonical source-qualified attribution, not an unproved inline historical label.
                        $actorSource=(clone$note)->setEagerLoads([])->reorder()->select('control_room_operator_notes.user_id');$actorSource->toSql();
                        $authorQuery=$sameReads->query(User::query()->whereIn('users.id',$actorSource));
                        $author=(clone$authorQuery)->first(['users.id','users.name']);
                        if($author===null||(int)$author->id!==(int)($value['user_id']??0)){return['entry'=>null];}
                        $authorOriginal=(clone$authorQuery)->whereKey($author->id)->whereRaw('BINARY users.name=BINARY ?',[$author->getRawOriginal('name')])
                            ->setEagerLoads([])->reorder()->select([])->selectRaw('1');$authorOriginal->toSql();$noteProofs[]=clone$authorOriginal;
                        $value['user_name']=$author->getRawOriginal('name');
                        $retained=(clone$root)->whereRaw('BINARY CAST('.$entry.' AS CHAR)=BINARY ?',[$row->getRawOriginal('copied_activity_entry')])
                            ->setEagerLoads([])->reorder()->select([])->selectRaw('1');$retained->toSql();$noteProofs[]=clone$retained;
                        // created_at remains the retained activity's recorded timestamp. The producer captures it
                        // BEFORE Note create; Note.created_at is not an equality witness and is never substituted.
                        return['entry'=>$value];
                    },$originalRootBeforeCopiedProof));
            if($result['available']&&is_array($result['result']['entry']??null)){$entries[$ordinal-1]=$result['result']['entry'];}
        }
        if($noteProofs!==[]){
            $verify=function()use($context,$linked,$reads,$noteProofs):void{
                $context->assertActive();$reads->assertActive();$linked->verifyReadProjections($context);
                foreach($noteProofs as$proof){abort_unless($reads->query(clone$proof)->exists(),404);}
            };
            ControlRoomOriginalReadOuterQuery::attach($actualBody,$verify,$reads);
        }
        return$entries;
    }

    private function readOperationalContext(ControlRoomAlert $alert, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads, Builder $original): array
    {
        self::$contextQueries ??= new \WeakMap;self::$contextValues ??= new \WeakMap;
        if(isset(self::$contextQueries[$context][(int)$alert->id])){
            return $reads->query(clone self::$contextQueries[$context][(int)$alert->id])->exists()?self::$contextValues[$context][(int)$alert->id]:[];
        }
        $query=$this->intersectParent(ControlRoomAlert::query()->whereKey($alert->id),$original,$reads)->select('control_room_alerts.id')
            ->selectRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256) as raw_context_fp");
        $query=$this->retainOrdinaryHistoryTrace($alert,$context,$reads,$original,$query);
        $resolutionActivities=$this->resolutionActivitiesInParent($alert,$context,$reads,$query);
        $copiedActivities=$this->copiedActivitiesInParent($alert,$context,$reads,$query);
        $remove="'$.resolution','$.resolution_history','$.operational_reopen_history','$.activity_log','$.journey_attention',
            '$.saved_incident_copy','$.saved_incident_copy_audit_id','$.root_note_origin','$.notes_provenance'";
        $query->selectRaw("CASE WHEN (".$this->ordinaryCorePredicate().") THEN JSON_REMOVE(COALESCE(control_room_alerts.context,JSON_OBJECT()),$remove) ELSE JSON_OBJECT() END as operational_context");
        foreach(['resolution_history','operational_reopen_history','activity_log']as$key){$query->selectRaw($this->historyProjection($key).' as '.$key);}
        $query->selectRaw($this->resolutionProjection().' as resolution');
        $row=$query->first();if($row===null){return [];}
        $body=$this->decode($row->getRawOriginal('operational_context'))??[];
        foreach(['resolution_history','operational_reopen_history','activity_log']as$key){$values=$this->decode($row->getRawOriginal($key));if(is_array($values)&&$values!==[]){$body[$key]=$values;}}
        $resolution=$this->decode($row->getRawOriginal('resolution'));if(is_array($resolution)){$body['resolution']=$resolution;}
        foreach($resolutionActivities as $ordinal=>$entry){
            // Discovery/actual body predicates freeze the same root bytes and original index; no append/reorder/dedupe.
            if(isset($body['activity_log'])&&array_key_exists($ordinal,$body['activity_log'])){$body['activity_log'][$ordinal]=$entry;}
        }
        foreach($copiedActivities as$ordinal=>$entry){
            if(isset($body['activity_log'])&&array_key_exists($ordinal,$body['activity_log'])){$body['activity_log'][$ordinal]=$entry;}
        }
        $same=(clone$query)->whereRaw("SHA2(CAST(COALESCE(control_room_alerts.context,JSON_OBJECT()) AS CHAR CHARACTER SET utf8mb4),256) = ?",[$row->getRawOriginal('raw_context_fp')]);
        foreach(['operational_context','resolution_history','operational_reopen_history','activity_log','resolution']as$key){
            $value=$row->getRawOriginal($key);
            $expression=$key==='operational_context'?"CASE WHEN (".$this->ordinaryCorePredicate().") THEN JSON_REMOVE(COALESCE(control_room_alerts.context,JSON_OBJECT()),$remove) ELSE JSON_OBJECT() END":($key==='resolution'?$this->resolutionProjection():$this->historyProjection($key));
            if($expression!==null){if($value===null){$same->whereRaw('('.$expression.') IS NULL');}else{$same->whereRaw('BINARY CAST(('.$expression.') AS CHAR) = BINARY ?',[$value]);}}
        }
        if(!$reads->query($same)->exists()){return [];}
        foreach(['copy_content_present','operator_origin_present','notes_provenance_present','source_history_present','private_source_creator','private_source_resolver','notes_present','activity_history_present','resolution_history_present','signal_payload_present']as$key){unset($body[$key]);}
        $queries=self::$contextQueries[$context]??[];$values=self::$contextValues[$context]??[];
        $queries[(int)$alert->id]=$same;$values[(int)$alert->id]=$body;self::$contextQueries[$context]=$queries;self::$contextValues[$context]=$values;
        return $body;
    }


    /** Final materialized-wire replay under the original context; never replaces a body baseline. */
    public function verifyOperationalContent(User $viewer,int $alertId,HealthSafetyReadContext $context): void
    {
        $context->assertViewer($viewer);
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,function(User$current,CurrentAuthorizationReads$reads)use($context,$alertId):void{
            foreach([self::$ordinaryQueries,self::$copyQueries,self::$operatorQueries,self::$contextQueries]as$map){
                $query=$map[$context][$alertId]??null;if($query!==null){abort_unless($reads->query(clone$query)->exists(),404);}
            }
        });
    }

    /** Callback data is the admitted operational projection only, never arbitrary raw/source context. */
    public function consumeOperationalContext(User$viewer,int$alertId,HealthSafetyReadContext$context,callable$project):array
    {
        $frame=app(ControlRoomGovernanceReadService::class)->consumeOperationalAlertDetails($viewer,$alertId,$context,
            function(Builder$parent,CurrentAuthorizationReads$reads,User$current)use($context,$alertId,$project):array{
                $original=self::$contextQueries[$context][$alertId]??null;$value=self::$contextValues[$context][$alertId]??null;
                if($original===null||!is_array($value)||!$reads->query(clone$original)->exists()){return['available'=>false,'result'=>null];}
                $result=$project($value,clone$original,$reads,$current,$context);
                if(!is_array($result)){throw new \LogicException('Operational context projectors must return detached arrays.');}
                \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                $this->verifyOperationalContent($current,$alertId,$context);
                return['available'=>true,'result'=>$result];
            });
        return $frame['available']?$frame['result']:['available'=>false,'result'=>null];
    }

    /** Actual search/count/page SQL intersects the retained original copy predicate. */
    private function applyPreparedNoteScope(Builder $query, User $viewer, HealthSafetyReadContext $context, Builder $sourceCandidates, ?string $notesLikePattern, Builder $executionQuery): Builder
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function(User $current,CurrentAuthorizationReads $reads)use($query,$context,$sourceCandidates,$notesLikePattern,$executionQuery):Builder{
                $this->assertMetadataDiscovery($sourceCandidates->getQuery());
                $reader=app(ControlRoomGovernanceReadService::class);
                $metadata=$reader->projectCurrentAlertQuery(ControlRoomAlert::query()->whereIn('control_room_alerts.id',
                    (clone$sourceCandidates)->setEagerLoads([])->reorder()->select('control_room_alerts.id')),$current,$context);
                $admitted=[];
                $metadata->chunkById(100,function($rows)use($reader,$current,$context,$reads,$notesLikePattern,$executionQuery,&$admitted):void{
                    foreach($rows as$alert){
                        $result=$reader->consumeOperationalAlertDetails($current,(int)$alert->id,$context,
                            function(Builder$original,CurrentAuthorizationReads$sameReads,User$viewer)use($alert,$context,$notesLikePattern,$executionQuery):array{
                                $flags=is_array($alert->context)?$alert->context:[];
                                if((bool)($flags['copy_content_present']??false)){
                                    return $this->copiedNoteInParent($alert,$viewer,$context,$sameReads,$original,$notesLikePattern);
                                }
                                // Exact R55 operator acceptance must be consumed, never a marker-only grant.
                                if((bool)($flags['operator_origin_present']??false)){return $this->operatorNoteInParent($alert,$viewer,$context,$sameReads,$notesLikePattern,$executionQuery);}
                                return $this->ordinaryNoteInParent($alert,$context,$sameReads,$original,$notesLikePattern);
                            });
                        if($result['available']&&$result['result']['notes_access']==='readable'){
                            $id=(int)$alert->id;$admitted[$id]=self::$copyQueries[$context][$id]??self::$operatorQueries[$context][$id]??self::$ordinaryQueries[$context][$id]??null;
                        }
                    }
                },'control_room_alerts.id','id');
                $query->where(function(Builder$notes)use($admitted):void{
                    $notes->whereRaw('1=0');
                    foreach($admitted as$id=>$actual){if($actual!==null){$notes->orWhere(fn(Builder$row)=>$row->where('control_room_alerts.id',$id)
                        ->whereExists((clone$actual)->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase()));}}
                });
                $verify=function()use($context,$reads,$admitted):void{
                    $context->assertActive();foreach($admitted as$original){if($original!==null){abort_unless($reads->query(clone$original)->exists(),404);}}
                };
                // Required SIXTH argument: hooks are attached to actual outer count/list/page, never only the nested clause.
                ControlRoomOriginalReadOuterQuery::attach($executionQuery, $verify, $reads);
                return$context->query($query);
            });
    }

    /** A Notes LIKE clause in discovery would read source text before source admission. */
    private function assertMetadataDiscovery(QueryBuilder $query): void
    {
        foreach ($query->wheres ?? [] as $where) {
            if (isset($where['query']) && $where['query'] instanceof QueryBuilder) { $this->assertMetadataDiscovery($where['query']); }
            $column = $where['column'] ?? '';
            $sql = $where['sql'] ?? '';
            if ((is_string($column) && preg_match('/(?:^|\.)notes$/i', $column))
                || (is_string($sql) && preg_match('/\b(?:control_room_alerts\.)?notes\b/i', $sql))) {
                throw new \LogicException('Copied-note candidates must be independent identity metadata, without Notes predicates.');
            }
        }
    }

    private function copiedNoteProjection(ControlRoomAlert $metadata, callable $independentScope, User $current,
        HealthSafetyReadContext $context, CurrentAuthorizationReads $reads, Builder $originalAuthority, ?string $notesLikePattern = null): array
    {
        $context->assertViewer($current);
        $unavailable = ['notes_access' => 'unavailable', 'notes' => null];
        $target = $this->target($metadata);
        $base = function () use ($independentScope, $target, $metadata, $reads, $originalAuthority): Builder {
            $query = $this->sameTarget($independentScope(), $target)->setEagerLoads([])->reorder();
            $query->whereRaw(app(UserSiteAccessService::class)->alertEffectiveSiteExpression($query, $reads).' <=> ?', [$metadata->getRawOriginal('note_effective_site_id')]);
            return $reads->query($query->whereIn('control_room_alerts.id',
                (clone $originalAuthority)->setEagerLoads([])->reorder()->select('control_room_alerts.id')));
        };
        $origin = $this->decode($metadata->getRawOriginal('copy_origin'));
        // Conflicting source and operator ownership never becomes an independently certified note.
        if ((int) $metadata->getRawOriginal('root_origin_present') === 1) { return $unavailable; }
        if ((int)$metadata->getRawOriginal('copy_origin_key_count') !== 6 || ! $this->validOrigin($origin, (int) $metadata->id)) { return $unavailable; }
        $auditId = $this->decode($metadata->getRawOriginal('copy_audit_id'));
        if (! is_int($auditId) || $auditId < 1) { return $unavailable; }
        // Identity/receipt metadata only. Neither query selects root notes or a source narrative.
        $receipt = $reads->query(AuditLog::query()->whereKey($auditId)
            ->where('action',self::ACTION)->where('auditable_type',$metadata->getMorphClass())->where('auditable_id',$metadata->id)
            ->whereRaw('JSON_LENGTH(meta) = 4')->whereRaw('JSON_LENGTH(JSON_EXTRACT(meta, \'$.origin\')) = 6')
            ->whereRaw('JSON_LENGTH(JSON_EXTRACT(meta, \'$.target\')) = 6')
            ->whereRaw("JSON_CONTAINS_PATH(meta,'all','$.origin.copy_uuid','$.origin.source_family','$.origin.source_version','$.origin.target_family','$.origin.target_key','$.origin.field','$.target.id','$.target.site_id','$.target.client_id','$.target.asset_id','$.target.source','$.target.alert_type') = 1")
            ->whereRaw("JSON_CONTAINS(JSON_EXTRACT(meta,'$.origin'), CAST(? AS JSON)) = 1",[json_encode($origin,JSON_THROW_ON_ERROR)])
            ->whereRaw("JSON_CONTAINS(JSON_EXTRACT(meta,'$.target'), CAST(? AS JSON)) = 1",[json_encode($target,JSON_THROW_ON_ERROR)])
            ->whereRaw("JSON_EXTRACT(meta,'$.actor_id') = user_id")->where('user_id','>',0)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.text_sha256')) REGEXP '^[a-f0-9]{64}$'")
            ->whereRaw("JSON_CONTAINS_PATH(meta,'all','$.origin','$.target','$.actor_id','$.text_sha256') = 1"))
            ->select(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta'])
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('audit', 'audit_logs').' as receipt_fp')->first();
        if ($receipt === null || $receipt->action !== self::ACTION || $receipt->auditable_type !== $metadata->getMorphClass()
            || (int) $receipt->auditable_id !== (int) $metadata->id || (int) $receipt->user_id < 1
            || ! $this->same($receipt->meta['origin'] ?? null, $origin)
            || ! $this->same($receipt->meta['target'] ?? null, $target)
            || count($receipt->meta) !== 4 || ! array_key_exists('actor_id', $receipt->meta)
            || $receipt->meta['actor_id'] !== (int) $receipt->user_id
            || ! is_string($receipt->meta['text_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $receipt->meta['text_sha256']) !== 1) { return $unavailable; }
        $receiptFingerprint = $receipt->getRawOriginal('receipt_fp');
        $textDigest = $receipt->meta['text_sha256'];
        $actorId = (int) $receipt->user_id;
        $admittedQuery = null;
        $result = app(IncidentSavedCopyService::class)->consumeProjectedCopy($context, $origin,
            function (array $copy, CurrentAuthorizationReads $sameReads, Builder $protectedCopy)
                use ($base, $origin, $target, $metadata, $auditId, $receiptFingerprint, $textDigest, $actorId, $unavailable, $notesLikePattern, &$admittedQuery): array {
                if (! hash_equals($textDigest, hash('sha256', $copy['text']))) { return $unavailable; }
                $protectedCopy = $this->correlateSource($protectedCopy, $target);
                $query = $base()->whereRaw($this->originFingerprint('saved_incident_copy').' = ?', [$metadata->getRawOriginal('copy_origin_fp')])
                    ->whereRaw("JSON_CONTAINS_PATH(COALESCE(control_room_alerts.context, JSON_OBJECT()), 'one', '$.root_note_origin') = 0")
                    ->where('control_room_alerts.context->saved_incident_copy_audit_id', $auditId)
                    ->whereRaw('BINARY control_room_alerts.notes = BINARY ?', [$copy['text']])
                    ->whereExists((clone $protectedCopy)->reorder()->select('incident_saved_copies.id')->toBase())
                    ->whereExists(fn (QueryBuilder $audit) => $audit->selectRaw('1')->from('audit_logs as accepted_note_audit')
                        ->where('accepted_note_audit.id', $auditId)
                        ->whereRaw(IncidentIndependenceBindingSql::fingerprint('audit', 'accepted_note_audit').' = ?', [$receiptFingerprint]))
                    ->whereNotExists(fn (QueryBuilder $other) => $other->selectRaw('1')->from('audit_logs as other_note_claim')
                        ->where('other_note_claim.meta->origin->copy_uuid', $origin['copy_uuid'])->where('other_note_claim.id', '!=', $auditId))
                    ->whereExists(fn (QueryBuilder $actor) => $actor->selectRaw('1')->from('users as note_copy_actor')->where('note_copy_actor.id', $actorId));
                $protectedCopy->toSql();
                if ($notesLikePattern !== null) { $query->where('control_room_alerts.notes', 'like', $notesLikePattern); }
                // Supplied source proof is on this ACTUAL destination SELECT through the SAME reads.
                $row = $sameReads->query($query)->first(['control_room_alerts.id', 'control_room_alerts.notes']);
                if ($row === null || $row->getRawOriginal('notes') !== $copy['text']
                    || ! $sameReads->query(clone $query)->exists()) { return $unavailable; }
                $admittedQuery = clone $query;
                return ['notes_access' => 'readable', 'notes' => $copy['text']];
            });

        if ($result['available'] && $admittedQuery !== null && $result['result']['notes_access'] === 'readable') {
            self::$copyQueries ??= new \WeakMap;
            $queries = self::$copyQueries[$context] ?? [];
            $queries[(int) $metadata->id] = $admittedQuery;
            self::$copyQueries[$context] = $queries;
        }
        return $result['available'] ? $result['result'] : $unavailable;
    }

    private function metadata(Builder $query, ?CurrentAuthorizationReads $reads = null): Builder
    {
        return $query->selectRaw(app(UserSiteAccessService::class)->alertEffectiveSiteExpression($query, $reads).' as note_effective_site_id')
            ->selectRaw('NULL as root_origin')
            ->selectRaw("JSON_CONTAINS_PATH(COALESCE(control_room_alerts.context, JSON_OBJECT()), 'one', '$.root_note_origin') as root_origin_present")
            ->selectRaw("JSON_CONTAINS_PATH(COALESCE(control_room_alerts.context, JSON_OBJECT()), 'one', '$.saved_incident_copy', '$.saved_incident_copy_audit_id') as copy_origin_present")
            ->selectRaw("JSON_LENGTH(JSON_EXTRACT(control_room_alerts.context, '$.saved_incident_copy')) as copy_origin_key_count")
            ->selectRaw("JSON_OBJECT('copy_uuid',JSON_EXTRACT(control_room_alerts.context,'$.saved_incident_copy.copy_uuid'),
                'source_family',JSON_EXTRACT(control_room_alerts.context,'$.saved_incident_copy.source_family'),
                'source_version',JSON_EXTRACT(control_room_alerts.context,'$.saved_incident_copy.source_version'),
                'target_family',JSON_EXTRACT(control_room_alerts.context,'$.saved_incident_copy.target_family'),
                'target_key',JSON_EXTRACT(control_room_alerts.context,'$.saved_incident_copy.target_key'),
                'field',JSON_EXTRACT(control_room_alerts.context,'$.saved_incident_copy.field')) as copy_origin")
            ->selectRaw("JSON_EXTRACT(control_room_alerts.context, '$.saved_incident_copy_audit_id') as copy_audit_id")
            ->selectRaw($this->originFingerprint('root_note_origin').' as root_origin_fp')
            ->selectRaw($this->originFingerprint('saved_incident_copy').' as copy_origin_fp');
    }

    private function target(ControlRoomAlert $metadata): array
    {
        $target = [];
        foreach (self::TARGET_COLUMNS as $column) {
            $value = $metadata->getRawOriginal($column);
            $target[$column] = $value === null ? null : (in_array($column, ['source', 'alert_type'], true) ? $value : (int) $value);
        }
        return $target;
    }

    private function sameTarget(Builder $query, array $target): Builder
    {
        foreach (self::TARGET_COLUMNS as $column) {
            $value = $target[$column];
            if ($value === null) { $query->whereNull('control_room_alerts.'.$column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY control_room_alerts.'.$column.' = BINARY ?', [$value]); }
            else { $query->where('control_room_alerts.'.$column, $value); }
        }
        return $query;
    }

    private function correlateSource(Builder $protectedCopy, array $target): Builder
    {
        foreach (['site_id', 'client_id'] as $column) {
            $target[$column] === null
                ? $protectedCopy->whereNull('incident_saved_copies.source_tuple->'.$column)
                : $protectedCopy->where('incident_saved_copies.source_tuple->'.$column, $target[$column]);
        }
        return $protectedCopy;
    }

    private function validOrigin(mixed $origin, int $alertId): bool
    {
        return is_array($origin) && count($origin) === count(self::ORIGIN_KEYS)
            && array_diff(self::ORIGIN_KEYS, array_keys($origin)) === []
            && ($origin['source_family'] ?? null) === 'client_incident'
            && ($origin['target_family'] ?? null) === IncidentSavedCopyService::ALERT_NOTES_TARGET
            && ($origin['target_key'] ?? null) === (string) $alertId;
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $this->canonical($left) === $this->canonical($right);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) { return $value; }
        if (! array_is_list($value)) { ksort($value); }
        foreach ($value as $key => $nested) { $value[$key] = $this->canonical($nested); }
        return $value;
    }

    private function decode(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : null;
    }

    private function originFingerprint(string $key): string
    {
        return "SHA2(CAST(JSON_EXTRACT(control_room_alerts.context, '$.".$key."') AS CHAR CHARACTER SET utf8mb4), 256)";
    }
}
