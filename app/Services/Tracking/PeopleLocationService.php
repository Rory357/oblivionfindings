<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\FleetResidentTransport;
use App\Models\FleetTelemetryEvent;
use App\Models\LoneWorkerSession;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\ControlRoom\AlertWorklistPresenter;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\Fleet\BoundaryService;
use App\Services\Fleet\ResidentTransportJourneyScope;
use App\Services\Fleet\VehicleGeofenceRules;
use App\Services\HealthSafety\LoneWorkerSessionAccessService;
use App\Services\HealthSafety\ShiftGpsAccessService;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only composition of canonical person, consent, session and response records. */
final class PeopleLocationService
{
    public const SAMPLE_LIMIT = 500;

    public function mayEnter(User $actor): bool
    {
        $actor->loadMissing(['roles.permissions', 'permissionOverrides']);

        return $actor->canDo('assets.telemetry.view')
            && ($actor->canDo('clients.viewAny') || $actor->canDo('clients.viewAssigned') || $actor->canDo('hazards.manage'));
    }

    public function workspace(User $actor, array $filters, string $returnTo, string $view = 'map'): array
    {
        abort_unless($this->mayEnter($actor), 403);
        $people = collect();
        $sites = collect();
        // Limit the candidate graph by approved Sites before reading any evidence.
        if ($filters['population'] !== 'staff') {
            $query = app(UserSiteAccessService::class)->applyClientScope(Client::query(), $actor, ['clinical.accessAllSites', 'sites.viewAll']);
            $query->whereIn('id', DeviceAssignment::query()->current()->where('assignable_type', DeviceAssignment::TARGET_CLIENT)->select('assignable_id'))
                ->with('site:id,name')->orderBy('id')->chunkById(100, function ($clients) use ($actor, $filters, &$people, &$sites): void {
                    $privacy = app(PersonalTrackingPrivacyService::class);
                    $before = $privacy->authorisedClientAssignmentsForClients($clients->modelKeys())->groupBy('assignable_id');
                    $rows = collect();
                    $batchSites = collect();
                    foreach ($clients as $client) {
                        $assignments = $before->get($client->id, collect());
                        if ($assignments->isEmpty() || ! Gate::forUser($actor)->allows('view', $client)
                            || ! app(ClientProfileSectionAccess::class)->canViewTracking($actor, $client)) {
                            continue;
                        }
                        $batchSites->push(['id' => $client->site_id, 'name' => $client->site?->name ?? 'Site']);
                        if ($filters['site'] !== 'all' && (int) $filters['site'] !== (int) $client->site_id) {
                            continue;
                        }
                        $rows->push($this->client($actor, $client, $filters['selected'] === 'c'.$client->id ? $filters['source'] : '', $assignments));
                    }
                    // Re-read authority after the evidence read, once per bounded batch.
                    $currentActor = $actor->fresh();
                    abort_unless($currentActor && $this->mayEnter($currentActor), 403);
                    $after = $privacy->authorisedClientAssignmentsForClients($clients->modelKeys())->groupBy('assignable_id');
                    $currentClients = Client::query()->whereKey($clients->modelKeys())->get()->keyBy('id');
                    $fingerprint = fn ($list) => $list->map(fn ($a) => app(ClientLocationAccessService::class)->fingerprint($a))->all();
                    foreach ($rows as $row) {
                        $id = $row['recordId'];
                        $client = $currentClients->get($id);
                        if ($client && Gate::forUser($currentActor)->allows('view', $client)
                            && app(ClientProfileSectionAccess::class)->canViewTracking($currentActor, $client)
                            && $fingerprint($before->get($id, collect())) === $fingerprint($after->get($id, collect()))) {
                            $people->push($row);
                        }
                    }
                    $allowedIds = $after->keys();
                    $sites = $sites->merge($batchSites->filter(fn ($site) => $currentClients->whereIn('id', $allowedIds)->contains(fn ($c) => (int) $c->site_id === (int) $site['id'] && Gate::forUser($currentActor)->allows('view', $c)
                        && app(ClientProfileSectionAccess::class)->canViewTracking($currentActor, $c))));
                });
        }
        if ($filters['population'] !== 'clients' && $actor->canDo('hazards.manage')) {
            LoneWorkerSession::query()->whereIn('status', ['active', 'overdue', 'emergency'])->whereNull('ended_at')
                ->with(['user', 'site', 'shift.client'])->orderBy('id')->chunkById(100, function ($sessions) use ($actor, &$people): void {
                    foreach ($sessions as $session) {
                        $row = $this->permitted(fn () => $this->staff($actor, $session));
                        if ($row) {
                            $people->push($row);
                        }
                    }
                });
        }
        $people = $people->groupBy('id')->map(function ($sources) use ($filters) {
            $row = $sources->first();
            if ($sources->count() === 1) {
                return $row;
            }
            $chosen = $sources->first(fn ($p) => $p['id'] === $filters['selected'] && $p['sources'][0]['id'] === (string) $filters['source']);

            return [...($chosen ?? [...$row, ...$this->emptyEvidence()]), 'sourceChoiceRequired' => ! $chosen, 'sources' => $sources->flatMap(fn ($p) => $p['sources'])->all()];
        })->values();
        $sites = $sites->merge($people->map(fn ($p) => $p['site']))->unique('id')->sortBy('name')->values();
        $people = $people->when($filters['site'] !== 'all', fn ($rows) => $rows->where('site.id', (int) $filters['site']))->values();
        $selected = $people->firstWhere('id', $filters['selected']);
        $history = null;
        if ($selected && in_array($view, ['history', 'analytics'], true) && $actor->canDo('people_locations.history.view')) {
            $history = $this->permitted(fn () => $this->history($actor, $selected['id'], $filters['source'], $filters['date'], isset($filters['journey']) ? (int) $filters['journey'] : null));
        }
        // Observation/history reads can outlive the original actor's permission cache.
        // Compose independent response evidence and capabilities from current authority.
        $actor = $actor->fresh();
        abort_unless($actor && $this->mayEnter($actor), 403);
        if (! $actor->canDo('people_locations.history.view')) {
            $history = null;
        }
        $alerts = $this->alerts($actor, $people, $returnTo);

        $boundaries = $view === 'map' ? ($this->permitted(fn () => $this->boundaries($actor, $people)) ?? []) : [];

        return ['preferences' => app(PeopleLocationPreferences::class)->read($actor), 'boundaries' => $boundaries, 'people' => $people->all(), 'sites' => $sites->all(), 'history' => $history,
            'alerts' => $alerts, 'canReadAlerts' => app(ControlRoomAlertAccessService::class)->canRead($actor),
            'canViewHistory' => $actor->canDo('people_locations.history.view'),
            'canExport' => $actor->canDo('people_locations.history.view') && $actor->canDo('people_locations.export'), 'checkedAt' => now()->toISOString(),
            'staffAvailable' => $actor->canDo('hazards.manage'), 'filters' => $filters];
    }

