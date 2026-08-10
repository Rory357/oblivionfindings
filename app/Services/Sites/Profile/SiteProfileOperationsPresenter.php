<?php

namespace App\Services\Sites\Profile;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Asset;
use App\Models\FleetFuelLog;
use App\Models\FleetIncident;
use App\Models\FleetOuting;
use App\Models\FleetTrip;
use App\Models\FleetVehicleBooking;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\SiteTypePlanPin;
use App\Models\User;
use App\Services\Sites\SiteTypePlanService;
use App\Support\ChecklistsDashboardData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SiteProfileOperationsPresenter
{
    public function __construct(
        private readonly SiteTypePlanService $typePlans,
        private readonly DeviceRegistryService $devices,
    ) {}

    /** @return array<string, mixed> */
    public function calendar(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('calendar.view');

        return [
            'locked' => ! $canView,
            'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
            'people' => $canView ? User::query()->staff()->orderBy('name')->get(['id', 'name']) : collect(),
            'canCreate' => $canView && ! $site->archived && $user->canDo('calendar.create') && $user->can('update', $site),
            'canManage' => $canView && ! $site->archived && $user->canDo('calendar.manage') && $user->can('update', $site),
            'canApprove' => $canView && ! $site->archived && $user->canDo('calendar.approve') && $user->can('update', $site),
            'feedUrl' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function checklists(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('checklists.view');
        $workspace = $canView ? (new ChecklistsDashboardData(request()))->forSite($site) : [];

        return [
            'locked' => ! $canView,
            ...$workspace,
            'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
            'backHref' => route('sites.show', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function mealPlanner(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $locked = $site->archived || ! $user->canDo('sites.meals.view') || $site->type === 'head_office';

        return [
            'locked' => $locked,
            'href' => $locked ? null : route('sites.meals.plan.index', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function assets(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $this->canViewAssets($user);
        $query = Asset::query()->where('site_id', $site->id);
        $items = $canView
            ? (clone $query)
                ->with('client:id,first_name,last_name')
                ->orderBy('name')
                ->get(['id', 'client_id', 'name', 'asset_tag', 'category', 'status', 'risk_level', 'location', 'inspection_due_at', 'maintenance_due_at', 'updated_at'])
                ->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                    'category' => $asset->category,
                    'status' => $asset->status,
                    'risk_level' => $asset->risk_level,
                    'location' => $asset->location,
                    'owner' => $asset->client ? [
                        'type' => 'client',
                        'id' => $asset->client->id,
                        'label' => trim($asset->client->first_name.' '.$asset->client->last_name),
                    ] : [
                        'type' => 'site',
                        'id' => $site->id,
                        'label' => $site->name,
                    ],
                    'inspection_due_at' => $asset->inspection_due_at?->toDateString(),
                    'maintenance_due_at' => $asset->maintenance_due_at?->toDateString(),
                    'updated_at' => $asset->updated_at?->toISOString(),
                    'href' => route('fleet-assets.assets.show', $asset),
                ])->values()
            : collect();

        return [
            'locked' => ! $canView,
            'items' => $items,
            'can_create' => $canView && ! $site->archived && $user->canDo('assets.create'),
            'href' => $canView ? route('fleet-assets.assets.index', ['site_id' => $site->id]) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function fleet(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('fleet.viewAny');
        if (! $canView) {
            return ['locked' => true];
        }

        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');
        $hasTrips = Schema::hasTable('fleet_trips');
        $hasFuel = Schema::hasTable('fleet_fuel_logs');
        $hasIncidents = Schema::hasTable('fleet_incidents');
        $hasBookings = Schema::hasTable('fleet_vehicle_bookings');
        $hasOutings = Schema::hasTable('fleet_outings');

        $vehicles = $hasFleetFields
            ? Asset::query()
                ->where('home_site_id', $site->id)
                ->where('category', 'vehicle')
                ->with('fleetState')
                ->orderBy('name')
                ->get()
                ->map(fn (Asset $vehicle) => [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'asset_tag' => $vehicle->asset_tag,
                    'status' => $vehicle->status,
                    'fleet_status' => $vehicle->fleetState?->status,
                    'speed_kph' => $vehicle->fleetState?->speed_kph,
                    'last_seen_at' => $vehicle->fleetState?->last_seen_at?->toISOString(),
                    'consent_blocked' => (bool) ($vehicle->fleetState?->consent_blocked ?? false),
                    'wof_expires_at' => $vehicle->wof_expires_at?->toDateString(),
                    'registration_expires_at' => $vehicle->registration_expires_at?->toDateString(),
                    'href' => route('fleet-assets.vehicles.show', $vehicle),
                ])->values()
            : collect();

        $vehicleIds = $vehicles->pluck('id')->all();
        $todayBookings = $hasBookings && $vehicleIds
            ? FleetVehicleBooking::query()
                ->where(fn ($query) => $query->where('pickup_site_id', $site->id)->orWhere('return_site_id', $site->id))
                ->whereDate('starts_at', '<=', today())
                ->whereDate('ends_at', '>=', today())
                ->whereIn('status', ['approved', 'checked_out'])
                ->with(['asset:id,name', 'user:id,name'])
                ->limit(10)
                ->get()
                ->map(fn (FleetVehicleBooking $booking) => [
                    'id' => $booking->id,
                    'vehicle' => $booking->asset ? ['id' => $booking->asset->id, 'name' => $booking->asset->name] : null,
                    'booked_by' => $booking->user?->name,
                    'purpose' => $booking->purpose,
                    'status' => $booking->status,
                    'starts_at' => $booking->starts_at?->toISOString(),
                    'ends_at' => $booking->ends_at?->toISOString(),
                    'href' => route('fleet-assets.bookings.show', $booking),
                ])->values()
            : collect();
        $activeOutings = $hasOutings && $vehicleIds
            ? FleetOuting::query()
                ->whereIn('asset_id', $vehicleIds)
                ->whereIn('status', ['planned', 'active'])
                ->where('planned_departure', '>=', today()->subDay())
                ->with(['asset:id,name', 'driver:id,name'])
                ->withCount('residents')
                ->limit(10)
                ->get()
                ->map(fn (FleetOuting $outing) => [
                    'id' => $outing->id,
                    'title' => $outing->title,
                    'destination' => $outing->destination,
                    'status' => $outing->status,
                    'planned_departure' => $outing->planned_departure?->toISOString(),
                    'vehicle' => $outing->asset ? ['id' => $outing->asset->id, 'name' => $outing->asset->name] : null,
                    'driver' => $outing->driver ? ['id' => $outing->driver->id, 'name' => $outing->driver->name] : null,
                    'residents_count' => (int) $outing->residents_count,
                    'href' => route('fleet-assets.outings.show', $outing),
                ])->values()
            : collect();

        $monthStart = now()->startOfMonth();
        $stats = [
            'trips_this_month' => $hasTrips && $vehicleIds ? FleetTrip::whereIn('asset_id', $vehicleIds)->where('started_at', '>=', $monthStart)->count() : 0,
            'distance_this_month' => $hasTrips && $vehicleIds ? round((float) FleetTrip::whereIn('asset_id', $vehicleIds)->where('started_at', '>=', $monthStart)->sum('distance_km'), 1) : 0,
            'fuel_cost_this_month' => $hasFuel && $vehicleIds ? round((float) FleetFuelLog::whereIn('asset_id', $vehicleIds)->where('logged_at', '>=', $monthStart)->sum('total_cost'), 2) : 0,
            'incidents_this_month' => $hasIncidents && $vehicleIds ? FleetIncident::whereIn('asset_id', $vehicleIds)->where('occurred_at', '>=', $monthStart)->count() : 0,
        ];
        $compliance = $vehicles->map(function (array $vehicle) {
            $items = [];
            foreach (['wof_expires_at' => 'WOF', 'registration_expires_at' => 'Registration'] as $field => $label) {
                if ($vehicle[$field]) {
                    $days = now()->diffInDays(Carbon::parse($vehicle[$field]), false);
                    if ($days <= 90) {
                        $items[] = [
                            'type' => $label,
                            'expires_at' => $vehicle[$field],
                            'days_remaining' => $days,
                            'status' => $days < 0 ? 'expired' : ($days <= 30 ? 'critical' : 'warning'),
                        ];
                    }
                }
            }

            return ['vehicle_name' => $vehicle['name'], 'vehicle_id' => $vehicle['id'], 'items' => $items];
        })->filter(fn (array $vehicle) => count($vehicle['items']) > 0)->values();

        return [
            'locked' => false,
            'vehicles' => $vehicles,
            'today_bookings' => $todayBookings,
            'active_outings' => $activeOutings,
            'stats' => $stats,
            'compliance' => $compliance,
            'href' => route('fleet-assets.dashboard', ['site_id' => $site->id]),
        ];
    }

    /** @return array<string, mixed> */
    public function hardware(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('siteHardware.view') || $user->canDo('securityDevices.devices.view');
        if (! $canView) {
            return ['locked' => true];
        }

        $typePlan = $this->typePlans->summaryFor($site);
        $currentPlan = $this->typePlans->currentEditable($site);
        $devicePins = $currentPlan
            ? $currentPlan->pins()->where('kind', SiteTypePlanPin::KIND_DEVICE)->get()->keyBy('device_id')
            : collect();
        $devices = $this->devices->visibleForSite($user, $site->id)
            ->with(['assignments' => fn ($query) => $query->active()])
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(function (Device $device) use ($devicePins) {
                $active = $device->assignments->first(fn ($assignment) => $assignment->released_at === null);
                $externalRef = is_array($device->external_ref) ? $device->external_ref : [];
                $meta = is_array($device->meta) ? $device->meta : [];
                $planPin = $devicePins->get($device->id);

                return [
                    'id' => $device->id,
                    'device_uid' => $device->device_uid,
                    'name' => $device->name,
                    'domain' => $device->domain,
                    'category' => $device->category,
                    'subcategory' => $device->subcategory,
                    'manufacturer' => $device->manufacturer,
                    'model' => $device->model,
                    'serial_number' => $device->serial_number,
                    'mac_address' => $device->mac_address,
                    'asset_tag' => $device->asset_tag,
                    'status' => $device->status?->value,
                    'health_status' => $device->health_status?->value,
                    'provider' => $device->provider,
                    'provider_entity_id' => $externalRef['provider_entity_id'] ?? null,
                    'provider_type' => $meta['provider_type'] ?? $externalRef['provider_type'] ?? null,
                    'last_seen_at' => $device->last_seen_at?->toISOString(),
                    'battery_level' => $device->battery_level,
                    'firmware_version' => $device->firmware_version,
                    'ip_address' => $device->ip_address,
                    'notes' => $device->notes,
                    'assignment_type' => $active?->assignable_type,
                    'assignment_id' => $active?->assignable_id,
                    'plan_pin' => $planPin ? $this->typePlans->serializePin($planPin) : null,
                ];
            })->values();

        return [
            'locked' => false,
            'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
            'devices' => $devices,
            'rooms' => SiteRoom::query()->where('site_id', $site->id)->orderBy('sort_order')->get(['id', 'name', 'sort_order']),
            'typePlan' => $typePlan,
            'can' => [
                'manage_hardware' => ! $site->archived && $user->can('update', $site) && $user->canDo('siteHardware.manage'),
                'register_device' => ! $site->archived && $user->can('create', Device::class),
            ],
            'href' => route('sites.hardware.index', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function plan(User $user, Site $site): array
    {
        $this->primePermissions($user);

        return [
            'locked' => false,
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'display_type' => $site->display_type,
            ],
            'typePlan' => $this->typePlans->summaryFor($site),
            'can' => ['update' => ! $site->archived && $user->can('update', $site)],
            'href' => route('sites.plan.show', $site),
        ];
    }

    private function primePermissions(User $user): void
    {
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);
    }

    private function canViewAssets(User $user): bool
    {
        return $user->canDo('assets.viewAny') || $user->canDo('assets.viewAssigned');
    }
}
