<?php

namespace App\Services\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\IncidentOwnerChange;
use App\Models\IncidentOwnerDelivery;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class IncidentOwnerNotifications
{
    public function deliver(string $id): void
    {
        try {
            DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($id): void {
                $leaf = app(IncidentTypedNotificationReadService::class);
                $identityColumns = ['id', 'change_id', 'recipient_user_id', 'recipient_role'];
                $identity = $reads->query(IncidentOwnerDelivery::query()->whereKey($id))->firstOrFail([...$identityColumns, 'status']);
                if ($identity->status === 'skipped') { return; }
                $changeColumns = ['id', 'incident_id', 'revision', 'owner_user_id', 'previous_owner_user_id', 'audit_log_id', 'source_schema_version'];
                $changeIdentity = $reads->query(IncidentOwnerChange::query()->whereKey($identity->change_id))->firstOrFail($changeColumns);
                $rootQuery = $reads->query(ClientIncident::query()->whereKey($changeIdentity->incident_id));
                $rootQuery->getQuery()->beforeQuery(fn ($query) => $query->lock('for update nowait'));
                $root = $rootQuery->firstOrFail(IncidentRoutingService::SOURCE_COLUMNS);
                $lease = $reads->query($leaf->identity(IncidentOwnerDelivery::query()->whereKey($id), $identity, $identityColumns));
                $lease->getQuery()->beforeQuery(fn ($query) => $query->lock('for update nowait'));
                $delivery = $lease->firstOrFail([...$identityColumns, 'status', 'attempts', 'delivered_at', 'failure_code']);
                if ($delivery->recipient_user_id === null) { $this->unavailable($delivery, 'skipped', null); return; }
                $expected = match ($delivery->recipient_role) { 'incoming' => $changeIdentity->owner_user_id, 'outgoing' => $changeIdentity->previous_owner_user_id, default => null };
                if ($expected === null || (int) $expected !== (int) $delivery->recipient_user_id) { throw new LogicException('Ownership delivery does not match its accepted recipient.'); }
                $recipient = $this->recipient($delivery->recipient_user_id, $reads);
                if (! $recipient || ! app(IncidentOwnerEligibility::class)->activeInternal($recipient, $reads)
                    || ! app(IncidentAccessService::class)->canView($recipient, $root, $reads)) {
                    $this->unavailable($delivery, 'skipped', null); return;
                }
                $source = $leaf->identity($leaf->source($recipient, (int) $root->id, $reads), $root, IncidentRoutingService::SOURCE_COLUMNS);
                if (! (clone $source)->exists()) { $this->unavailable($delivery, 'failed', 'source_unavailable'); return; }
                $change = $leaf->row('change', (int) $changeIdentity->id, $reads, $source, $changeIdentity, $changeColumns);
                $rows = ['change' => $change];
                $changeFrame = $leaf->protect($source, $rows, $reads);
                if ($change->audit_log_id !== null) {
                    $auditIdentity = $leaf->constrain(AuditLog::query()->whereKey($change->audit_log_id)
                        ->whereRaw('BINARY `action` = BINARY ?', ['incidents.owner.'.$change->source])
                        ->whereRaw('BINARY `auditable_type` = BINARY ?', [$root->getMorphClass()])->where('auditable_id', $root->id)
                        ->when($change->actor_user_id === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $change->actor_user_id)), $changeFrame, $reads)
                        ->firstOrFail(['id', 'action', 'auditable_type', 'auditable_id', 'user_id']);
                    $rows['audit'] = $leaf->row('audit', (int) $auditIdentity->id, $reads, $changeFrame, $auditIdentity, ['id', 'action', 'auditable_type', 'auditable_id', 'user_id']);
                }
                app(IncidentOwnershipService::class)->assertAcceptance($change, $leaf->protect($source, $rows, $reads), $reads);
                $guard = $leaf->protect($source, $rows, $reads)
                    ->whereExists(function ($query) use ($leaf, $identity): void {
                        $query->selectRaw('1')->from('incident_owner_deliveries')->where('id', $identity->id)
                            ->where('change_id', $identity->change_id)->where('recipient_user_id', $identity->recipient_user_id)
                            ->whereRaw('BINARY `recipient_role` = BINARY ?', [$identity->recipient_role]);
                    });
                $guard->whereNotExists(function ($query) use ($change, $root): void {
                    $query->selectRaw('1')->from('audit_logs')->where('action', 'incidents.owner.'.$change->source)
                        ->where('auditable_type', $root->getMorphClass())->where('auditable_id', $root->id)->where('meta->request_uuid', $change->request_uuid);
                    if ($change->audit_log_id !== null) { $query->where('id', '!=', $change->audit_log_id); }
                });
                if (! $this->eligible($recipient, $root, $change, $delivery, $reads)) {
                    if (! (clone $guard)->exists()) { throw new LogicException('Incident ownership notification source changed before acceptance.'); }
                    $this->unavailable($delivery, 'skipped', null); return;
                }
                $this->assertCurrent($recipient, $root, $change, $delivery, $guard, $reads);
                $payload = ['kind' => 'incident_ownership', 'event_key' => 'incidents.owner.changed',
                    'title' => $delivery->recipient_role === 'incoming' ? 'Incident ownership assigned' : 'Incident ownership changed',
                    'body' => 'Open the incident to review the current responsibilities.',
                    'url' => '/incidents/'.$root->id, 'entity' => 'Incident', 'entity_id' => $root->id];
                $notification = $reads->query(DB::table('notifications')->where('id', $delivery->id)->whereExists((clone $guard)->reorder()->select('client_incidents.id')->toBase()))->exists();
                if (! $notification) { $message = new AppEventNotification($payload); $message->id = $delivery->id; $recipient->notify($message); }
                $this->assertNotice($delivery, $recipient, $payload, $reads, $guard);
                $this->assertCurrent($recipient, $root, $change, $delivery, $guard, $reads);
                if ($delivery->status !== 'delivered') {
                    $delivery->fill(['status' => 'delivered', 'delivered_at' => now(), 'failure_code' => null, 'attempts' => $delivery->attempts + 1]);
                    IncidentRoutingSettings::saved($delivery);
                }
                // Database-only notice and status commit only after the same source/typed graph survives model observers and final SQL.
                $this->assertCurrent($recipient, $root, $change, $delivery, $guard, $reads);
                $this->assertNotice($delivery, $recipient, $payload, $reads, $guard);
                if (! $reads->query(IncidentOwnerDelivery::query()->whereKey($id)->where('change_id', $identity->change_id)
                    ->where('recipient_user_id', $recipient->id)->where('recipient_role', $identity->recipient_role)->whereRaw('BINARY `status` = BINARY ?', ['delivered'])->whereExists((clone $guard)->reorder()->select('client_incidents.id')->toBase()))->exists()) {
                    throw new LogicException('Ownership notification lacks its accepted delivery state.');
                }
            }), 3);
        } catch (Throwable $exception) {
            IncidentOwnerDelivery::query()->whereKey($id)->whereNotIn('status', ['delivered', 'skipped'])->update([
                'status' => 'failed', 'failure_code' => 'delivery_failed', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    private function recipient(?int $id, CurrentAuthorizationReads $reads): ?User
    {
        if (! $id || ! $reads->query(User::query()->whereKey($id))->exists()) { return null; }
        return app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($id, IncidentOwnerEligibility::PERMISSIONS);
    }

    private function eligible(User $recipient, ClientIncident $root, IncidentOwnerChange $change, IncidentOwnerDelivery $delivery, CurrentAuthorizationReads $reads): bool
    {
        return app(IncidentOwnerEligibility::class)->activeInternal($recipient, $reads)
            && app(IncidentAccessService::class)->canView($recipient, $root, $reads)
            && ($delivery->recipient_role !== 'incoming' || ((int) $root->incident_owner_user_id === (int) $recipient->id
                && (int) $root->owner_revision === (int) $change->revision && app(IncidentOwnerEligibility::class)->canOwn($recipient, $root, $reads)));
    }

    private function assertCurrent(User $recipient, ClientIncident $root, IncidentOwnerChange $change, IncidentOwnerDelivery $delivery, $guard, CurrentAuthorizationReads $reads): void
    {
        $fresh = $this->recipient((int) $recipient->id, $reads);
        if (! $fresh || ! $this->eligible($fresh, $root, $change, $delivery, $reads)
            || ! app(IncidentAccessService::class)->apply(clone $guard, $fresh, $reads)->exists()) {
            throw new LogicException('Incident ownership notification source changed before acceptance.');
        }
    }

    private function unavailable(IncidentOwnerDelivery $delivery, string $state, ?string $code): void
    {
        if ($delivery->status === 'delivered') { return; }
        $delivery->fill(['status' => $state, 'failure_code' => $code, 'attempts' => $delivery->attempts + 1]);
        IncidentRoutingSettings::saved($delivery);
    }

    private function assertNotice(IncidentOwnerDelivery $delivery, User $recipient, array $payload, CurrentAuthorizationReads $reads, \Illuminate\Database\Eloquent\Builder $guard): void
    {
        $notice = $reads->query(DB::table('notifications')->where('id', $delivery->id)
            ->whereRaw('BINARY `type` = BINARY ?', [AppEventNotification::class])
            ->whereRaw('BINARY `notifiable_type` = BINARY ?', [$recipient->getMorphClass()])->where('notifiable_id', $recipient->id)
            ->whereExists((clone $guard)->reorder()->select('client_incidents.id')->toBase()))->first();
        $data = $notice ? json_decode($notice->data, true) : null; if (is_array($data)) { unset($data['emitted_at']); }
        if (! $notice || $notice->type !== AppEventNotification::class || $notice->notifiable_type !== $recipient->getMorphClass()
            || (int) $notice->notifiable_id !== (int) $recipient->id || IncidentRoutingValues::digest($data) !== IncidentRoutingValues::digest($payload)) {
            throw new LogicException('Ownership delivery requires its exact persisted recipient notification.');
        }
    }
}
