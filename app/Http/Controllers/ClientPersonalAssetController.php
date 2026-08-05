<?php

namespace App\Http\Controllers;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\ClientPersonalAsset;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Services\AuditLogger;
use App\Services\Clients\ClientPersonalAssetTrackerService;
use App\Services\ConsentValidationService;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ClientPersonalAssetController extends Controller
{
    public function __construct(
        private readonly ClientPersonalAssetTrackerService $trackers,
    ) {}

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
            'tracker_device_id' => ['nullable', 'integer'],
            'tracker_hardware_id' => ['prohibited'],
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
     * tracker input to the canonical Device reference. LocationHardware is
     * consulted only as read-only Site compatibility evidence.
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
        $newStatus = $validated['status'] ?? $asset?->status ?? 'active';
        $currentDeviceId = $asset ? $this->canonicalTrackerDeviceId($asset) : null;

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

        if (array_key_exists('tracker_device_id', $validated)) {
            $submittedDeviceId = $validated['tracker_device_id'] === null
                ? null
                : (int) $validated['tracker_device_id'];
            $canManageTrackers = (bool) ($request->user()?->canDo('fleet.manage')
                || $request->user()?->canDo('assets.trackers.manage'));
            $access = app(SecurityDevicesAccessService::class);
            $canUseUnassignedStock = $request->user() !== null
                && $access->canViewUnassigned($request->user());

            if (! $canManageTrackers || ! $canUseUnassignedStock) {
                if ($submittedDeviceId !== null) {
                    $errors['tracker_device_id'] = 'Managing trackers requires unassigned stock access.';
                }

                // Ordinary client editors may update other asset fields without
                // silently detaching an existing tracker hidden from their UI.
                unset($validated['tracker_device_id']);
            } elseif ($submittedDeviceId === null) {
                $validated['tracker_device_id'] = null;
            } elseif (in_array($newStatus, ['disposed', 'returned'], true)
                && $submittedDeviceId === $currentDeviceId) {
                // Preserve the canonical reference as history while the
                // lifecycle transaction releases its active assignment.
                $validated['tracker_device_id'] = $submittedDeviceId;
            } else {
                $device = $currentDeviceId === $submittedDeviceId
                    ? $access->visibleDevices($request->user())
                        ->whereKey($submittedDeviceId)
                        ->where('domain', 'tracking')
                        ->first()
                    : $access->unassignedTrackingDeviceForClient(
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

                $usedByAnotherAsset = $device
                    ? ClientPersonalAsset::query()
                        ->where('tracker_device_id', $device->id)
                        ->whereNotIn('status', ['disposed', 'returned'])
                        ->when($asset, fn ($query) => $query->whereKeyNot($asset->id))
                        ->exists()
                    : false;
                $consent = ConsentValidationService::latestValidTrackingConsentForClient($client);

                if (! $device || ! $legacyHardware || $usedByAnotherAsset || ! $consent) {
                    $errors['tracker_device_id'] = ! $device
                        ? "Choose an unassigned tracking device for the client's Site."
                        : (! $legacyHardware
                            ? 'That tracking device is not linked to the required compatibility record.'
                            : ($usedByAnotherAsset
                                ? 'That tracking device is already linked to another active personal asset.'
                                : 'Assigning a personal tracker requires an active location tracking consent.'));
                } else {
                    $validated['tracker_device_id'] = $device->id;
                }
            }
        }

        $submittedDeviceId = $validated['tracker_device_id'] ?? null;
        if (in_array($newStatus, ['disposed', 'returned'], true)
            && $submittedDeviceId !== null
            && $submittedDeviceId !== $currentDeviceId) {
            $errors['tracker_device_id'] = 'Returned or disposed assets cannot receive a tracking device.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    private function canonicalTrackerDeviceId(ClientPersonalAsset $asset): ?int
    {
        if (is_numeric($asset->tracker_device_id)) {
            return (int) $asset->tracker_device_id;
        }
        if (! is_numeric($asset->tracker_hardware_id)) {
            return null;
        }

        $matches = Device::query()
            ->where('legacy_location_hardware_id', (int) $asset->tracker_hardware_id)
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $validated = $this->validatedAssetData($request, $client);
        $trackerSubmitted = array_key_exists('tracker_device_id', $validated);
        $trackerDeviceId = $trackerSubmitted ? $validated['tracker_device_id'] : null;
        unset($validated['tracker_device_id']);

        if ($request->hasFile('photo')) {
            $validated['photo_path'] = $request->file('photo')->store("clients/{$client->id}/assets", 'public');
        }
        unset($validated['photo']);

        $validated['recorded_by_user_id'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['ownership'] = $validated['ownership'] ?? 'client';

        try {
            $asset = DB::transaction(function () use (
                $client,
                $request,
                $validated,
                $trackerSubmitted,
                $trackerDeviceId,
            ): ClientPersonalAsset {
                $asset = $client->personalAssets()->create($validated);
                if ($trackerSubmitted && $trackerDeviceId !== null) {
                    $asset = $this->trackers->replaceTracker(
                        $asset,
                        $client,
                        (int) $trackerDeviceId,
                        (int) $request->user()->id,
                    );
                }

                return $asset;
            });
        } catch (Throwable $exception) {
            if (isset($validated['photo_path'])) {
                Storage::disk('public')->delete($validated['photo_path']);
            }

            throw $exception;
        }

        AuditLogger::log('clients.personal_asset.create', $client);

        return back()->with('success', 'Personal asset added.');
    }

    public function update(Request $request, Client $client, ClientPersonalAsset $asset)
    {
        $this->authorize('update', $client);

        abort_unless($asset->client_id === $client->id, 404);

        $validated = $this->validatedAssetData($request, $client, $asset);
        $trackerSubmitted = array_key_exists('tracker_device_id', $validated);
        $trackerDeviceId = $trackerSubmitted ? $validated['tracker_device_id'] : null;
        unset($validated['tracker_device_id']);

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
            DB::transaction(function () use (
                $asset,
                $client,
                $request,
                $validated,
                $oldStatus,
                $newStatus,
                $trackerSubmitted,
                $trackerDeviceId,
            ): void {
                if ($trackerSubmitted && ! in_array($newStatus, ['disposed', 'returned'], true)) {
                    $this->trackers->replaceTracker(
                        $asset,
                        $client,
                        $trackerDeviceId === null ? null : (int) $trackerDeviceId,
                        (int) $request->user()->id,
                    );
                }

                $asset->refresh();
                $asset->update($validated);

                if (in_array($newStatus, ['disposed', 'returned'], true)) {
                    $this->trackers->releaseTracker(
                        $asset,
                        $client,
                        (int) $request->user()->id,
                    );
                }

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

            if (in_array($newStatus, ['disposed', 'returned'], true)) {
                $this->trackers->releaseTracker(
                    $asset,
                    $client,
                    (int) $request->user()->id,
                );
            }

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

        DB::transaction(function () use ($asset, $client, $request): void {
            $this->trackers->releaseTracker(
                $asset,
                $client,
                (int) $request->user()->id,
            );
            $asset->delete();
        });

        if ($asset->photo_path) {
            Storage::disk('public')->delete($asset->photo_path);
        }

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
