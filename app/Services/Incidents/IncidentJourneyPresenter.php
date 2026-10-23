<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomSourceContentService;
use App\Services\ControlRoom\ControlRoomGovernanceReadService;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use App\Services\HealthSafety\HsVisibilityService;
use App\Support\Incidents\LinkedOperationalEvidencePresenter;

class IncidentJourneyPresenter
{
    public function __construct(
        private readonly LinkedOperationalEvidencePresenter $linkedEvidence,
    ) {}

    /** Existing two-argument callers receive fresh source reads; supplied models carry identities only. */
    public function present(IncidentJourney $journey, User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        if ($context === null) {
            return HealthSafetyReadContext::within($viewer, ControlRoomGovernanceReadService::PERMISSIONS,
                fn (HealthSafetyReadContext $same): array => $this->present($journey, $viewer, $same));
        }
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $current, CurrentAuthorizationReads $reads) use ($journey, $context): array {
                $source = $context->applyIncidentSourceScope(app(IncidentAccessService::class)
                    ->apply(ClientIncident::query()->whereKey($journey->incident->id), $current, $reads));
                $ownership = $this->incidentOwnershipExpression($reads);
                $effectiveSite = $this->incidentEffectiveSiteExpression($reads);
                $metadata = $context->query(clone $source)->select(array_values(array_unique([
                    ...IncidentIndependenceIntakeService::ROOT_COLUMNS, 'type', 'hs_event_id', 'control_room_alert_id', 'reference_number',
                ])))->selectRaw($effectiveSite.' as _effective_site_id')->selectRaw('SHA2(CAST('.$ownership.' AS CHAR CHARACTER SET utf8mb4),256) as _journey_ownership_fingerprint')->first();
                $original = null;
                if ($metadata !== null) {
                    $original = clone $source;
                    foreach ($metadata->getRawOriginal() as $column => $value) {
                        if (in_array($column, ['_journey_ownership_fingerprint', '_effective_site_id'], true)) { continue; }
                        if ($value === null) { $original->whereNull('client_incidents.'.$column); }
                        elseif (is_string($value)) { $original->whereRaw('BINARY '.$original->getQuery()->getGrammar()->wrap('client_incidents.'.$column).' = BINARY ?', [$value]); }
                        else { $original->where('client_incidents.'.$column, $value); }
                    }
                    $actor = $context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, $metadata->_effective_site_id === null ? [] : [(int) $metadata->_effective_site_id]);
                    $original->whereRaw($effectiveSite.' <=> ?', [$metadata->_effective_site_id])->whereRaw('SHA2(CAST('.$ownership.' AS CHAR CHARACTER SET utf8mb4),256) = ?', [$metadata->_journey_ownership_fingerprint])
                        ->whereExists($this->existsQuery($actor));
                }
                $governance = app(ControlRoomGovernanceReadService::class);
                $alertId = $metadata?->control_room_alert_id ?? $journey->alert?->id;
                $alert = null; $record = null;
                // Alert authority is independent; absence of CR permission cannot suppress a lawful incident-only page.
                if ($alertId !== null && app(ControlRoomAlertAccessService::class)->canList($current)) {
                    $alert = $governance->projectCurrentAlertQuery(ControlRoomAlert::query()->whereKey((int) $alertId), $current, $context)->first();
                    if ($alert !== null) {
                        $record = $governance->forAlerts([$alert], $current, $context)->get((int) $alert->id);
                        if (! is_array($record) || (int) ($record['incident']['id'] ?? 0) !== (int) $journey->incident->id
                            || ! $governance->isCurrentRecord($record, $context, (int) $alert->id)) { $alert = null; $record = null; }
                    }
                }
                $projectIncident = function (?Builder $alertOriginal) use ($governance, $current, $context, $journey, $original, $alert, $record, $metadata): array {
                    if ($original === null) { return $this->restrictedIncidentPayload($alert, $current, $context, $alertOriginal, $record); }
                    $detail = $governance->consumeReadableIncidentDetails($current, (int) $journey->incident->id, $context,
                        function (Builder $admitted, CurrentAuthorizationReads $reads, User $actor, HealthSafetyReadContext $same) use ($alert, $alertOriginal, $record, $metadata, $original): array {
                            $admitted->whereExists($this->existsQuery($original));
                            if ($alertOriginal !== null) { $admitted->whereExists($this->existsQuery($alertOriginal)); }
                            $incident = $same->query(clone $admitted)->firstOrFail();
                            $event = $same->query(HsEvent::query()->where('source_type', ClientIncident::class)->where('source_id', $incident->id)
                                ->when($incident->hs_event_id !== null, fn ($q) => $q->whereKey($incident->hs_event_id))
                                ->whereExists($this->existsQuery($admitted)))
                                ->first(['id', 'source_type', 'source_id', 'site_id', 'client_id', 'control_room_alert_id', 'status', 'handover_status']);
                            return $this->presentAdmitted(new IncidentJourney($incident, $alertOriginal === null ? null : $alert, $event), $actor, $same, $admitted, $alertOriginal, $record,
                                $metadata->control_room_alert_id !== null);
                        }, $original);
                    return $detail['available'] ? $detail['result'] : $this->restrictedIncidentPayload($alert, $current, $context, $alertOriginal, $record);
                };
                if ($alert === null) { return $projectIncident(null); }
                $details = $governance->consumeOperationalAlertDetails($current, (int) $alert->id, $context,
                    fn (Builder $parent): array => $projectIncident($parent));
                return $details['available'] ? $details['result'] : $projectIncident(null);
            });
    }

    private function presentAdmitted(IncidentJourney $journey, User $viewer, HealthSafetyReadContext $context, Builder $incidentOriginal,
        ?Builder $alertOriginal, ?array $governance, bool $knownAlert): array
    {
        $incident = $journey->incident;
        $alert = $journey->alert;
        $hsEvent = $journey->hsEvent;

        $incident->load($this->guardedLoads(['site:id,name', 'client:id,first_name,last_name,site_id', 'client.site:id,name',
            'shift:id,site_id', 'shift.site:id,name', 'reporter:id,name', 'attachments:id,incident_id,original_name',
            'followups:id,client_incident_id,notes,due_at,completed_at,assigned_to_user_id'], $context, $incidentOriginal));
        if ($alert !== null && $alertOriginal !== null) {
            $alert->load($this->guardedLoads(['sla', 'sla.slaDefinition', 'playbookRun:id,playbook_id,status,current_step,completed_steps,total_steps',
                'playbookRun.playbook:id,name,code', 'tasks:id,alert_id,title,status,priority,due_at,assigned_to_user_id', 'tasks.assignedTo:id,name',
                'evidencePacks:id,alert_id,title,status,item_count', 'communications:id,alert_id,subject,status,sent_at,delivered_at'], $context, $alertOriginal));
        }
        $hsProjection = $this->healthSafetyInContext($hsEvent, $viewer, $context, $incidentOriginal, $alertOriginal);
        $hsDetails = $hsProjection['event'] ?? null;
        $hsFacts = $hsProjection['facts'] ?? ['present' => $hsEvent !== null,
            'access' => $hsEvent === null ? 'absent' : (($hsProjection['status'] ?? null) === null ? 'unavailable' : 'restricted'),
            'status' => $hsProjection['status'] ?? null,
            'handover_status' => $hsProjection['handover']['status'] ?? null,
            'has_completed_action' => null, 'can_manage' => false];
        $fallbackSite = $incident->shift?->site ?? $incident->client?->site;
        $siteDetails = $incident->site ? ['id' => $incident->site->id, 'name' => $incident->site->name]
            : ($hsProjection['site'] ?? ($fallbackSite ? ['id' => $fallbackSite->id, 'name' => $fallbackSite->name] : null));
        $canOpenIncident = $context->query(clone $incidentOriginal)->exists();
        $canOpenHs = ($hsProjection['access'] ?? null) === 'readable';
        $linkedOperationalEvidence = $alert
            ? $this->linkedEvidence->present(
                $alert,
                $viewer,
                fn ($item): string => $canOpenIncident
                    ? "/incidents/{$incident->id}/control-room-evidence/{$item->id}/download"
                    : "/control-room/alerts/{$alert->id}/evidence/{$item->evidence_pack_id}/items/{$item->id}/download", $context,
            )
            : null;
        $canOpenAlert = data_get($linkedOperationalEvidence, 'source.href') !== null;
        $sourceContent = app(ControlRoomSourceContentService::class);
        $safeAlertContent = $alert && $canOpenAlert && is_array($governance)
            ? $sourceContent->present($alert, $governance, app(ControlRoomAlertProvenanceService::class)->sanitiseContextForRead($alert), $context) : null;
        if ($alert !== null) {
            $sourceContent->verifyOperationalContent($viewer, (int) $alert->id, $context);
            app(ControlRoomGovernanceReadService::class)->verifyCurrentSources($viewer, (int) $alert->id, $context);
        }
        abort_unless($context->query(clone $incidentOriginal)->exists(), 404);
        return [
            'references' => [
                'control_room' => $canOpenAlert ? ($alert->reference_number ?: null) : null,
                'incident' => $canOpenIncident ? ($incident->reference_number ?: null) : null,
                'health_safety' => $canOpenHs ? ($hsDetails['reference_number'] ?: null) : null,
            ],
            'occurred_at' => $canOpenIncident ? $incident->occurred_at?->toIso8601String() : null,
            'linked_access' => [
                'incident' => $canOpenIncident ? 'readable' : 'restricted',
                'control_room' => $alert === null ? ($knownAlert ? 'restricted' : 'absent') : ($canOpenAlert ? 'readable' : 'restricted'),
                'health_safety' => $hsEvent === null ? 'absent' : ($canOpenHs ? 'readable' : 'restricted'),
            ],
            'incident' => $canOpenIncident ? [
                'id' => $incident->id,
                'reference_number' => $incident->reference_number ?: null,
                'status' => $incident->status,
                'severity' => $incident->severity,
                'type' => $incident->type,
                'site' => $siteDetails,
                'person' => $incident->client ? [
                    'id' => $incident->client->id,
                    'name' => trim($incident->client->first_name.' '.$incident->client->last_name),
                ] : null,
                'reporter' => $incident->reporter ? [
                    'id' => $incident->reporter->id,
                    'name' => $incident->reporter->name,
                ] : null,
                'narrative' => [
                    'title' => $incident->title,
                    'description' => $incident->description,
                    'location' => $incident->location,
                    'immediate_controls' => $incident->immediate_action_taken ?: $incident->immediate_action,
                    'witnesses' => $incident->witnesses,
                    'potential_severity' => $incident->potential_severity,
                    'potential_consequence' => $incident->potential_consequence,
                ],
                'attachments' => $incident->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                ])->values()->all(),
                'followups' => $incident->followups->map(fn ($followup) => [
                    'id' => $followup->id,
                    'title' => $followup->notes,
                    'status' => $followup->completed_at ? 'complete' : 'open',
                    'due_at' => $followup->due_at?->toIso8601String(),
                ])->values()->all(),
                'href' => '/incidents/'.$incident->id,
            ] : null,
            'linked_operational_evidence' => $linkedOperationalEvidence,
            'control_room' => $this->presentAlert($alert, $canOpenAlert, $safeAlertContent),
            'health_safety' => $hsDetails,
            'journey_state' => $knownAlert && $alert === null ? 'Journey needs review' : $this->journeyState($incident, $alert, null, $hsFacts),
            'lifecycle' => [
                $this->stage('control_room', 'Control Room', $canOpenAlert ? $alert->reference_number : null, $knownAlert && $alert === null ? 'blocked' : $this->alertState($alert), $canOpenAlert ? '/control-room/alerts/'.$alert->id : null),
                $this->stage('incident', 'Incident report', $canOpenIncident ? $incident->reference_number : null, $this->incidentState($incident), $canOpenIncident ? '/incidents/'.$incident->id : null),
                $this->stage('health_safety', 'Health & Safety', $canOpenHs ? $hsDetails['reference_number'] : null, $this->healthSafetyState($hsFacts), $canOpenHs ? $hsDetails['href'] : null),
            ],
            'next_action' => $this->nextAction($incident, $alert, $hsDetails, $hsFacts, $viewer, $canOpenAlert, $canOpenIncident, $canOpenHs),
        ];
    }

    private function healthSafetyInContext(?HsEvent $metadata, User $current, HealthSafetyReadContext $context, Builder $incidentOriginal, ?Builder $alertOriginal): ?array
    {
        if ($metadata === null) { return null; }
        try {
        $candidate = HsEvent::query()->whereKey($metadata->id)->where('source_type', ClientIncident::class)
            ->where('source_id', $metadata->source_id)->where('site_id', $metadata->site_id)->where('client_id', $metadata->client_id)
            ->whereExists($this->existsQuery($incidentOriginal));
        foreach ($metadata->getRawOriginal() as $column => $value) {
            $candidate->where('hs_events.'.$column, $value);
        }
        if ($alertOriginal !== null) { $candidate->whereExists($this->existsQuery($alertOriginal)); }
        $source = app(HsEventAccessService::class)->applyReadableScope(clone $candidate, $current, $context, $candidate);
        $event = $context->query(clone $source)->first();
        if ($event === null) {
            return $context->consumeViewer(FirstAidAccessService::PERMISSIONS, function () use ($context, $candidate): array {
                $workflow = $context->applyEventSiteScope(clone $candidate, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS)
                    ->first(['id', 'status', 'handover_status']);
                return ['access' => 'restricted', 'status' => $workflow?->status, 'handover' => ['status' => $workflow?->handover_status]];
            });
        }
        $event->load($this->guardedLoads(['site:id,name', 'owner:id,name', 'acceptedBy:id,name',
            'activeInvestigation.leadInvestigator:id,name'], $context, $source));
        $facts = ['present' => true, 'access' => 'readable', 'status' => $event->status, 'handover_status' => $event->handover_status,
            'has_completed_action' => $context->query($event->correctiveActions()->getQuery()->whereExists($this->existsQuery($source)))
                ->where('status', HsCorrectiveAction::STATUS_COMPLETED)->exists(), 'can_manage' => $current->canDo('hazards.manage')];
        $result = ['access' => 'readable', 'site' => $event->site ? ['id' => (int) $event->site->id, 'name' => $event->site->name] : null,
            'event' => $this->presentHealthSafety($event, true), 'facts' => $facts];
        abort_unless($context->query(clone $source)->exists(), 404);
        return $result;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
            return ['access' => 'restricted', 'status' => null, 'handover' => ['status' => null]];
        }
    }

    private function restrictedIncidentPayload(?ControlRoomAlert $alert, User $current, HealthSafetyReadContext $context, ?Builder $original, ?array $record): array
    {
        $safe = null; $linked = null;
        if ($alert !== null && $original !== null && is_array($record)) {
            $alert->load($this->guardedLoads(['sla', 'sla.slaDefinition', 'playbookRun:id,playbook_id,status,current_step,completed_steps,total_steps',
                'playbookRun.playbook:id,name,code', 'tasks:id,alert_id,title,status,priority,due_at,assigned_to_user_id', 'tasks.assignedTo:id,name',
                'evidencePacks:id,alert_id,title,status,item_count', 'communications:id,alert_id,subject,status,sent_at,delivered_at'], $context, $original));
            $safe = app(ControlRoomSourceContentService::class)->present($alert, $record,
                app(ControlRoomAlertProvenanceService::class)->sanitiseContextForRead($alert), $context);
            $linked = $this->linkedEvidence->present($alert, $current,
                fn ($item): string => "/control-room/alerts/{$alert->id}/evidence/{$item->evidence_pack_id}/items/{$item->id}/download", $context);
            app(ControlRoomSourceContentService::class)->verifyOperationalContent($current, (int) $alert->id, $context);
            app(ControlRoomGovernanceReadService::class)->verifyCurrentSources($current, (int) $alert->id, $context);
        }
        $canAlert = $alert !== null && $original !== null;
        return ['references' => ['incident' => null, 'control_room' => $canAlert ? $alert->reference_number : null, 'health_safety' => null],
            'occurred_at' => null, 'linked_access' => ['incident' => 'restricted', 'control_room' => $canAlert ? 'readable' : 'unavailable', 'health_safety' => 'unavailable'],
            'incident' => null, 'linked_operational_evidence' => $linked, 'control_room' => $this->presentAlert($alert, $canAlert, $safe), 'health_safety' => null,
            'journey_state' => $canAlert && $alert->isActionable() ? 'Operational response active' : 'Journey needs review',
            'lifecycle' => [$this->stage('control_room', 'Control Room', $canAlert ? $alert->reference_number : null, $canAlert ? $this->alertState($alert) : 'blocked', $canAlert ? '/control-room/alerts/'.$alert->id : null),
                $this->stage('incident', 'Incident report', null, 'blocked', null), $this->stage('health_safety', 'Health & Safety', null, 'blocked', null)],
            'next_action' => $canAlert ? ['label' => $alert->isActionable() && $current->canDo('controlRoom.alerts.manage') ? 'Continue Control Room response' : 'View alert',
                'href' => '/control-room/alerts/'.$alert->id, 'stage' => 'control_room'] : ['label' => 'No action available for your role', 'href' => null, 'stage' => 'blocked']];
    }

    private function incidentEffectiveSiteExpression(CurrentAuthorizationReads $reads): string
    {
        $reads->assertActive();
        $type = bin2hex(ClientIncident::class);
        return "CASE WHEN client_incidents.site_id IS NOT NULL THEN client_incidents.site_id "
            ."WHEN EXISTS(SELECT 1 FROM hs_events e WHERE e.id = client_incidents.hs_event_id AND e.deleted_at IS NULL "
            ."AND e.source_type = CONVERT(UNHEX('$type') USING utf8mb4) AND e.source_id = client_incidents.id AND e.site_id IS NOT NULL FOR SHARE NOWAIT) "
            ."THEN (SELECT e.site_id FROM hs_events e WHERE e.id = client_incidents.hs_event_id AND e.deleted_at IS NULL LIMIT 1 FOR SHARE NOWAIT) "
            ."WHEN client_incidents.shift_id IS NOT NULL THEN (SELECT s.site_id FROM shifts s WHERE s.id = client_incidents.shift_id AND s.client_id = client_incidents.client_id LIMIT 1 FOR SHARE NOWAIT) "
            ."ELSE (SELECT c.site_id FROM clients c WHERE c.id = client_incidents.client_id AND c.deleted_at IS NULL LIMIT 1 FOR SHARE NOWAIT) END";
    }

    private function incidentOwnershipExpression(CurrentAuthorizationReads $reads): string
    {
        $reads->assertActive();
        // Canonical incident occurrence fallback inputs only, before any narrative body.
        return "JSON_OBJECT('client', (SELECT JSON_OBJECT('id', c.id, 'site_id', c.site_id) FROM clients c WHERE c.id = client_incidents.client_id AND c.deleted_at IS NULL LIMIT 1 FOR SHARE NOWAIT), "
            ."'shift', (SELECT JSON_OBJECT('id', s.id, 'client_id', s.client_id, 'site_id', s.site_id) FROM shifts s WHERE s.id = client_incidents.shift_id LIMIT 1 FOR SHARE NOWAIT), "
            ."'event', (SELECT JSON_OBJECT('id', e.id, 'source_type', e.source_type, 'source_id', e.source_id, 'site_id', e.site_id, 'control_room_alert_id', e.control_room_alert_id) FROM hs_events e WHERE e.id = client_incidents.hs_event_id AND e.deleted_at IS NULL LIMIT 1 FOR SHARE NOWAIT))";
    }

    private function existsQuery(Builder $original): \Illuminate\Database\Query\Builder
    {
        return (clone $original)->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase();
    }

    private function guardedLoads(array $loads, HealthSafetyReadContext $context, Builder $original): array
    {
        $result = [];
        $expanded = [];
        foreach ($loads as $load) {
            $relation = explode(':', $load, 2)[0];
            $segments = explode('.', $relation);
            while (count($segments) > 1) { array_pop($segments); $expanded[implode('.', $segments)] ??= implode('.', $segments); }
            $expanded[$relation] = $load;
        }
        foreach ($expanded as $load) {
            [$relation, $columns] = array_pad(explode(':', $load, 2), 2, null);
            $result[$relation] = function ($child) use ($columns, $context, $original): void {
                $query = $child instanceof \Illuminate\Database\Eloquent\Relations\Relation ? $child->getQuery() : $child;
                if ($columns !== null) { $query->select(explode(',', $columns)); }
                $context->query($query->whereExists($this->existsQuery($original)));
            };
        }
        return $result;
    }
    public function journeyState(
        ?ClientIncident $incident,
        ?ControlRoomAlert $alert,
        ?HsEvent $hsEvent,
        ?array $healthSafetyFacts = null,
    ): string {
        // Existing three-argument callers retain their canonical model behavior.
        // present() supplies a detached snapshot and never consults caller state.
        $hasHs = $healthSafetyFacts === null ? $hsEvent !== null : ($healthSafetyFacts['present'] ?? false);
        $hsStatus = $healthSafetyFacts === null ? $hsEvent?->status : ($healthSafetyFacts['status'] ?? null);
        $handoverStatus = $healthSafetyFacts === null ? $hsEvent?->handover_status : ($healthSafetyFacts['handover_status'] ?? null);
        $hsKnown = ! $hasHs || $healthSafetyFacts === null
            || (($healthSafetyFacts['access'] ?? null) === 'readable' && $this->knownHsStatus($hsStatus));
        if ($incident === null && $alert === null && ! $hasHs) {
            return 'Journey needs review';
        }
        $incidentClosed = $incident === null || $incident->status === 'closed';
        $healthSafetyClosed = ! $hasHs || ($hsKnown && $hsStatus === HsEvent::STATUS_CLOSED);
        $operationalClosed = $alert === null || in_array($alert->status, [
            ControlRoomAlert::STATUS_CLOSED,
            ControlRoomAlert::STATUS_DISMISSED,
        ], true);
        $operationalResponseEnded = $alert !== null
            && in_array($alert->status, [
                ControlRoomAlert::STATUS_RESOLVED,
                ControlRoomAlert::STATUS_CLOSED,
                ControlRoomAlert::STATUS_DISMISSED,
            ], true);

        if ($incidentClosed && $healthSafetyClosed && $operationalClosed) {
            return 'Journey closed';
        }

        if ($alert?->isActionable()) {
            return 'Operational response active';
        }

        if ($operationalResponseEnded
            && ! in_array($incident?->status, ['reviewed', 'closed'], true)
        ) {
            return 'Operationally resolved';
        }

        if ($hasHs && ! $hsKnown) {
            return 'Journey needs review';
        }

        if ($hasHs
            && in_array($handoverStatus, [
                HsEvent::HANDOVER_NOT_READY,
                HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
            ], true)
        ) {
            return 'H&S acceptance pending';
        }

        if ($hsStatus === HsEvent::STATUS_CLOSED) {
            return 'Governance closed';
        }

        if ($hasHs && ($healthSafetyFacts === null
            ? $hsEvent->correctiveActions()->where('status', HsCorrectiveAction::STATUS_COMPLETED)->exists()
            : ($healthSafetyFacts['has_completed_action'] ?? false))) {
            return 'Awaiting independent verification';
        }

        if ($hasHs && ($healthSafetyFacts === null ? $hsEvent->isOpen() : $hsStatus !== HsEvent::STATUS_CLOSED)) {
            return 'H&S governance active';
        }

        if (in_array($incident?->status, ['reviewed', 'closed'], true)) {
            return 'Incident review complete';
        }

        if ($operationalResponseEnded) {
            return 'Operationally resolved';
        }

        if ($incident?->status === 'draft') {
            return 'Incident draft';
        }

        if ($incident?->status === 'submitted') {
            return 'Incident review pending';
        }

        return 'Journey needs review';
    }

    /** @return array<string, mixed>|null */
    private function presentAlert(?ControlRoomAlert $alert, bool $canOpen, ?array $sourceContent): ?array
    {
        if ($alert === null || ! $canOpen) {
            return null;
        }

        return [
            'id' => $alert->id,
            'reference_number' => $alert->reference_number ?: null,
            'status' => $alert->status,
            'severity' => $alert->severity,
            'notes' => $sourceContent['notes'] ?? match ($sourceContent['notes_access'] ?? null) { 'unavailable' => ControlRoomSourceContentService::UNAVAILABLE, 'restricted' => ControlRoomSourceContentService::RESTRICTED, default => null },
            'notes_access' => $sourceContent['notes_access'] ?? 'unavailable',
            'sla_status' => $alert->sla?->getStatus(),
            'playbook' => $alert->playbookRun ? [
                'name' => $alert->playbookRun->playbook?->name,
                'status' => $alert->playbookRun->status,
                'current_step' => $alert->playbookRun->current_step,
                'completed_steps' => $alert->playbookRun->completed_steps,
                'total_steps' => $alert->playbookRun->total_steps,
            ] : null,
            'tasks' => $alert->tasks->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->toIso8601String(),
                'owner' => $task->assignedTo ? ['id' => $task->assignedTo->id, 'name' => $task->assignedTo->name] : null,
            ])->values()->all(),
            'evidence' => $alert->evidencePacks->map(fn ($pack) => [
                'id' => $pack->id,
                'title' => $pack->title,
                'status' => $pack->status,
                'item_count' => (int) $pack->item_count,
            ])->values()->all(),
            'communications' => $alert->communications->map(fn ($communication) => [
                'id' => $communication->id,
                'subject' => $communication->subject,
                'status' => $communication->status,
                'sent_at' => $communication->sent_at?->toIso8601String(),
            ])->values()->all(),
            'href' => $canOpen ? '/control-room/alerts/'.$alert->id : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentHealthSafety(?HsEvent $event, bool $canOpen): ?array
    {
        if ($event === null || ! $canOpen) {
            return null;
        }

        return [
            'id' => $event->id,
            'reference_number' => $event->reference_number ?: null,
            'status' => $event->status,
            'severity' => $event->severity,
            'handover' => [
                'status' => $event->handover_status,
                'owner' => $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                'accepted_by' => $event->acceptedBy ? ['id' => $event->acceptedBy->id, 'name' => $event->acceptedBy->name] : null,
                'accepted_at' => $event->accepted_at?->toIso8601String(),
                'notes' => $event->acceptance_notes,
            ],
            'worksafe' => [
                'notifiable' => (bool) $event->worksafe_notifiable,
                'status' => $event->worksafe_status,
                'reference' => $event->worksafe_reference,
                'notified_at' => $event->worksafe_notified_at?->toIso8601String(),
                'acknowledged_at' => $event->worksafe_acknowledged_at?->toIso8601String(),
            ],
            'investigation' => $event->activeInvestigation ? [
                'reference_number' => $event->activeInvestigation->reference_number,
                'status' => $event->activeInvestigation->status,
                'lead' => $event->activeInvestigation->leadInvestigator ? [
                    'id' => $event->activeInvestigation->leadInvestigator->id,
                    'name' => $event->activeInvestigation->leadInvestigator->name,
                ] : null,
                'due_at' => $event->activeInvestigation->target_completion_date?->toIso8601String(),
            ] : null,
            'href' => $canOpen ? '/health-safety/events/'.$event->id : null,
        ];
    }

    /** @return array{key: string, label: string, reference_number: ?string, state: string, href: ?string} */
    private function stage(string $key, string $label, ?string $reference, string $state, ?string $href): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'reference_number' => $reference ?: null,
            'state' => $state,
            'href' => $href,
        ];
    }

    private function alertState(?ControlRoomAlert $alert): string
    {
        if ($alert === null) {
            return 'not_started';
        }

        return $alert->isTerminal() ? 'complete' : 'in_progress';
    }

    private function incidentState(ClientIncident $incident): string
    {
        return $incident->status === 'draft' ? 'in_progress' : 'complete';
    }

    private function knownHsStatus(?string $status): bool
    {
        return in_array($status, [HsEvent::STATUS_OPEN, HsEvent::STATUS_INVESTIGATING,
            HsEvent::STATUS_CORRECTIVE_ACTION, HsEvent::STATUS_MONITORING, HsEvent::STATUS_CLOSED], true);
    }

    private function healthSafetyState(array $facts): string
    {
        if (! $facts['present']) {
            return 'not_started';
        }
        if ($facts['access'] !== 'readable' || ! $this->knownHsStatus($facts['status'])) {
            return 'blocked';
        }
        if ($facts['handover_status'] === HsEvent::HANDOVER_AWAITING_ACCEPTANCE) {
            return 'waiting';
        }

        return $facts['status'] === HsEvent::STATUS_CLOSED ? 'complete' : 'in_progress';
    }

    /** @return array{label: string, href: ?string, stage: string} */
    private function nextAction(ClientIncident $incident, ?ControlRoomAlert $alert, ?array $event, array $facts, User $viewer, bool $canOpenAlert, bool $canOpenIncident, bool $canOpenHs): array
    {
        // Immediate response takes precedence over report completion and
        // governance acceptance, without offering a source the viewer cannot open.
        if ($alert?->isActionable() && $canOpenAlert && $viewer->canDo('controlRoom.alerts.manage')) {
            return ['label' => 'Continue Control Room response', 'href' => '/control-room/alerts/'.$alert->id, 'stage' => 'control_room'];
        }

        if ($incident->status === 'draft' && $canOpenIncident && $viewer->can('update', $incident)) {
            return ['label' => 'Finish incident report', 'href' => '/incidents/'.$incident->id, 'stage' => 'incident'];
        }

        if ($canOpenHs && $facts['can_manage'] && $this->knownHsStatus($facts['status'])
            && $facts['status'] !== HsEvent::STATUS_CLOSED && $facts['handover_status'] === HsEvent::HANDOVER_AWAITING_ACCEPTANCE) {
            return ['label' => 'Accept H&S handover', 'href' => $event['href'], 'stage' => 'health_safety'];
        }

        if ($canOpenHs && $facts['can_manage'] && $this->knownHsStatus($facts['status']) && $facts['status'] !== HsEvent::STATUS_CLOSED) {
            return ['label' => 'Continue H&S work', 'href' => $event['href'], 'stage' => 'health_safety'];
        }

        if ($canOpenIncident) {
            return ['label' => 'View incident', 'href' => '/incidents/'.$incident->id, 'stage' => 'incident'];
        }

        if ($alert !== null && $canOpenAlert) {
            return ['label' => 'View alert', 'href' => '/control-room/alerts/'.$alert->id, 'stage' => 'control_room'];
        }

        if ($canOpenHs) {
            return ['label' => 'View H&S event', 'href' => $event['href'], 'stage' => 'health_safety'];
        }

        if ($this->journeyState($incident, $alert, null, $facts) === 'Journey closed') {
            return ['label' => 'Journey closed', 'href' => null, 'stage' => 'complete'];
        }

        return ['label' => 'No action available for your role', 'href' => null, 'stage' => 'blocked'];
    }
}
