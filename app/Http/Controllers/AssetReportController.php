<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Http\Request;

class AssetReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $today = now()->startOfDay();

        $base = Asset::query()->with(['site:id,name', 'client:id,first_name,last_name,site_id']);

        $overdueInspections = (clone $base)
            ->where('requires_inspection', true)
            ->whereNotNull('inspection_due_at')
            ->where('inspection_due_at', '<', $today->toDateString())
            ->orderBy('inspection_due_at')
            ->limit(500)
            ->get();

        $overdueMaintenance = (clone $base)
            ->where('requires_maintenance', true)
            ->whereNotNull('maintenance_due_at')
            ->where('maintenance_due_at', '<', $today->toDateString())
            ->orderBy('maintenance_due_at')
            ->limit(500)
            ->get();

        $expiringWarranties = (clone $base)
            ->whereNotNull('warranty_expires_at')
            ->whereBetween('warranty_expires_at', [$today->toDateString(), $today->copy()->addDays(60)->toDateString()])
            ->orderBy('warranty_expires_at')
            ->limit(500)
            ->get();

        $mapAsset = fn (Asset $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'asset_tag' => $a->asset_tag,
            'status' => $a->status,
            'risk_level' => $a->risk_level,
            'site' => $a->site ? $a->site->only(['id','name']) : null,
            'client' => $a->client ? [
                'id' => $a->client->id,
                'name' => trim($a->client->first_name . ' ' . $a->client->last_name),
            ] : null,
            'inspection_due_at' => optional($a->inspection_due_at)->toDateString(),
            'maintenance_due_at' => optional($a->maintenance_due_at)->toDateString(),
            'warranty_expires_at' => optional($a->warranty_expires_at)->toDateString(),
        ];

        return inertia('reports/assets', [
            'overdueInspections' => $overdueInspections->map($mapAsset),
            'overdueMaintenance' => $overdueMaintenance->map($mapAsset),
            'expiringWarranties' => $expiringWarranties->map($mapAsset),
        ]);
    }
}
