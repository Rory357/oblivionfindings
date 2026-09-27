<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\SiteGeocodingController;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AssetTelemetrySnapshot;
use App\Models\BoundaryVersion;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetVehicleGeofenceAssignment as Assignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\Fleet\BoundaryRuleService;
use App\Services\Fleet\BoundaryService;
use App\Services\Fleet\VehicleGeofenceRules;
use App\Services\Fleet\VehicleLocationService;
use App\Services\Tracking\BoundaryHandoffService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class GeofenceController extends Controller
{
    public function __construct(private readonly BoundaryService $boundaries) {}

    public function index(Request $request)
    {
        $actor = $this->boundaries->actor($request->user());

        return Inertia::render('fleet-assets/geofences/index', [
            'canManage' => $this->boundaries->canManage($actor), 'addressSearch' => $this->addressCapabilities(),
            'mapProvider' => ['url' => config('fleet.maps.boundary_tile_url'), 'attribution' => config('fleet.maps.boundary_tile_attribution')],
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function catalogue(Request $request)
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'site_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:available,retired,all'], 'use' => ['nullable', 'in:Vehicles,Assets'],
            'sort' => ['nullable', 'in:name,updated'], 'page' => ['nullable', 'integer', 'min:1'],
            'size' => ['nullable', 'integer', 'min:1', 'max:300']]);
        $actor = $this->boundaries->actor($request->user());
        $query = $this->boundaries->permitted($actor)->with('site:id,name')->withExists('assignedAssets');
        $this->search($query, $input['q'] ?? '', ['name', 'address']);
        if (! empty($input['site_id'])) {
            $query->where('site_id', $input['site_id']);
        }
        if (($input['status'] ?? 'available') === 'available') {
            $query->whereNull('retired_at');
        }
        if (($input['status'] ?? '') === 'retired') {
            $query->whereNotNull('retired_at');
        }
        if (! empty($input['use'])) {
            $query->where(fn ($q) => $q->whereNull('permitted_uses')->orWhereJsonContains('permitted_uses', $input['use']));
        }
        ($input['sort'] ?? '') === 'updated' ? $query->orderByDesc('updated_at') : $query->orderBy('name');
        $page = $query->orderBy('id')->paginate($input['size'] ?? 12);

        return $this->json(['data' => $page->getCollection()->map(fn ($b) => $this->boundaries->present($b)),
            'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function show(Request $request, AssetGeofence $geofence)
    {
        $b = $this->boundaries->resolve($this->boundaries->actor($request->user()), $geofence->id);

        return $this->json(['boundary' => $this->boundaries->present($b), 'impact' => $this->boundaries->impact($b)]);
    }

    public function sites(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:120'], 'id' => ['nullable', 'integer']]);
        $query = $this->boundaries->access->accessibleSites($this->boundaries->actor($request->user()));
        if ($request->filled('id')) {
            $query->whereKey($request->integer('id'));
        }
        $this->search($query, (string) $request->query('q', ''), ['name', 'address_line_1']);

        return $this->json(['data' => $query->orderBy('name')->orderBy('id')->limit(20)
            ->get(['id', 'name', 'address_line_1', 'suburb', 'city', 'latitude', 'longitude'])]);
    }

    public function handoff(Request $request, string $token, BoundaryHandoffService $handoffs)
    {
        $actor = $this->boundaries->actor($request->user());
        $h = $handoffs->resolve($actor, $token);
        $site = $this->boundaries->access->accessibleSites($actor)->whereKey($h->site_id)
            ->firstOrFail(['id', 'name', 'address_line_1', 'suburb', 'city', 'latitude', 'longitude']);

        return $this->json(['site' => $site, 'expires_at' => $h->expires_at, 'ready' => $h->boundary_id !== null]);
    }

    public function returnBoundary(Request $request, string $token, BoundaryHandoffService $handoffs)
    {
        $actor = $this->boundaries->actor($request->user());
        $data = $request->validate(['boundary_id' => ['required', 'integer'], 'revision' => ['required', 'integer']]);
        $this->boundaries->resolve($actor, $data['boundary_id']);

        return $this->json($handoffs->select($actor, $token, $data['boundary_id'], $data['revision']));
    }

    private function addressCapabilities(): array
    {
        $host = strtolower((string) parse_url((string) config('fleet.maps.address_search_endpoint'), PHP_URL_HOST));
        $public = $host === 'openstreetmap.org' || str_ends_with($host, '.openstreetmap.org');
        $autocomplete = (bool) config('fleet.maps.address_search_autocomplete', false);

        // Public Nominatim permits submitted searches, never type-ahead.
        // Fail closed if the deployment accidentally enables that combination.
        return ['enabled' => (bool) config('fleet.maps.boundary_address_search_enabled', false) && (! $public || ! $autocomplete),
            'autocomplete' => $autocomplete && ! $public,
            'attribution' => $public ? '© OpenStreetMap contributors · Nominatim' : (string) config('fleet.maps.address_search_attribution', 'Address provider'),
            'attribution_url' => $public ? 'https://www.openstreetmap.org/copyright' : null];
    }

    public function addresses(Request $request, SiteGeocodingController $geocoder)
    {
        $actor = $this->boundaries->actor($request->user(), true);
        abort_unless($this->addressCapabilities()['enabled'], 503, 'An approved address provider is not enabled. Choose a site or enter coordinates.');
        try {
            $response = $geocoder->search($request, true);
        } catch (\RuntimeException) {
            abort(503, 'Address search is unavailable. Retry or enter the position manually.');
        }
        $this->boundaries->actor($actor, true);

        return $response->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function resources(Request $request, VehicleLocationService $locations)
    {
        $data = $request->validate(['id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:120'], 'kind' => ['nullable', 'in:Vehicle,Asset'],
            'site_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'], 'size' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $actor = $this->boundaries->actor($request->user());
        $query = $this->boundaries->resources($actor)->with(['site:id,name', 'homeSite:id,name', 'categoryRef:id,slug']);
        if ($request->filled('id')) {
            $query->whereKey($request->integer('id'));
        }
        $this->search($query, $data['q'] ?? '', ['name', 'asset_tag', 'registration_number']);
        if (($data['kind'] ?? '') === 'Vehicle') {
            $query->vehicles();
        }
        if (($data['kind'] ?? '') === 'Asset') {
            $query->whereNotIn('id', Asset::query()->vehicles()->select('id'));
        }
        if (! empty($data['site_id'])) {
            $query->where(fn ($q) => $q->where('site_id', $data['site_id'])->orWhere(fn ($x) => $x->whereNull('site_id')->where('home_site_id', $data['site_id'])));
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate($data['size'] ?? 8);
        $ids = $page->getCollection()->pluck('id');
        $links = Assignment::query()->active()->whereIn('asset_id', $ids)
            ->whereIn('geofence_id', $this->boundaries->permitted($actor)->select('asset_geofences.id'))->get(['asset_id', 'geofence_id']);
        $legacy = DB::table('asset_geofence_assignments')->whereIn('asset_id', $ids)
            ->whereIn('asset_geofence_id', $this->boundaries->permitted($actor)->select('asset_geofences.id'))->get();
        $direct = $this->boundaries->permitted($actor)->whereIn('asset_id', $ids)->get(['id', 'asset_id']);
        $vehicles = $page->getCollection()->filter(fn ($asset) => strtolower((string) $asset->category) === 'vehicle' || $asset->categoryRef?->slug === 'vehicle');
        $states = $actor->canDo('fleet.viewAny') ? $locations->mapStates($actor, $vehicles) : [];
        $rows = $page->getCollection()->map(function (Asset $asset) use ($actor, $states, $links, $legacy, $direct): array {
            $vehicle = strtolower((string) $asset->category) === 'vehicle' || $asset->categoryRef?->slug === 'vehicle';
            $state = $vehicle && $asset->client_id === null ? ($states[$asset->id] ?? null) : null;
            // Client-owned assets are only located in the consent-aware Client Location workspace.
            if (! $vehicle && $asset->client_id === null && $actor->canDo('assets.telemetry.view')) {
                $device = $this->boundaries->access->visibleDevices($actor)
                    ->whereHas('activeAssetLinks', fn ($q) => $q->where('asset_id', $asset->id))
                    ->whereDoesntHave('activeAssetLinks', fn ($q) => $q->where('asset_id', '!=', $asset->id))
                    ->whereDoesntHave('assignments', fn ($q) => $q->active()->whereIn('assignable_type', ['client', 'staff']))
                    ->whereIn('subcategory', ['gps_tracker', 'asset_tracker', 'vehicle_tracker'])
                    ->orderByDesc('last_seen_at')->first();
                if ($device) {
                    $link = $device->activeAssetLinks()->where('asset_id', $asset->id)->first();
                    // Installation coordinates and device heartbeat times are not position evidence.
                    $observation = $link?->linked_at ? AssetTelemetrySnapshot::query()->where('asset_id', $asset->id)
                        ->where('device_id', $device->id)->where('occurred_at', '>=', $link->linked_at)
                        ->orderByDesc('occurred_at')->orderByDesc('id')->first() : null;
                    if ($observation && ! $observation->consent_blocked) {
                        $state = ['lat' => $observation->latitude, 'lng' => $observation->longitude,
                            'accuracy_m' => $observation->accuracy_m, 'observed_at' => $observation->occurred_at?->toIso8601String(),
                            'received_at' => $observation->received_at?->toIso8601String(),
                            'fresh' => $observation->occurred_at?->between(now()->subMinutes(VehicleLocationService::FRESH_MINUTES), now()) ?? false];
                    }
                }
            }
            $linked = $links->where('asset_id', $asset->id)->pluck('geofence_id')
                ->merge($legacy->where('asset_id', $asset->id)->pluck('asset_geofence_id'))
                ->merge($direct->where('asset_id', $asset->id)->pluck('id'))->unique()->values();

            return ['id' => $asset->id, 'name' => $asset->name, 'tag' => $asset->registration_number ?: $asset->asset_tag,
                'kind' => $vehicle ? 'Vehicle' : 'Asset', 'site' => $asset->site?->name ?? $asset->homeSite?->name,
                'position' => $state && $state['lat'] !== null && $state['lng'] !== null ? ['lat' => (float) $state['lat'], 'lng' => (float) $state['lng']] : null,
                'observed_at' => $state['observed_at'] ?? null, 'received_at' => $state['received_at'] ?? null,
                'fresh' => $state['fresh'] ?? false, 'accuracy_m' => isset($state['accuracy_m']) ? (float) $state['accuracy_m'] : null,
                'boundary_ids' => $linked, 'href' => $vehicle ? '/fleet-assets/vehicles/'.$asset->id.'?tab=map' : '/fleet-assets/assets/'.$asset->id];
        });

        return $this->json(['data' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'as_of' => now()->toIso8601String()]);
    }

    public function rules(Request $request)
    {
        $request->validate(['id' => ['nullable', 'integer'], 'review' => ['nullable', 'boolean'], 'site_id' => ['nullable', 'integer'], 'boundary_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->boundaries->actor($request->user());
        $query = Assignment::query()->active()->whereIn('asset_id', $this->boundaries->resources($actor)->select('assets.id'))
            ->whereIn('geofence_id', $this->boundaries->permitted($actor)->select('asset_geofences.id'))->with(['geofence', 'asset.categoryRef']);
        if ($request->filled('boundary_id')) {
            $query->where('geofence_id', $request->integer('boundary_id'));
        }
        if ($request->filled('id')) {
            $query->whereKey($request->integer('id'));
        }
        if ($request->filled('site_id')) {
            $query->whereHas('geofence', fn ($q) => $q->where('site_id', $request->integer('site_id')));
        }
        $this->search($query, (string) $request->query('q', ''), ['label', 'purpose']);
        if ($request->boolean('review')) {
            $query->whereIn('id', $this->reviewIds(clone $query));
        }
        $page = $query->orderBy('label')->orderBy('id')->paginate(20);

        return $this->json(['data' => $page->getCollection()->map(fn ($r) => [
            'id' => $r->id, 'asset_id' => $r->asset_id, 'resource' => $r->asset->name, 'boundary_id' => $r->geofence_id,
            'label' => $r->label, 'purpose' => $r->purpose, 'response_proposal' => $r->response_proposal,
            'schedule' => $r->schedule, 'policy' => $r->policy_proposal, 'revision' => $r->lock_version,
            'geometry' => $r->geometry_snapshot, 'monitoring' => $r->monitoring,
            'source_changed' => $this->needsReview($r),
        ]), 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    private function needsReview(Assignment $rule): bool
    {
        $kind = strtolower((string) $rule->asset->category) === 'vehicle' || $rule->asset->categoryRef?->slug === 'vehicle' ? 'Vehicles' : 'Assets';

        return $rule->geofence->retired_at !== null
            || ! in_array($kind, $rule->geofence->permitted_uses ?? ['Vehicles', 'Assets'], true)
            || ! hash_equals(VehicleGeofenceRules::boundaryHash($rule->geofence), (string) $rule->geometry_hash);
    }

    private function reviewIds(Builder $query): array
    {
        $ids = [];
        $query->with(['geofence', 'asset.categoryRef'])->chunkById(200, function ($rules) use (&$ids): void {
            foreach ($rules as $rule) {
                if ($this->needsReview($rule)) {
                    $ids[] = $rule->id;
                }
            }
        });

        return $ids;
    }

    public function summary(Request $request)
    {
        $request->validate(['site_id' => ['nullable', 'integer']]);
        $actor = $this->boundaries->actor($request->user());
        $areas = $this->boundaries->permitted($actor);
        if ($request->filled('site_id')) {
            $areas->where('site_id', $request->integer('site_id'));
        }
        $rules = Assignment::query()->active()
            ->whereIn('asset_id', $this->boundaries->resources($actor)->select('assets.id'))
            ->whereIn('geofence_id', (clone $areas)->select('asset_geofences.id'));
        $followUp = app(ControlRoomAlertAccessService::class)->applyReadableScope(ControlRoomAlert::query(), $actor)
            ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
            ->whereIn('fleet_signal_id', $this->eventQuery($request, $actor)->select('fleet_signals.id'));

        return $this->json(['boundaries' => (clone $areas)->whereNull('retired_at')->count(),
            'rules' => (clone $rules)->count(), 'review' => count($this->reviewIds($rules)),
            'follow_up' => $followUp->distinct()->count('fleet_signal_id')]);
    }

    private function historyQuery(Request $request, AssetGeofence $b): Builder
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'category' => ['nullable', 'in:created,baseline,geometry,details,retired'],
            'actor' => ['nullable', 'string', 'max:120'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1']]);
        $q = BoundaryVersion::query()->where('boundary_id', $b->id)->with('actor:id,name');
        $this->search($q, $input['q'] ?? '', ['reason']);
        if (! empty($input['category'])) {
            $q->where('category', $input['category']);
        }
        if (! empty($input['actor'])) {
            $q->whereHas('actor', fn ($u) => $u->where('name', 'like', '%'.addcslashes($input['actor'], '%_\\').'%'));
        }
        if (! empty($input['from'])) {
            $q->where('recorded_at', '>=', CarbonImmutable::parse($input['from'], 'Pacific/Auckland')->startOfDay()->utc());
        }
        if (! empty($input['to'])) {
            $q->where('recorded_at', '<', CarbonImmutable::parse($input['to'], 'Pacific/Auckland')->addDay()->startOfDay()->utc());
        }

        return $q->orderByDesc('revision');
    }

    public function history(Request $request, AssetGeofence $geofence)
    {
        $actor = $this->boundaries->actor($request->user());
        $b = $this->boundaries->resolve($actor, $geofence->id);
        $page = $this->historyQuery($request, $b)->paginate(20);

        return $this->json(['data' => $page->getCollection(), 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'current' => $this->boundaries->present($b), 'impact' => $this->boundaries->impact($b),
            'notice' => 'History starts at the first retained snapshot. Older shapes and actors are not reconstructed. Dependencies shown are current.']);
    }

    public function version(Request $request, AssetGeofence $geofence, int $revision)
    {
        $b = $this->boundaries->resolve($this->boundaries->actor($request->user()), $geofence->id);

        return $this->json(['version' => BoundaryVersion::query()->with('actor:id,name')->where('boundary_id', $b->id)->where('revision', $revision)->firstOrFail()]);
    }

    public function ruleHistory(Request $request, AssetGeofence $geofence)
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'actor' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->boundaries->actor($request->user());
        $b = $this->boundaries->resolve($actor, $geofence->id);
        $query = DB::table('boundary_rule_versions as v')->join('fleet_vehicle_geofence_assignments as r', 'r.id', '=', 'v.assignment_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.actor_id')->where('v.boundary_id', $b->id)
            ->whereIn('r.asset_id', $this->boundaries->resources($actor)->select('assets.id'));
        if (! empty($input['q'])) {
            $query->where('v.reason', 'like', '%'.addcslashes($input['q'], '%_\\').'%');
        }
        if (! empty($input['actor'])) {
            $query->where('u.name', 'like', '%'.addcslashes($input['actor'], '%_\\').'%');
        }
        if (! empty($input['from'])) {
            $query->where('v.recorded_at', '>=', CarbonImmutable::parse($input['from'], 'Pacific/Auckland')->startOfDay()->utc());
        }
        if (! empty($input['to'])) {
            $query->where('v.recorded_at', '<', CarbonImmutable::parse($input['to'], 'Pacific/Auckland')->addDay()->startOfDay()->utc());
        }
        $query->select('v.*', 'u.name as actor')->orderByDesc('v.recorded_at')->orderByDesc('v.id');
        $present = function ($row) {
            $row->snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $row->recorded_at = CarbonImmutable::parse($row->recorded_at, 'UTC')->toIso8601String();

            return $row;
        };
        if ($request->boolean('export')) {
            $this->boundaries->actor($actor, true);
            $entries = $query->limit(5001)->get()->map($present);
            abort_if($entries->count() > 5000, 422, 'Narrow the date range to export at most 5,000 entries.');
            $this->boundaries->resolve($this->boundaries->actor($actor, true), $b->id);
            AuditLogger::logOrFail('fleet.boundary.rules.export', $b, ['actor_id' => $actor->id, 'records' => $entries->count()]);

            return response()->streamDownload(function () use ($b, $entries): void {
                echo json_encode(['boundary_id' => $b->id, 'exported_at' => now()->toIso8601String(), 'timezone' => 'Pacific/Auckland',
                    'limit' => 'Retained permitted vehicle and asset purpose rules only. Person-specific evidence stays with its authorised owner.', 'entries' => $entries], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            }, 'boundary-'.$b->id.'-rule-history.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private']);
        }
        $page = $query->paginate(20);
        $rows = $page->getCollection()->map($present);

        return $this->json(['data' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function export(Request $request, AssetGeofence $geofence)
    {
        $actor = $this->boundaries->actor($request->user(), true);
        $b = $this->boundaries->resolve($actor, $geofence->id);
        $rows = $this->historyQuery($request, $b)->limit(5001)->get();
        abort_if($rows->count() > 5000, 422, 'Narrow the date range to export at most 5,000 entries.');
        $this->boundaries->resolve($this->boundaries->actor($actor, true), $b->id);
        AuditLogger::logOrFail('fleet.boundary.history.export', $b, ['actor_id' => $actor->id, 'records' => $rows->count()]);

        return response()->streamDownload(function () use ($b, $rows): void {
            echo json_encode(['boundary_id' => $b->id, 'exported_at' => now()->toIso8601String(),
                'timezone' => 'Pacific/Auckland', 'limit' => 'Retained boundary snapshots only; no reconstructed history or personal records.', 'entries' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }, 'boundary-'.$b->id.'-history.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private']);
    }

    private function eventQuery(Request $request, User $actor): Builder
    {
        $query = FleetSignal::query()->whereIn('asset_id', $this->boundaries->resources($actor)->whereNull('client_id')->select('assets.id'))
            ->whereIn('geofence_id', $this->boundaries->permitted($actor)->select('asset_geofences.id'))
            ->whereDoesntHave('trip', fn ($q) => $q->where('is_personal', true)->orWhere('consent_blocked', true))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fleet_trips')
                ->whereColumn('fleet_trips.asset_id', 'fleet_signals.asset_id')
                ->where(fn ($t) => $t->where('is_personal', true)->orWhere('consent_blocked', true))
                ->whereColumn('fleet_trips.started_at', '<=', 'fleet_signals.occurred_at')
                ->where(fn ($t) => $t->whereNull('fleet_trips.ended_at')->orWhereColumn('fleet_trips.ended_at', '>=', 'fleet_signals.occurred_at')))
            ->with(['asset:id,name', 'outbox']);
        if ($request->filled('boundary_id')) {
            $query->where('geofence_id', $request->integer('boundary_id'));
        }
        if ($request->filled('site_id')) {
            $query->whereHas('geofence', fn ($q) => $q->where('site_id', $request->integer('site_id')));
        }
        if ($request->filled('q')) {
            $term = '%'.addcslashes((string) $request->query('q'), '%_\\').'%';
            $query->where(fn ($q) => $q->where('signal_type', 'like', $term)->orWhereHas('asset', fn ($a) => $a->where('name', 'like', $term)));
        }

        return $query;
    }

    public function events(Request $request)
    {
        $actor = $this->boundaries->actor($request->user());
        $request->validate(['q' => ['nullable', 'string', 'max:120'], 'follow_up' => ['nullable', 'boolean'], 'site_id' => ['nullable', 'integer'], 'boundary_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $this->eventQuery($request, $actor);
        if ($request->boolean('follow_up')) {
            $query->whereIn('id', app(ControlRoomAlertAccessService::class)->applyReadableScope(ControlRoomAlert::query(), $actor)
                ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)->select('fleet_signal_id'));
        }
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(20);
        $alerts = app(ControlRoomAlertAccessService::class)
            ->applyReadableScope(ControlRoomAlert::query(), $actor)
            ->whereIn('fleet_signal_id', $page->getCollection()->pluck('id'))->pluck('id', 'fleet_signal_id');

        return $this->json(['data' => $page->getCollection()->map(fn ($e) => [
            'id' => $e->id, 'resource' => $e->asset->name, 'boundary_id' => $e->geofence_id,
            'kind' => $e->signal_type, 'observed_at' => $e->occurred_at?->toIso8601String(), 'received_at' => $e->created_at?->toIso8601String(),
            'delivery' => $e->outbox?->status ?? 'Not recorded', 'boundary_version' => $e->payload['boundary_version'] ?? null,
            'geometry' => $e->payload['boundary_snapshot'] ?? null,
            'follow_up_href' => isset($alerts[$e->id]) ? '/control-room/alerts/'.$alerts[$e->id] : null,
        ]), 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function storeRule(Request $r, BoundaryRuleService $rules)
    {
        return $this->json(['id' => $rules->save($r->user(), $r->all())->id], 201);
    }

    public function updateRule(Request $r, int $assignment, BoundaryRuleService $rules)
    {
        return $this->json(['id' => $rules->save($r->user(), $r->all(), $assignment)->id]);
    }

    public function removeRule(Request $r, int $assignment, BoundaryRuleService $rules)
    {
        $data = $r->validate(['expected_version' => ['required', 'integer'], 'reason' => ['required', 'string']]);
        $rules->remove($r->user(), $assignment, $data['expected_version'], $data['reason']);

        return $this->json(['removed' => true]);
    }

    public function create()
    {
        return redirect('/fleet-assets/geofences?new=1');
    }

    public function edit(Request $r, AssetGeofence $geofence)
    {
        $this->boundaries->resolve($this->boundaries->actor($r->user(), true), $geofence->id);

        return redirect('/fleet-assets/geofences?edit='.$geofence->id);
    }

    public function store(Request $r)
    {
        return $this->json(['boundary' => $this->boundaries->present($this->boundaries->save($r->user(), $r->all()))], 201);
    }

    public function update(Request $r, AssetGeofence $geofence)
    {
        return $this->json(['boundary' => $this->boundaries->present($this->boundaries->save($r->user(), $r->all(), $geofence->id))]);
    }

    public function toggleActive(Request $r, AssetGeofence $geofence)
    {
        $this->boundaries->resolve($this->boundaries->actor($r->user(), true), $geofence->id);
        abort(409, 'Boundary availability and monitoring are separate. Review monitoring in its owning profile.');
    }

    public function legacyLinks(Request $r, AssetGeofence $geofence)
    {
        $data = $r->validate(['expected_revision' => ['required', 'integer'], 'action' => ['required', 'in:pause,unlink'], 'reason' => ['required', 'string']]);

        return $this->json(['boundary' => $this->boundaries->present($this->boundaries->legacyLinks($r->user(), $geofence->id, $data['expected_revision'], $data['action'], $data['reason']))]);
    }

    public function destroy(Request $r, AssetGeofence $geofence)
    {
        $data = $r->validate(['expected_revision' => ['required', 'integer'], 'reason' => ['required', 'string']]);

        return $this->json(['boundary' => $this->boundaries->present($this->boundaries->retire($r->user(), $geofence->id, $data['expected_revision'], $data['reason']))]);
    }

    private function search(Builder $q, string $term, array $columns): void
    {
        $term = trim($term);
        if ($term !== '') {
            $q->where(function ($match) use ($term, $columns): void {
                foreach ($columns as $column) {
                    $match->orWhere($column, 'like', '%'.addcslashes($term, '%_\\').'%');
                }
                if (preg_match('/^(?:BG-)?(\d+)$/i', $term, $id)) {
                    $match->orWhere($match->getModel()->getQualifiedKeyName(), (int) $id[1]);
                }
            });
        }
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status)->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
