<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetTelemetryEvent;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnexpectedValueException;

/** Canonical current staff, exact source tuples and independently governed disclosure. */
final class LoneWorkerSessionAccessService
{
    public const PERMISSIONS = ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites', 'assets.telemetry.view', 'controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage', 'reports.viewAny', 'incidents.viewAny', 'incidents.viewAssigned', 'hr.cases.view', 'medications.controlled.view', 'clients.viewAny', 'clients.viewAssigned', 'clinical.accessAllSites', 'sites.viewAll'];

    public const LIVE_STATUSES = ['active', 'overdue', 'emergency'];

    public function __construct(private readonly UserSiteAccessService $siteAccess, private readonly AuthorizationEvidenceLockService $authorization, private readonly HrCurrentStaffService $staff) {}

    /** Consume the caller's fresh staff/Site evidence without a second actor refresh. */
    public function withReadContext(HealthSafetyReadContext $context, callable $operation): mixed
    {
        return $context->consumeViewer(self::PERMISSIONS, $operation);
    }

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($viewer, $operation) {
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->exists(), 403);
            $current->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first());
            $sites = $this->siteAccess->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless($sites !== [], 403);

            return $operation($current, $reads, $sites);
        }));
    }

    public function readableQuery(User $viewer, bool $own = false): Builder
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($own) {
            abort_unless($own || $current->canDo('hazards.view'), 403);
            $query = app(LoneWorkerSessionScope::class)->applyForSites(LoneWorkerSession::query(), $sites);

            return $own ? $query->where('user_id', $current->id) : $query;
        });
    }

    public function recordForViewer(LoneWorkerSession $session, User $viewer, bool $own = false): LoneWorkerSession
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($session, $own) {
            $fresh = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
            $this->assertRecord($fresh, $current, $sites, false, $own);

            return $fresh;
        });
    }

    public function assertRecord(LoneWorkerSession $session, User $current, array $siteIds, bool $manage = false, bool $own = false): void
    {
        abort_unless(DB::transactionLevel() > 0, 403);
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($session, $current, $siteIds, $manage, $own): void {
            $owner = (int) $session->user_id === (int) $current->id;
            abort_unless((! $own || $owner) && ($owner || $current->canDo('hazards.view')) && (! $manage || ($current->canDo('hazards.view') && $current->canDo('hazards.manage'))), 403);
            $site = $this->assertSource($session, $reads, $manage || in_array($session->status, self::LIVE_STATUSES, true));
            abort_unless(in_array($site, $siteIds, true), 403, UserSiteAccessService::DEFAULT_MESSAGE);
        });
    }

    /** Retained API; owning a session never bypasses current staff or source Site integrity. */
    public function assertCanAccessSession(User $user, LoneWorkerSession $session, bool $allowOwnerWithoutSiteAssignment = false): void
    {
        $this->recordForViewer($session, $user, $allowOwnerWithoutSiteAssignment && ! $user->canDo('hazards.manage'));
    }

    public function assertSource(LoneWorkerSession $session, CurrentAuthorizationReads $reads, bool $requireCurrentWorker = true): int
    {
        $worker = $reads->query(User::query())->find($session->user_id);
        $client = $session->client_id !== null ? $reads->query(Client::query())->find($session->client_id) : null;
        $shift = $session->shift_id !== null ? $reads->query(Shift::query())->find($session->shift_id) : null;
        abort_unless($worker && ($session->client_id === null || $client) && ($session->shift_id === null || $shift), 403);
        $shiftClient = $shift?->client_id !== null ? ($client && (int) $client->id === (int) $shift->client_id ? $client : $reads->query(Client::query())->find($shift->client_id)) : null;
        if ($shift) {
            abort_unless((int) $shift->user_id === (int) $session->user_id && $this->sameNullableId($shift->client_id, $session->client_id), 403);
            abort_unless($shift->client_id === null || ($shiftClient && $shiftClient->site_id !== null), 403);
            abort_unless($shift->site_id === null || ! $shiftClient || (int) $shift->site_id === (int) $shiftClient->site_id, 403);
            $shift->setRelation('client', $shiftClient);
            $shift->setRelation('staff', $worker);
        }
        abort_unless($session->client_id === null || $client->site_id !== null, 403);
        $sites = collect([$session->site_id, $client?->site_id, $shift?->site_id, $shiftClient?->site_id])->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values();
        abort_unless($sites->count() === 1, 403);
        $siteId = (int) $sites->first();
        $site = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->find($siteId);
        abort_unless($site, 403);
        if ($requireCurrentWorker) {
            abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($worker->id)->exists(), 403);
            $worker->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $worker->id)->first());
            abort_unless(in_array($siteId, $this->siteAccess->accessibleSiteIds($worker, [], $reads), true), 403);
        }
        $session->setRelation('user', $worker)->setRelation('client', $client)->setRelation('shift', $shift)->setRelation('site', $site);

        return $siteId;
    }

    public function eligibleWorkerAtSite(int $userId, int $siteId): User
    {
        $candidate = User::query()->findOrFail($userId);

        return $this->withCurrentViewer($candidate, function ($current, $reads) use ($siteId) {
            abort_unless(in_array($siteId, $this->siteAccess->accessibleSiteIds($current, [], $reads), true), 403);

            return $current;
        });
    }

    public function assertOwnActiveShift(LoneWorkerSession $session, User $current, CurrentAuthorizationReads $reads): Shift
    {
        abort_unless((int) $session->user_id === (int) $current->id && $session->shift_id !== null, 403);
        $shift = $reads->query(Shift::query())->findOrFail($session->shift_id);
        abort_unless((int) $shift->user_id === (int) $current->id && $shift->actual_starts_at && $shift->actual_starts_at->lte(now()) && $shift->actual_ends_at === null && ! in_array($shift->status, ['cancelled', 'completed'], true), 403);

        return $shift;
    }

    public function commandCan(LoneWorkerSession $session, User $viewer): array
    {
        $keys = ['view', 'manage', 'edit', 'end', 'delete', 'remove', 'check_in', 'panic', 'locate', 'acknowledge_panic'];
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($session) {
                $row = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
                $this->assertRecord($row, $current, $sites);
                $eligible = true;
                try {
                    $this->assertSource($row, $reads, true);
                } catch (HttpException) {
                    $eligible = false;
                }
                $manage = $current->canDo('hazards.view') && $current->canDo('hazards.manage') && $eligible;
                $owner = (int) $row->user_id === (int) $current->id;
                $live = in_array($row->status, self::LIVE_STATUSES, true) && $row->ended_at === null;
                $routine = $live && $row->status !== 'emergency';
                $responses = $reads->query(ControlRoomAlert::query())->where('source', 'lone_worker')->where('alert_type', LoneWorkerSignalService::canonicalAlertType(LoneWorkerSignalService::TYPE_EMERGENCY))->where('context->normalized_data->lone_worker_session_id', $row->id)->where('status', ControlRoomAlert::STATUS_OPEN)->get()->filter(fn ($alert) => $this->alertMatchesSession($alert, $row, $reads));
                $acknowledge = $manage && $live && $current->canDo('assets.telemetry.view') && $current->canDo('controlRoom.alerts.manage') && $responses->count() === 1 && $reads->query(app(ControlRoomAlertAccessService::class)->applyReadableScope(ControlRoomAlert::query(), $current))->whereKey($responses->first()?->id)->exists();
                $tracker = $manage && $live && $current->canDo('assets.telemetry.view') ? $this->trackerForViewer($row, $current) : null;
                $locate = $tracker !== null && $tracker['tracker'] !== null && ($tracker['tracker']['can_refresh'] ?? false) && in_array($tracker['state'], ['available', 'stale', 'unknown'], true);
                $selfEnd = false;
                if ($owner && $routine) {
                    try {
                        $this->assertOwnActiveShift($row, $current, $reads);
                        $selfEnd = true;
                    } catch (HttpException) {
                    }
                }

                return ['view' => true, 'manage' => $manage, 'edit' => $manage && $routine, 'end' => $routine && ($manage || $selfEnd), 'delete' => $manage && $row->status === 'completed', 'remove' => $manage && $row->status === 'completed',
                    'check_in' => $routine && ($owner || $manage), 'panic' => $routine && ($owner || $manage), 'locate' => $locate, 'acknowledge_panic' => $acknowledge];
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return array_fill_keys($keys, false);
            }
            throw $error;
        }
    }

    public function pickerOptions(User $viewer, ?LoneWorkerSession $session = null): array
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $siteIds) use ($session) {
            $self = ! $current->canDo('hazards.view');
            if ($session) {
                $this->assertRecord($session, $current, $siteIds);
            }
            $workers = $reads->query($this->staff->currentUsersQuery())->when($self, fn ($query) => $query->whereKey($current->id))->orderBy('name')->get()->filter(fn ($worker) => array_intersect($this->siteAccess->accessibleSiteIds($worker, [], $reads), $siteIds) !== [])->values();
            $shifts = $reads->query(Shift::query())->whereIn('user_id', $workers->pluck('id'))->whereNotNull('actual_starts_at')->where('actual_starts_at', '<=', now())->whereNull('actual_ends_at')->whereNotIn('status', ['cancelled', 'completed'])->orderBy('ends_at')->get()->filter(function ($shift) use ($siteIds, $reads) {
                $probe = new LoneWorkerSession(['user_id' => $shift->user_id, 'site_id' => $shift->site_id, 'client_id' => $shift->client_id, 'shift_id' => $shift->id]);
                try {
                    $allowed = in_array($this->assertSource($probe, $reads), $siteIds, true);
                    $shift->setRelation('client', $probe->client);

                    return $allowed;
                } catch (HttpException) {
                    return false;
                }
            });

            $clients = $self ? collect() : $reads->query(Client::query())->whereIn('site_id', $siteIds)->orderBy('first_name')->orderBy('last_name')->get(['id', 'site_id', 'first_name', 'last_name'])->filter(fn ($client) => app(ClientPolicy::class)->viewFromCurrentEvidence($current, $client, $reads))->map(fn ($client) => ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name), 'first_name' => $client->first_name, 'last_name' => $client->last_name, 'site_id' => $client->site_id])->values();

            return ['clients' => $clients, 'can_geocode' => $current->canDo('hazards.view'), 'sites' => $reads->query(Site::query())->whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name', 'address_line_1', 'address_line_2', 'suburb', 'city', 'postcode', 'country', 'latitude', 'longitude'])->map(fn ($site) => ['id' => $site->id, 'name' => $site->name, 'address' => trim($site->address) !== '' ? $site->address : null, 'latitude' => $this->validSitePoint($site) ? (float) $site->latitude : null, 'longitude' => $this->validSitePoint($site) ? (float) $site->longitude : null]), 'workers' => $workers->map(fn ($worker) => ['id' => $worker->id, 'name' => $worker->name, 'site_ids' => array_values(array_intersect($this->siteAccess->accessibleSiteIds($worker, [], $reads), $siteIds))]), 'shifts' => $shifts->values()->map(fn ($shift) => ['id' => $shift->id, 'worker' => ['id' => $shift->user_id, 'name' => $workers->firstWhere('id', $shift->user_id)?->name], 'site_id' => $shift->site_id ?? $shift->client?->site_id, 'client_id' => $shift->client && app(ClientPolicy::class)->viewFromCurrentEvidence($current, $shift->client, $reads) ? $shift->client_id : null, 'starts_at' => $shift->starts_at?->toISOString(), 'ends_at' => $shift->ends_at?->toISOString(), 'actual_starts_at' => $shift->actual_starts_at?->toISOString(), 'is_lone_worker' => $shift->is_lone_worker, 'location' => $shift->location, 'address' => $shift->location, 'latitude' => null, 'longitude' => null])];
        });
    }

    private function validSitePoint(Site $site): bool
    {
        return is_numeric($site->latitude) && is_numeric($site->longitude)
            && (float) $site->latitude >= -90 && (float) $site->latitude <= 90
            && (float) $site->longitude >= -180 && (float) $site->longitude <= 180;
    }

    public function trackerForViewer(LoneWorkerSession $session, User $viewer): array
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($session) {
            $row = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
            $this->assertRecord($row, $current, $sites);
            if (! $current->canDo('assets.telemetry.view') || ((int) $row->user_id !== (int) $current->id && ! $current->canDo('hazards.manage'))) {
                return ['state' => 'restricted', 'tracker' => null];
            }
            if (! in_array($row->status, self::LIVE_STATUSES, true) || $row->ended_at !== null) {
                return ['state' => 'session_ended', 'tracker' => null];
            }
            $assignments = $reads->query(DeviceAssignment::query())->where('assignable_type', DeviceAssignment::TARGET_STAFF)->where('assignable_id', $row->user_id)->whereNull('released_at')->where('assigned_at', '<=', now())->whereHas('device', fn ($device) => $device->where('domain', 'tracking'))->orderBy('id')->get();
            if ($assignments->isEmpty()) {
                return ['state' => 'not_assigned', 'tracker' => null];
            }
            if ($assignments->count() !== 1) {
                return ['state' => 'integrity_error', 'tracker' => null];
            }
            if ($assignments->first()->collection_stopped_at !== null) {
                return ['state' => 'collection_stopped', 'tracker' => null];
            }
            $assignment = $assignments->first();
            if (! is_array($assignment->access_audience) || ! in_array('health_and_safety', $assignment->access_audience, true)) {
                return ['state' => 'restricted', 'tracker' => null];
            }
            $device = $reads->query(Device::query())->where('domain', 'tracking')->find($assignment->device_id);
            try {
                $valid = $device && app(CanonicalDeviceSiteResolver::class)->forCurrentEvidence($reads)->resolveForContext((int) $device->id) === (int) $row->site->id;
            } catch (UnexpectedValueException) {
                $valid = false;
            }
            if (! $valid) {
                return ['state' => 'integrity_error', 'tracker' => null];
            }
            $event = null;
            if ($assignment->collection_started_at && (int) $assignment->retention_days > 0 && $row->started_at && $row->started_at->lte(now())) {
                $from = collect([$assignment->assigned_at, $assignment->collection_started_at, $row->started_at, now()->subDays((int) $assignment->retention_days)])->sortByDesc(fn ($at) => $at->getTimestamp())->first();
                $event = $reads->query(FleetTelemetryEvent::query())->where('device_id', $device->id)->where('consent_blocked', false)->whereBetween('occurred_at', [$from, now()])->where('received_at', '<=', now())->whereColumn('received_at', '>=', 'occurred_at')->whereNotNull('latitude')->whereNotNull('longitude')->orderByDesc('occurred_at')->orderByDesc('id')->first();
            }
            $state = $event === null ? 'unknown' : ($event->occurred_at->lt(now()->subMinutes(15)) ? 'stale' : 'available');
            $collectionState = $device->last_seen_at === null || $device->last_seen_at->gt(now()) ? 'unknown' : ($device->last_seen_at->lt(now()->subMinutes(15)) ? 'stale' : 'current');

            return ['state' => $state, 'tracker' => ['name' => $device->name, 'can_refresh' => $assignment->collection_started_at?->lte(now()) && (int) $assignment->retention_days > 0 && app(DeclaredDeviceCommandCapabilities::class)->supports($device, 'tracking.location_refresh'), 'latitude' => $event?->latitude, 'longitude' => $event?->longitude, 'observed_at' => $event?->occurred_at?->toISOString(), 'received_at' => $event?->received_at?->toISOString(), 'location_source' => $event ? 'canonical_telemetry_event' : null, 'collection_state' => $collectionState, 'last_seen_at' => $device->last_seen_at?->toISOString(), 'battery_level' => $device->battery_level, 'panic_active' => (bool) data_get($device->meta, 'panic_active', false)]];
        });
    }

    public function canViewCanonicalAlert(LoneWorkerSession $session, ControlRoomAlert $alert, User $viewer): bool
    {
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($session, $alert) {
                $row = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
                $this->assertRecord($row, $current, $sites);
                $fresh = $reads->query(ControlRoomAlert::query())->find($alert->id);

                return $fresh && $this->alertMatchesSession($fresh, $row, $reads) && $reads->query(app(ControlRoomAlertAccessService::class)->applyReadableScope(ControlRoomAlert::query(), $current))->whereKey($fresh->id)->exists();
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return false;
            } throw $error;
        }
    }

    public function readableIncidentForAlert(LoneWorkerSession $session, ControlRoomAlert $alert, User $viewer): ?ClientIncident
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($session, $alert) {
            $row = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
            $this->assertRecord($row, $current, $sites);
            $fresh = $reads->query(ControlRoomAlert::query())->find($alert->id);
            if (! $fresh || ! $this->alertMatchesSession($fresh, $row, $reads)) {
                return null;
            }
            $incident = app(IncidentAccessService::class)->apply(ClientIncident::query(), $current, $reads)->where('control_room_alert_id', $fresh->id)->first();

            return $incident && $this->sameNullableId($incident->client_id, $row->client_id) && (int) $incident->site_id === (int) $row->site->id ? $incident : null;
        });
    }

    public function alertMatchesSession(ControlRoomAlert $alert, LoneWorkerSession $session, ?CurrentAuthorizationReads $reads = null): bool
    {
        $context = data_get($alert->context, 'normalized_data');
        $type = data_get($context, 'signal_type') ?? data_get($alert->context, 'signal_type_code');
        $siteId = $session->site?->id ?? $session->site_id;
        if ($alert->source !== 'lone_worker' || ! is_array($context) || $this->positiveId($context['lone_worker_session_id'] ?? null) !== (int) $session->id || $this->positiveId($alert->site_id) !== (int) $siteId || ! $this->sameNullableId($alert->client_id, $session->client_id) || ! is_string($type) || LoneWorkerSignalService::canonicalAlertType($type) !== $alert->alert_type) {
            return false;
        }
        foreach (['worker_user_id' => $session->user_id, 'site_id' => $siteId, 'client_id' => $session->client_id] as $key => $expected) {
            if (! array_key_exists($key, $context) || ! $this->sameNullableId($context[$key], $expected)) {
                return false;
            }
        }

        return $this->canonicalOriginMatchesSession($alert, $session, $reads);
    }

    public function canonicalOriginMatchesSession(ControlRoomAlert $alert, LoneWorkerSession $session, ?CurrentAuthorizationReads $reads = null): bool
    {
        if ($reads === null) {
            return DB::transaction(fn () => CurrentAuthorizationReads::within(fn ($currentReads) => $this->canonicalOriginMatchesSession($alert, $session, $currentReads)));
        }
        if ($this->positiveId($alert->origin_signal_id) === null) {
            return false;
        }
        $signal = $reads->query(Signal::query())->find($alert->origin_signal_id);
        $source = $signal ? $reads->query(SignalSource::query())->find($signal->signal_source_id) : null;
        $context = $signal?->normalized_data;
        $type = $signal?->signal_type_code;
        if (! $signal || $signal->status !== 'processed' || $source?->slug !== 'lone_worker' || ! is_array($context) || ($context['source_module'] ?? null) !== 'lone_worker' || ! is_string($type) || ($context['signal_type'] ?? null) !== $type || LoneWorkerSignalService::canonicalAlertType($type) !== $alert->alert_type || (int) $signal->site_id !== (int) $session->site?->id || ! $this->sameNullableId($signal->client_id, $session->client_id) || (int) $signal->alert_id !== (int) $alert->id) {
            return false;
        }
        foreach (['lone_worker_session_id' => $session->id, 'worker_user_id' => $session->user_id, 'site_id' => $session->site?->id, 'client_id' => $session->client_id] as $key => $expected) {
            if (! array_key_exists($key, $context) || ! $this->sameNullableId($context[$key], $expected)) {
                return false;
            }
        }

        return true;
    }

    private function positiveId(mixed $id): ?int
    {
        return is_int($id) && $id > 0 ? $id : (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1 && (string) (int) $id === $id ? (int) $id : null);
    }

    private function sameNullableId(mixed $actual, mixed $expected): bool
    {
        return $expected === null ? $actual === null : $this->positiveId($actual) === $this->positiveId($expected) && $this->positiveId($expected) !== null;
    }
}
