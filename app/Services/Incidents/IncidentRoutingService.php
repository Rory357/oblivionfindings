<?php

namespace App\Services\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\IncidentOwnerChange;
use App\Models\IncidentRoutingDecision;
use App\Models\IncidentRoutingIntent;
use App\Models\IncidentTriageDelivery;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Durable submission routing. The report's transaction stages intent, never resolves recipients. */
final class IncidentRoutingService
{
    public const SOURCE_COLUMNS = ['id', 'client_id', 'site_id', 'shift_id', 'reported_by', 'submitted_at', 'type', 'is_hr_confidential', 'status', 'incident_owner_user_id', 'owner_revision'];

    public function __construct(private readonly IncidentRoutingSettings $settings, private readonly IncidentRoutingResolver $resolver, private readonly IncidentOwnershipService $owners) {}

    public static function tuple(ClientIncident $row): array
    {
        return ['incident_id' => (int) $row->id, 'client_id' => $row->client_id === null ? null : (int) $row->client_id,
            'site_id' => $row->site_id === null ? null : (int) $row->site_id, 'shift_id' => $row->shift_id === null ? null : (int) $row->shift_id,
            'reported_by' => $row->reported_by === null ? null : (int) $row->reported_by, 'type' => (string) $row->type,
            'is_hr_confidential' => (bool) $row->is_hr_confidential, 'submitted_at' => $row->submitted_at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    /** Initial submission hook only: source identity/real trigger, never recipients/configuration. */
    public function stageForSubmitted(int $incidentId, ?User $actualTrigger = null): ?int
    {
        return DB::transaction(function () use ($incidentId, $actualTrigger): ?int {
            $row = ClientIncident::query()->whereKey($incidentId)->lockForUpdate()->firstOrFail(self::SOURCE_COLUMNS);
            if (in_array($row->status, ['draft', 'closed'], true) || $row->incident_owner_user_id !== null || (int) $row->owner_revision !== 0) {
                return null;
            }
            return CurrentAuthorizationReads::within(fn ($reads) => $this->stage($row, 'initial', $actualTrigger, $reads));
        });
    }

    /** Explicit postcommit/sweep hook. Retains the historical owner; never automatically transfers ownership. */
    public function stageForReplacement(int $incidentId, ?User $actualTrigger = null): ?int
    {
        return DB::transaction(function () use ($incidentId, $actualTrigger): ?int {
            $row = ClientIncident::query()->whereKey($incidentId)->lockForUpdate()->firstOrFail(self::SOURCE_COLUMNS);
            if (in_array($row->status, ['draft', 'closed'], true) || ($row->incident_owner_user_id === null && (int) $row->owner_revision === 0)) {
                return null;
            }
            return CurrentAuthorizationReads::within(function ($reads) use ($row, $actualTrigger): ?int {
                if ($this->owners->hasCurrentOwner($row, $reads)) { return null; }
                return $this->stage($row, 'replacement', $actualTrigger, $reads);
            });
        });
    }

    private function stage(ClientIncident $row, string $kind, ?User $actualTrigger, CurrentAuthorizationReads $reads): int
    {
        $reads->assertActive();
        $existing = IncidentRoutingIntent::query()->where('incident_id', $row->id)->where('owner_revision', $row->owner_revision)->where('kind', $kind)->lockForUpdate()->first();
        if ($existing) { return (int) $existing->id; }
        $trigger = $actualTrigger ? $reads->query(User::query()->whereKey($actualTrigger->id))->first(['id', 'name']) : null;
        $tuple = self::tuple($row);
        $intent = new IncidentRoutingIntent(['incident_id' => $row->id, 'owner_revision' => (int) $row->owner_revision, 'kind' => $kind,
            'request_uuid' => (string) Str::uuid(), 'source_tuple' => $tuple, 'source_digest' => IncidentRoutingValues::digest($tuple),
            'trigger_actor_id' => $trigger?->id, 'trigger_actor_name' => $trigger?->name, 'state' => 'pending', 'attempts' => 0]);
        IncidentRoutingSettings::saved($intent);
        return (int) $intent->id;
    }

    /** Runs only after report commit. Expected routing failure is retained; unexpected failure leaves a retryable intent. */
    public function consume(int $intentId, ?User $manager = null): array
    {
        try {
            return $this->settings->withLockedConfiguration(function ($snapshot) use ($intentId, $manager): array {
                $identity = IncidentRoutingIntent::query()->whereKey($intentId)->firstOrFail(['id', 'incident_id']);
                $root = ClientIncident::query()->whereKey($identity->incident_id)->lock('for update nowait')->firstOrFail(self::SOURCE_COLUMNS);
                $intent = IncidentRoutingIntent::query()->whereKey($intentId)->lockForUpdate()->firstOrFail();
                if ($intent->state !== 'pending' && $intent->state !== 'error') {
                    $last = IncidentRoutingDecision::query()->where('intent_id', $intent->id)->orderByDesc('attempt')->first();
                    if ($last) {
                        $this->assertDecision($last, $intent);
                        return $this->result($last, $intent);
                    }
                    IncidentRoutingValues::error('routing', 'The retained routing outcome needs repair.');
                }
                return CurrentAuthorizationReads::within(function ($reads) use ($root, $intent, $snapshot, $manager): array {
                    if ($manager) {
                        $current = app(\App\Services\AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($manager, IncidentRoutingSettings::PERMISSIONS);
                        abort_unless(app(IncidentOwnerEligibility::class)->canAssign($current, $root, $reads), 403);
                    }
                    $now = CarbonImmutable::instance(now('UTC'));
                    $tuple = self::tuple($root);
                    $resolved = $this->resolver->resolve($root, $snapshot, $reads, $now);
                    $change = null;
                    if (! hash_equals($intent->source_digest, IncidentRoutingValues::digest($intent->source_tuple))) {
                        IncidentRoutingValues::error('routing', 'The original routing intent needs repair.');
                    }
                    if (in_array($root->status, ['draft', 'closed'], true) || (int) $root->owner_revision !== $intent->owner_revision) {
                        $state = 'superseded'; $reason = 'source_lifecycle_changed';
                    } elseif (IncidentRoutingValues::digest($tuple) !== $intent->source_digest) {
                        $state = 'setup_required'; $reason = 'source_context_changed';
                    } elseif ($this->owners->hasCurrentOwner($root, $reads)) {
                        $state = 'superseded'; $reason = 'existing_owner_preserved';
                    } elseif ($intent->kind === 'initial' && $root->incident_owner_user_id === null && $resolved['owner_user_id'] !== null && $resolved['monitored']) {
                        $change = $this->owners->commitConfigured($root, $intent, $resolved['owner_user_id'], $snapshot, $resolved['route'], $reads);
                        $state = 'assigned'; $reason = $resolved['route'];
                    } elseif ($intent->kind === 'replacement') {
                        $state = $resolved['monitored'] ? 'replacement_needed' : 'setup_required';
                        $reason = $resolved['monitored'] ? 'owner_ineligible' : 'triage_unavailable';
                    } else {
                        $state = $resolved['monitored'] ? 'needs_owner' : 'setup_required';
                        $reason = $resolved['monitored'] ? $resolved['reason_code'] : ($resolved['reason_code'] === 'configuration_missing' || $resolved['reason_code'] === 'configuration_invalid' ? $resolved['reason_code'] : 'triage_unavailable');
                    }
                    $unresolved = in_array($state, ['needs_owner', 'replacement_needed', 'setup_required'], true);
                    $escalate = $unresolved && $resolved['escalation_after_minutes'] !== null ? $now->addMinutes($resolved['escalation_after_minutes']) : null;
                    if ($escalate && $escalate->year > 9999) {
                        IncidentRoutingValues::error('configuration', 'The configured interval cannot be represented safely.');
                    }
                    $facts = ['intent_id' => (int) $intent->id, 'attempt' => $intent->attempts + 1, 'state' => $state, 'reason_code' => $reason,
                        'source_tuple' => $intent->source_tuple, 'source_digest' => $intent->source_digest,
                        'setting_change_id' => $snapshot['setting_change_id'], 'configuration_revision' => $snapshot['revision'],
                        'configuration_digest' => $snapshot['digest'], 'configuration' => $snapshot['configuration'],
                        'owner_change_id' => $change?->id, 'recorded_at' => $now->format('Y-m-d\TH:i:s.u\Z'), 'escalate_at' => $escalate?->format('Y-m-d\TH:i:s.u\Z')];
                    $meta = ['intent_id' => (int) $intent->id, 'attempt' => $facts['attempt'], 'decision_digest' => IncidentRoutingValues::digest($facts)];
                    $actor = $intent->trigger_actor_id ? $reads->query(User::query()->whereKey($intent->trigger_actor_id))->first(['id']) : null;
                    $request = clone request(); $request->setUserResolver(fn () => $actor);
                    AuditLogger::logOrFail('incidents.routing.evaluated', $intent, $meta, $request, $actor === null);
                    $audits = AuditLog::query()->where('action', 'incidents.routing.evaluated')->where('auditable_type', $intent->getMorphClass())
                        ->where('auditable_id', $intent->id)->where('meta->attempt', $facts['attempt'])->lockForUpdate()->get();
                    if ($audits->count() !== 1 || IncidentRoutingValues::digest($audits->sole()->meta) !== IncidentRoutingValues::digest($meta)) {
                        throw new LogicException('Routing requires one exact persisted decision audit.');
                    }
                    $decision = new IncidentRoutingDecision($facts + ['audit_log_id' => $audits->sole()->id]);
                    IncidentRoutingSettings::saved($decision);
                    $intent->forceFill(['state' => $state, 'attempts' => $facts['attempt'], 'failure_code' => null, 'evaluated_at' => $now]);
                    IncidentRoutingSettings::saved($intent);
                    if ($unresolved) {
                        foreach (['triage' => $resolved['triage_user_id'], 'escalation' => $resolved['escalation_user_id']] as $role => $recipient) {
                            if ($recipient !== null && ($role !== 'escalation' || $escalate !== null)) {
                                IncidentRoutingSettings::saved(new IncidentTriageDelivery(['id' => (string) Str::uuid(), 'decision_id' => $decision->id, 'recipient_user_id' => $recipient,
                                    'recipient_role' => $role, 'status' => 'pending', 'due_at' => $role === 'triage' ? $now : $escalate, 'attempts' => 0]));
                            }
                        }
                    }
                    $this->assertDecision($decision, $intent);
                    return $this->result($decision, $intent);
                });
            }, beforeRead: $manager === null ? null : function () use ($manager, $intentId): void {
                $this->owners->withViewer($manager, function ($current, $reads) use ($intentId): void {
                    $identity = $reads->query(IncidentRoutingIntent::query()->whereKey($intentId))->firstOrFail(['id', 'incident_id']);
                    $root = $this->owners->admittedIncident($current, (int) $identity->incident_id, $reads);
                    abort_unless(app(IncidentOwnerEligibility::class)->canAssign($current, $root, $reads), 403);
                });
            });
        } catch (Throwable $error) {
            // This is failure evidence, not an accepted decision; preserve original intent/UUID for physical retry.
            if (! ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) && ! ($error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException)) {
                IncidentRoutingIntent::query()->whereKey($intentId)->whereIn('state', ['pending', 'error'])->update(['state' => 'error', 'failure_code' => 'routing_failed', 'updated_at' => now()]);
            }
            throw $error;
        }
    }

    public function preview(User $viewer, int $incidentId): array
    {
        return $this->settings->withLockedConfiguration(fn ($snapshot) => $this->owners->withViewer($viewer, function ($current, $reads) use ($incidentId, $snapshot): array {
            $root = $this->owners->admittedIncident($current, $incidentId, $reads);
            abort_unless(app(IncidentOwnerEligibility::class)->canAssign($current, $root, $reads), 403);
            $resolved = $this->resolver->resolve($root, $snapshot, $reads, CarbonImmutable::instance(now('UTC')));
            $owner = $resolved['owner_user_id'] === null ? null : $reads->query(User::query()->whereKey($resolved['owner_user_id']))->first(['id', 'name']);
            return ['incident_id' => $incidentId, 'expected_owner_revision' => (int) $root->owner_revision, 'configuration_revision' => $snapshot['revision'],
                'configuration_state' => $snapshot['state'], 'scope' => $resolved['scope'], 'owner' => $owner ? ['id' => (int) $owner->id, 'name' => $owner->name] : null,
                'route' => $resolved['route'], 'reason_code' => $resolved['monitored'] ? $resolved['reason_code'] : 'triage_unavailable', 'monitored' => $resolved['monitored'], 'existing_owner_will_be_preserved' => $root->incident_owner_user_id !== null || (int) $root->owner_revision > 0];
        }), beforeRead: function () use ($viewer, $incidentId): void {
            $this->owners->withViewer($viewer, function ($current, $reads) use ($incidentId): void {
                $root = $this->owners->admittedIncident($current, $incidentId, $reads);
                abort_unless(app(IncidentOwnerEligibility::class)->canAssign($current, $root, $reads), 403);
            });
        });
    }

    public function retryForViewer(User $viewer, int $intentId): array
    {
        $this->owners->withViewer($viewer, function ($current, $reads) use ($intentId): void {
            $id = $reads->query(IncidentRoutingIntent::query()->whereKey($intentId))->firstOrFail(['id', 'incident_id']);
            $root = $this->owners->admittedIncident($current, (int) $id->incident_id, $reads, true);
            abort_unless(app(IncidentOwnerEligibility::class)->canAssign($current, $root, $reads), 403);
            $intent = IncidentRoutingIntent::query()->whereKey($intentId)->lockForUpdate()->firstOrFail();
            if (! in_array($intent->state, ['assigned', 'superseded'], true)) {
                $intent->state = 'pending'; IncidentRoutingSettings::saved($intent);
            }
        });
        return $this->consume($intentId, $viewer);
    }

    /** Cursor projection, not a total/completeness certificate. Settings authority alone does not admit incidents. */
    public function queueForViewer(User $viewer, int $afterId = 0): array
    {
        return $this->owners->withViewer($viewer, function ($current, $reads) use ($afterId): array {
            abort_unless($current->canDo('incidents.owner.assign'), 403);
            $sources = app(IncidentAccessService::class)->apply(ClientIncident::query(), $current, $reads)->whereNotIn('status', ['draft', 'closed'])->select('client_incidents.id');
            $rows = $reads->query(IncidentRoutingIntent::query()->where('id', '>', $afterId)->whereIn('incident_id', $sources)
                ->whereIn('state', ['pending', 'error', 'needs_owner', 'setup_required', 'replacement_needed'])->orderBy('id')->limit(26))->get();
            $items = [];
            foreach ($rows->take(25) as $intent) {
                $root = $this->owners->admittedIncident($current, (int) $intent->incident_id, $reads);
                if ($this->owners->hasCurrentOwner($root, $reads)) {
                    continue;
                }
                $last = $reads->query(IncidentRoutingDecision::query()->where('intent_id', $intent->id)->orderByDesc('attempt'))->first();
                if ($last) {
                    $this->assertDecision($last, $intent);
                }
                $items[] = ['intent_id' => (int) $intent->id, 'incident_id' => (int) $root->id, 'href' => '/incidents/'.$root->id,
                    'state' => $intent->state, 'reason_code' => $last?->reason_code, 'can_retry' => app(IncidentOwnerEligibility::class)->canAssign($current, $root, $reads),
                    'evaluated_at' => $intent->evaluated_at?->toIso8601String(), 'escalate_at' => $last?->escalate_at?->toIso8601String()];
            }
            return ['items' => $items, 'next_cursor' => $rows->count() > 25 ? (int) $rows[24]->id : null];
        });
    }

    /** Scheduler hook only; callers retain cursor. No retroactive assignment of unowned legacy reports. */
    public function sweep(int $limit = 100, int $afterIncidentId = 0, int $afterIntentId = 0): array
    {
        $limit = max(1, min(100, $limit));
        $attempted = 0; $failed = 0;
        $intents = IncidentRoutingIntent::query()->where('id', '>', max(0, $afterIntentId))->whereIn('state', ['pending', 'error'])->orderBy('id')->limit($limit)->pluck('id');
        foreach ($intents as $id) {
            try { $this->consume((int) $id); } catch (Throwable) { $failed++; }
            $attempted++;
        }
        $owned = ClientIncident::query()->where('id', '>', $afterIncidentId)->where(fn ($q) => $q->whereNotNull('incident_owner_user_id')->orWhere('owner_revision', '>', 0))->whereNotIn('status', ['draft', 'closed'])->orderBy('id')->limit($limit)->pluck('id');
        foreach ($owned as $id) {
            try {
                if ($intent = $this->stageForReplacement((int) $id)) { $this->consume($intent); }
            } catch (Throwable) { $failed++; }
            $attempted++; // One selected replacement check, whether no change, consumed, or failed.
        }
        return ['attempted' => $attempted, 'failed' => $failed, 'next_incident_cursor' => $owned->count() === $limit ? (int) $owned->last() : null,
            'next_intent_cursor' => $intents->count() === $limit ? (int) $intents->last() : null];
    }

    public function assertDecision(IncidentRoutingDecision $decision, IncidentRoutingIntent $intent, ?\Illuminate\Database\Eloquent\Builder $protectedSource = null, ?CurrentAuthorizationReads $reads = null): void
    {
        $boundary = app(IncidentTypedNotificationReadService::class);
        $boundary->constrain(AuditLog::query(), $protectedSource, $reads);
        if ($protectedSource !== null) { $protectedSource = (clone $protectedSource)->whereKey((int) $intent->incident_id); }
        $facts = $decision->only(['intent_id', 'attempt', 'state', 'reason_code', 'source_tuple', 'source_digest', 'setting_change_id', 'configuration_revision', 'configuration_digest', 'configuration', 'owner_change_id']);
        foreach (['intent_id', 'setting_change_id', 'owner_change_id'] as $field) { $facts[$field] = $facts[$field] === null ? null : (int) $facts[$field]; }
        $facts['recorded_at'] = $decision->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z');
        $facts['escalate_at'] = $decision->escalate_at?->utc()->format('Y-m-d\TH:i:s.u\Z');
        $auditQuery = AuditLog::query()->whereKey($decision->audit_log_id)->lock('for share nowait');
        if ($protectedSource !== null) {
            $auditQuery->whereRaw('BINARY `action` = BINARY ?', ['incidents.routing.evaluated'])
                ->whereRaw('BINARY `auditable_type` = BINARY ?', [$intent->getMorphClass()])->where('auditable_id', $intent->id)
                ->when($intent->trigger_actor_id === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $intent->trigger_actor_id));
        }
        $audit = $boundary->constrain($auditQuery, $protectedSource, $reads)->first();
        $meta = ['intent_id' => (int) $intent->id, 'attempt' => $decision->attempt, 'decision_digest' => IncidentRoutingValues::digest($facts)];
        if ((int) $decision->intent_id !== (int) $intent->id || $decision->source_digest !== $intent->source_digest
            || IncidentRoutingValues::digest($decision->source_tuple) !== $intent->source_digest || ! $audit || $audit->action !== 'incidents.routing.evaluated'
            || $audit->auditable_type !== $intent->getMorphClass() || (int) $audit->auditable_id !== (int) $intent->id
            || $audit->user_id != $intent->trigger_actor_id || IncidentRoutingValues::digest($audit->meta) !== IncidentRoutingValues::digest($meta)) {
            IncidentRoutingValues::error('routing', 'The retained routing decision needs repair.');
        }
        if ($decision->setting_change_id) {
            $historyQuery = \App\Models\HealthSafetySettingChange::query()->whereKey($decision->setting_change_id)->lock('for share nowait');
            if ($protectedSource !== null) { $historyQuery->where('setting_key', IncidentRoutingSettings::KEY); }
            $history = $boundary->constrain($historyQuery, $protectedSource, $reads)->firstOrFail();
            $this->settings->assertHistory($history, $protectedSource, $reads);
            if ($history->revision !== $decision->configuration_revision || $history->after_digest !== $decision->configuration_digest
                || IncidentRoutingValues::digest($decision->configuration) !== $history->after_digest) {
                IncidentRoutingValues::error('routing', 'The original routing configuration needs repair.');
            }
        }
        if ($decision->owner_change_id) {
            $changeQuery = IncidentOwnerChange::query()->whereKey($decision->owner_change_id)->lock('for share nowait');
            if ($protectedSource !== null) { $changeQuery->whereIn('incident_id', (clone $protectedSource)->reorder()->select('client_incidents.id')); }
            $change = $boundary->constrain($changeQuery, $protectedSource, $reads)->firstOrFail();
            $this->owners->assertAcceptance($change, $protectedSource, $reads);
            if ((int) $change->incident_id !== (int) $intent->incident_id || $change->request_uuid !== $intent->request_uuid || $change->routing_version !== (string) $decision->configuration_revision) {
                IncidentRoutingValues::error('routing', 'The assignment does not match the routing decision.');
            }
        }
    }

    private function result(IncidentRoutingDecision $decision, IncidentRoutingIntent $intent): array
    {
        // Capture receipt has no recipient identity, source body, deadline guess or delivery-success claim.
        return ['incident_id' => (int) $intent->incident_id, 'routing_intent_id' => (int) $intent->id, 'routing_decision_id' => (int) $decision->id,
            'state' => $decision->state, 'reason_code' => $decision->reason_code, 'owner_assigned' => $decision->state === 'assigned', 'delivery' => 'unconfirmed'];
    }
}