    private function authorisedAssignments(User $actor, Client $client): Collection
    {
        $actor->loadMissing(['roles.permissions', 'permissionOverrides']);
        Gate::forUser($actor)->authorize('view', $client);
        abort_unless(app(ClientProfileSectionAccess::class)->canViewTracking($actor, $client), 403);
        $assignments = app(PersonalTrackingPrivacyService::class)->authorisedClientAssignments($client);
        abort_if($assignments->isEmpty(), 403);

        return $assignments;
    }

    private function client(User $actor, Client $client, string $source = '', ?Collection $assignments = null): array
    {
        $assignments ??= $this->authorisedAssignments($actor, $client);
        $sources = $assignments->map(fn ($a) => $this->source($a))->all();
        // Multiple sources require an explicit choice; do not silently prefer a Device.
        $selected = $source !== '' ? $assignments->firstWhere('id', (int) $source) : ($assignments->count() === 1 ? $assignments->first() : null);
        $evidence = $selected ? $this->evidence($selected, null, false) : $this->emptyEvidence();

        return ['id' => 'c'.$client->id, 'recordId' => $client->id, 'kind' => 'client', 'name' => $client->full_name,
            'reference' => 'Client '.$client->id, 'site' => ['id' => $client->site_id, 'name' => $client->site?->name ?? 'Site'],
            'authority' => 'Consent and assignment current', 'sourceChoiceRequired' => ! $selected && $assignments->count() > 1, 'sources' => $sources, ...$evidence,
            'profileUrl' => '/operations/clients/'.$client->id.'?tab=location',
            'transportUrl' => '/operations/clients/'.$client->id.'?tab=transport'];
    }

