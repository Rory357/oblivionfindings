<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientPersonalAsset;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientPersonalAssetController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'condition' => ['nullable', 'string', 'in:new,good,fair,poor'],
            'location' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'acquired_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($request->hasFile('photo')) {
            $validated['photo_path'] = $request->file('photo')->store("clients/{$client->id}/assets", 'public');
        }
        unset($validated['photo']);

        $validated['recorded_by_user_id'] = $request->user()->id;

        $client->personalAssets()->create($validated);

        AuditLogger::log('clients.personal_asset.create', $client);

        return back()->with('success', 'Personal asset added.');
    }

    public function update(Request $request, Client $client, ClientPersonalAsset $asset)
    {
        $this->authorize('update', $client);

        abort_unless($asset->client_id === $client->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'condition' => ['nullable', 'string', 'in:new,good,fair,poor'],
            'location' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'acquired_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($request->hasFile('photo')) {
            if ($asset->photo_path) {
                Storage::disk('public')->delete($asset->photo_path);
            }
            $validated['photo_path'] = $request->file('photo')->store("clients/{$client->id}/assets", 'public');
        }
        unset($validated['photo']);

        $asset->update($validated);

        AuditLogger::log('clients.personal_asset.update', $client);

        return back()->with('success', 'Personal asset updated.');
    }

    public function destroy(Request $request, Client $client, ClientPersonalAsset $asset)
    {
        $this->authorize('update', $client);

        abort_unless($asset->client_id === $client->id, 404);

        if ($asset->photo_path) {
            Storage::disk('public')->delete($asset->photo_path);
        }

        $asset->delete();

        AuditLogger::log('clients.personal_asset.delete', $client);

        return back()->with('success', 'Personal asset removed.');
    }
}
