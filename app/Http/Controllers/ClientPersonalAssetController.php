<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientPersonalAsset;
use App\Models\TimelineEvent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientPersonalAssetController extends Controller
{
    private function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'condition' => ['nullable', 'string', 'in:new,good,fair,poor'],
            'location' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'room_id' => ['nullable', 'integer', 'exists:site_house_rooms,id'],
            'tracker_hardware_id' => ['nullable', 'integer', 'exists:location_hardware,id'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'acquired_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', 'in:active,in_repair,lost,damaged,disposed,returned'],
            'ownership' => ['nullable', 'string', 'in:client,provider,funded,loaned'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'return_required' => ['nullable', 'boolean'],
            'return_by' => ['nullable', 'date'],
            'last_serviced_at' => ['nullable', 'date'],
            'next_service_due' => ['nullable', 'date'],
            'service_provider' => ['nullable', 'string', 'max:255'],
            'warranty_expires_at' => ['nullable', 'date'],
            'insurance_reference' => ['nullable', 'string', 'max:255'],
            'portal_visible' => ['nullable', 'boolean'],
            'disposed_at' => ['nullable', 'date'],
            'disposal_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $validated = $request->validate($this->validationRules());

        if ($request->hasFile('photo')) {
            $validated['photo_path'] = $request->file('photo')->store("clients/{$client->id}/assets", 'public');
        }
        unset($validated['photo']);

        $validated['recorded_by_user_id'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['ownership'] = $validated['ownership'] ?? 'client';

        $asset = $client->personalAssets()->create($validated);

        AuditLogger::log('clients.personal_asset.create', $client);

        return back()->with('success', 'Personal asset added.');
    }

    public function update(Request $request, Client $client, ClientPersonalAsset $asset)
    {
        $this->authorize('update', $client);

        abort_unless($asset->client_id === $client->id, 404);

        $validated = $request->validate($this->validationRules());

        $oldStatus = $asset->status;

        if ($request->hasFile('photo')) {
            if ($asset->photo_path) {
                Storage::disk('public')->delete($asset->photo_path);
            }
            $validated['photo_path'] = $request->file('photo')->store("clients/{$client->id}/assets", 'public');
        }
        unset($validated['photo']);

        // Auto-set disposed_at when status changes to disposed/returned
        $newStatus = $validated['status'] ?? $oldStatus;
        if (in_array($newStatus, ['disposed', 'returned']) && !in_array($oldStatus, ['disposed', 'returned'])) {
            $validated['disposed_at'] = $validated['disposed_at'] ?? now()->toDateString();
        }

        $asset->update($validated);

        // Create timeline event on significant status changes
        if ($oldStatus !== $newStatus && in_array($newStatus, ['lost', 'damaged', 'disposed', 'returned'])) {
            $statusLabels = [
                'lost' => 'reported as lost',
                'damaged' => 'reported as damaged',
                'disposed' => 'disposed of',
                'returned' => 'returned',
            ];

            TimelineEvent::create([
                'client_id' => $client->id,
                'actor_id' => $request->user()->id,
                'site_id' => $client->site_id,
                'type' => 'note',
                'source_type' => 'personal_asset',
                'source_id' => $asset->id,
                'occurred_at' => now(),
                'subject' => "Personal asset {$statusLabels[$newStatus]}: {$asset->name}",
                'body' => $validated['disposal_reason'] ?? $validated['notes'] ?? null,
                'visibility' => 'staff',
            ]);
        }

        AuditLogger::log('clients.personal_asset.update', $client);

        return back()->with('success', 'Personal asset updated.');
    }

    public function updateStatus(Request $request, Client $client, ClientPersonalAsset $asset)
    {
        $this->authorize('update', $client);

        abort_unless($asset->client_id === $client->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,in_repair,lost,damaged,disposed,returned'],
            'disposal_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $oldStatus = $asset->status;
        $newStatus = $validated['status'];

        if (in_array($newStatus, ['disposed', 'returned']) && !in_array($oldStatus, ['disposed', 'returned'])) {
            $asset->disposed_at = now();
            $asset->disposal_reason = $validated['disposal_reason'] ?? null;
        }

        $asset->status = $newStatus;
        $asset->save();

        if ($oldStatus !== $newStatus && in_array($newStatus, ['lost', 'damaged', 'disposed', 'returned'])) {
            $statusLabels = [
                'lost' => 'reported as lost',
                'damaged' => 'reported as damaged',
                'disposed' => 'disposed of',
                'returned' => 'returned',
            ];

            TimelineEvent::create([
                'client_id' => $client->id,
                'actor_id' => $request->user()->id,
                'site_id' => $client->site_id,
                'type' => 'note',
                'source_type' => 'personal_asset',
                'source_id' => $asset->id,
                'occurred_at' => now(),
                'subject' => "Personal asset {$statusLabels[$newStatus]}: {$asset->name}",
                'body' => $validated['disposal_reason'] ?? null,
                'visibility' => 'staff',
            ]);
        }

        AuditLogger::log('clients.personal_asset.status_change', $client);

        return back()->with('success', 'Asset status updated.');
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