    private function source(DeviceAssignment $assignment): array
    {
        return ['id' => (string) $assignment->id, 'label' => $assignment->device->name ?: 'Tracker '.$assignment->device_id,
            'reference' => 'Device '.$assignment->device_id, 'retentionDays' => $assignment->retention_days,
            'purpose' => $assignment->tracking_purpose];
    }

    private function emptyEvidence(): array
    {
        return ['position' => null, 'positionState' => 'unknown', 'battery' => null, 'batteryAt' => null,
            'power' => 'unknown', 'powerAt' => null, 'motion' => 'unknown', 'motionAt' => null, 'contactAt' => null];
    }

    private function bounds(DeviceAssignment $assignment, ?string $date, ?array $journey = null): array
    {
        $from = collect([CarbonImmutable::now()->subDays((int) $assignment->retention_days),
            CarbonImmutable::parse($assignment->assigned_at), CarbonImmutable::parse($assignment->collection_started_at),
            CarbonImmutable::parse($assignment->consent->given_at)])->max();
        $to = CarbonImmutable::now();
        if ($date) {
            $from = $from->max(CarbonImmutable::parse($date, 'Pacific/Auckland')->startOfDay());
            $to = $to->min(CarbonImmutable::parse($date, 'Pacific/Auckland')->addDay()->startOfDay()->subMicrosecond());
        }

        if ($journey) {
            $from = $from->max(CarbonImmutable::parse($journey['departedAt']));
            $to = $to->min($journey['arrivedAt'] ? CarbonImmutable::parse($journey['arrivedAt']) : CarbonImmutable::now());
        }

        return [$from->utc(), $to->utc()];
    }

