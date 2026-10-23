<?php

namespace App\Services\Incidents;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HealthSafetySettingChange;
use App\Models\IncidentRoutingDecision;
use App\Models\IncidentRoutingIntent;
use App\Models\IncidentTriageDelivery;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Named triage notification is separate from ownership and from completion of the report. */
final class IncidentTriageDeliveryService
{
    public function __construct(private readonly IncidentRoutingSettings $settings, private readonly IncidentRoutingService $routing, private readonly IncidentOwnershipService $owners, private readonly IncidentOwnerEligibility $eligibility, private readonly IncidentRoutingResolver $resolver, private readonly AuthorizationEvidenceLockService $authorization) {}

    public function deliver(string $id, ?User $manager = null): array
    {
        try {
            return $this->settings->withLockedConfigurationMutex(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($id, $manager): array {
                $leaf = app(IncidentTypedNotificationReadService::class);
                $deliveryColumns = ['id', 'decision_id', 'recipient_user_id', 'recipient_role', 'due_at'];
                $identity = $reads->query(IncidentTriageDelivery::query()->whereKey($id))->firstOrFail($deliveryColumns);
                $decisionColumns = ['id', 'intent_id', 'audit_log_id', 'setting_change_id', 'owner_change_id', 'source_digest', 'recorded_at', 'escalate_at'];
                $decisionIdentity = $reads->query(IncidentRoutingDecision::query()->whereKey($identity->decision_id)->select($decisionColumns)
                    ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(configuration, '$.triage.user_id')) AS expected_triage")
                    ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(configuration, '$.triage.escalation_user_id')) AS expected_escalation"))->firstOrFail();
                $intentColumns = ['id', 'incident_id', 'owner_revision', 'source_digest'];
                $intentIdentity = $reads->query(IncidentRoutingIntent::query()->whereKey($decisionIdentity->intent_id))->firstOrFail($intentColumns);
                $rootQuery = $reads->query(ClientIncident::query()->whereKey($intentIdentity->incident_id));
                $rootQuery->getQuery()->beforeQuery(fn ($query) => $query->lock('for update nowait'));
                $root = $rootQuery->firstOrFail(IncidentRoutingService::SOURCE_COLUMNS);
                $lease = $reads->query($leaf->identity(IncidentTriageDelivery::query()->whereKey($id), $identity, $deliveryColumns));
                $lease->getQuery()->beforeQuery(fn ($query) => $query->lock('for update nowait'));
                $delivery = $lease->firstOrFail([...$deliveryColumns, 'status', 'attempts', 'delivered_at', 'failure_code']);
                if ($manager) { $this->admitManager($manager, (int) $root->id, $reads); }
                $expected = match ($delivery->recipient_role) { 'triage' => $decisionIdentity->expected_triage, 'escalation' => $decisionIdentity->expected_escalation, default => null };
                $due = $delivery->recipient_role === 'triage' ? $decisionIdentity->recorded_at : $decisionIdentity->escalate_at;
                if (! is_scalar($expected) || ! preg_match('/^[1-9][0-9]*$/D', (string) $expected) || (int) $expected !== (int) $delivery->recipient_user_id
                    || ! $due || ! $delivery->due_at->equalTo($due)) {
                    IncidentRoutingValues::error('delivery', 'The triage delivery does not match its accepted routing decision.');
                }
                $recipient = $this->recipient((int) $delivery->recipient_user_id, $reads);
                if (! $recipient || ! $this->eligibility->activeInternal($recipient, $reads) || ! app(IncidentAccessService::class)->canView($recipient, $root, $reads)) {
                    return $this->unavailable($delivery, 'skipped', 'current_authority_unavailable');
                }
                $source = $leaf->identity($leaf->source($recipient, (int) $root->id, $reads), $root, IncidentRoutingService::SOURCE_COLUMNS);
                if (! (clone $source)->exists()) { return $this->unavailable($delivery, 'failed', 'source_unavailable'); }
                // The immutable routing context must still describe this exact source BEFORE private intent/decision/configuration bodies.
                if (! hash_equals((string) $intentIdentity->source_digest, IncidentRoutingValues::digest(IncidentRoutingService::tuple($root)))
                    || $decisionIdentity->source_digest !== $intentIdentity->source_digest) {
                    return $this->unavailable($delivery, 'failed', 'source_unavailable');
                }
                $intent = $leaf->row('intent', (int) $intentIdentity->id, $reads, $source, $intentIdentity, $intentColumns);
                $decision = $leaf->row('decision', (int) $decisionIdentity->id, $reads, $leaf->protect($source, ['intent' => $intent], $reads), $decisionIdentity, $decisionColumns);
                $frame = $leaf->protect($source, ['intent' => $intent, 'decision' => $decision], $reads);
                $auditIdentity = $leaf->constrain(AuditLog::query()->whereKey($decision->audit_log_id)
                    ->whereRaw('BINARY `action` = BINARY ?', ['incidents.routing.evaluated'])
                    ->whereRaw('BINARY `auditable_type` = BINARY ?', [$intent->getMorphClass()])->where('auditable_id', $intent->id), $frame, $reads)
                    ->firstOrFail(['id', 'action', 'auditable_type', 'auditable_id', 'user_id']);
                $audit = $leaf->row('audit', (int) $auditIdentity->id, $reads, $frame, $auditIdentity, ['id', 'action', 'auditable_type', 'auditable_id', 'user_id']);
                $rows = ['intent' => $intent, 'decision' => $decision, 'audit' => [$audit]];
                $mutex = null;
                if ($decision->setting_change_id !== null) {
                    $frame = $leaf->protect($source, $rows, $reads);
                    $settingIdentity = $leaf->constrain(HealthSafetySettingChange::query()->whereKey($decision->setting_change_id)
                        ->where('setting_key', IncidentRoutingSettings::KEY), $frame, $reads)->firstOrFail(['id', 'audit_id', 'setting_key', 'revision']);
                    $setting = $leaf->row('setting', (int) $settingIdentity->id, $reads, $frame, $settingIdentity, ['id', 'audit_id', 'setting_key', 'revision']);
                    $mutex = $reads->query(AppSetting::query()->where('key', IncidentRoutingSettings::REVISION_KEY))->firstOrFail(['id', 'key']);
                    $configAuditId = $reads->query(AuditLog::query()->whereKey($setting->audit_id)
                        ->whereRaw('BINARY `action` = BINARY ?', ['incidents.routing.settings.'.$setting->action])
                        ->whereRaw('BINARY `auditable_type` = BINARY ?', [$mutex->getMorphClass()])->where('auditable_id', $mutex->id))
                        ->firstOrFail(['id', 'action', 'auditable_type', 'auditable_id', 'user_id']);
                    $rows['setting'] = $setting;
                    $rows['audit'][] = $leaf->row('audit', (int) $configAuditId->id, $reads, $leaf->protect($source, [...$rows, 'setting' => $setting], $reads), $configAuditId, ['id', 'action', 'auditable_type', 'auditable_id', 'user_id']);
                }
                $this->routing->assertDecision($decision, $intent, $leaf->protect($source, $rows, $reads), $reads);
                $last = $reads->query(IncidentRoutingDecision::query()->where('intent_id', $intent->id)->orderByDesc('attempt'))->first(['id']);
                $eligible = $last && (int) $last->id === (int) $decision->id && $intent->attempts === $decision->attempt
                    && in_array($intent->state, ['needs_owner', 'replacement_needed', 'setup_required'], true)
                    && ! in_array($root->status, ['draft', 'closed'], true) && (int) $root->owner_revision === $intent->owner_revision
                    && ! $this->owners->hasCurrentOwner($root, $reads, $leaf->protect($source, $rows, $reads)) && $this->resolver->eligible((int) $recipient->id, $root, $reads, true);

                $guard = $leaf->protect($source, $rows, $reads)
                    ->whereExists(fn ($query) => $query->selectRaw('1')->from('incident_triage_deliveries')->where('id', $identity->id)
                        ->where('decision_id', $identity->decision_id)->where('recipient_user_id', $identity->recipient_user_id)
                        ->whereRaw('BINARY `recipient_role` = BINARY ?', [$identity->recipient_role])->where('due_at', $identity->getRawOriginal('due_at')))
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('incident_routing_decisions')
                        ->where('intent_id', $intent->id)->where('attempt', '>', $decision->attempt));
                if ($mutex !== null) { $guard->whereExists(fn ($query) => $query->selectRaw('1')->from('app_settings')->where('id', $mutex->id)->where('key', $mutex->key)); }
                $guard->whereNotExists(fn ($query) => $query->selectRaw('1')->from('audit_logs')->where('action', 'incidents.routing.evaluated')
                    ->where('auditable_type', $intent->getMorphClass())->where('auditable_id', $intent->id)->where('meta->attempt', $decision->attempt)->where('id', '!=', $audit->id));
                if ($mutex !== null) {
                    $guard->whereNotExists(fn ($query) => $query->selectRaw('1')->from('audit_logs')
                        ->where('action', 'incidents.routing.settings.'.$setting->action)->where('auditable_type', $mutex->getMorphClass())
                        ->where('auditable_id', $mutex->id)->where('meta->request_uuid', $setting->command_key)->where('id', '!=', $setting->audit_id));
                }
                if (! $eligible) {
                    if (! (clone $guard)->exists()) { throw new LogicException('Incident triage notification source changed before acceptance.'); }
                    return $this->unavailable($delivery, 'skipped', 'current_authority_unavailable');
                }
                $this->assertCurrent($recipient, $root, $guard, $reads, $manager);
                if ($delivery->due_at->isFuture()) { return ['id' => $delivery->id, 'state' => 'pending', 'confirmed' => false]; }
                $payload = ['kind' => 'incident_ownership', 'event_key' => 'incidents.owner.needs_owner',
                    'title' => $intent->kind === 'replacement' ? 'Incident owner needs replacement' : 'Incident needs an owner',
                    'body' => 'Open the authorised incident to review ownership.', 'url' => '/incidents/'.$root->id,
                    'entity' => 'Incident', 'entity_id' => (int) $root->id, 'routing_delivery_id' => $delivery->id];
                $notification = $reads->query(DB::table('notifications')->where('id', $delivery->id)->whereExists((clone $guard)->reorder()->select('client_incidents.id')->toBase()))->exists();
                if (! $notification) { $message = new AppEventNotification($payload); $message->id = $delivery->id; $recipient->notify($message); }
                $this->assertNotice($delivery, $recipient, $payload, $reads, $guard);
                $this->assertCurrent($recipient, $root, $guard, $reads, $manager);
                if ($delivery->status !== 'delivered') {
                    $delivery->forceFill(['status' => 'delivered', 'failure_code' => null, 'attempts' => $delivery->attempts + 1, 'delivered_at' => now()->utc()]);
                    IncidentRoutingSettings::saved($delivery);
                }
                $this->assertCurrent($recipient, $root, $guard, $reads, $manager);
                $this->assertNotice($delivery, $recipient, $payload, $reads, $guard);
                if (! $reads->query(IncidentTriageDelivery::query()->whereKey($id)->where('decision_id', $identity->decision_id)
                    ->where('recipient_user_id', $recipient->id)->where('recipient_role', $identity->recipient_role)->whereRaw('BINARY `status` = BINARY ?', ['delivered'])->whereExists((clone $guard)->reorder()->select('client_incidents.id')->toBase()))->exists()) {
                    throw new LogicException('Triage notification lacks its accepted delivery state.');
                }
                return ['id' => $delivery->id, 'state' => 'delivered', 'confirmed' => true];
            }), beforeRead: $manager === null ? null : function () use ($manager, $id): void {
                $this->owners->withViewer($manager, function ($current, $reads) use ($id): void {
                    $delivery = $reads->query(IncidentTriageDelivery::query()->whereKey($id))->firstOrFail(['id', 'decision_id']);
                    $decision = $reads->query(IncidentRoutingDecision::query()->whereKey($delivery->decision_id))->firstOrFail(['id', 'intent_id']);
                    $intent = $reads->query(IncidentRoutingIntent::query()->whereKey($decision->intent_id))->firstOrFail(['id', 'incident_id']);
                    $this->admitManager($current, (int) $intent->incident_id, $reads);
                });
            });
        } catch (Throwable $error) {
            if (! ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface)) {
                IncidentTriageDelivery::query()->whereKey($id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'failed', 'failure_code' => 'delivery_failed', 'updated_at' => now()]);
            }
            throw $error;
        }
    }

    private function recipient(int $id, CurrentAuthorizationReads $reads): ?User
    {
        if (! $reads->query(User::query()->whereKey($id))->exists()) { return null; }
        return $this->authorization->lockForUserWithoutWaiting($id, IncidentRoutingSettings::PERMISSIONS);
    }

    private function admitManager(User $manager, int $id, CurrentAuthorizationReads $reads): void
    {
        $current = $this->authorization->lockForUserWithoutWaiting($manager, IncidentRoutingSettings::PERMISSIONS);
        $source = app(IncidentTypedNotificationReadService::class)->source($current, $id, $reads, 'read');
        $root = $source->first(IncidentRoutingService::SOURCE_COLUMNS);
        abort_unless($root !== null && $this->eligibility->canManage($current, $root, $reads), 403);
    }

    private function assertCurrent(User $recipient, ClientIncident $root, $guard, CurrentAuthorizationReads $reads, ?User $manager): void
    {
        $fresh = $this->recipient((int) $recipient->id, $reads);
        if (! $fresh || ! $this->resolver->eligible((int) $fresh->id, $root, $reads, true)
            || ! app(IncidentAccessService::class)->apply(clone $guard, $fresh, $reads)->exists()) {
            throw new LogicException('Incident triage notification source changed before acceptance.');
        }
        if ($manager) { $this->admitManager($manager, (int) $root->id, $reads); }
    }

    private function unavailable(IncidentTriageDelivery $delivery, string $state, string $code): array
    {
        if ($delivery->status !== 'delivered') {
            $delivery->forceFill(['status' => $state, 'failure_code' => $code]); IncidentRoutingSettings::saved($delivery);
        }
        return ['id' => $delivery->id, 'state' => 'unavailable', 'confirmed' => false];
    }

    private function assertNotice(IncidentTriageDelivery $delivery, User $recipient, array $payload, CurrentAuthorizationReads $reads, \Illuminate\Database\Eloquent\Builder $guard): void
    {
        $notice = $reads->query(DB::table('notifications')->where('id', $delivery->id)
            ->whereRaw('BINARY `type` = BINARY ?', [AppEventNotification::class])
            ->whereRaw('BINARY `notifiable_type` = BINARY ?', [$recipient->getMorphClass()])->where('notifiable_id', $recipient->id)
            ->whereExists((clone $guard)->reorder()->select('client_incidents.id')->toBase()))->first();
        $data = $notice ? json_decode($notice->data, true) : null; if (is_array($data)) { unset($data['emitted_at']); }
        if (! $notice || $notice->type !== AppEventNotification::class || $notice->notifiable_type !== $recipient->getMorphClass()
            || (int) $notice->notifiable_id !== (int) $recipient->id || IncidentRoutingValues::digest($data) !== IncidentRoutingValues::digest($payload)) {
            throw new LogicException('Triage delivery requires the exact persisted current-recipient notification.');
        }
    }

    public function deliverDue(int $limit = 100): array
    {
        $attempted = 0; $confirmed = 0; $failed = 0;
        foreach (IncidentTriageDelivery::query()->whereIn('status', ['pending', 'failed'])->where('due_at', '<=', now())->orderBy('due_at')->limit(max(1, min(100, $limit)))->pluck('id') as $id) {
            try { $confirmed += $this->deliver($id)['confirmed'] ? 1 : 0; } catch (Throwable) { $failed++; } $attempted++;
        }
        return ['attempted' => $attempted, 'confirmed' => $confirmed, 'failed' => $failed];
    }
}
