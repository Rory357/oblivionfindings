<?php

namespace App\Http\Controllers;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\ClientPersonalAsset;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Services\AuditLogger;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            'site_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'tracker_hardware_id' => ['nullable', 'integer'],
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

    /**
     * Validate profile picker IDs against the client's canonical Site and convert
     * the canonical Device id back to the temporary LocationHardware FK.
     *
     * @return array<string, mixed>
     */
    private function validatedAssetData(
        Request $request,
        Client $client,
        ?ClientPersonalAsset $asset = null,
    ): array {
        $validated = $request->validate($this->validationRules());
        $errors = [];
        $clientSiteId = is_numeric($client->site_id) ? (int) $client->site_id : null;

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        if ($siteId !== null) {
            $siteIsEligible = $clientSiteId !== null
                && $siteId === $clientSiteId
                && Site::query()
                    ->whereKey($siteId)
                    ->where('is_active', true)
                    ->exists();

            if (! $siteIsEligible) {
                $errors['site_id'] = "Choose the client's active Site.";
            }
        }

        $roomId = isset($validated['room_id']) ? (int) $validated['room_id'] : null;
        if ($roomId !== null) {
            $roomIsEligible = $siteId !== null
                && $siteId === $clientSiteId
                && SiteHouseRoom::query()
                    ->whereKey($roomId)
                    ->where('site_id', $siteId)
                    ->where('is_active', true)
                    ->exists();

            if (! $roomIsEligible) {
                $errors['room_id'] = 'Choose an active room within the selected site.';
            }
        }

        if (array_key_exists('tracker_hardware_id', $validated)) {
            $submittedDeviceId = $validated['tracker_hardware_id'] === null
                ? null
                : (int) $validated['tracker_hardware_id'];
            $canManageTrackers = (bool) ($request->user()?->canDo('fleet.manage')
                || $request->user()?->canDo('assets.trackers.manage'));
            $access = app(SecurityDevicesAccessService::class);
            $canUseUnassignedStock = $request->user() !== null
                && $access->canViewUnassigned($request->user());

            if (! $canManageTrackers || ! $canUseUnassignedStock) {
                if ($submittedDeviceId !== null) {
                    $errors['tracker_hardware_id'] = 'Managing trackers requires unassigned stock access.';
                }

                // Ordinary client editors may update other asset fields without
                // silently detaching an existing tracker hidden from their UI.
                unset($validated['tracker_hardware_id']);
            } elseif ($submittedDeviceId === null) {
                $validated['tracker_hardware_id'] = null;
            } else {
                $device = $access->unassignedTrackingDeviceForClient(
                    $request->user(),
                    $client,
                    $submittedDeviceId,
                );

                $legacyHardware = ($device?->legacy_location_hardware_id && $clientSiteId !== null)
                    ? LocationHardware::query()
                        ->whereKey($device->legacy_location_hardware_id)
                        ->where('site_id', $clientSiteId)
                        ->where('category', LocationHardware::CATEGORY_TRACKER)
                        ->where('status', '!=', LocationHardware::STATUS_RETIRED)
                        ->first()
                    : null;

                $usedByAnotherAsset = $legacyHardware
                    ? ClientPersonalAsset::query()
                        ->where('tracker_hardware_id', $legacyHardware->id)
                        ->when($asset, fn ($query) => $query->whereKeyNot($asset->id))
                        ->exists()
                    : false;

                if (! $device || ! $legacyHardware || $usedByAnotherAsset) {
                    $errors['tracker_hardware_id'] = ! $device
                        ? "Choose an unassigned tracking device for the client's Site."
                        : (! $legacyHardware
                            ? 'That tracking device is not linked to the required compatibility record.'
                            : 'That tracking device is already linked to another personal asset.');
                } else {
                    $validated['tracker_hardware_id'] = $legacyHardware->id;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $validated = $this->validatedAssetData($request, $client);

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

        $validated = $this->validatedAssetData($request, $client, $asset);

        $oldStatus = $asset->status;
        $oldPhotoPath = $asset->photo_path;
        $newPhotoPath = null;

        if ($request->hasFile('photo')) {
            $newPhotoPath = $request->file('photo')->store("clients/{$client->id}/assets", 'public');
            $validated['photo_path'] = $newPhotoPath;
        }
        unset($validated['photo']);

        // Auto-set disposed_at when status changes to disposed/returned
        $newStatus = $validated['status'] ?? $oldStatus;
        if (in_array($newStatus, ['disposed', 'returned']) && ! in_array($oldStatus, ['disposed', 'returned'])) {
            $validated['disposed_at'] = $validated['disposed_at'] ?? now()->toDateString();
        }

        try {
            DB::transaction(function () use ($asset, $client, $request, $validated, $oldStatus, $newStatus): void {
                $asset->update($validated);

                $this->recordStatusTransition(
                    $request,
                    $client,
                    $asset,
                    $oldStatus,
                    $newStatus,
                    $validated['disposal_reason'] ?? $validated['notes'] ?? null,
                );
            });
        } catch (Throwable $exception) {
            if ($newPhotoPath !== null) {
                Storage::disk('public')->delete($newPhotoPath);
            }

            throw $exception;
        }

        if ($newPhotoPath !== null && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath) {
            Storage::disk('public')->delete($oldPhotoPath);
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

        DB::transaction(function () use ($asset, $client, $request, $validated, $oldStatus, $newStatus): void {
            if (in_array($newStatus, ['disposed', 'returned']) && ! in_array($oldStatus, ['disposed', 'returned'])) {
                $asset->disposed_at = now();
                $asset->disposal_reason = $validated['disposal_reason'] ?? null;
            }

            $asset->status = $newStatus;
            $asset->save();

            $this->recordStatusTransition(
                $request,
                $client,
                $asset,
                $oldStatus,
                $newStatus,
                $validated['disposal_reason'] ?? null,
            );
        });

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

    private function recordStatusTransition(
        Request $request,
        Client $client,
        ClientPersonalAsset $asset,
        string $oldStatus,
        string $newStatus,
        ?string $body,
    ): void {
        if ($oldStatus === $newStatus || ! in_array($newStatus, ['lost', 'damaged', 'disposed', 'returned'])) {
            return;
        }

        $statusLabels = [
            'lost' => 'reported as lost',
            'damaged' => 'reported as damaged',
            'disposed' => 'disposed of',
            'returned' => 'returned',
        ];
        $actorId = $request->user()->id;

        app(TimelineEmitter::class)->record([
            'client_id' => $client->id,
            'actor_user_id' => $actorId,
            'site_id' => $client->site_id,
            'type' => 'personal_asset_status_changed',
            'occurred_at' => now(),
            'subject' => "Personal asset {$statusLabels[$newStatus]}: {$asset->name}",
            'body' => $body,
            'meta' => [
                'personal_asset_id' => $asset->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
            ],
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $actorId,
        ]);
    }
}
