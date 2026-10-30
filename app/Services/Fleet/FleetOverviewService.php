<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only, permission-scoped projection for the Fleet & Assets landing page.
 * This is one operating organisation across approved Sites. Each source keeps
 * its own record and direct-object boundary; the overview owns no workflow.
 */
final class FleetOverviewService
{
    /** Failed optional feeds never become a successful zero or an availability claim. */
    private array $failedSources = [];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly VehicleBookingAccessService $bookings,
        private readonly VehicleReadinessService $readiness,
        private readonly VehicleLocationService $locations,
        private readonly MaintenanceAccessService $maintenance,
        private readonly VehicleCalendarService $calendar,
        private readonly MaintenanceRestrictionService $restrictions,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, ?int $receiptSiteId = null, string $receiptSearch = '', int $receiptPage = 1): array
    {
        $this->failedSources = [];
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $now = CarbonImmutable::now($zone);
        $end = $now->startOfDay()->addDays(7);
        $canFleet = $viewer->canDo('fleet.viewAny');
        $canAssets = $viewer->canDo('assets.viewAny') || $viewer->canDo('assets.viewAssigned');
        $canBooking = $canFleet || $viewer->canDo('assets.viewAny');
        $canMaintenance = $this->maintenance->canRead($viewer);

        // Do not infer Fleet visibility from an Asset assignment. Asset-only
        // readers see their authorised Asset register records, including any
        // vehicle-classified Asset, but no Fleet booking or position details.
        $vehicleQuery = $canFleet
            ? $this->access->accessibleVehiclesForFleet($viewer)
            : $this->access->accessibleAssets($viewer, true);
        $vehicles = $vehicleQuery->with(['site:id,name,latitude,longitude', 'homeSite:id,name,latitude,longitude', 'client:id,site_id', 'client.site:id,name,latitude,longitude'])
            ->orderBy('name')->orderBy('id')->get();
        $assets = $canAssets
            ? $this->access->accessibleAssets($viewer)
                ->whereNotIn('id', $vehicles->modelKeys())
                ->with(['site:id,name,latitude,longitude', 'homeSite:id,name,latitude,longitude', 'client:id,site_id', 'client.site:id,name,latitude,longitude'])
                ->orderBy('name')->orderBy('id')->get()
                ->reject(fn (Asset $asset): bool => $asset->category === 'vehicle')
                ->values()
            : collect();

        $bookingRows = Schema::hasTable('fleet_vehicle_bookings') && $canBooking
            ? $this->readSource('Bookings', fn () => $this->bookings->accessibleBookings($viewer)
                ->whereIn('asset_id', $vehicles->modelKeys())
                ->with('user:id,name')
                ->where(function ($query) use ($now, $end): void {
                    $query->where(function ($current) use ($now): void {
                        $current->where('status', 'checked_out')->where('ends_at', '<', $now->utc());
                    })->orWhere(function ($upcoming) use ($now, $end): void {
                        $upcoming->whereIn('status', ['approved', 'checked_out'])
                            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $now->startOfDay()->utc());
                    });
                })
                ->orderBy('starts_at')->get(), collect())
            : collect();
        $bookingsKnown = Schema::hasTable('fleet_vehicle_bookings') && $canBooking;
        $readinessKnown = Schema::hasTable('fleet_vehicle_compliance_records')
            && Schema::hasTable('fleet_maintenance_restrictions');
        $assessments = $readinessKnown && $canFleet
            ? $this->readSource('Vehicle readiness', fn () => $this->readiness->projections($vehicles), []) : [];
        $readinessKnown = $readinessKnown && ! isset($this->failedSources['Vehicle readiness']);
        $fallbackHolds = ! $readinessKnown && $canFleet
            ? $this->readSource('Maintenance', fn () => $this->restrictions->blockersMany($vehicles->modelKeys()), []) : [];

        $currentBookings = $bookingRows->filter(fn ($booking): bool => $booking->status === 'checked_out'
            && $booking->starts_at?->lessThanOrEqualTo($now))
            ->groupBy('asset_id');
        // Reservation/custody existence is checked over the authorised vehicle
        // scope, independent of whether the viewer may see a booking's detail.
        // Hidden details never leak into labels, but cannot make a vehicle look
        // available. The booking owner still controls row-level disclosure.
        $siteScopedVehicleIds = array_fill_keys($this->bookings->authorizedVehicleIds($viewer), true);
        $ownSiteVehicleIds = array_keys($siteScopedVehicleIds);
        $busyVehicleIds = $bookingsKnown
            ? $this->readSource('Bookings', fn () => FleetVehicleBooking::query()->whereIn('asset_id', $ownSiteVehicleIds)
                ->where(function ($query) use ($now): void {
                    $query->where('status', 'checked_out')
                        ->orWhere(function ($approved) use ($now): void {
                            $approved->where('status', 'approved')
                                ->where('starts_at', '<=', $now->utc())->where('ends_at', '>', $now->utc());
                        });
                })->pluck('asset_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all(), [])
            : [];
        $unavailableKnown = Schema::hasTable('fleet_vehicle_unavailable_periods');
        $unavailableVehicleIds = $unavailableKnown
            ? $this->readSource('Bookings', fn () => FleetVehicleUnavailablePeriod::query()->whereIn('asset_id', $ownSiteVehicleIds)
                ->overlapping($now->utc(), $now->addMinute()->utc())
                ->pluck('asset_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all(), [])
            : [];
        $bookingsKnown = $bookingsKnown && $unavailableKnown && ! isset($this->failedSources['Bookings']);
        $vehicleData = $vehicles->map(function (Asset $vehicle) use (
            $viewer, $canFleet, $canBooking, $bookingsKnown, $readinessKnown, $assessments,
            $currentBookings, $busyVehicleIds, $unavailableVehicleIds, $siteScopedVehicleIds, $fallbackHolds
        ): array {
            $assessment = $assessments[(int) $vehicle->id] ?? null;
            $hasHold = ($assessment && ($assessment->restrictionIds !== [] || $assessment->checkRunIds !== []))
                || ! empty($fallbackHolds[(int) $vehicle->id]['restriction_ids'])
                || ! empty($fallbackHolds[(int) $vehicle->id]['check_run_ids']);
            $atOwnSite = isset($siteScopedVehicleIds[(int) $vehicle->id]);
            $availability = match (true) {
                ! $canFleet || ! $atOwnSite => 'Unknown',
                $hasHold => 'Restricted',
                ! $readinessKnown || ! $bookingsKnown => 'Unknown',
                $currentBookings->has($vehicle->id) => 'In use',
                isset($busyVehicleIds[(int) $vehicle->id]) || isset($unavailableVehicleIds[(int) $vehicle->id]) => 'Unknown',
                $assessment?->canProceed === true => 'Available now',
                default => 'Unknown',
            };
            $location = null;
            if ($canFleet && $atOwnSite) {
                $location = $this->readSource('Locations', fn () => $this->locations->lastPermittedPosition($viewer, $vehicle), null);
            }
            $site = $vehicle->site ?? $vehicle->homeSite ?? $vehicle->client?->site;

            return [
                'id' => (int) $vehicle->id, 'kind' => 'vehicle',
                'name' => (string) $vehicle->name, 'ref' => (string) ($vehicle->asset_tag ?: $vehicle->id),
                'registration' => $vehicle->registration_number,
                'site_id' => $site ? (int) $site->id : null,
                'site' => $site?->name, 'availability' => $availability,
                'readiness_note' => ! $atOwnSite ? 'Site record access limited'
                    : ($hasHold ? 'Active maintenance restriction or unresolved check'
                    : (isset($busyVehicleIds[(int) $vehicle->id]) ? 'Calendar or custody evidence needs review'
                        : (isset($unavailableVehicleIds[(int) $vehicle->id]) ? 'Calendar unavailable period'
                            : ($assessment?->canProceed ? 'No recorded readiness blocker' : 'Readiness needs review')))),
                'location' => $location,
                'href' => $canFleet ? route('fleet-assets.vehicles.show', $vehicle->id) : route('fleet-assets.assets.show', $vehicle->id),
                'can_book' => $canBooking && $atOwnSite,
                'can_location' => $canFleet && $atOwnSite,
            ];
        })->values();

        $assetData = $assets->map(function (Asset $asset): array {
            $site = $asset->site ?? $asset->homeSite ?? $asset->client?->site;

            return [
                'id' => (int) $asset->id, 'kind' => 'asset',
                'name' => (string) $asset->name, 'ref' => (string) ($asset->asset_tag ?: $asset->id),
                'registration' => null,
                'site_id' => $site ? (int) $site->id : null,
                'site' => $site?->name, 'availability' => null,
                'readiness_note' => null,
                'location' => $site && $site->latitude !== null && $site->longitude !== null
                    ? ['lat' => (float) $site->latitude, 'lng' => (float) $site->longitude,
                        'observed_at' => null, 'source' => 'Registered Site · approximate, not live', 'fresh' => false]
                    : null,
                'href' => route('fleet-assets.assets.show', $asset->id), 'can_book' => false, 'can_location' => false,
            ];
        })->values();

        $resourceById = $vehicleData->concat($assetData)->keyBy('id');
        $canReceipt = $canAssets && $viewer->canDo('assets.assignments.manage');
        $receiptSchemaReady = Schema::hasTable('asset_assignments')
            && Schema::hasColumn('asset_assignments', 'receipt_confirmed_at')
            && Schema::hasColumn('asset_assignments', 'receipt_confirmed_by_user_id')
            && Schema::hasColumn('asset_assignments', 'receipt_note');
        $approvedSiteIds = $this->access->accessibleSiteIds($viewer);
        $receiptAssetIds = $canReceipt
            ? $vehicles->concat($assets)
                ->filter(function (Asset $asset) use ($viewer, $resourceById, $approvedSiteIds): bool {
                    $resource = $resourceById->get((int) $asset->id);
                    $siteIds = collect([$asset->site_id, $asset->home_site_id, $asset->client?->site_id])
                        ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
                        ->map(fn ($id): int => (int) $id)->unique()->values()->all();

                    return $resource && count($siteIds) === 1
                        && in_array($siteIds[0], $approvedSiteIds, true)
                        && $viewer->can('manageAssignments', $asset);
                })
                ->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [];
        $receiptSearch = mb_substr(trim($receiptSearch), 0, 120);
        $scopedReceiptAssetIds = collect($receiptAssetIds)->filter(function (int $id) use ($resourceById, $receiptSiteId, $receiptSearch): bool {
            $resource = $resourceById->get($id);
            if (! $resource || ($receiptSiteId !== null && $resource['site_id'] !== $receiptSiteId)) {
                return false;
            }
            if ($receiptSearch === '') {
                return true;
            }

            return mb_stripos(implode(' ', [$resource['name'], $resource['ref'], $resource['site'] ?? '', $resource['registration'] ?? '']), $receiptSearch) !== false;
        })->values()->all();
        $receiptFeed = $canReceipt && $receiptSchemaReady
            ? $this->readSource('Assignments and receipt', function () use ($scopedReceiptAssetIds, $receiptPage): array {
                $pending = AssetAssignment::query()
                    ->whereIn('asset_id', $scopedReceiptAssetIds)
                    ->whereNull('released_at')
                    ->whereNull('receipt_confirmed_at')
                    ->where('assigned_at', '<=', now());
                $count = (clone $pending)->count();
                $pages = max(1, (int) ceil($count / 5));
                $page = min(max(1, $receiptPage), $pages);

                return [
                    'count' => $count,
                    'page' => $page,
                    'pages' => $pages,
                    'rows' => $pending->orderBy('assigned_at')->orderBy('id')->offset(($page - 1) * 5)->limit(5)->get(),
                ];
            }, null)
            : null;
        $receipts = [
            'state' => ! $canReceipt ? 'no_access' : ($receiptFeed === null ? 'unavailable' : 'loaded'),
            'count' => $receiptFeed['count'] ?? null,
            'page' => $receiptFeed['page'] ?? 1,
            'pages' => $receiptFeed['pages'] ?? 1,
            'site' => $receiptSiteId === null ? 'all' : (string) $receiptSiteId,
            'query' => $receiptSearch,
            'rows' => $receiptFeed === null ? [] : $receiptFeed['rows']->map(function (AssetAssignment $assignment) use ($resourceById): array {
                $resource = $resourceById->get((int) $assignment->asset_id);

                return [
                    'id' => (int) $assignment->id,
                    'asset_id' => (int) $assignment->asset_id,
                    'asset' => $resource['name'],
                    'ref' => $resource['ref'],
                    'site_id' => $resource['site_id'],
                    'site' => $resource['site'],
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'href' => route('fleet-assets.assets.show', $assignment->asset_id).'?tab=assignments',
                ];
            })->values(),
        ];
        $bookedHours = [];
        for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
            $dayStart = $now->startOfDay()->addDays($dayIndex);
            $dayEnd = $dayStart->addDay();
            $vehicleSeconds = [];
            foreach ($bookingRows as $booking) {
                if (! in_array($booking->status, ['approved', 'checked_out'], true)
                    || ! $booking->starts_at || ! $booking->ends_at) {
                    continue;
                }
                $key = (int) $booking->asset_id;
                $vehicleSeconds[$key] = ($vehicleSeconds[$key] ?? 0) + max(0,
                    min($booking->ends_at->getTimestamp(), $dayEnd->getTimestamp())
                    - max($booking->starts_at->getTimestamp(), $dayStart->getTimestamp()));
            }
            foreach ($vehicleSeconds as $key => $seconds) {
                $bookedHours[] = ['date' => $dayStart->toDateString(),
                    'site_id' => $resourceById->get($key)['site_id'] ?? null,
                    'asset_id' => $key, 'hours' => $seconds / 3600];
            }
        }
        $attention = [];
        $agenda = [];
        foreach ($bookingRows as $booking) {
            $resource = $resourceById->get((int) $booking->asset_id);
            if (! $resource) {
                continue;
            }
            $item = [
                'id' => 'booking-'.(int) $booking->id, 'type' => 'booking',
                'title' => $booking->status === 'checked_out' ? 'Vehicle return' : 'Confirmed booking',
                'ref' => (string) ($booking->reference_number ?: 'BK-'.$booking->id),
                'resource' => $resource['name'], 'resource_ref' => $resource['ref'],
                'owner' => $booking->user?->name,
                'site_id' => $resource['site_id'], 'site' => $resource['site'],
                'due_at' => $booking->ends_at?->toIso8601String(),
                // A checked-out booking appears as a return obligation; its
                // agenda time is the return deadline, not the original pickup.
                'starts_at' => ($booking->status === 'checked_out'
                    ? $booking->ends_at : $booking->starts_at)?->toIso8601String(),
                'ends_at' => $booking->ends_at?->toIso8601String(),
                'href' => route('fleet-assets.bookings.show', $booking->id),
            ];
            if ($booking->status === 'checked_out' && $booking->ends_at?->lessThan($now)) {
                $attention[] = $item + ['category' => 'returns', 'detail' => 'Checked out · return overdue'];
            } elseif ($booking->ends_at?->greaterThan($now)) {
                $agenda[] = $item;
            }
        }

        if ($canMaintenance && Schema::hasTable('fleet_work_orders')) {
            $orders = $this->readSource('Maintenance', fn () => $this->maintenance->scopedWorkOrders($viewer)
                ->whereIn('asset_id', $resourceById->keys()->all())
                ->whereIn('status', ['open', 'in_progress', 'on_hold'])
                ->with(['asset:id,name,asset_tag,site_id', 'assignedTo:id,name'])
                ->orderBy('due_at')->get(), collect());
            foreach ($orders as $order) {
                $resource = $resourceById->get((int) $order->asset_id);
                if (! $resource) {
                    continue;
                }
                $row = [
                    'id' => 'work-'.(int) $order->id, 'type' => 'work',
                    'title' => (string) ($order->title ?: 'Maintenance follow-up'),
                    'ref' => (string) ($order->reference_number ?: 'WO-'.$order->id),
                    'resource' => $resource['name'], 'resource_ref' => $resource['ref'],
                    'owner' => $order->assignedTo?->name,
                    'site_id' => $resource['site_id'], 'site' => $resource['site'],
                    'due_at' => $order->due_at?->toIso8601String(),
                    'starts_at' => null, 'ends_at' => null,
                    'href' => route('fleet-assets.work-orders.show', $order->id),
                ];
                $attention[] = $row + [
                    'category' => $order->assigned_to_user_id ? 'work' : 'unassigned',
                    'detail' => $order->assigned_to_user_id ? 'Open maintenance work' : 'Owner needed',
                ];
                if ($order->due_at && $order->due_at->greaterThanOrEqualTo($now)
                    && $order->due_at->lessThan($end)) {
                    $agenda[] = $row;
                }
            }
        }

        // A current maintenance restriction remains visible even if no work
        // order row is open. The vehicle profile is the canonical evidence.
        foreach ($vehicleData as $resource) {
            if ($resource['availability'] !== 'Restricted') {
                continue;
            }
            $attention[] = [
                'id' => 'hold-'.$resource['id'], 'type' => 'work',
                'title' => 'Active vehicle hold', 'ref' => $resource['ref'],
                'resource' => $resource['name'], 'resource_ref' => $resource['ref'],
                'site_id' => $resource['site_id'], 'site' => $resource['site'],
                'due_at' => null, 'starts_at' => null, 'ends_at' => null,
                'href' => $resource['href'], 'category' => 'restricted',
                'detail' => $resource['readiness_note'],
            ];
        }

        // Date-only obligations remain date-only. They are not reservations or
        // evidence that a vehicle is available for use.
        foreach ($vehicleData as $resource) {
            if (! $resource['can_location'] || $resource['availability'] === 'Restricted') {
                continue;
            }
            $assessment = $assessments[$resource['id']] ?? null;
            $reasons = array_values(array_filter($assessment?->blockingReasons() ?? [],
                fn ($reason): bool => str_starts_with($reason->code, 'compliance.')));
            if ($reasons === []) {
                continue;
            }
            $attention[] = [
                'id' => 'evidence-'.$resource['id'], 'type' => 'due', 'category' => 'evidence',
                'title' => 'Check vehicle readiness evidence', 'ref' => $resource['ref'],
                'resource' => $resource['name'], 'resource_ref' => $resource['ref'],
                'site_id' => $resource['site_id'], 'site' => $resource['site'],
                'due_at' => null, 'starts_at' => null, 'ends_at' => null, 'owner' => null,
                'href' => $resource['href'].'?tab=overview&view=readiness',
                'detail' => $reasons[0]->message,
            ];
        }

        if ($canMaintenance && Schema::hasTable('fleet_service_schedules')) {
            $schedules = $this->readSource('Maintenance', fn () => FleetServiceSchedule::query()
                ->whereIn('asset_id', $vehicleData->pluck('id')->all())
                ->where('is_active', true)->whereNotNull('next_due_at')
                ->where('next_due_at', '>=', $now->toDateString())
                ->where('next_due_at', '<', $end->toDateString())
                ->get(), collect());
            foreach ($schedules as $schedule) {
                $resource = $resourceById->get((int) $schedule->asset_id);
                if (! $resource) {
                    continue;
                }
                $agenda[] = [
                    'id' => 'schedule-'.$schedule->id, 'type' => 'due',
                    'title' => (string) ($schedule->name ?: 'Service due'),
                    'ref' => 'Service', 'resource' => $resource['name'],
                    'resource_ref' => $resource['ref'], 'site_id' => $resource['site_id'],
                    'site' => $resource['site'], 'due_at' => $schedule->next_due_at?->toDateString(),
                    'starts_at' => null, 'ends_at' => null,
                    'href' => $resource['href'],
                ];
            }
        }

        // Appointments are owned by the vehicle calendar. Only show events
        // whose canonical feed authorises details for this viewer.
        if ($canFleet && $canMaintenance) {
            foreach ($vehicles as $vehicle) {
                if (! isset($siteScopedVehicleIds[(int) $vehicle->id])) {
                    continue;
                }
                $resource = $resourceById->get((int) $vehicle->id);
                $events = $this->readSource('Planning', fn () => $this->calendar->events($viewer, $vehicle, $now->startOfDay(), $end), []);
                foreach ($events as $event) {
                    if (! in_array($event['kind'] ?? null, ['appointment', 'compliance', 'check', 'reminder'], true)
                        || ($event['status'] ?? null) === 'completed') {
                        continue;
                    }
                    $starts = CarbonImmutable::parse($event['start']);
                    if (! $starts->lessThan($end) || (! ($event['allDay'] ?? false)
                        && CarbonImmutable::parse($event['end'] ?? $event['start'])->lessThanOrEqualTo($now))) {
                        continue;
                    }
                    $allDay = (bool) ($event['allDay'] ?? false);
                    $agenda[] = [
                        'id' => 'vehicle-'.$vehicle->id.':'.$event['id'], 'type' => $event['kind'] === 'appointment' ? 'appointment' : 'due',
                        'title' => (string) $event['title'], 'ref' => (string) ($event['ref'] ?? 'Appointment'),
                        'resource' => $resource['name'], 'resource_ref' => $resource['ref'],
                        'site_id' => $resource['site_id'], 'site' => $resource['site'],
                        'due_at' => $allDay ? $starts->setTimezone($zone)->toDateString() : $event['start'],
                        'starts_at' => $allDay ? null : $event['start'],
                        'ends_at' => $allDay ? null : $event['end'],
                        'href' => $event['link'] ?? $resource['href'].'?tab=overview&view=readiness',
                    ];
                }
            }
        }

        $sites = $this->access->accessibleSites($viewer)->orderBy('name')->get(['id', 'name']);

        return [
            'as_of' => $now->toIso8601String(), 'timezone' => $zone,
            'viewer_key' => (int) $viewer->id,
            'sites' => $sites->map(fn ($site): array => ['id' => (int) $site->id, 'name' => $site->name])->values(),
            'vehicles' => $vehicleData, 'assets' => $assetData,
            'attention' => $attention, 'agenda' => $agenda,
            'receipts' => $receipts,
            'bookings' => $bookingRows->map(fn ($row): array => [
                'id' => (int) $row->id, 'asset_id' => (int) $row->asset_id,
                'status' => $row->status, 'starts_at' => $row->starts_at?->toIso8601String(),
                'ends_at' => $row->ends_at?->toIso8601String(),
            ])->values(),
            'booked_hours' => $bookedHours,
            'sources' => array_map(function (array $source): array {
                if (isset($this->failedSources[$source['name']])) {
                    $source['state'] = 'unavailable';
                    $source['count'] = null;
                }

                return $source;
            }, [
                ['name' => 'Bookings', 'state' => ! $canBooking ? 'no_access' : ($bookingsKnown ? 'loaded' : 'unavailable'),
                    'count' => $bookingsKnown ? $bookingRows->count() : null,
                    'description' => 'Approved reservations and checked-out returns in the next seven days, plus overdue returns.'],
                ['name' => 'Vehicle readiness', 'state' => ! $canFleet ? 'no_access' : ($readinessKnown ? 'loaded' : 'unavailable'),
                    'count' => $readinessKnown && $canFleet ? $vehicles->count() : null,
                    'description' => 'Current compliance and maintenance evidence. A use decision still checks its driver and time.'],
                ['name' => 'Maintenance', 'state' => ! $canMaintenance ? 'no_access' : (Schema::hasTable('fleet_work_orders') ? 'loaded' : 'unavailable'),
                    'count' => $canMaintenance ? count(array_filter($attention, fn (array $row): bool => $row['type'] === 'work')) : null,
                    'description' => 'Open work orders and active vehicle holds; maintenance owners resolve them in source records.'],
                ['name' => 'Assignments and receipt', 'state' => $receipts['state'], 'count' => $receipts['count'],
                    'description' => 'Active Asset assignments awaiting an explicit receipt verification, scoped to Sites and records this viewer may manage.'],
                ['name' => 'Locations', 'state' => ! $canFleet && ! $canAssets ? 'no_access' : 'loaded',
                    'count' => $canFleet || $canAssets ? $vehicleData->concat($assetData)->filter(fn (array $row): bool => $row['location'] !== null)->count() : null,
                    'description' => 'Permitted tracker positions and approximate registered Asset Sites. Personal and consent-withheld positions have no pin.'],
                ['name' => 'Planning', 'state' => $canFleet && $canMaintenance ? 'loaded' : 'no_access',
                    'count' => count(array_filter($agenda, fn (array $row): bool => in_array($row['type'], ['appointment', 'due'], true))),
                    'description' => 'Service appointments, compliance dates and vehicle reminders from the source calendar.'],
            ]),
            'can' => [
                'fleet' => $canFleet, 'assets' => $canAssets,
                'booking' => $canBooking, 'maintenance' => $canMaintenance,
                'settings' => $canFleet || $viewer->canDo('assets.viewAny'),
            ],
        ];
    }

    private function readSource(string $name, callable $read, mixed $fallback): mixed
    {
        if (isset($this->failedSources[$name])) {
            return $fallback;
        }
        try {
            return $read();
        } catch (QueryException|\RuntimeException $exception) {
            report($exception);
            $this->failedSources[$name] = true;

            return $fallback;
        }
    }
}
