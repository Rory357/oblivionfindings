<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\Assets\AssetOwnershipService;
use Illuminate\Http\Request;

class AssetOwnershipController extends Controller
{
    public function __construct(
        private readonly AssetOwnershipService $ownerships,
    ) {}

    public function store(Request $request, Asset $asset)
    {
        $this->authorize('manageOwnership', $asset);

        $data = $request->validate([
            'owner_type' => ['required', 'in:site,client'],
            'owner_id' => ['required', 'integer'],
            'effective_from' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->ownerships->change($request->user(), $asset, $data);

        return back()->with('success', 'Ownership updated.');
    }
}
