<?php

namespace App\Services\Medication\PharmacyConnect;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationPharmacyAcknowledgment;
use App\Models\MedicationPharmacyConnection;
use App\Models\MedicationPharmacyDispatch;
use App\Models\MedicationPharmacyDispatchCommand;
use App\Models\MedicationPharmacyOrder;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class PharmacyDispatchService
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly PharmacyPartnerRegistry $partners,
        private readonly PharmacyOrderSnapshot $snapshots,
        private readonly PharmacyDispatchPresentation $presentation,
        private readonly HttpJsonPharmacyTransport $transport,
        private readonly MedicationEventRecorder $events,
        private readonly AuthorizationEvidenceLockService $authorization,
        private readonly HrCurrentStaffService $staff,
    ) {}

    public function read(User $actor, MedicationPharmacyOrder $order): array
    {
        $client = $this->access->client($actor, (int) $order->client_id);
        $medication = $order->medication;
        abort_unless($medication && (int) $medication->client_id === (int) $client->id, 404);
        $dispatch = Schema::hasTable('medication_pharmacy_dispatches') ? MedicationPharmacyDispatch::where('pharmacy_order_id', $order->id)->first() : null;
        $controlled = $this->controlled($medication, $dispatch);
        abort_if($controlled && ! $actor->canDo('medications.controlled.view'), 404);
        $installed = Schema::hasTable('medication_pharmacy_connections') && Schema::hasTable('medication_pharmacy_dispatches');
        $connectionRows = $this->partners->enabled() && $installed
            ? MedicationPharmacyConnection::where('enabled', true)->get()->filter(fn ($connection) => in_array((int) $client->site_id, $connection->site_ids ?? [], true))
            : collect();
        $connections = $connectionRows->filter(function ($connection) use ($client) {
            try {
                $this->partners->forSite($connection->partner_key, (int) $client->site_id);

                return true;
            } catch (PharmacyConnectionException) {
                return false;
            }
        })->map(fn ($connection) => $this->presentation->connection($connection))->values()->all();
        $authority = $this->staff->isCurrent($actor) && $client->status === 'active'
            && $actor->canDo('medications.stock.update') && $actor->canDo('medications.pharmacy.send')
            && (! $controlled || $actor->canDo('medications.controlled.record'));
        $active = $this->active($medication);
        $retryable = false;
        if ($dispatch && $dispatch->state === 'failed') {
            try {
                $connection = $dispatch->connection;
                $retryable = $connection && $connection->enabled && $connection->version === $dispatch->connection_version
                    && in_array((int) $client->site_id, $connection->site_ids ?? [], true)
                    && ($this->partners->forSite($connection->partner_key, (int) $client->site_id)['supports_idempotency'] ?? false) === true;
            } catch (PharmacyConnectionException) {
                // No destination means no retry affordance.
            }
        }

        return ['enabled' => $this->partners->enabled(), 'connections' => $connections,
            'dispatch' => $dispatch ? $this->presentation->dispatch($dispatch) : null,
            'can_send' => $this->partners->enabled() && $authority && $active && $order->status === 'draft' && ! $dispatch && $connections !== [],
            'can_retry' => $this->partners->enabled() && $authority && $active && $order->status === 'draft' && $retryable,
            'can_cancel' => $authority && $dispatch && in_array($dispatch->state, ['queued', 'failed'], true),
            'can_resolve_unknown' => $authority && $dispatch?->state === 'unknown',
            'local_order_closed' => in_array($order->status, ['cancelled', 'closed_short'], true),
            'notice' => 'Pharmacy acceptance does not receive stock. Count and record physical delivery separately.'];
    }

    public function queue(User $actor, MedicationPharmacyOrder $order, int $connectionId, string $requestUuid): array
    {
        $this->partners->assertEnabled();
        $this->assertInstalled();

        return $this->scope->forPharmacyOrder($actor, $order, function (Client $client, ClientMedication $medication, MedicationPharmacyOrder $lockedOrder, User $lockedActor) use ($connectionId, $requestUuid): array {
            $this->authority($lockedActor, $client, $medication);
            $existing = MedicationPharmacyDispatch::where('pharmacy_order_id', $lockedOrder->id)->lockForUpdate()->first();
            if ($existing) {
                $this->assertRetainedControlledAuthority($lockedActor, $medication, $existing);
                if ($existing->request_uuid !== $requestUuid || (int) $existing->requested_by !== (int) $lockedActor->id || (int) $existing->connection_id !== $connectionId) {
                    throw new PharmacyConnectionException('dispatch_exists', 'This supply order already has a connected delivery record. Open its current status.', 409);
                }

                return ['dispatch' => $this->presentation->dispatch($existing), 'duplicate' => true];
            }
            if (MedicationPharmacyDispatch::where('request_uuid', $requestUuid)->lockForUpdate()->first(['id'])) {
                throw new PharmacyConnectionException('idempotency_conflict', 'This request was already used for a different supply order.', 409);
            }
            $this->assertDraft($medication, $lockedOrder);
            $connection = MedicationPharmacyConnection::whereKey($connectionId)->lockForUpdate()->first();
            abort_unless($connection && $connection->enabled && in_array((int) $client->site_id, $connection->site_ids ?? [], true), 404);
            $partner = $this->partners->forSite($connection->partner_key, (int) $client->site_id);
            $snapshot = $this->snapshots->capture($client, $medication, $lockedOrder, $partner);
            $dispatch = MedicationPharmacyDispatch::create([
                'uuid' => (string) Str::uuid(), 'connection_id' => $connection->id, 'pharmacy_order_id' => $lockedOrder->id,
                'client_id' => $client->id, 'client_medication_id' => $medication->id, 'site_id' => $client->site_id,
                'requested_by' => $lockedActor->id, 'request_uuid' => $requestUuid, 'connection_version' => $connection->version,
                'partner_fingerprint' => $this->partners->fingerprint($partner, (int) $client->site_id),
                'snapshot_fingerprint' => $this->snapshots->fingerprint($snapshot), 'snapshot' => $snapshot,
                'state' => 'queued', 'result_code' => 'waiting_to_send',
            ]);
            $this->event($client, $medication, $lockedOrder, $dispatch, $lockedActor->id, 'queued');
            DB::afterCommit(fn () => DispatchPharmacyOrder::dispatch($dispatch->id));

            return ['dispatch' => $this->presentation->dispatch($dispatch), 'duplicate' => false];
        });
    }

    /** Durable claim is committed before any network side effect. No database callback containing HTTP retries. */
    public function send(int $dispatchId): void
    {
        $claim = DB::transaction(function () use ($dispatchId): ?string {
            $dispatch = MedicationPharmacyDispatch::whereKey($dispatchId)->lockForUpdate()->first();
            if (! $dispatch || $dispatch->state !== 'queued') {
                return null;
            }
            $token = (string) Str::uuid();
            $dispatch->forceFill(['state' => 'sending', 'claim_token' => $token, 'sending_at' => now(),
                'attempt_count' => $dispatch->attempt_count + 1, 'result_code' => 'sending'])->save();

            return $token;
        }, 1);
        if ($claim === null) {
            return;
        }
        $outboundStarted = false;
        try {
            // The outer transaction deliberately has ONE attempt; nested scope never retries HTTP.
            DB::transaction(function () use ($dispatchId, $claim, &$outboundStarted): void {
                $snapshot = MedicationPharmacyDispatch::findOrFail($dispatchId);
                $actor = User::find($snapshot->requested_by);
                $order = MedicationPharmacyOrder::find($snapshot->pharmacy_order_id);
                if (! $actor || ! $order) {
                    throw new PharmacyConnectionException('authority_revoked', 'Ordering authority is no longer current.');
                }
                $this->scope->forPharmacyOrder($actor, $order, function (Client $client, ClientMedication $medication, MedicationPharmacyOrder $lockedOrder, User $lockedActor) use ($dispatchId, $claim, &$outboundStarted): void {
                    $this->authority($lockedActor, $client, $medication);
                    $connectionId = MedicationPharmacyDispatch::whereKey($dispatchId)->value('connection_id');
                    $connection = MedicationPharmacyConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail();
                    $dispatch = MedicationPharmacyDispatch::whereKey($dispatchId)->lockForUpdate()->firstOrFail();
                    if ($dispatch->state !== 'sending' || $dispatch->claim_token !== $claim) {
                        return;
                    }
                    $partner = $this->validateSnapshot($client, $medication, $lockedOrder, $connection, $dispatch);
                    $outboundStarted = true;
                    $result = $this->transport->send($partner, $dispatch->uuid, [...$dispatch->snapshot['payload'], 'dispatch_uuid' => $dispatch->uuid]);
                    $dispatch->forceFill(['state' => $result->state, 'result_code' => $result->code, 'http_status' => $result->httpStatus,
                        'sent_at' => $result->state === 'sent' ? now() : null, 'claim_token' => null])->save();
                    if ($result->state === 'sent') {
                        $lockedOrder->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'communication_method' => 'secure_message',
                            'communication_reference' => $dispatch->uuid, 'communication_recorded_by' => $lockedActor->id,
                            'communication_recorded_at' => now()])->save();
                    }
                    $this->event($client, $medication, $lockedOrder, $dispatch, $lockedActor->id, $result->state);
                });
            }, 1);
        } catch (Throwable $exception) {
            // Do not report raw transport/database exceptions: they may hold clinical data.
            $this->finishInterrupted($dispatchId, $claim, $outboundStarted,
                $exception instanceof PharmacyConnectionException ? $exception->errorCode : ($outboundStarted ? 'dispatch_interrupted' : 'authority_or_snapshot_revoked'));
        }
    }

    public function command(User $actor, MedicationPharmacyOrder $order, int $dispatchId, string $action, array $data): array
    {
        if ($action === 'retry') {
            $this->partners->assertEnabled();
        }
        $this->assertInstalled();

        return $this->scope->forPharmacyOrder($actor, $order, function (Client $client, ClientMedication $medication, MedicationPharmacyOrder $lockedOrder, User $lockedActor) use ($dispatchId, $action, $data): array {
            $this->authority($lockedActor, $client, $medication);
            $connectionId = MedicationPharmacyDispatch::whereKey($dispatchId)->where('pharmacy_order_id', $lockedOrder->id)->value('connection_id');
            abort_unless($connectionId, 404);
            $connection = MedicationPharmacyConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail();
            $dispatch = MedicationPharmacyDispatch::whereKey($dispatchId)->where('pharmacy_order_id', $lockedOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertRetainedControlledAuthority($lockedActor, $medication, $dispatch);
            $fingerprint = hash('sha256', json_encode([$lockedActor->id, $dispatchId, $action, $data], JSON_THROW_ON_ERROR));
            $receipt = MedicationPharmacyDispatchCommand::where('request_uuid', $data['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                if (! hash_equals($receipt->fingerprint, $fingerprint)) {
                    throw new PharmacyConnectionException('idempotency_conflict', 'This request was already used for a different pharmacy action.', 409);
                }

                return ['dispatch' => $this->presentation->dispatch($dispatch), 'duplicate' => true];
            }
            if ($dispatch->state !== $data['expected_state']) {
                throw new PharmacyConnectionException('dispatch_changed', 'The pharmacy delivery status changed. Refresh before continuing.', 409);
            }
            if ($action === 'retry') {
                if ($dispatch->state !== 'failed') {
                    throw new PharmacyConnectionException('retry_not_safe', 'Check delivery with the pharmacy before considering another send.', 409);
                }
                $partner = $this->validateSnapshot($client, $medication, $lockedOrder, $connection, $dispatch);
                if (($partner['supports_idempotency'] ?? false) !== true) {
                    throw new PharmacyConnectionException('retry_not_safe', 'This pharmacy has not agreed to duplicate-safe retries.', 409);
                }
                $dispatch->forceFill(['state' => 'queued', 'result_code' => 'retry_waiting_to_send'])->save();
                DB::afterCommit(fn () => DispatchPharmacyOrder::dispatch($dispatch->id));
            } elseif ($action === 'cancel') {
                if (! in_array($dispatch->state, ['queued', 'failed'], true)) {
                    throw new PharmacyConnectionException('supplier_cancellation_required', 'This order may have reached the pharmacy. Contact them; stopping this record cannot cancel their order.', 409);
                }
                $resultCode = $dispatch->state === 'failed' ? $dispatch->result_code
                    : ($dispatch->attempt_count > 0 ? 'retry_stopped' : 'stopped_before_send');
                $dispatch->forceFill(['state' => 'cancelled', 'result_code' => $resultCode])->save();
            } elseif ($action === 'resolve') {
                if ($dispatch->state !== 'unknown' || ($data['confirmed_not_received'] ?? false) !== true || trim((string) ($data['reference'] ?? '')) === '') {
                    throw new PharmacyConnectionException('resolution_required', 'Record the pharmacy check confirming they did not receive this order.', 409);
                }
                $dispatch->forceFill(['state' => 'failed', 'result_code' => 'pharmacy_confirmed_not_received'])->save();
            } else {
                throw new PharmacyConnectionException('action_invalid', 'Choose a pharmacy delivery action.');
            }
            MedicationPharmacyDispatchCommand::create(['dispatch_id' => $dispatch->id, 'request_uuid' => $data['request_uuid'],
                'actor_id' => $lockedActor->id, 'action' => $action, 'fingerprint' => $fingerprint,
                'result' => ['state' => $dispatch->state, 'reference' => $action === 'resolve' ? $data['reference'] : null], 'created_at' => now()]);
            $this->event($client, $medication, $lockedOrder, $dispatch, $lockedActor->id, $action);

            return ['dispatch' => $this->presentation->dispatch($dispatch), 'duplicate' => false];
        });
    }

    public function acknowledge(MedicationPharmacyConnection $submittedConnection, array $data, string $bodyFingerprint): array
    {
        return DB::transaction(function () use ($submittedConnection, $data, $bodyFingerprint): array {
            $snapshot = MedicationPharmacyDispatch::where('uuid', $data['dispatch_uuid'])->where('connection_id', $submittedConnection->id)->first();
            abort_unless($snapshot, 404);
            $client = Client::withTrashed()->whereKey($snapshot->client_id)->lockForUpdate()->firstOrFail();
            $medication = ClientMedication::withTrashed()->whereKey($snapshot->client_medication_id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            $order = MedicationPharmacyOrder::whereKey($snapshot->pharmacy_order_id)->where('client_id', $client->id)->where('client_medication_id', $medication->id)->lockForUpdate()->firstOrFail();
            $senderCurrent = $this->currentSender((int) $snapshot->requested_by, $client, $medication);
            $site = Site::withTrashed()->whereKey($client->site_id)->lockForUpdate()->firstOrFail();
            $siteCurrent = ! $site->trashed() && $site->is_active && ! $site->archived && $site->archived_at === null;
            $connection = MedicationPharmacyConnection::whereKey($submittedConnection->id)->lockForUpdate()->firstOrFail();
            $dispatch = MedicationPharmacyDispatch::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $existing = MedicationPharmacyAcknowledgment::where('connection_id', $connection->id)->where('event_id', $data['event_id'])->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $bodyFingerprint)) {
                    throw new PharmacyConnectionException('acknowledgment_conflict', 'This pharmacy event identifier was already used for different evidence.', 409);
                }

                return ['received' => true, 'duplicate' => true, 'applied' => $existing->applied, 'code' => $existing->processing_code];
            }
            if ($dispatch->attempt_count < 1) {
                throw new PharmacyConnectionException('acknowledgment_not_expected', 'There is no dispatched order for this acknowledgment.', 409);
            }
            if ($dispatch->acknowledgment_outcome !== null && $dispatch->acknowledgment_outcome !== $data['outcome']) {
                throw new PharmacyConnectionException('acknowledgment_conflict', 'The pharmacy already supplied a different final response.', 409);
            }
            $applied = (bool) $dispatch->acknowledgment_applied;
            $code = $dispatch->acknowledgment_code ?? 'supply_state_retained';
            try {
                $partner = $this->partners->forSite($connection->partner_key, (int) $client->site_id);
                $current = $this->snapshots->capture($client, $medication, $order, $partner);
                $currentAuthority = $senderCurrent && $siteCurrent && $connection->enabled && $connection->version === $dispatch->connection_version
                    && in_array((int) $client->site_id, $connection->site_ids ?? [], true)
                    && $this->active($medication) && ! $client->trashed()
                    && hash_equals($dispatch->snapshot_fingerprint, $this->snapshots->fingerprint($current))
                    && hash_equals($dispatch->partner_fingerprint, $this->partners->fingerprint($partner, (int) $client->site_id));
                if ($currentAuthority && $data['outcome'] === 'accepted' && in_array($order->status, ['draft', 'submitted'], true)) {
                    // A signed receipt also resolves a timeout. It proves actual contact.
                    $order->forceFill(['status' => 'submitted', 'submitted_at' => $order->submitted_at ?? now(),
                        'communication_method' => 'secure_message', 'communication_reference' => $dispatch->uuid,
                        'communication_recorded_by' => $dispatch->requested_by, 'communication_recorded_at' => $order->communication_recorded_at ?? now()])->save();
                    $order->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
                    $applied = true;
                    $code = 'pharmacy_acceptance_recorded';
                } elseif (! $currentAuthority) {
                    $code = 'snapshot_or_connection_changed';
                }
            } catch (PharmacyConnectionException) {
                $code = 'snapshot_or_connection_changed';
            }
            $dispatch->forceFill(['state' => $data['outcome'], 'acknowledgment_outcome' => $data['outcome'],
                'acknowledgment_applied' => $applied, 'acknowledgment_code' => $code, 'supplier_reference' => $data['supplier_reference'],
                'acknowledged_at' => now(), 'claim_token' => null, 'result_code' => 'authenticated_pharmacy_'.$data['outcome']])->save();
            MedicationPharmacyAcknowledgment::create(['connection_id' => $connection->id, 'dispatch_id' => $dispatch->id,
                'event_id' => $data['event_id'], 'fingerprint' => $bodyFingerprint, 'outcome' => $data['outcome'],
                'supplier_reference' => $data['supplier_reference'], 'applied' => $applied, 'processing_code' => $code, 'received_at' => now()]);
            $this->event($client, $medication, $order, $dispatch, null, 'acknowledged');

            return ['received' => true, 'duplicate' => false, 'applied' => $applied, 'code' => $code];
        }, 3);
    }

    /** Scheduler recovery never resends a claimed or uncertain dispatch. */
    public function recover(): array
    {
        if (! Schema::hasTable('medication_pharmacy_connections') || ! Schema::hasTable('medication_pharmacy_dispatches')) {
            return ['unknown' => 0, 'queued' => 0];
        }
        $stale = MedicationPharmacyDispatch::where('state', 'sending')
            ->where('sending_at', '<', now()->subSeconds(max(120, (int) config('emar-pharmacy-connect.sending_lease_seconds', 120))))
            ->orderBy('id')->limit(50)->get(['id', 'claim_token']);
        foreach ($stale as $dispatch) {
            $this->finishInterrupted($dispatch->id, $dispatch->claim_token, true, 'worker_interrupted_delivery_unknown');
        }
        $queued = MedicationPharmacyDispatch::where('state', 'queued')->orderBy('id')->limit(50)->pluck('id');
        foreach ($queued as $id) {
            DispatchPharmacyOrder::dispatch((int) $id);
        }

        return ['unknown' => $stale->count(), 'queued' => $queued->count()];
    }

    private function validateSnapshot(Client $client, ClientMedication $medication, MedicationPharmacyOrder $order, MedicationPharmacyConnection $connection, MedicationPharmacyDispatch $dispatch): array
    {
        $this->partners->assertEnabled();
        $this->assertDraft($medication, $order);
        if (! $connection->enabled || $connection->version !== $dispatch->connection_version || ! in_array((int) $client->site_id, $connection->site_ids ?? [], true)) {
            throw new PharmacyConnectionException('connection_changed', 'The pharmacy connection changed before sending.');
        }
        $partner = $this->partners->forSite($connection->partner_key, (int) $client->site_id);
        if (! hash_equals($dispatch->partner_fingerprint, $this->partners->fingerprint($partner, (int) $client->site_id))
            || ! hash_equals($dispatch->snapshot_fingerprint, $this->snapshots->fingerprint($this->snapshots->capture($client, $medication, $order, $partner)))) {
            throw new PharmacyConnectionException('snapshot_changed', 'The person or supply order changed before sending. Create a checked new order.');
        }

        return $partner;
    }

    private function authority(User $actor, Client $client, ClientMedication $medication): void
    {
        abort_unless($actor->canDo('medications.pharmacy.send') && $actor->canDo('medications.stock.update'), 403);
        abort_unless(! $client->trashed() && $client->status === 'active', 404);
        $this->access->assertReadable($actor, $client);
        abort_if($medication->controlled_drug && (! $actor->canDo('medications.controlled.view') || ! $actor->canDo('medications.controlled.record')), 404);
    }

    private function assertInstalled(): void
    {
        if (! Schema::hasTable('medication_pharmacy_connections') || ! Schema::hasTable('medication_pharmacy_dispatches')) {
            throw new PharmacyConnectionException('installation_required', 'Connected pharmacy ordering has not been installed.');
        }
    }

    private function controlled(ClientMedication $medication, ?MedicationPharmacyDispatch $dispatch = null): bool
    {
        return (bool) $medication->controlled_drug || (bool) ($dispatch?->snapshot['payload']['medicine']['controlled'] ?? false);
    }

    private function assertRetainedControlledAuthority(User $actor, ClientMedication $medication, MedicationPharmacyDispatch $dispatch): void
    {
        abort_if($this->controlled($medication, $dispatch)
            && (! $actor->canDo('medications.controlled.view') || ! $actor->canDo('medications.controlled.record')), 404);
    }

    private function currentSender(int $actorId, Client $client, ClientMedication $medication): bool
    {
        if (! User::whereKey($actorId)->exists()) {
            return false;
        }
        try {
            $users = $this->authorization->lockForUsers([$actorId], [
                'medications.view', 'medications.stock.update', 'medications.pharmacy.send',
                'medications.controlled.view', 'medications.controlled.record',
                ...MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
            ]);
            $profiles = $this->scope->lockCurrentStaffProfiles($users, [$actorId]);
            $actor = $users->get($actorId);
            $actor->setRelation('hrEmployeeProfile', $profiles->get($actorId));
            $this->scope->readerSiteIds($actor, 'medications.pharmacy.send', (int) $client->site_id);
            $this->authority($actor, $client, $medication);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    private function active(ClientMedication $medication): bool
    {
        $day = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return $medication->active && $medication->state === 'active' && $medication->approval_status === 'verified'
            && ! $medication->trashed() && $medication->superseded_by === null
            && ($medication->start_date === null || $medication->start_date->toDateString() <= $day)
            && ($medication->end_date === null || $medication->end_date->toDateString() >= $day);
    }

    private function assertDraft(ClientMedication $medication, MedicationPharmacyOrder $order): void
    {
        if (! $this->active($medication) || $order->status !== 'draft') {
            throw new PharmacyConnectionException('supply_not_sendable', 'Only a draft supply order for a current checked medicine can be sent.', 409);
        }
    }

    private function finishInterrupted(int $id, ?string $claim, bool $outboundStarted, string $code): void
    {
        DB::transaction(function () use ($id, $claim, $outboundStarted, $code): void {
            $snapshot = MedicationPharmacyDispatch::find($id);
            if (! $snapshot) {
                return;
            }
            $client = Client::withTrashed()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $medication = ClientMedication::withTrashed()->whereKey($snapshot->client_medication_id)->lockForUpdate()->first();
            $order = MedicationPharmacyOrder::whereKey($snapshot->pharmacy_order_id)->lockForUpdate()->first();
            $dispatch = MedicationPharmacyDispatch::whereKey($id)->lockForUpdate()->first();
            if (! $dispatch || $dispatch->state !== 'sending' || $dispatch->claim_token !== $claim) {
                return;
            }
            $dispatch->forceFill(['state' => $outboundStarted ? 'unknown' : 'cancelled', 'result_code' => $code, 'claim_token' => null])->save();
            if ($client && $medication && $order) {
                $this->event($client, $medication, $order, $dispatch, null, $dispatch->state);
            }
        }, 1);
    }

    private function event(Client $client, ClientMedication $medication, MedicationPharmacyOrder $order, MedicationPharmacyDispatch $dispatch, ?int $actorId, string $action): void
    {
        $event = $this->eventData($client, $medication, $order, $dispatch, $actorId, $action);
        if ($event !== null) {
            $this->events->append($event);
        }
    }

    /** Build captured-site evidence for the caller's final medication audit batch. */
    public function eventData(Client $client, ClientMedication $medication, MedicationPharmacyOrder $order, MedicationPharmacyDispatch $dispatch, ?int $actorId, string $action): ?MedicationEventData
    {
        if (! Site::whereKey($dispatch->site_id)->exists()) {
            // Retain factual supplier evidence even if its historical house has been retired.
            AuditLogger::logOrFail('pharmacy.dispatch.'.$action, $dispatch, ['actor_id' => $actorId,
                'dispatch_id' => $dispatch->id, 'state' => $dispatch->state, 'result_code' => $dispatch->result_code], new Request, systemActor: $actorId === null);

            return null;
        }

        return new MedicationEventData(siteId: (int) $dispatch->site_id, kind: 'pharmacy.dispatch.'.$action,
            subjectType: 'pharmacy_supply', subjectId: (string) $order->id, actorId: $actorId, occurredAt: CarbonImmutable::now('UTC'),
            summary: 'Pharmacy delivery status recorded', facts: ['dispatch_id' => $dispatch->id, 'connection_id' => $dispatch->connection_id,
                'state' => $dispatch->state, 'result_code' => $dispatch->result_code, 'attempt_count' => $dispatch->attempt_count],
            clientId: $client->trashed() || (int) $client->site_id !== (int) $dispatch->site_id ? null : $client->id, controlled: $this->controlled($medication, $dispatch));
    }
}
