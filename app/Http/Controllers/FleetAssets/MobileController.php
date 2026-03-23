<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetVehicleBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class MobileController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();

        $assignedVehicle = null;
        $todayBookingsCount = 0;
        $todayChecksCount = 0;

        if (Schema::hasColumn('assets', 'primary_driver_user_id')) {
            $vehicle = Asset::where('primary_driver_user_id', $user->id)
                ->where('category', 'vehicle')
                ->first();

            if ($vehicle) {
                $assignedVehicle = [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'asset_tag' => $vehicle->asset_tag ?? '',
                    'status' => $vehicle->status ?? 'active',
                ];
            }
        }

        if (Schema::hasTable('fleet_vehicle_bookings')) {
            try {
                $todayBookingsCount = FleetVehicleBooking::whereDate('starts_at', today())
                    ->where('user_id', $user->id)
                    ->count();
            } catch (\Exception $e) {
                $todayBookingsCount = 0;
            }
        }

        return Inertia::render('fleet-assets/mobile/dashboard', [
            'assigned_vehicle' => $assignedVehicle,
            'today_bookings_count' => $todayBookingsCount,
            'today_checks_count' => $todayChecksCount,
            'auth_user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ]);
    }
}