    private function evidence(DeviceAssignment $assignment, ?string $date, bool $includeHistory, ?array $journey = null): array
    {
        [$from, $to] = $this->bounds($assignment, $date, $journey);
        $events = $from->greaterThan($to) ? collect() : FleetTelemetryEvent::query()
            ->where('device_id', $assignment->device_id)->where('consent_blocked', false)->where('occurred_at', '>=', $from)->where('occurred_at', '<=', $to)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::SAMPLE_LIMIT + 1)->get();
        $truncated = $events->count() > self::SAMPLE_LIMIT;
        $events = $events->take(self::SAMPLE_LIMIT);
        $positionWindow = $from->greaterThan($to) ? ['positions' => collect(), 'truncated' => false]
            : app(IntegrationEventHistoryService::class)->forDeviceWindow($assignment->device,
                ['date_from' => $from->toDateTimeString(), 'date_to' => $to->toDateTimeString()], (int) $assignment->retention_days);
        $positions = $positionWindow['positions']->filter(fn ($p) => $this->validPoint($p)
            && CarbonImmutable::parse($p['timestamp'])->betweenIncluded($from, $to))->values();
        $meta = $assignment->device->meta ?? [];
        $projected = ['lat' => $meta['lat'] ?? $meta['latitude'] ?? null, 'lng' => $meta['lng'] ?? $meta['longitude'] ?? null,
            'timestamp' => $meta['last_location_at'] ?? null, 'accuracy' => $meta['accuracy'] ?? null];
        if ($this->validPoint($projected) && CarbonImmutable::parse($projected['timestamp'])->betweenIncluded($from, $to)
            && is_numeric($assignment->device->latitude) && is_numeric($assignment->device->longitude)
            && abs($projected['lat'] - $assignment->device->latitude) < 0.000001
            && abs($projected['lng'] - $assignment->device->longitude) < 0.000001) {
            $positions->push($projected);
        }
        $positions = $positions->map(fn ($p) => [...$p, 'timestamp' => CarbonImmutable::parse($p['timestamp'])->utc()->toISOString()])->sortByDesc('timestamp')->unique(fn ($p) => $p['timestamp'].'|'.$p['lat'].'|'.$p['lng'])->map(fn ($p) => [
            'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'timestamp' => $p['timestamp'],
            'accuracy' => isset($p['accuracy']) && is_numeric($p['accuracy']) && $p['accuracy'] >= 0 ? (float) $p['accuracy'] : null,
        ])->values();
        $truncated = $truncated || $positionWindow['truncated'] || $positions->count() > self::SAMPLE_LIMIT;
        $positions = $positions->take(self::SAMPLE_LIMIT);
        $position = $positions->first();
        $battery = $events->first(fn ($e) => $e->battery_pct !== null && $e->battery_pct >= 0 && $e->battery_pct <= 100);
        $power = $events->first(fn ($e) => $this->power($e) !== 'unknown');
        $motion = $events->first(fn ($e) => in_array($e->motion_status, ['moving', 'motion', 'stationary', 'rest'], true));
        $contact = $assignment->device->last_seen_at;
        $result = ['position' => $position, 'positionState' => $position ? (CarbonImmutable::parse($position['timestamp'])->lt(now()->subMinutes(15)) ? 'stale' : 'recent') : 'unknown',
            'battery' => $battery?->battery_pct, 'batteryAt' => $battery?->occurred_at->toISOString(),
            'power' => $power ? $this->power($power) : 'unknown', 'powerAt' => $power?->occurred_at->toISOString(),
            'motion' => $motion ? (in_array($motion->motion_status, ['moving', 'motion']) ? 'moving' : 'stationary') : 'unknown',
            'motionAt' => $motion?->occurred_at->toISOString(), 'contactAt' => $contact?->betweenIncluded($from, $to) ? $contact->toISOString() : null];
        if ($includeHistory) {
            $result += ['positions' => $positions->all(),
                'samples' => $events->reverse()->map(fn ($e) => ['at' => $e->occurred_at->toISOString(),
                    'battery' => $e->battery_pct !== null && $e->battery_pct >= 0 && $e->battery_pct <= 100 ? $e->battery_pct : null,
                    'power' => $this->power($e), 'motion' => $e->motion_status, 'event' => $e->event_type])->values()->all(),
                'truncated' => $truncated,
                'window' => ['from' => $from->toISOString(), 'to' => $to->toISOString(), 'timezone' => 'Pacific/Auckland']];
        }

