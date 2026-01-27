<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Services\AuditLogger;
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
        }

        AuditLogger::log('assets.maintenance.create', $log, [
            'asset_id' => $asset->id,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return back();
    }
}
