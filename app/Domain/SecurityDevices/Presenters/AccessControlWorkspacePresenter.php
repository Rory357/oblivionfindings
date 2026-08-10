<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredentialDeviceBindingEvent;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredentialLifecycleEvent;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlScheduleRevision;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccessControlWorkspacePresenter
{
    private const LIST_LIMIT = 100;

    private const HISTORY_LIMIT = 50;

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function present(User $viewer, Builder $deviceScope): array
    {
        $canView = $viewer->canDo('securityDevices.accessControl.view');
        $canManage = $viewer->canDo('securityDevices.accessControl.manage');
        if (! $canView) {
            return $this->restricted($canManage);
        }

        $siteIds = $this->access->accessibleSiteIds($viewer);
        $deviceOptions = $this->deviceOptions($deviceScope, $siteIds);
        $visibleDeviceIds = $deviceOptions->pluck('id')->all();
        $schedules = AccessControlSchedule::query()
            ->whereIn('site_id', $siteIds)
            ->with('site:id,name')
            ->orderBy('site_id')
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get();
        $scheduleRevisions = AccessControlScheduleRevision::query()
            ->whereIn('access_schedule_id', $schedules->modelKeys())
            ->whereIn('site_id', $siteIds)
            ->with('recordedBy:id,name')
            ->latest('version')
            ->get()
            ->groupBy('access_schedule_id');
        $credentials = AccessControlCredential::query()
            ->whereIn('site_id', $siteIds)
            ->whereHas('schedule', fn (Builder $query) => $query
                ->whereIn('access_control_schedules.site_id', $siteIds)
                ->whereColumn('access_control_schedules.site_id', 'access_control_credentials.site_id'))
            ->with([
                'site:id,name',
                'latestLifecycleEvent',
                'schedule' => fn ($query) => $query
                    ->whereIn('site_id', $siteIds)
                    ->select(['id', 'site_id', 'name']),
            ])
            ->orderByRaw("case when status = 'active' and provider_reconciliation_status = 'reconciled' then 0 when provider_reconciliation_status = 'failed' then 1 else 2 end")
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $latestBindings = $this->latestBindingEvents($credentials);
        $confirmedLifecycleEvidence = $this->confirmedLifecycleEvidence($credentials);
        $credentialDevices = $credentials->toBase()->mapWithKeys(function (AccessControlCredential $credential) use (
            $latestBindings,
            $confirmedLifecycleEvidence,
            $visibleDeviceIds,
        ): array {
            return [$credential->getKey() => $this->confirmedBindingDevices(
                $credential,
                $latestBindings->get($credential->getKey(), collect()),
                $confirmedLifecycleEvidence->get($credential->getKey(), collect()),
                $visibleDeviceIds,
            )];
        });
        $credentialLifecycles = $credentials->toBase()->mapWithKeys(fn (AccessControlCredential $credential): array => [
            $credential->getKey() => $this->credentialLifecycle(
                $credential,
                $credentialDevices->get($credential->getKey(), collect()),
            ),
        ]);
        $activeCredentialIds = $credentialLifecycles
            ->filter(fn (array $lifecycle): bool => $lifecycle['accessStillConfirmed'])
            ->keys();
        $activeCredentialCounts = $credentials
            ->whereIn('id', $activeCredentialIds)
            ->countBy(fn (AccessControlCredential $credential): int => (int) $credential->access_schedule_id);
        $scheduleEvidence = $schedules->toBase()->mapWithKeys(fn (AccessControlSchedule $schedule): array => [
            $schedule->getKey() => $this->scheduleReconciliation(
                $schedule,
                $scheduleRevisions->get($schedule->getKey(), collect())->first(),
            ),
        ]);
        $holderLabels = $this->holderLabels($viewer, $credentials);

        return [
            'restricted' => false,
            'canManage' => $canManage,
            'summary' => [
                'activeCredentials' => $activeCredentialIds->count(),
                'activeSchedules' => $schedules->filter(fn (AccessControlSchedule $schedule): bool => $schedule->is_active
                    && ($scheduleEvidence->get($schedule->getKey())['providerConfirmed'] ?? false))->count(),
                'coveredDoors' => $credentialDevices
                    ->only($activeCredentialIds->all())
                    ->flatten(1)
                    ->pluck('id')
                    ->unique()
                    ->count(),
            ],
            'sites' => Site::query()->whereKey($siteIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Site $site): array => ['id' => (int) $site->id, 'name' => $site->name])->values(),
            'deviceOptions' => $deviceOptions,
            'holderOptions' => $this->holderOptions($viewer),
            'providerActions' => $this->providerActions(),
            'schedules' => $schedules->map(function (AccessControlSchedule $schedule) use (
                $activeCredentialCounts,
                $scheduleEvidence,
                $scheduleRevisions,
            ): array {
                $activeCredentials = (int) $activeCredentialCounts->get($schedule->getKey(), 0);

                return [
                    'id' => (int) $schedule->id,
                    'siteId' => (int) $schedule->site_id,
                    'siteName' => $schedule->site?->name ?? 'Unknown Site',
                    'name' => $schedule->name,
                    'days' => $schedule->days ?? [],
                    'startsAt' => $schedule->starts_at,
                    'endsAt' => $schedule->ends_at,
                    'timezone' => $schedule->timezone,
                    'isActive' => (bool) $schedule->is_active,
                    'version' => (int) $schedule->version,
                    'activeCredentials' => $activeCredentials,
                    'impact' => [
                        'activeCredentials' => $activeCredentials,
                        'requiresExactConfirmation' => $activeCredentials > 0,
                        'updateConfirmation' => $activeCredentials > 0 ? 'UPDATE '.$activeCredentials : null,
                        'deactivateConfirmation' => $activeCredentials > 0 ? 'DEACTIVATE '.$activeCredentials : null,
                    ],
                    'providerReconciliation' => $scheduleEvidence->get($schedule->getKey()),
                    'deactivatedAt' => $schedule->deactivated_at?->toIso8601String(),
                    'deactivationReason' => $schedule->deactivation_reason,
                    'revisionHistory' => $scheduleRevisions->get($schedule->id, collect())
                        ->take(10)
                        ->map(fn (AccessControlScheduleRevision $revision): array => [
                            'id' => (int) $revision->id,
                            'version' => (int) $revision->version,
                            'action' => $revision->action,
                            'reason' => $revision->change_reason,
                            'activeCredentialsAffected' => (int) $revision->provider_confirmed_credentials_affected,
                            'actor' => $revision->recordedBy?->name ?? 'System',
                            'occurredAt' => $revision->created_at?->toIso8601String(),
                        ])->values(),
                ];
            })->values(),
            'credentials' => $credentials->map(fn (AccessControlCredential $credential): array => [
                'id' => (int) $credential->id,
                'siteId' => (int) $credential->site_id,
                'siteName' => $credential->site?->name ?? 'Unknown Site',
                'label' => $credential->label,
                'holderType' => $credential->holder_type,
                'holderLabel' => $holderLabels->get($credential->holder_type.':'.$credential->holder_id, 'Restricted holder'),
                'referenceKey' => $credential->reference_key,
                'status' => $credential->status,
                'providerLifecycle' => $credentialLifecycles->get($credential->getKey()),
                'scheduleName' => $credential->schedule?->name ?? 'Schedule unavailable',
                'devices' => $credentialDevices->get($credential->getKey(), collect()),
                'validFrom' => $credential->valid_from?->toIso8601String(),
                'validUntil' => $credential->valid_until?->toIso8601String(),
                'revokedAt' => $credential->revoked_at?->toIso8601String(),
                'revocationReason' => $credential->revocation_reason,
            ])->values(),
            'history' => $this->history($credentials, $schedules),
        ];
    }

    private function restricted(bool $canManage): array
    {
        return [
            'restricted' => true,
            'canManage' => $canManage,
            'summary' => ['activeCredentials' => 0, 'activeSchedules' => 0, 'coveredDoors' => 0],
            'sites' => [],
            'deviceOptions' => [],
            'holderOptions' => [],
            'providerActions' => $this->providerActions(),
            'schedules' => [],
            'credentials' => [],
            'history' => [],
        ];
    }

    /** @param list<int> $siteIds */
    private function deviceOptions(Builder $deviceScope, array $siteIds): Collection
    {
        return (clone $deviceScope)
            ->where('category', 'access_control')
            ->with(['assignments' => fn ($query) => $query->active()->where('assigned_at', '<=', now())])
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function (Device $device) use ($siteIds): ?array {
                $siteId = $this->siteIdForDevice($device);
                if ($siteId === null || ! in_array($siteId, $siteIds, true)) {
                    return null;
                }

                return [
                    'id' => (int) $device->id,
                    'siteId' => $siteId,
                    'name' => $device->name,
                ];
            })
            ->filter()
            ->values();
    }

    private function siteIdForDevice(Device $device): ?int
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

    private function holderOptions(User $viewer): Collection
    {
        $staff = $this->access->assignableStaff($viewer)
            ->with('hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids')
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'type' => AccessControlCredential::HOLDER_STAFF,
                'label' => $user->name,
                'siteIds' => collect([
                    $user->hrEmployeeProfile?->primary_site_id,
                    ...($user->hrEmployeeProfile?->secondary_site_ids ?? []),
                ])->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
            ]);
        $clients = $this->access->assignableClients($viewer)
            ->take(self::LIST_LIMIT)
            ->map(fn (Client $client): array => [
                'id' => (int) $client->id,
                'type' => AccessControlCredential::HOLDER_CLIENT,
                'label' => $client->full_name,
                'siteIds' => [(int) $client->site_id],
            ]);

        return $staff->concat($clients)->values();
    }

    private function holderLabels(User $viewer, Collection $credentials): Collection
    {
        $staffIds = $credentials->where('holder_type', AccessControlCredential::HOLDER_STAFF)->pluck('holder_id');
        $clientIds = $credentials->where('holder_type', AccessControlCredential::HOLDER_CLIENT)->pluck('holder_id');
        $staff = $staffIds->isEmpty()
            ? collect()
            : $this->access->assignableStaff($viewer)->whereKey($staffIds)->pluck('name', 'id')
                ->mapWithKeys(fn (string $name, mixed $id): array => ['staff:'.$id => $name]);
        $clients = $clientIds->isEmpty()
            ? collect()
            : Client::query()->whereKey($this->access->authorizedClientIds($viewer))->whereKey($clientIds)->get()
                ->mapWithKeys(fn (Client $client): array => ['client:'.$client->id => $client->full_name]);

        return $staff->merge($clients);
    }

    private function latestBindingEvents(Collection $credentials): Collection
    {
        if ($credentials->isEmpty()) {
            return collect();
        }

        $latestSequences = DB::table('access_control_credential_device_binding_events')
            ->select([
                'access_credential_id',
                'device_id',
                DB::raw('MAX(sequence) as latest_sequence'),
            ])
            ->whereIn('access_credential_id', $credentials->modelKeys())
            ->groupBy('access_credential_id', 'device_id');

        return AccessControlCredentialDeviceBindingEvent::query()
            ->from('access_control_credential_device_binding_events as binding')
            ->joinSub($latestSequences, 'latest_binding', fn ($join) => $join
                ->on('latest_binding.access_credential_id', '=', 'binding.access_credential_id')
                ->on('latest_binding.device_id', '=', 'binding.device_id')
                ->on('latest_binding.latest_sequence', '=', 'binding.sequence'))
            ->select('binding.*')
            ->with(['device.assignments' => fn ($query) => $query
                ->active()
                ->where('assigned_at', '<=', now())
                ->orderByDesc('assigned_at')
                ->orderByDesc('id')])
            ->get()
            ->groupBy('access_credential_id');
    }

    private function confirmedLifecycleEvidence(Collection $credentials): Collection
    {
        if ($credentials->isEmpty()) {
            return collect();
        }

        return AccessControlCredentialLifecycleEvent::query()
            ->whereIn('access_credential_id', $credentials->modelKeys())
            ->where('provider_confirmed', true)
            ->whereNotNull('provider_request_key')
            ->whereNotNull('provider_event_key')
            ->get()
            ->groupBy('access_credential_id');
    }

    /** @param list<int> $visibleDeviceIds */
    private function confirmedBindingDevices(
        AccessControlCredential $credential,
        Collection $bindingEvents,
        Collection $lifecycleEvidence,
        array $visibleDeviceIds,
    ): Collection {
        $confirmedEvidence = $lifecycleEvidence->keyBy(fn (AccessControlCredentialLifecycleEvent $event): string => $event->provider_request_key.'|'.$event->provider_event_key);

        return $bindingEvents
            ->filter(function (AccessControlCredentialDeviceBindingEvent $binding) use (
                $credential,
                $confirmedEvidence,
                $visibleDeviceIds,
            ): bool {
                $device = $binding->device;
                $evidence = $confirmedEvidence->get($binding->provider_request_key.'|'.$binding->provider_event_key);

                return (int) $binding->site_id === (int) $credential->site_id
                    && $binding->binding_status === AccessControlCredentialDeviceBindingEvent::STATUS_ACTIVE
                    && $binding->provider_reconciliation_status === AccessControlCredential::RECONCILIATION_RECONCILED
                    && $binding->provider_confirmed
                    && $evidence instanceof AccessControlCredentialLifecycleEvent
                    && (int) $evidence->site_id === (int) $credential->site_id
                    && $evidence->provider_action === AccessControlCredential::PROVIDER_ACTION_ISSUE
                    && in_array(
                        (int) $binding->device_id,
                        collect((array) data_get($evidence->credential_snapshot, 'device_ids', []))
                            ->map(fn (mixed $id): int => (int) $id)
                            ->all(),
                        true,
                    )
                    && $device instanceof Device
                    && $device->domain === 'security'
                    && $device->category === 'access_control'
                    && $device->assignments->count() === 1
                    && in_array((int) $device->getKey(), $visibleDeviceIds, true)
                    && $this->siteIdForDevice($device) === (int) $binding->site_id;
            })
            ->map(fn (AccessControlCredentialDeviceBindingEvent $binding): array => [
                'id' => (int) $binding->device->id,
                'name' => $binding->device->name,
                'href' => "/security-devices/devices/{$binding->device->id}",
            ])
            ->values();
    }

    private function history(Collection $credentials, Collection $schedules): Collection
    {
        if ($credentials->isEmpty() && $schedules->isEmpty()) {
            return collect();
        }

        $auditHistory = AuditLog::query()
            ->whereNotIn('action', ['access_control.credential.provider_transition'])
            ->where(function (Builder $query) use ($credentials, $schedules): void {
                if ($credentials->isNotEmpty()) {
                    $query->where(fn (Builder $branch) => $branch
                        ->where('auditable_type', AccessControlCredential::class)
                        ->whereIn('auditable_id', $credentials->modelKeys()));
                }
                if ($schedules->isNotEmpty()) {
                    $method = $credentials->isNotEmpty() ? 'orWhere' : 'where';
                    $query->{$method}(fn (Builder $branch) => $branch
                        ->where('auditable_type', AccessControlSchedule::class)
                        ->whereIn('auditable_id', $schedules->modelKeys()));
                }
            })
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'id' => 'audit:'.$entry->id,
                'action' => match ($entry->action) {
                    'access_control.schedule.created' => 'Schedule created',
                    'access_control.schedule.updated' => 'Schedule updated',
                    'access_control.schedule.deactivated' => 'Schedule deactivated',
                    'access_control.credential.issued' => 'Credential registration recorded — provider not confirmed',
                    'access_control.credential.revoked' => 'Local revocation recorded — provider not confirmed',
                    'access_control.credential.issue_requested' => 'Credential issue requested',
                    'access_control.credential.revocation_requested' => 'Credential revocation requested',
                    default => str_replace(['access_control.', '_', '.'], ['', ' ', ' '], $entry->action),
                },
                'actor' => $entry->user?->name ?? 'System',
                'occurredAt' => $entry->created_at?->toIso8601String(),
                'sortAt' => $entry->created_at?->getTimestamp() ?? 0,
            ]);
        $providerHistory = $credentials->isEmpty()
            ? collect()
            : AccessControlCredentialLifecycleEvent::query()
                ->whereIn('access_credential_id', $credentials->modelKeys())
                ->whereIn('site_id', $credentials->pluck('site_id')->unique())
                ->with('recordedBy:id,name')
                ->latest('occurred_at')
                ->latest('id')
                ->limit(self::HISTORY_LIMIT)
                ->get()
                ->map(fn (AccessControlCredentialLifecycleEvent $event): array => [
                    'id' => 'credential-event:'.$event->id,
                    'action' => $this->credentialHistoryLabel($event),
                    'actor' => $event->recordedBy?->name ?? 'System/provider',
                    'occurredAt' => ($event->occurred_at ?? $event->created_at)?->toIso8601String(),
                    'sortAt' => ($event->occurred_at ?? $event->created_at)?->getTimestamp() ?? 0,
                ]);

        return $auditHistory
            ->concat($providerHistory)
            ->sortByDesc('sortAt')
            ->take(self::HISTORY_LIMIT)
            ->map(fn (array $entry): array => collect($entry)->except('sortAt')->all())
            ->values();
    }

    private function credentialHistoryLabel(AccessControlCredentialLifecycleEvent $event): string
    {
        if ($event->event_type === 'legacy_local_state_snapshot'
            || $event->evidence_kind === 'unconfirmed_local_claim') {
            return 'Legacy credential state retained — provider unconfirmed';
        }

        $status = (string) data_get($event->credential_snapshot, 'status');

        return match ($status) {
            AccessControlCredential::STATUS_PENDING_ISSUE => 'Provider accepted credential issue request',
            AccessControlCredential::STATUS_ACTIVE => 'Provider confirmed credential access',
            AccessControlCredential::STATUS_ISSUE_FAILED => 'Provider reported credential issue failure',
            AccessControlCredential::STATUS_PENDING_REVOKE => 'Provider accepted credential revocation request',
            AccessControlCredential::STATUS_REVOKED => 'Provider confirmed credential revocation',
            AccessControlCredential::STATUS_REVOKE_FAILED => 'Provider reported credential revocation failure',
            default => 'Credential provider evidence recorded',
        };
    }

    /** @return array{issue: array{available: bool, reason: string}, revoke: array{available: bool, reason: string}} */
    private function providerActions(): array
    {
        return [
            'issue' => [
                'available' => false,
                'reason' => 'No approved credential-issue adapter and provider reconciliation contract is connected. No access will be granted from this screen.',
            ],
            'revoke' => [
                'available' => false,
                'reason' => 'No approved credential-revocation adapter and provider reconciliation contract is connected. Use the provider directly and reconcile the evidence before treating access as revoked.',
            ],
        ];
    }

    /** @return array{status: string, label: string, tone: string, requiredAt: ?string, message: string, failureReason: ?string, providerConfirmed: bool} */
    private function scheduleReconciliation(
        AccessControlSchedule $schedule,
        ?AccessControlScheduleRevision $latestRevision,
    ): array {
        $status = (string) $schedule->provider_reconciliation_status;
        $expectedAction = match ($status) {
            AccessControlSchedule::RECONCILIATION_PENDING => 'provider_pending',
            AccessControlSchedule::RECONCILIATION_FAILED => 'provider_failed',
            AccessControlSchedule::RECONCILIATION_RECONCILED => 'provider_reconciled',
            default => null,
        };
        $evidenceMatches = $latestRevision instanceof AccessControlScheduleRevision
            && (int) $latestRevision->site_id === (int) $schedule->site_id
            && $latestRevision->action === $expectedAction
            && filled($schedule->provider_reconciliation_request_key)
            && filled($schedule->provider_reconciliation_event_key)
            && $latestRevision->provider_request_key === $schedule->provider_reconciliation_request_key
            && $latestRevision->provider_event_key === $schedule->provider_reconciliation_event_key
            && data_get($latestRevision->snapshot, 'provider_reconciliation_status') === $status
            && data_get($latestRevision->snapshot, 'provider_reconciliation_request_key') === $schedule->provider_reconciliation_request_key
            && data_get($latestRevision->snapshot, 'provider_reconciliation_event_key') === $schedule->provider_reconciliation_event_key;
        $providerConfirmed = $status === AccessControlSchedule::RECONCILIATION_RECONCILED
            && $evidenceMatches
            && $latestRevision?->provider_confirmed
            && $schedule->provider_reconciliation_confirmed_at !== null
            && blank($schedule->provider_reconciliation_failure_reason);
        $requiredStateIsTruthful = $status === AccessControlSchedule::RECONCILIATION_REQUIRED
            && blank($schedule->provider_reconciliation_request_key)
            && blank($schedule->provider_reconciliation_event_key)
            && $schedule->provider_reconciliation_confirmed_at === null
            && blank($schedule->provider_reconciliation_failure_reason);

        [$label, $tone, $message] = match (true) {
            $providerConfirmed => [
                'Provider reconciled',
                'positive',
                'Immutable provider evidence confirms this schedule projection is reconciled.',
            ],
            $status === AccessControlSchedule::RECONCILIATION_PENDING && $evidenceMatches => [
                'Provider reconciliation pending',
                'warning',
                'The correlated provider request is in progress. Do not assume the schedule is enforced until confirmation arrives.',
            ],
            $status === AccessControlSchedule::RECONCILIATION_FAILED
                && $evidenceMatches
                && filled($schedule->provider_reconciliation_failure_reason) => [
                    'Provider reconciliation failed',
                    'danger',
                    'The correlated provider request failed. This schedule must not be treated as enforced.',
                ],
            $requiredStateIsTruthful => [
                'Provider reconciliation required',
                'warning',
                'Saved in Oblivion Findings only. Provider-side schedule execution has not been claimed and must be reconciled separately.',
            ],
            default => [
                'Provider evidence inconsistent',
                'danger',
                'The schedule projection has no matching immutable provider evidence. Treat enforcement as unknown until repaired.',
            ],
        };

        return [
            'status' => $status,
            'label' => $label,
            'tone' => $tone,
            'requiredAt' => $schedule->provider_reconciliation_required_at?->toIso8601String(),
            'message' => $message,
            'failureReason' => $evidenceMatches ? $schedule->provider_reconciliation_failure_reason : null,
            'providerConfirmed' => $providerConfirmed,
        ];
    }

    /** @return array{state: string, label: string, tone: string, message: string, requestedAt: ?string, confirmedAt: ?string, failureReason: ?string, accessStillConfirmed: bool} */
    private function credentialLifecycle(AccessControlCredential $credential, Collection $activeDevices): array
    {
        $action = (string) $credential->provider_reconciliation_action;
        $reconciliation = (string) $credential->provider_reconciliation_status;
        $latest = $credential->latestLifecycleEvent;
        $eventMatches = $latest instanceof AccessControlCredentialLifecycleEvent
            && (int) $latest->site_id === (int) $credential->site_id
            && filled($credential->provider_reconciliation_request_key)
            && filled($credential->provider_reconciliation_event_key)
            && $latest->provider_request_key === $credential->provider_reconciliation_request_key
            && $latest->provider_event_key === $credential->provider_reconciliation_event_key
            && $latest->provider_action === $action
            && data_get($latest->credential_snapshot, 'status') === $credential->status
            && data_get($latest->credential_snapshot, 'provider_reconciliation_status') === $reconciliation
            && data_get($latest->credential_snapshot, 'provider_reconciliation_action') === $action
            && data_get($latest->credential_snapshot, 'provider_reconciliation_request_key') === $credential->provider_reconciliation_request_key
            && data_get($latest->credential_snapshot, 'provider_reconciliation_event_key') === $credential->provider_reconciliation_event_key;
        $requiredStateIsTruthful = $reconciliation === AccessControlCredential::RECONCILIATION_REQUIRED
            && in_array($credential->status, [
                AccessControlCredential::STATUS_PENDING_ISSUE,
                AccessControlCredential::STATUS_PENDING_REVOKE,
            ], true)
            && blank($credential->provider_reconciliation_request_key)
            && blank($credential->provider_reconciliation_event_key)
            && $credential->provider_reconciliation_confirmed_at === null
            && blank($credential->provider_reconciliation_failure_reason);
        $hasConfirmedAccess = $activeDevices->isNotEmpty();

        if ($reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED
            && $credential->status === AccessControlCredential::STATUS_ACTIVE
            && $action === AccessControlCredential::PROVIDER_ACTION_ISSUE
            && $eventMatches
            && $latest?->provider_confirmed
            && $credential->provider_reconciliation_confirmed_at !== null
            && $hasConfirmedAccess) {
            [$state, $label, $tone, $message, $accessStillConfirmed] = [
                'active',
                'Active — provider confirmed',
                'positive',
                'Correlated provider evidence confirms this credential on the listed Site-bound readers.',
                true,
            ];
        } elseif ($reconciliation === AccessControlCredential::RECONCILIATION_RECONCILED
            && $credential->status === AccessControlCredential::STATUS_REVOKED
            && $action === AccessControlCredential::PROVIDER_ACTION_REVOKE
            && $eventMatches
            && $latest?->provider_confirmed
            && $credential->provider_reconciliation_confirmed_at !== null
            && ! $hasConfirmedAccess) {
            [$state, $label, $tone, $message, $accessStillConfirmed] = [
                'revoked',
                'Revoked — provider confirmed',
                'neutral',
                'Correlated provider evidence confirms access was removed from every governed reader.',
                false,
            ];
        } elseif ($reconciliation === AccessControlCredential::RECONCILIATION_FAILED
            && $eventMatches
            && filled($credential->provider_reconciliation_failure_reason)) {
            $revokeFailed = $action === AccessControlCredential::PROVIDER_ACTION_REVOKE
                && $credential->status === AccessControlCredential::STATUS_REVOKE_FAILED;
            $issueFailed = $action === AccessControlCredential::PROVIDER_ACTION_ISSUE
                && $credential->status === AccessControlCredential::STATUS_ISSUE_FAILED;
            if ($revokeFailed || $issueFailed) {
                [$state, $label, $tone, $message, $accessStillConfirmed] = [
                    'failed',
                    $revokeFailed ? 'Revocation failed — access remains' : 'Issue failed',
                    'danger',
                    $revokeFailed && $hasConfirmedAccess
                        ? 'The provider rejected revocation. Previously confirmed access remains active on the listed readers.'
                        : 'The provider action failed. No new physical access is claimed.',
                    $revokeFailed && $hasConfirmedAccess,
                ];
            }
        } elseif (in_array($reconciliation, [
            AccessControlCredential::RECONCILIATION_REQUIRED,
            AccessControlCredential::RECONCILIATION_PENDING,
        ], true)
            && $credential->status === AccessControlCredential::STATUS_PENDING_REVOKE
            && ($requiredStateIsTruthful || $eventMatches)) {
            [$state, $label, $tone, $message, $accessStillConfirmed] = [
                'pending',
                $hasConfirmedAccess ? 'Revocation pending — access remains' : 'Revocation not confirmed',
                'warning',
                $hasConfirmedAccess
                    ? 'Revocation is not confirmed. Previously confirmed access remains active on the listed readers.'
                    : 'A legacy local revocation claim exists, but no provider-confirmed access or removal evidence is available.',
                $hasConfirmedAccess,
            ];
        } elseif (in_array($reconciliation, [
            AccessControlCredential::RECONCILIATION_REQUIRED,
            AccessControlCredential::RECONCILIATION_PENDING,
        ], true)
            && $credential->status === AccessControlCredential::STATUS_PENDING_ISSUE
            && ($requiredStateIsTruthful || $eventMatches)
            && ! $hasConfirmedAccess) {
            [$state, $label, $tone, $message, $accessStillConfirmed] = [
                'pending',
                'Access not confirmed',
                'warning',
                'A local or pending provider record exists, but no physical access is claimed until correlated confirmation arrives.',
                false,
            ];
        }

        if (! isset($state)) {
            [$state, $label, $tone, $message, $accessStillConfirmed] = [
                'failed',
                'Provider evidence inconsistent',
                'danger',
                'The current credential projection does not match immutable lifecycle and reader-binding evidence. Treat access as unknown until repaired.',
                false,
            ];
        }

        return [
            'state' => $state,
            'label' => $label,
            'tone' => $tone,
            'message' => $message,
            'requestedAt' => $eventMatches
                ? $credential->provider_reconciliation_requested_at?->toIso8601String()
                : null,
            'confirmedAt' => $eventMatches
                ? $credential->provider_reconciliation_confirmed_at?->toIso8601String()
                : null,
            'failureReason' => $eventMatches ? $credential->provider_reconciliation_failure_reason : null,
            'accessStillConfirmed' => $accessStillConfirmed,
        ];
    }
}
