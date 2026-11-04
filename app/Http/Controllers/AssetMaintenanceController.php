<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AssetMaintenanceController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('recordMaintenance', $asset);

        $data = $request->validate([
            'performed_at' => ['required', 'date'],
            'type' => ['nullable', 'string', 'max:120'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'next_due_at' => ['nullable', 'date'],
        ]);

        $log = AssetMaintenanceLog::create([
            'asset_id' => $asset->id,
            'performed_by_user_id' => $request->user()?->id,
            'performed_at' => $data['performed_at'],
            'type' => $data['type'] ?? null,
            'vendor' => $data['vendor'] ?? null,
            'cost' => $data['cost'] ?? null,
            'notes' => $data['notes'] ?? null,
            'next_due_at' => $data['next_due_at'] ?? null,
        ]);

        if (!empty($data['next_due_at'])) {
            $asset->update([
                'requires_maintenance' => true,
                'maintenance_due_at' => $data['next_due_at'],
                'updated_by_user_id' => $request->user()?->id,
            ]);
        } elseif (
            $asset->maintenance_due_at !== null
            && $asset->maintenance_due_at->toDateString() <= Carbon::parse($data['performed_at'])->toDateString()
        ) {
            // The service that was due has been done and no next one is
            // planned: clear the due date so the asset stops showing as
            // overdue (Sites list, Site profile). A log dated before the due
            // date (catching up on older work) leaves it alone.
            $asset->update([
                'maintenance_due_at' => null,
                'updated_by_user_id' => $request->user()?->id,
            ]);
        }

        AuditLogger::log('assets.maintenance.create', $log, [
            'asset_id' => $asset->id,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return back()->with('success', 'Maintenance logged.');
    }
}
