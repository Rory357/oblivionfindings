<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetOwnership;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class AssetOwnershipController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);
        abort_unless($request->user()?->canDo('assets.ownership.manage'), 403);

        $data = $request->validate([
            'owner_type' => ['required', 'in:organisation,site,client'],
            'owner_id' => ['required', 'integer'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        AssetOwnership::query()
            ->where('asset_id', $asset->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => now()]);

        $ownership = AssetOwnership::create([
            'asset_id' => $asset->id,
            'owner_type' => $data['owner_type'],
            'owner_id' => $data['owner_id'],
            'effective_from' => $data['effective_from'] ?? now(),
            'effective_to' => null,
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLogger::log('assets.ownership.changed', $asset, [
            'ownership_id' => $ownership->id,
        ]);

        return back()->with('success', 'Ownership updated.');
    }
}
