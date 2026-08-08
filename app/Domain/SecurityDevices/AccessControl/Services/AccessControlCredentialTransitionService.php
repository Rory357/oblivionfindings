<?php

namespace App\Domain\SecurityDevices\AccessControl\Services;

use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredentialDeviceBindingEvent;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredentialLifecycleEvent;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class AccessControlCredentialTransitionService
{
    /** @var list<string> */
    private const PROVIDER_TRANSITION_FIELDS = [
        'status',
        'provider_reconciliation_status',
        'provider_reconciliation_requested_at',
        'provider_reconciliation_failure_reason',
        'revocation_reason',
    ];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /**
     * Canonical provider-evidence persistence seam. The adapter must first record a
     * pending request and may then record exactly one correlated terminal response.
     *
     * @param  array<string, mixed>  $transition
     * @param  list<int>  $deviceIds
     */
    public function recordProviderTransition(
        User $actor,
        AccessControlCredential $credential,
        string $eventType,
        array $transition,
        string $providerRequestKey,
        string $providerEventKey,
        array $deviceIds = [],
        ?DateTimeInterface $occurredAt = null,
    ): AccessControlCredential {
        $this->assertCanManage($actor);
        $this->assertSafeEvidenceKey($eventType, 'event type', 48);
        $this->assertSafeEvidenceKey($providerRequestKey, 'provider request reference');
        $this->assertSafeEvidenceKey($providerEventKey, 'provider event reference');

        $unknownFields = array_diff(array_keys($transition), self::PROVIDER_TRANSITION_FIELDS);
        if ($unknownFields !== []) {
            throw new UnexpectedValueException('Unsupported credential transition fields: '.implode(', ', $unknownFields));
        }
        foreach (['status', 'provider_reconciliation_status'] as $requiredField) {
            if (! array_key_exists($requiredField, $transition)) {
                throw new UnexpectedValueException("Provider transition is missing {$requiredField}.");
            }
        }

        $siteId = (int) $credential->site_id;
        $this->access->assertCanViewSite($actor, $siteId);

        return DB::transaction(function () use (
            $actor,
            $credential,
            $eventType,
            $transition,
            $providerRequestKey,
            $providerEventKey,
            $deviceIds,
            $occurredAt,
            $siteId,
        ): AccessControlCredential {
            $site = Site::query()
                ->whereKey($siteId)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();
            abort_unless($site, 404);
            $this->access->assertCanViewSite($actor, $siteId);

            $locked = AccessControlCredential::query()
                ->with('schedule:id,site_id')
                ->whereKey($credential->getKey())
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->schedule === null || (int) $locked->schedule->site_id !== $siteId) {
                throw new UnexpectedValueException('Credential and schedule Site provenance do not match. Provider evidence was not recorded.');
            }

            $duplicate = AccessControlCredentialLifecycleEvent::query()
                ->where('provider_event_key', $providerEventKey)
                ->lockForUpdate()
                ->first();
            if ($duplicate !== null) {
                $targetAction = $this->targetAction((string) $transition['status']);
                $suppliedDeviceIds = $this->normaliseDeviceIds($deviceIds);
                $recordedDeviceIds = $this->normaliseDeviceIds((array) data_get(
                    $duplicate->credential_snapshot,
                    'device_ids',
                    [],
                ));
                if ((int) $duplicate->access_credential_id !== (int) $locked->getKey()
                    || $duplicate->provider_request_key !== $providerRequestKey
                    || $duplicate->event_type !== $eventType
                    || $duplicate->provider_action !== $targetAction
                    || data_get($duplicate->credential_snapshot, 'status') !== $transition['status']
                    || data_get($duplicate->credential_snapshot, 'provider_reconciliation_status') !== $transition['provider_reconciliation_status']
                    || ($suppliedDeviceIds !== [] && $suppliedDeviceIds !== $recordedDeviceIds)) {
                    throw new UnexpectedValueException('Provider event reference was already recorded for different evidence.');
                }

                return $locked->refresh()->load(['schedule', 'lifecycleEvents', 'bindingEvents']);
            }

            $previous = clone $locked;
            $action = $this->targetAction((string) $transition['status']);
            $reconciliation = (string) $transition['provider_reconciliation_status'];
            $targetDeviceIds = $this->assertTransitionGraph(
                $previous,
                $action,
                $reconciliation,
                $providerRequestKey,
                $deviceIds,
            );
            $eventOccurredAt = $occurredAt ?? now();

            $locked->forceFill([
                ...$transition,
                'provider_reconciliation_action' => $action,
                'provider_reconciliation_request_key' => $providerRequestKey,
                'provider_reconciliation_event_key' => $providerEventKey,
                'provider_reconciliation_requested_at' => $reconciliation === AccessControlCredential::RECONCILIATION_PENDING
                    ? ($transition['provider_reconciliation_requested_at'] ?? $eventOccurredAt)
                    : $previous->provider_reconciliation_requested_at,
                'provider_reconciliation_confirmed_at' => $reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED
                    ? $eventOccurredAt
                    : null,
                'provider_reconciliation_failure_reason' => $reconciliation === AccessControlCredential::RECONCILIATION_FAILED
                    ? trim((string) ($transition['provider_reconciliation_failure_reason'] ?? ''))
                    : null,
                'revoked_at' => $action === AccessControlCredential::PROVIDER_ACTION_REVOKE
                    && $reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED
                        ? $eventOccurredAt
                        : null,
                'revoked_by_user_id' => $action === AccessControlCredential::PROVIDER_ACTION_REVOKE
                    && $reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED
                        ? $actor->getKey()
                        : null,
                'revocation_reason' => $action === AccessControlCredential::PROVIDER_ACTION_REVOKE
                    && $reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED
                        ? trim((string) ($transition['revocation_reason'] ?? ''))
                        : null,
            ]);
            AccessControlCredential::assertTruthfulEvidenceState($locked);

            $nextSequence = (int) $locked->lifecycleEvents()->max('sequence') + 1;
            $snapshot = Arr::only(
                $locked->attributesToArray(),
                AccessControlCredential::LIFECYCLE_EVIDENCE_FIELDS,
            );
            $snapshot['device_ids'] = $targetDeviceIds;

            $event = $locked->lifecycleEvents()->create([
                'site_id' => $siteId,
                'sequence' => $nextSequence,
                'event_type' => $eventType,
                'evidence_kind' => $reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED
                    ? 'provider_confirmed'
                    : 'provider_reported',
                'provider_action' => $action,
                'provider_request_key' => $providerRequestKey,
                'provider_event_key' => $providerEventKey,
                'provider_confirmed' => $reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED,
                'occurred_at' => $eventOccurredAt,
                'recorded_by_user_id' => $actor->getKey(),
                'credential_snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            if ($reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED) {
                $this->appendBindingEvidence(
                    $locked,
                    $action,
                    $providerRequestKey,
                    $providerEventKey,
                    $targetDeviceIds,
                    $actor,
                    $eventOccurredAt,
                );
            }

            // Direct Eloquent mutation is rejected. This service owns the append-first,
            // transactionally coupled update of the current projection.
            $locked->saveQuietly();

            AuditLogger::logOrFail('access_control.credential.provider_transition', $locked, [
                'actor_id' => (int) $actor->getKey(),
                'site_id' => $siteId,
                'lifecycle_event_id' => (int) $event->getKey(),
                'provider_action' => $action,
                'provider_reconciliation_status' => $reconciliation,
                'provider_request_key' => $providerRequestKey,
                'provider_event_key' => $providerEventKey,
                'device_ids' => $targetDeviceIds,
            ]);

            return $locked->refresh()->load(['schedule', 'lifecycleEvents', 'bindingEvents']);
        });
    }

    /** @param list<int> $deviceIds
     * @return list<int>
     */
    private function assertTransitionGraph(
        AccessControlCredential $previous,
        string $action,
        string $reconciliation,
        string $providerRequestKey,
        array $deviceIds,
    ): array {
        $sourceReconciliation = (string) $previous->provider_reconciliation_status;
        $sourceAction = (string) $previous->provider_reconciliation_action;
        $sourceStatus = (string) $previous->status;

        if ($reconciliation === AccessControlCredential::RECONCILIATION_PENDING) {
            $sameActionRetry = $action === $sourceAction
                && in_array($sourceReconciliation, [
                    AccessControlCredential::RECONCILIATION_REQUIRED,
                    AccessControlCredential::RECONCILIATION_FAILED,
                ], true);
            $revokeFromActive = $action === AccessControlCredential::PROVIDER_ACTION_REVOKE
                && $sourceAction === AccessControlCredential::PROVIDER_ACTION_ISSUE
                && $sourceStatus === AccessControlCredential::STATUS_ACTIVE
                && $sourceReconciliation === AccessControlCredential::RECONCILIATION_RECONCILED;
            if (! $sameActionRetry && ! $revokeFromActive) {
                throw new UnexpectedValueException('Provider request does not follow the current credential lifecycle state.');
            }

            if ($action === AccessControlCredential::PROVIDER_ACTION_ISSUE) {
                return $this->authorisedBindingDevices($previous, $deviceIds)->modelKeys();
            }

            $activeDeviceIds = $this->currentActiveBindingDeviceIds($previous);
            if ($activeDeviceIds === []) {
                throw new UnexpectedValueException('A revocation request requires provider-confirmed active credential-device bindings.');
            }
            $suppliedIds = $this->normaliseDeviceIds($deviceIds);
            if ($suppliedIds !== [] && $suppliedIds !== $activeDeviceIds) {
                throw new UnexpectedValueException('Revocation device scope does not match the current provider-confirmed bindings.');
            }

            return $activeDeviceIds;
        }

        if (! in_array($reconciliation, [
            AccessControlCredential::RECONCILIATION_RECONCILED,
            AccessControlCredential::RECONCILIATION_FAILED,
        ], true)
            || $sourceReconciliation !== AccessControlCredential::RECONCILIATION_PENDING
            || $sourceAction !== $action
            || $previous->provider_reconciliation_request_key !== $providerRequestKey) {
            throw new UnexpectedValueException('Provider response is stale, reversed, or does not match the active request.');
        }

        // The reconciliation status is stored in the immutable snapshot, not as a mutable event column.
        $pendingEvent = $previous->lifecycleEvents()
            ->where('provider_request_key', $providerRequestKey)
            ->latest('sequence')
            ->first();
        if ($pendingEvent === null
            || data_get($pendingEvent->credential_snapshot, 'provider_reconciliation_status') !== AccessControlCredential::RECONCILIATION_PENDING) {
            throw new UnexpectedValueException('Provider response has no immutable pending-request evidence.');
        }

        $requestedDeviceIds = $this->normaliseDeviceIds((array) data_get($pendingEvent->credential_snapshot, 'device_ids', []));
        $suppliedDeviceIds = $this->normaliseDeviceIds($deviceIds);
        if ($suppliedDeviceIds !== [] && $suppliedDeviceIds !== $requestedDeviceIds) {
            throw new UnexpectedValueException('Provider response device scope does not match the correlated request.');
        }

        if ($reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED
            && $action === AccessControlCredential::PROVIDER_ACTION_ISSUE) {
            $this->authorisedBindingDevices($previous, $requestedDeviceIds);
        }

        return $requestedDeviceIds;
    }

    /** @param list<int> $deviceIds */
    private function appendBindingEvidence(
        AccessControlCredential $credential,
        string $action,
        string $providerRequestKey,
        string $providerEventKey,
        array $deviceIds,
        User $actor,
        DateTimeInterface $occurredAt,
    ): void {
        $deviceEvidence = $action === AccessControlCredential::PROVIDER_ACTION_ISSUE
            ? $this->authorisedBindingDevices($credential, $deviceIds)->keyBy('id')
            : collect();

        foreach ($deviceIds as $deviceId) {
            $nextSequence = (int) $credential->bindingEvents()
                ->where('device_id', $deviceId)
                ->max('sequence') + 1;
            $device = $deviceEvidence->get($deviceId);
            $assignment = $device instanceof Device ? $device->assignments->first() : null;

            $credential->bindingEvents()->create([
                'site_id' => (int) $credential->site_id,
                'device_id' => $deviceId,
                'sequence' => $nextSequence,
                'binding_status' => $action === AccessControlCredential::PROVIDER_ACTION_ISSUE
                    ? AccessControlCredentialDeviceBindingEvent::STATUS_ACTIVE
                    : AccessControlCredentialDeviceBindingEvent::STATUS_REMOVED,
                'provider_action' => $action,
                'provider_reconciliation_status' => AccessControlCredential::RECONCILIATION_RECONCILED,
                'provider_request_key' => $providerRequestKey,
                'provider_event_key' => $providerEventKey,
                'provider_confirmed' => true,
                'occurred_at' => $occurredAt,
                'recorded_by_user_id' => $actor->getKey(),
                'binding_snapshot' => [
                    'credential_status' => $credential->status,
                    'device_id' => $deviceId,
                    'site_id' => (int) $credential->site_id,
                    'device_assignment_id' => $assignment?->getKey(),
                    'device_assignment_type' => $assignment?->assignable_type,
                    'device_assignment_target_id' => $assignment?->assignable_id,
                ],
                'created_at' => now(),
            ]);
        }
    }

    /** @param list<int> $deviceIds */
    private function authorisedBindingDevices(AccessControlCredential $credential, array $deviceIds): Collection
    {
        $ids = $this->normaliseDeviceIds($deviceIds);
        if ($ids === []) {
            throw new UnexpectedValueException('Provider credential issue evidence requires at least one reader or door controller.');
        }

        $devices = Device::query()
            ->whereKey($ids)
            ->where('domain', 'security')
            ->where('category', 'access_control')
            ->lockForUpdate()
            ->get();
        if ($devices->count() !== count($ids)) {
            throw new UnexpectedValueException('Provider evidence references an unavailable access-control device.');
        }
        $assignments = DeviceAssignment::query()
            ->whereIn('device_id', $ids)
            ->active()
            ->where('assigned_at', '<=', now())
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('device_id');
        $roomIds = $assignments->flatten()
            ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
            ->pluck('assignable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        if ($roomIds->isNotEmpty()) {
            SiteRoom::query()->whereKey($roomIds)->lockForUpdate()->get();
        }

        foreach ($devices as $device) {
            $device->setRelation('assignments', $assignments->get($device->getKey(), collect()));
            if ($device->assignments->count() !== 1) {
                throw new UnexpectedValueException('Provider evidence requires one unambiguous active device assignment.');
            }
            if ($this->effectiveSiteId($device) !== (int) $credential->site_id) {
                throw new UnexpectedValueException('Provider evidence cannot bind a credential to a device outside its canonical Site.');
            }
        }

        return $devices;
    }

    /** @return list<int> */
    private function currentActiveBindingDeviceIds(AccessControlCredential $credential): array
    {
        $confirmedIssueEvents = $credential->lifecycleEvents()
            ->where('site_id', $credential->site_id)
            ->where('provider_action', AccessControlCredential::PROVIDER_ACTION_ISSUE)
            ->where('provider_confirmed', true)
            ->get()
            ->keyBy(fn (AccessControlCredentialLifecycleEvent $event): string => $event->provider_request_key.'|'.$event->provider_event_key);

        return $credential->bindingEvents()
            ->orderBy('device_id')
            ->orderByDesc('sequence')
            ->get()
            ->unique('device_id')
            ->filter(function (AccessControlCredentialDeviceBindingEvent $event) use ($credential, $confirmedIssueEvents): bool {
                $lifecycleEvent = $confirmedIssueEvents->get(
                    $event->provider_request_key.'|'.$event->provider_event_key,
                );

                return (int) $event->site_id === (int) $credential->site_id
                    && $event->binding_status === AccessControlCredentialDeviceBindingEvent::STATUS_ACTIVE
                    && $event->provider_reconciliation_status === AccessControlCredential::RECONCILIATION_RECONCILED
                    && $event->provider_confirmed
                    && $lifecycleEvent instanceof AccessControlCredentialLifecycleEvent
                    && in_array(
                        (int) $event->device_id,
                        $this->normaliseDeviceIds((array) data_get(
                            $lifecycleEvent->credential_snapshot,
                            'device_ids',
                            [],
                        )),
                        true,
                    );
            })
            ->pluck('device_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $deviceIds
     * @return list<int>
     */
    private function normaliseDeviceIds(array $deviceIds): array
    {
        return collect($deviceIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function targetAction(string $status): string
    {
        if (in_array($status, [
            AccessControlCredential::STATUS_PENDING_ISSUE,
            AccessControlCredential::STATUS_ACTIVE,
            AccessControlCredential::STATUS_ISSUE_FAILED,
        ], true)) {
            return AccessControlCredential::PROVIDER_ACTION_ISSUE;
        }
        if (in_array($status, [
            AccessControlCredential::STATUS_PENDING_REVOKE,
            AccessControlCredential::STATUS_REVOKED,
            AccessControlCredential::STATUS_REVOKE_FAILED,
        ], true)) {
            return AccessControlCredential::PROVIDER_ACTION_REVOKE;
        }

        throw new UnexpectedValueException('Provider transition credential status is not recognised.');
    }

    private function assertSafeEvidenceKey(string $value, string $label, int $maxLength = 191): void
    {
        if ($value === '' || mb_strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $value) !== 1) {
            throw new UnexpectedValueException(ucfirst($label).' is not a safe provider evidence reference.');
        }
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->canDo('securityDevices.accessControl.manage'), 403);
    }

    private function effectiveSiteId(Device $device): ?int
    {
        $assignment = $device->assignments->first();
        if (! $assignment instanceof DeviceAssignment) {
            return null;
        }
        if ($assignment->assignable_type === DeviceAssignment::TARGET_SITE) {
            return (int) $assignment->assignable_id;
        }
        if ($assignment->assignable_type === DeviceAssignment::TARGET_ROOM) {
            $siteId = SiteRoom::query()->whereKey($assignment->assignable_id)->value('site_id');

            return is_numeric($siteId) ? (int) $siteId : null;
        }

        return null;
    }
}
