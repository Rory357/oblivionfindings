<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetFuelLog;
use App\Models\FleetResidentTransport;
use App\Models\FleetTrip;
use App\Models\FleetWorkOrder;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class CostAllocationController extends Controller
{
    public function index(Request $request)
    {
        $days = (int) ($request->input('days', 30));
        $days = in_array($days, [30, 90, 180, 365]) ? $days : 30;
        $since = now()->subDays($days);

        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');
        $hasFuelTable = Schema::hasTable('fleet_fuel_logs');
        $hasWorkOrders = Schema::hasTable('fleet_work_orders');
        $hasTripsTable = Schema::hasTable('fleet_trips');
        $hasTransports = Schema::hasTable('fleet_resident_transports');

        // Get all vehicles with their home site
        $vehicles = Asset::vehicles();
        if ($hasFleetFields) {
            $vehicles = $vehicles->with('homeSite');
        }
        $vehicles = $vehicles->get();

        // Fuel costs by asset
        $fuelByAsset = [];
        if ($hasFuelTable) {
            $fuelByAsset = FleetFuelLog::query()
                ->where('created_at', '>=', $since)
                ->selectRaw('asset_id, SUM(total_cost) as total_fuel')
                ->groupBy('asset_id')
                ->pluck('total_fuel', 'asset_id')
                ->toArray();
        }

        // Maintenance costs by asset
        $maintenanceByAsset = [];
        if ($hasWorkOrders) {
            $maintenanceByAsset = FleetWorkOrder::query()
                ->where('created_at', '>=', $since)
                ->whereNotNull('actual_cost')
                ->selectRaw('asset_id, SUM(actual_cost) as total_maintenance')
                ->groupBy('asset_id')
                ->pluck('total_maintenance', 'asset_id')
                ->toArray();
        }

        // Trip distances by asset (for estimated fuel from distance)
        $tripDistanceByAsset = [];
        if ($hasTripsTable) {
            $tripDistanceByAsset = FleetTrip::query()
                ->where('created_at', '>=', $since)
                ->selectRaw('asset_id, SUM(distance_km) as total_distance')
                ->groupBy('asset_id')
                ->pluck('total_distance', 'asset_id')
                ->toArray();
        }

        // Group costs by house/site
        $sites = Site::query()->whereNotNull('name')->get(['id', 'name']);
        $siteMap = $sites->keyBy('id');

        $bySite = [];
        $totalFuel = 0;
        $totalMaintenance = 0;

        foreach ($vehicles as $vehicle) {
            $siteId = $hasFleetFields ? ($vehicle->home_site_id ?? $vehicle->site_id) : $vehicle->site_id;
            if (!$siteId) {
                continue;
            }

            $siteName = $siteMap->get($siteId)?->name ?? 'Unknown';
            if (!isset($bySite[$siteId])) {
                $bySite[$siteId] = [
                    'id' => $siteId,
                    'name' => $siteName,
                    'vehicles' => 0,
                    'fuel_cost' => 0,
                    'maintenance_cost' => 0,
                    'total' => 0,
                ];
            }

            $fuel = (float) ($fuelByAsset[$vehicle->id] ?? 0);
            $maintenance = (float) ($maintenanceByAsset[$vehicle->id] ?? 0);

            $bySite[$siteId]['vehicles']++;
            $bySite[$siteId]['fuel_cost'] += $fuel;
            $bySite[$siteId]['maintenance_cost'] += $maintenance;
            $bySite[$siteId]['total'] += ($fuel + $maintenance);
            $totalFuel += $fuel;
            $totalMaintenance += $maintenance;
        }

        $bySite = array_values($bySite);

        // Round all monetary values
        foreach ($bySite as &$s) {
            $s['fuel_cost'] = round($s['fuel_cost'], 2);
            $s['maintenance_cost'] = round($s['maintenance_cost'], 2);
            $s['total'] = round($s['total'], 2);
            $s['cost_per_vehicle'] = $s['vehicles'] > 0 ? round($s['total'] / $s['vehicles'], 2) : 0;
        }
        unset($s);

        // Transport costs per resident
        $byResident = [];
        if ($hasTransports) {
            $transports = FleetResidentTransport::query()
                ->where('created_at', '>=', $since)
                ->get(['resident_id', 'resident_name', 'asset_id', 'booking_id']);

            $avgCostPerKm = 0.35; // Default NZ average fuel cost per km

            foreach ($transports as $transport) {
                $residentId = $transport->resident_id ?? $transport->resident_name;
                $residentName = $transport->resident_name ?? "Resident #{$transport->resident_id}";

                if (!$residentId) {
                    continue;
                }

                if (!isset($byResident[$residentId])) {
                    $byResident[$residentId] = [
                        'id' => $residentId,
                        'name' => $residentName,
                        'house' => '',
                        'trips' => 0,
                        'distance_km' => 0,
                        'estimated_cost' => 0,
                    ];
                }

                $byResident[$residentId]['trips']++;

                // Estimate distance from linked trip if available
                if ($transport->asset_id && $hasTripsTable) {
                    $tripDist = (float) ($tripDistanceByAsset[$transport->asset_id] ?? 0);
                    // Rough estimate: divide total asset distance by transport count for that asset
                    $transportCountForAsset = $transports->where('asset_id', $transport->asset_id)->count();
                    $estimatedDist = $transportCountForAsset > 0 ? $tripDist / $transportCountForAsset : 0;
                    $byResident[$residentId]['distance_km'] += round($estimatedDist, 1);
                    $byResident[$residentId]['estimated_cost'] += round($estimatedDist * $avgCostPerKm, 2);
                }
            }

            // Try to get house names for residents
            if (Schema::hasTable('clients') && class_exists(\App\Models\Client::class)) {
                $residentIds = collect($byResident)->pluck('id')->filter(fn ($id) => is_numeric($id))->values()->all();
                if (!empty($residentIds)) {
                    try {
                        $clients = \App\Models\Client::whereIn('id', $residentIds)->get(['id', 'site_id']);
                        foreach ($clients as $client) {
                            if (isset($byResident[$client->id]) && $client->site_id) {
                                $byResident[$client->id]['house'] = $siteMap->get($client->site_id)?->name ?? '';
                            }
                        }
                    } catch (\Throwable $e) {
                        // Clients table may have different structure
                    }
                }
            }
        }

        $byResident = array_values($byResident);

        // KPI calculations
        $totalFleetCost = round($totalFuel + $totalMaintenance, 2);
        $vehicleCount = $vehicles->count();
        $costPerVehicle = $vehicleCount > 0 ? round($totalFleetCost / $vehicleCount, 2) : 0;
        $residentCount = count($byResident);
        $costPerResident = $residentCount > 0 ? round(
            collect($byResident)->sum('estimated_cost') / $residentCount, 2
        ) : 0;
        $houseCount = count($bySite);
        $costPerHouse = $houseCount > 0 ? round($totalFleetCost / $houseCount, 2) : 0;

        // CSV Export
        if ($request->input('export') === 'csv') {
            $tab = $request->input('tab', 'house');
            return response()->streamDownload(function () use ($tab, $bySite, $byResident) {
                $handle = fopen('php://output', 'w');
                if ($tab === 'resident') {
                    fputcsv($handle, ['Resident', 'House', 'Trips', 'Distance (km)', 'Estimated Cost']);
                    foreach ($byResident as $r) {
                        fputcsv($handle, [$r['name'], $r['house'], $r['trips'], $r['distance_km'], $r['estimated_cost']]);
                    }
                } else {
                    fputcsv($handle, ['House', 'Vehicles', 'Fuel Cost', 'Maintenance Cost', 'Total', 'Cost/Vehicle']);
                    foreach ($bySite as $s) {
                        fputcsv($handle, [$s['name'], $s['vehicles'], $s['fuel_cost'], $s['maintenance_cost'], $s['total'], $s['cost_per_vehicle']]);
                    }
                }
                fclose($handle);
            }, "cost-allocation-{$tab}-" . now()->format('Y-m-d') . '.csv');
        }

        return Inertia::render('fleet-assets/reports/cost-allocation', [
            'by_site' => $bySite,
            'by_resident' => $byResident,
            'days' => $days,
            'stats' => [
                'total_fleet_cost' => $totalFleetCost,
                'cost_per_vehicle' => $costPerVehicle,
                'cost_per_resident' => $costPerResident,
                'cost_per_house' => $costPerHouse,
                'total_fuel' => round($totalFuel, 2),
                'total_maintenance' => round($totalMaintenance, 2),
            ],
        ]);
    }
}
