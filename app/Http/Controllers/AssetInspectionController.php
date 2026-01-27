<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetInspection;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class AssetInspectionController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('recordInspection', $asset);

        $data = $request->validate([
            'inspected_at' => ['required', 'date'],
            'result' => ['required', 'in:pass,fail,needs_followup'],
            'notes' => ['nullable', 'string'],
            'next_due_at' => ['nullable', 'date'],
        ]);

        $inspection = AssetInspection::create([
            'asset_id' => $asset->id,
            'inspected_by_user_id' => $request->user()?->id,
            'inspected_at' => $data['inspected_at'],
            'result' => $data['result'],
            'notes' => $data['notes'] ?? null,
            'next_due_at' => $data['next_due_at'] ?? null,
        ]);

        // Optionally roll the next due date onto the asset
        if (!empty($data['next_due_at'])) {
            $asset->update([
                'requires_inspection' => true,
                'inspection_due_at' => $data['next_due_at'],
                'updated_by_user_id' => $request->user()?->id,
            ]);
        }

        AuditLogger::log('assets.inspections.create', $inspection, [
            'asset_id' => $asset->id,
            'site_id' => $asset->site_id,
            'client_id' => $asset->client_id,
        ]);

        return back();
    }
}