        return $result;
    }

    private function power(FleetTelemetryEvent $event): string
    {
        // external_power defaults to false in legacy storage; it is not an observation.
        $value = data_get($event->raw_payload, 'charging_status');

        return match ($value) {
            'charging' => 'charging', 'charge_full' => 'full', 'stopped_charging' => 'not_charging',
            default => data_get($event->raw_payload, 'external_power') === true ? 'external' : 'unknown',
        };
    }

    private function validPoint(array $point): bool
    {
        try {
            return is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null)
                && abs((float) $point['lat']) <= 90 && abs((float) $point['lng']) <= 180
                && is_string($point['timestamp'] ?? null) && trim($point['timestamp']) !== ''
                && CarbonImmutable::parse($point['timestamp'])->isValid();
        } catch (\Throwable) {
            return false;
        }
    }

    private function staff(User $actor, LoneWorkerSession $session): array
    {
        abort_unless($actor->canDo('hazards.manage') && $actor->canDo('assets.telemetry.view'), 403);
        app(LoneWorkerSessionAccessService::class)->assertCanAccessSession($actor, $session);
        abort_unless(in_array($session->status, ['active', 'overdue', 'emergency']) && ! $session->ended_at
            && $session->started_at && $session->started_at->lte(now()), 403);
        $point = null;
        if ($session->shift) {
            $ping = app(ShiftGpsAccessService::class)->latestForLiveSession($actor, $session->shift, $session);
            if ($ping) {
                $point = ['lat' => (float) $ping->latitude, 'lng' => (float) $ping->longitude,
                    'timestamp' => $ping->captured_at->toISOString(), 'accuracy' => $ping->accuracy];
            }
        }
        $fresh = $session->fresh();
        $currentActor = $actor->fresh();
        abort_unless($fresh && $currentActor?->canDo('hazards.manage') && $currentActor->canDo('assets.telemetry.view')
            && ! $fresh->ended_at && in_array($fresh->status, ['active', 'overdue', 'emergency']), 403);
        abort_unless(collect(['user_id', 'site_id', 'shift_id', 'client_id', 'started_at'])->every(fn ($key) => $fresh->getRawOriginal($key) === $session->getRawOriginal($key)), 403);
        app(LoneWorkerSessionAccessService::class)->assertCanAccessSession($currentActor, $fresh);
        $siteId = $session->site_id ?? $session->client?->site_id ?? $session->shift?->site_id ?? $session->shift?->client?->site_id;

        return ['id' => 's'.$session->user_id, 'recordId' => $session->user_id, 'kind' => 'staff', 'name' => $session->user->name,
            'reference' => 'Session '.$session->id, 'site' => ['id' => (int) $siteId, 'name' => Site::find($siteId)?->name ?? 'Site'],
            'authority' => 'Live safety session', 'sources' => [['id' => (string) $session->id, 'label' => 'Safety session '.$session->id,
                'reference' => 'Session '.$session->id, 'retentionDays' => null, 'purpose' => 'Worker safety']],
            ...$this->emptyEvidence(), 'position' => $point, 'positionState' => $point ? 'recent' : 'unknown',
            'contactAt' => $session->last_check_in_at?->toISOString(),
            'profileUrl' => '/health-safety/lone-workers?session='.$session->id, 'transportUrl' => null];
    }

    public function history(User $actor, string $person, string $source, string $date, ?int $journeyId = null): array
    {
        abort_unless($this->mayEnter($actor) && $actor->canDo('people_locations.history.view'), 403);
        abort_unless(preg_match('/^c([1-9][0-9]*)$/', $person, $match), 403, 'Staff location history is held in the authorised safety session.');
        $client = Client::query()->findOrFail((int) $match[1]);
        $assignments = $this->authorisedAssignments($actor, $client);
        $assignment = $source !== '' ? $assignments->firstWhere('id', (int) $source) : ($assignments->count() === 1 ? $assignments->first() : null);
        if (! $assignment) {
            return ['needsSource' => true, 'personId' => $person];
        }
        $fingerprint = app(ClientLocationAccessService::class)->fingerprint($assignment);
        $journeys = $this->journeys($actor, $client, $date);
        $journey = $journeyId ? collect($journeys)->firstWhere('id', $journeyId) : null;
        abort_if($journeyId && (! $journey || ! $journey['departedAt']), 404);
        $evidence = $this->evidence($assignment, $date, true, $journey);
        $currentActor = $actor->fresh();
        abort_unless($currentActor && $this->mayEnter($currentActor) && $currentActor->canDo('people_locations.history.view'), 403);
        $currentClient = $client->fresh();
        abort_unless($currentClient, 403);
        $fresh = $this->authorisedAssignments($currentActor, $currentClient)->firstWhere('id', $assignment->id);
        abort_unless($fresh && hash_equals($fingerprint, app(ClientLocationAccessService::class)->fingerprint($fresh)), 403);

        // Transport has independent authority and mutable canonical relationships.
        // Day history remains available if Transport access ends, but its choices
        // must be current. A selected journey must still describe the read window.
        $currentJourneys = $this->journeys($currentActor, $currentClient, $date);
        $currentJourney = $journeyId ? collect($currentJourneys)->firstWhere('id', $journeyId) : null;
        abort_if($journeyId && ! $currentJourney, 404);
        abort_if($journeyId && $currentJourney !== $journey, 409, 'The passenger journey changed. Reload the report.');

        return ['needsSource' => false, 'personId' => $person, 'name' => $currentClient->full_name, 'source' => $this->source($fresh),
            'fingerprint' => $fingerprint, ...$evidence, 'journeys' => $currentJourneys, 'journey' => $currentJourney,
            'scope' => $journey ? 'Selected Auckland day within the passenger journey' : 'Selected Auckland day'];
    }

    private function journeys(User $actor, Client $client, string $date): array
    {
        if (! ($actor->canDo('fleet.viewAny') || $actor->canDo('fleet.manage'))) {
            return [];
        }
        $from = CarbonImmutable::parse($date, 'Pacific/Auckland')->startOfDay();

        return app(ResidentTransportJourneyScope::class)->applyTransportScope(FleetResidentTransport::query(), $actor)
            ->where('resident_id', $client->id)->where('departed_at', '<', $from->addDay()->utc())
            ->where(fn ($q) => $q->whereNull('arrived_at')->orWhere('arrived_at', '>=', $from->utc()))
            ->orderBy('departed_at')->get()->map(fn ($j) => ['id' => $j->id, 'reference' => 'Journey '.$j->id,
                'departedAt' => $j->departed_at?->toISOString(), 'arrivedAt' => $j->arrived_at?->toISOString(),
                'accountedAt' => $j->passengers_accounted_at?->toISOString(), 'status' => $j->status,
                'destination' => $j->dropoff_location, 'purpose' => $j->transport_type,
                'href' => '/fleet-assets/transports/'.$j->id])->all();
    }

    private function boundaries(User $actor, Collection $people): array
    {
        $service = app(BoundaryService::class);
        $current = $service->actor($actor);

        // Shared Site geometry only. Personal/asset-bound geometry stays in its owning record.
        return $service->permitted($current)->whereNull('retired_at')->whereNull('asset_id')
            ->whereIn('site_id', $people->pluck('site.id')->unique())->whereIn('scope', ['site', 'house'])
            ->orderBy('id')->get()->map(fn ($boundary) => [
                'id' => $boundary->id, 'name' => $boundary->name,
                'geometry' => VehicleGeofenceRules::fromBoundary($boundary),
                'revision' => $boundary->revision, 'href' => '/fleet-assets/geofences?tab=boundaries&selected='.$boundary->id,
            ])->filter(fn ($b) => $b['geometry'] !== null)->values()->all();
    }

    private function alerts(User $actor, Collection $people, string $returnTo): array
    {
        $access = app(ControlRoomAlertAccessService::class);
        if (! $access->canRead($actor) || $people->isEmpty()) {
            return [];
        }
        $clients = $people->where('kind', 'client')->pluck('recordId');
        $sessionPeople = $people->where('kind', 'staff')->mapWithKeys(fn ($p) => collect($p['sources'])->mapWithKeys(fn ($s) => [(int) $s['id'] => $p['id']])->all());
        $sessions = $sessionPeople->keys();

        return $access->applyReadableScope(ControlRoomAlert::query(), $actor)
            ->where(function ($q) use ($clients, $sessions) {
                $q->whereIn('client_id', $clients)->whereIn('source', ['personal_tracker', 'client_zone', 'fleet'])
                    ->orWhere(function ($staff) use ($sessions) {
                        $staff->whereIn('context->normalized_data->lone_worker_session_id', $sessions)
                            ->where('source', 'lone_worker');
                    });
            })->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
            ->orderBy('due_at')->get()->map(function ($alert) use ($actor, $sessionPeople, $access, $returnTo) {
                $row = app(AlertWorklistPresenter::class)->present($alert, $actor);

                return ['id' => $alert->id, 'reference' => $alert->reference_number ?: 'Alert '.$alert->id,
                    'personId' => $alert->source === 'lone_worker' ? $sessionPeople->get((int) data_get($alert->context, 'normalized_data.lone_worker_session_id')) : 'c'.$alert->client_id,
                    'type' => $alert->alert_type, 'status' => $alert->status, 'severity' => $alert->severity,
                    'owner' => $row['assignee']['name'] ?? null, 'dueAt' => $row['next_deadline_at'], 'triggeredAt' => $row['triggered_at'],
                    'nextAction' => $row['next_action']['label'],
                    'href' => $access->destinationForScopedAlert($alert, $actor, $returnTo)['href']];
            })->all();
    }

    private function permitted(callable $read): mixed
    {
        try {
            return $read();
        } catch (AuthorizationException) {
            return null;
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404])) {
                return null;
            }
            throw $error; // A failed upstream read must not masquerade as zero records.
        }
    }
}
