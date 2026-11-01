<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The Notes & documents tab every new device page shares (drawing pg-sdr-5d): upkeep dates, its
 * documents with their expiry, notes and upkeep records, and the asset record it's linked to.
 * Today's Documents, Maintenance and Link asset in one plain tab.
 */
final class DeviceNotesTab
{
    /** Stored document kinds in words (the categories the upload accepts). */
    public const array KINDS = [
        'manual' => 'Manual',
        'install_photo' => 'Install photo',
        'compliance_cert' => 'Certificate',
        'firmware_notes' => 'Software notes',
        'configuration' => 'Settings',
        'network_diagram' => 'Network plan',
        'other' => 'Other',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, Device $device, CarbonImmutable $now): array
    {
        $canSeeUpkeep = $viewer->canDo('securityDevices.maintenance.view');

        return [
            'upkeep' => $this->upkeep($device),
            'documents' => $device->documents()
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (DeviceDocument $document): array => [
                    'id' => (int) $document->id,
                    'title' => (string) $document->title,
                    'file' => $this->fileWords($document),
                    'kind' => self::KINDS[(string) $document->category] ?? DevicePageWords::sentence((string) $document->category),
                    'added_at' => $document->created_at?->toIso8601String(),
                    'expiry' => $document->expiry_date === null ? null : [
                        'date' => $document->expiry_date->toDateString(),
                        'ended' => $document->expiry_date->lt($now->startOfDay()),
                        'soon' => $document->expiry_date->gte($now->startOfDay()) && $document->expiry_date->lte($now->addDays(60)),
                    ],
                    'href' => "/security-devices/devices/{$device->id}/documents/{$document->id}",
                ])
                ->values()
                ->all(),
            'kinds' => collect(self::KINDS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'notes' => $this->notes($device, $canSeeUpkeep),
            'purchase' => $this->purchase($viewer, $device),
        ];
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> label, value, date (ISO) to format */
    private function upkeep(Device $device): array
    {
        $rows = [];
        if ($device->commissioned_at !== null) {
            $rows[] = ['Installed', '', $device->commissioned_at->toDateString()];
        }
        if ($device->next_service_due !== null) {
            $rows[] = ['Next service', '', $device->next_service_due->toDateString()];
        }
        if ($device->warranty_expires_at !== null) {
            $rows[] = ['Warranty until', '', $device->warranty_expires_at->toDateString()];
        }
        if ($device->commissioned_at !== null && (int) $device->expected_lifespan_months > 0) {
            $rows[] = ['Replace by', '', $device->commissioned_at->copy()->addMonths((int) $device->expected_lifespan_months)->toDateString()];
        }

        return $rows;
    }

    private function fileWords(DeviceDocument $document): string
    {
        $extension = strtoupper((string) pathinfo((string) $document->original_name, PATHINFO_EXTENSION));
        $parts = array_filter([
            $extension !== '' ? $extension : null,
            $document->version ? 'version '.$document->version : null,
            $document->notes ? mb_strimwidth((string) $document->notes, 0, 80, '…') : null,
        ]);

        return $parts === [] ? 'File' : implode(' · ', $parts);
    }

    /** @return list<array<string, mixed>> */
    private function notes(Device $device, bool $canSeeUpkeep): array
    {
        $items = [];
        $note = trim((string) $device->notes);
        if ($note !== '') {
            $items[] = [
                'key' => 'device-note',
                // The device's own note keeps no date of its own.
                'at' => null,
                'tone' => 'info',
                'title' => mb_strimwidth($note, 0, 140, '…'),
                'sub' => 'Note on this device',
            ];
        }
        if ($canSeeUpkeep) {
            $device->maintenanceRecords()
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->each(function (DeviceMaintenanceRecord $record) use (&$items): void {
                    $status = strtolower((string) $record->status);
                    $type = (string) $record->type;
                    $items[] = [
                        'key' => 'upkeep-'.$record->id,
                        'at' => ($record->completed_at ?? $record->scheduled_for ?? $record->created_at)?->toIso8601String(),
                        'tone' => match ($status) {
                            'completed', 'done' => 'success',
                            'overdue', 'failed' => 'warning',
                            'cancelled' => 'none',
                            default => 'info',
                        },
                        'title' => trim((string) $record->description) !== ''
                            ? mb_strimwidth((string) $record->description, 0, 140, '…')
                            : DevicePageWords::sentence($type),
                        'sub' => DevicePageWords::sentence($type).' · '.mb_strtolower(DevicePageWords::sentence($status !== '' ? $status : 'recorded')),
                    ];
                });
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function purchase(User $viewer, Device $device): array
    {
        $assetIds = $this->access->accessibleAssetIds($viewer);
        $link = $device->activeAssetLinks()
            ->with('asset')
            ->get()
            ->first(fn (DeviceAssetLink $link): bool => in_array((int) $link->asset_id, $assetIds, true));
        $asset = $link?->asset;
        // Same destinations as today's profile: a vehicle opens only for Fleet staff.
        $isVehicle = $asset !== null && strcasecmp((string) $asset->category, 'vehicle') === 0;
        $href = match (true) {
            $asset === null => null,
            $isVehicle => $viewer->canDo('fleet.viewAny') ? "/fleet-assets/vehicles/{$asset->id}?tab=technology" : null,
            default => "/fleet-assets/assets/{$asset->id}?tab=technology",
        };

        return [
            'price' => $device->purchase_price === null ? null : '$'.number_format((float) $device->purchase_price, 2),
            'asset' => $asset === null ? null : [
                'name' => (string) $asset->name,
                'tag' => $asset->asset_tag ? (string) $asset->asset_tag : null,
                'href' => $href,
            ],
        ];
    }
}
