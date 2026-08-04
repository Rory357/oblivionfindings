<?php

namespace App\Domain\SecurityDevices\Console;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-time migration command: moves data from legacy hardware tables into the
 * canonical devices table and related assignment/link tables.
 *
 * MATCHING ORDER (most confident first):
 *  1. MAC address (exact, case-insensitive)  — highest confidence hardware identifier
 *  2. IMEI (exact)                            — unique per cellular device
 *  3. Serial number (exact, case-insensitive) — manufacturer-assigned, usually unique
 *  4. Provider + external_ref (exact)         — integration-assigned identifier
 *
 * Any match at a lower tier is only accepted if higher tiers didn't match.
 * Ambiguous cases (multiple candidates) are logged as warnings, not auto-merged.
 *
 * CATEGORY MAPPING:
 *  Conservative — when the legacy category maps cleanly, use the specific new taxonomy.
 *  When ambiguous, fall back to a safe generic (e.g. sensor → iot_healthcare/environmental/temperature).
 *  The 'other' catch-all maps to facilities/building_safety/other with a warning.
 */
class MigrateDevicesCommand extends Command
{
    protected $signature = 'sd:migrate-devices
        {--dry-run : Report what would happen without writing}
        {--rollback : Delete all devices created by a previous migration run}';

    protected $description = 'Migrate legacy hardware tables into canonical devices table';

    // ── Counters ──────────────────────────────────────────────────

    private int $locationHardwareScanned = 0;

    private int $controlRoomDevicesScanned = 0;

    private int $assetTrackersScanned = 0;

    private int $devicesCreated = 0;

    private int $duplicatesMerged = 0;

    private int $assignmentsCreated = 0;

    private int $assetLinksCreated = 0;

    private int $skipped = 0;

    private array $warnings = [];

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->handleRollback();
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no data will be written.');
        }

        $this->info('');
        $this->info('Phase A: Migrating location_hardware...');
        $this->migrateLocationHardware($dryRun);

        $this->info('Phase B: Migrating control_room_devices...');
        $this->migrateControlRoomDevices($dryRun);

        $this->info('Phase C: Migrating asset_trackers...');
        $this->migrateAssetTrackers($dryRun);

        $this->printReport($dryRun);

        return self::SUCCESS;
    }

    // ── Phase A: location_hardware ────────────────────────────────

    private function migrateLocationHardware(bool $dryRun): void
    {
        $rows = DB::table('location_hardware')->whereNull('deleted_at')->get();
        $this->locationHardwareScanned = $rows->count();

        foreach ($rows as $row) {
            // PR26 keeps devices.legacy_location_hardware_id as the surviving
            // idempotency bridge for location history compatibility.
            $existing = Device::where('legacy_location_hardware_id', $row->id)->first();
            if ($existing) {
                $this->skipped++;

                continue;
            }

            [$domain, $category, $subcategory] = $this->mapLocationHardwareCategory($row->category);

            $deviceData = [
                'name' => $row->name,
                'domain' => $domain,
                'category' => $category,
                'subcategory' => $subcategory,
                'serial_number' => $row->serial ?: null,
                'mac_address' => $row->mac ?: null,
                'asset_tag' => $row->asset_tag ?: null,
                'status' => $this->mapLocationHardwareStatus($row->status),
                'health_status' => $this->inferHealthFromStatus($row->status),
                'last_seen_at' => $row->last_seen_at,
                'provider' => $row->provider,
                'external_ref' => $row->external_ref ? json_decode($row->external_ref, true) : null,
                'meta' => $row->meta ? json_decode($row->meta, true) : null,
                'notes' => $row->notes,
                'legacy_location_hardware_id' => $row->id,
            ];

            // Extract extra fields from external_ref if available.
            $extRef = $deviceData['external_ref'] ?? [];
            if (! empty($extRef['firmware_version'])) {
                $deviceData['firmware_version'] = $extRef['firmware_version'];
            }
            if (! empty($extRef['model'])) {
                $deviceData['model'] = $extRef['model'];
            }
            if (! empty($extRef['ip'])) {
                $deviceData['ip_address'] = $extRef['ip'];
            }

            if ($dryRun) {
                $this->devicesCreated++;
                $this->planAssignmentsForLocationHardware($row, null, $dryRun);

                continue;
            }

            DB::transaction(function () use ($deviceData, $row) {
                $device = Device::create($deviceData);

                $this->devicesCreated++;

                $this->planAssignmentsForLocationHardware($row, $device, false);
            });
        }
    }

    private function planAssignmentsForLocationHardware(object $row, ?Device $device, bool $dryRun): void
    {
        // Site assignment.
        if ($row->site_id) {
            if (! $dryRun && $device) {
                $assignableType = 'site';
                $assignableId = $row->site_id;

                // If room is set, assign to room instead (implies site).
                if ($row->room_id) {
                    $assignableType = 'room';
                    $assignableId = $row->room_id;
                }

                DeviceAssignment::create([
                    'device_id' => $device->id,
                    'assignable_type' => $assignableType,
                    'assignable_id' => $assignableId,
                    'assigned_at' => $row->created_at ?? now(),
                ]);
            }
            $this->assignmentsCreated++;
        }

        // Person assignment (staff or client).
        if ($row->linked_person_type && $row->linked_person_id) {
            $targetType = match ($row->linked_person_type) {
                'staff' => 'staff',
                'client' => 'client',
                default => null,
            };

            if ($targetType) {
                if (! $dryRun && $device) {
                    DeviceAssignment::create([
                        'device_id' => $device->id,
                        'assignable_type' => $targetType,
                        'assignable_id' => $row->linked_person_id,
                        'assigned_at' => $row->created_at ?? now(),
                    ]);
                }
                $this->assignmentsCreated++;
            } else {
                $this->addWarning("location_hardware #{$row->id}: unknown linked_person_type '{$row->linked_person_type}'");
            }
        }

        // Asset link.
        if ($row->linked_asset_id) {
            $linkType = ($row->category === 'tracker') ? LinkType::InstalledIn : LinkType::Primary;

            if (! $dryRun && $device) {
                DeviceAssetLink::create([
                    'device_id' => $device->id,
                    'asset_id' => $row->linked_asset_id,
                    'link_type' => $linkType,
                    'linked_at' => $row->created_at ?? now(),
                ]);
            }
            $this->assetLinksCreated++;
        }
    }

    // ── Phase B: control_room_devices ─────────────────────────────

    private function migrateControlRoomDevices(bool $dryRun): void
    {
        $rows = DB::table('control_room_devices')->whereNull('deleted_at')->get();
        $this->controlRoomDevicesScanned = $rows->count();

        foreach ($rows as $row) {
            // Idempotency: skip if already migrated.
            if ($row->canonical_device_id ?? null) {
                $this->skipped++;

                continue;
            }

            // Attempt deduplication against devices already created in Phase A.
            $match = $this->findDuplicateDevice($row);

            if ($match) {
                // Merge signal-pipeline fields into existing device.
                if (! $dryRun) {
                    DB::transaction(function () use ($match, $row) {
                        $updates = [];

                        // Merge health/signal fields if the CR device has newer data.
                        if ($row->last_signal_at && (! $match->last_signal_at || $row->last_signal_at > $match->last_signal_at->toDateTimeString())) {
                            $updates['last_signal_at'] = $row->last_signal_at;
                        }
                        if ($row->battery_level !== null && $match->battery_level === null) {
                            $updates['battery_level'] = $row->battery_level;
                            $updates['battery_updated_at'] = $row->battery_updated_at;
                        }
                        if ($row->latitude && ! $match->latitude) {
                            $updates['latitude'] = $row->latitude;
                            $updates['longitude'] = $row->longitude;
                        }
                        if ($row->location_description && ! $match->location_description) {
                            $updates['location_description'] = $row->location_description;
                        }

                        if ($updates !== []) {
                            $match->update($updates);
                        }

                        DB::table('control_room_devices')
                            ->where('id', $row->id)
                            ->update(['canonical_device_id' => $match->id]);
                    });
                }
                $this->duplicatesMerged++;

                continue;
            }

            // No duplicate — create a new device.
            [$domain, $category, $subcategory] = $this->mapControlRoomDeviceType($row->type);

            $deviceData = [
                'name' => $row->name,
                'domain' => $domain,
                'category' => $category,
                'subcategory' => $subcategory,
                'manufacturer' => $row->vendor,
                'model' => $row->model,
                'status' => $this->mapControlRoomStatus($row->status),
                'health_status' => $this->inferHealthFromCrStatus($row->status, $row->last_seen_at),
                'last_seen_at' => $row->last_seen_at,
                'last_signal_at' => $row->last_signal_at,
                'battery_level' => $row->battery_level,
                'battery_updated_at' => $row->battery_updated_at,
                'latitude' => $row->latitude,
                'longitude' => $row->longitude,
                'location_description' => $row->location_description,
                'provider' => $row->vendor, // best-effort provider mapping
                'external_ref' => $row->external_ref ? ['cr_external_ref' => $row->external_ref] : null,
                'config' => $row->config ? json_decode($row->config, true) : null,
            ];

            if ($dryRun) {
                $this->devicesCreated++;
                $this->planAssignmentsForControlRoomDevice($row, null, $dryRun);

                continue;
            }

            DB::transaction(function () use ($deviceData, $row) {
                $device = Device::create($deviceData);

                DB::table('control_room_devices')
                    ->where('id', $row->id)
                    ->update(['canonical_device_id' => $device->id]);

                $this->devicesCreated++;

                $this->planAssignmentsForControlRoomDevice($row, $device, false);
            });
        }
    }

    private function planAssignmentsForControlRoomDevice(object $row, ?Device $device, bool $dryRun): void
    {
        if ($row->site_id) {
            if (! $dryRun && $device) {
                // Only create if device doesn't already have a site assignment (from Phase A merge).
                $existingAssignment = DeviceAssignment::where('device_id', $device->id)
                    ->whereNull('released_at')
                    ->first();

                if (! $existingAssignment) {
                    DeviceAssignment::create([
                        'device_id' => $device->id,
                        'assignable_type' => 'site',
                        'assignable_id' => $row->site_id,
                        'assigned_at' => $row->created_at ?? now(),
                    ]);
                    $this->assignmentsCreated++;
                }
            } else {
                $this->assignmentsCreated++;
            }
        }

        if ($row->client_id) {
            if (! $dryRun && $device) {
                DeviceAssignment::create([
                    'device_id' => $device->id,
                    'assignable_type' => 'client',
                    'assignable_id' => $row->client_id,
                    'assigned_at' => $row->created_at ?? now(),
                ]);
            }
            $this->assignmentsCreated++;
        }

        if ($row->asset_id) {
            if (! $dryRun && $device) {
                // Check if link already exists (from Phase A).
                $existingLink = DeviceAssetLink::where('device_id', $device->id)
                    ->where('asset_id', $row->asset_id)
                    ->whereNull('unlinked_at')
                    ->exists();

                if (! $existingLink) {
                    DeviceAssetLink::create([
                        'device_id' => $device->id,
                        'asset_id' => $row->asset_id,
                        'link_type' => LinkType::InstalledIn,
                        'linked_at' => $row->created_at ?? now(),
                    ]);
                    $this->assetLinksCreated++;
                }
            } else {
                $this->assetLinksCreated++;
            }
        }
    }

    // ── Phase C: asset_trackers ───────────────────────────────────

    private function migrateAssetTrackers(bool $dryRun): void
    {
        $rows = DB::table('asset_trackers')->get();
        $this->assetTrackersScanned = $rows->count();

        foreach ($rows as $row) {
            // PR26 keeps devices.legacy_asset_tracker_id as the surviving
            // idempotency bridge for legacy telemetry and consent reads.
            $existing = Device::where('legacy_asset_tracker_id', $row->id)->first();
            if ($existing) {
                $this->skipped++;

                continue;
            }

            // Attempt deduplication against devices created in Phase A+B.
            $match = $this->findDuplicateForTracker($row);

            if ($match) {
                if (! $dryRun) {
                    DB::transaction(function () use ($match, $row) {
                        $match->update([
                            'legacy_asset_tracker_id' => $row->id,
                            'imei' => $match->imei ?: ($row->imei ?: null),
                        ]);

                        // Create asset link if not already present.
                        $existingLink = DeviceAssetLink::where('device_id', $match->id)
                            ->where('asset_id', $row->asset_id)
                            ->whereNull('unlinked_at')
                            ->exists();

                        if (! $existingLink) {
                            DeviceAssetLink::create([
                                'device_id' => $match->id,
                                'asset_id' => $row->asset_id,
                                'link_type' => LinkType::InstalledIn,
                                'linked_at' => $row->paired_at ?? $row->created_at ?? now(),
                            ]);
                            $this->assetLinksCreated++;
                        }

                        $this->maybeCreateConsentAssignment($match, $row);
                    });
                } else {
                    $this->assetLinksCreated++;
                }
                $this->duplicatesMerged++;

                continue;
            }

            // No duplicate — create a new device. Its canonical asset link below
            // supplies operational provenance; legacy storage stays application-level.
            $deviceData = [
                'name' => "Tracker {$row->device_uid}",
                'domain' => 'tracking',
                'category' => 'vehicle_tracker',
                'subcategory' => 'hardwired_gps',
                'manufacturer' => $row->vendor,
                'imei' => $row->imei,
                'serial_number' => $row->serial_number,
                'status' => $this->mapTrackerStatus($row->status),
                'health_status' => HealthStatus::Unknown->value,
                'last_seen_at' => $row->last_seen_at,
                'provider' => $row->vendor,
                'external_ref' => $row->vendor_metadata ? json_decode($row->vendor_metadata, true) : null,
                'legacy_asset_tracker_id' => $row->id,
            ];

            if ($dryRun) {
                $this->devicesCreated++;
                $this->assetLinksCreated++; // will create link

                continue;
            }

            DB::transaction(function () use ($deviceData, $row) {
                $device = Device::create($deviceData);

                $this->devicesCreated++;

                // Asset link.
                DeviceAssetLink::create([
                    'device_id' => $device->id,
                    'asset_id' => $row->asset_id,
                    'link_type' => LinkType::InstalledIn,
                    'linked_at' => $row->paired_at ?? $row->created_at ?? now(),
                ]);
                $this->assetLinksCreated++;

                $this->maybeCreateConsentAssignment($device, $row);
            });
        }
    }

    /**
     * If the tracker has a consent_id, it was assigned to a client.
     * Try to resolve the client from the consent record.
     */
    private function maybeCreateConsentAssignment(Device $device, object $trackerRow): void
    {
        if (! $trackerRow->consent_id) {
            return;
        }

        $consent = DB::table('client_consents')->where('id', $trackerRow->consent_id)->first();
        if (! $consent || ! $consent->client_id) {
            $this->addWarning("asset_tracker #{$trackerRow->id}: consent_id {$trackerRow->consent_id} has no client_id");

            return;
        }

        // Don't duplicate if device already has a client assignment.
        $existing = DeviceAssignment::where('device_id', $device->id)
            ->where('assignable_type', 'client')
            ->where('assignable_id', $consent->client_id)
            ->whereNull('released_at')
            ->exists();

        if (! $existing) {
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => 'client',
                'assignable_id' => $consent->client_id,
                'assigned_at' => $trackerRow->paired_at ?? $trackerRow->created_at ?? now(),
                'consent_id' => $trackerRow->consent_id,
            ]);
            $this->assignmentsCreated++;
        }
    }

    // ── Deduplication ─────────────────────────────────────────────

    /**
     * Attempt to find an existing canonical device matching a control_room_devices row.
     * Uses a strict, tiered matching strategy.
     */
    private function findDuplicateDevice(object $crRow): ?Device
    {
        // The CR device model has: name, device_uid, type, vendor, model,
        // external_ref, site_id. No MAC, no serial, no IMEI directly.
        // Matching against Phase A devices is limited.

        // Tier 1: vendor + external_ref (integration-assigned ID).
        if ($crRow->vendor && $crRow->external_ref) {
            $candidates = Device::whereNotNull('external_ref')
                ->where('provider', $crRow->vendor)
                ->get()
                ->filter(function (Device $d) use ($crRow) {
                    $ref = $d->external_ref;
                    if (! is_array($ref)) {
                        return false;
                    }

                    // Check if any value in external_ref matches the CR external_ref.
                    return in_array($crRow->external_ref, $ref, true)
                        || ($ref['provider_entity_id'] ?? null) === $crRow->external_ref
                        || ($ref['controller_id'] ?? null) === $crRow->external_ref;
                });

            if ($candidates->count() === 1) {
                return $candidates->first();
            }
            if ($candidates->count() > 1) {
                $this->addWarning(
                    "control_room_device #{$crRow->id}: ambiguous match by vendor+external_ref ".
                    "(vendor={$crRow->vendor}, ref={$crRow->external_ref}) — {$candidates->count()} candidates. Skipping merge."
                );

                return null;
            }
        }

        // Tier 2: exact name + site_id (weak — only if single match).
        if ($crRow->name && $crRow->site_id) {
            $candidates = Device::where('name', $crRow->name)
                ->whereHas('assignments', function ($q) use ($crRow) {
                    $q->whereNull('released_at')
                        ->where('assignable_type', 'site')
                        ->where('assignable_id', $crRow->site_id);
                })
                ->get();

            if ($candidates->count() === 1) {
                return $candidates->first();
            }
            if ($candidates->count() > 1) {
                $this->addWarning(
                    "control_room_device #{$crRow->id}: ambiguous match by name+site ".
                    "(name={$crRow->name}, site={$crRow->site_id}) — {$candidates->count()} candidates. Skipping merge."
                );
            }
        }

        return null;
    }

    /**
     * Attempt to find an existing canonical device matching an asset_tracker row.
     */
    private function findDuplicateForTracker(object $trackerRow): ?Device
    {
        // Tier 1: IMEI (exact).
        if ($trackerRow->imei) {
            $match = Device::where('imei', $trackerRow->imei)->first();
            if ($match) {
                return $match;
            }
        }

        // Tier 2: serial_number (exact, case-insensitive).
        if ($trackerRow->serial_number) {
            $match = Device::whereRaw('LOWER(serial_number) = ?', [strtolower($trackerRow->serial_number)])->first();
            if ($match) {
                return $match;
            }
        }

        // Tier 3: vendor + device_uid (the tracker's own identifier).
        if ($trackerRow->vendor && $trackerRow->device_uid) {
            $candidates = Device::where('provider', $trackerRow->vendor)
                ->where('device_uid', $trackerRow->device_uid)
                ->get();

            if ($candidates->count() === 1) {
                return $candidates->first();
            }
            if ($candidates->count() > 1) {
                $this->addWarning(
                    "asset_tracker #{$trackerRow->id}: ambiguous match by vendor+device_uid ".
                    "(vendor={$trackerRow->vendor}, uid={$trackerRow->device_uid}) — {$candidates->count()} candidates."
                );
            }
        }

        return null;
    }

    // ── Category mapping ──────────────────────────────────────────

    /**
     * Map location_hardware category → [domain, category, subcategory].
     * Conservative: when ambiguous, use a safe generic.
     *
     * @return array{string, string, string|null}
     */
    private function mapLocationHardwareCategory(string $legacyCategory): array
    {
        return match ($legacyCategory) {
            'gateway' => ['it_infrastructure', 'network', 'router'],
            'switch' => ['it_infrastructure', 'network', 'switch'],
            'ap' => ['it_infrastructure', 'network', 'wireless_ap'],
            'camera' => ['security', 'cctv', 'dome_camera'],        // default to dome; refine manually
            'door' => ['security', 'access_control', 'card_reader'], // door controller → access_control
            'sensor' => ['iot_healthcare', 'environmental', 'temperature'], // generic sensor → safe default
            'nvr' => ['security', 'cctv', 'nvr'],
            'ai' => ['it_infrastructure', 'server', 'physical_server'], // AI device → server
            'tracker' => ['tracking', 'personal_tracker', 'wearable_gps'],   // default; may be vehicle_tracker
            'other' => ['facilities', 'building_safety', null],            // catch-all
            default => ['facilities', 'building_safety', null],
        };
    }

    /**
     * Map control_room_devices type → [domain, category, subcategory].
     *
     * @return array{string, string, string|null}
     */
    private function mapControlRoomDeviceType(string $type): array
    {
        return match ($type) {
            'camera' => ['security', 'cctv', 'dome_camera'],
            'door' => ['security', 'access_control', 'card_reader'],
            'sensor' => ['iot_healthcare', 'environmental', 'temperature'],
            'alarm_panel' => ['security', 'alarm', 'panel'],
            'bed_sensor' => ['iot_healthcare', 'bed_sensor', 'pressure_mat'],
            'personal_tracker' => ['tracking', 'personal_tracker', 'wearable_gps'],
            'vehicle_tracker' => ['tracking', 'vehicle_tracker', 'hardwired_gps'],
            'environmental' => ['iot_healthcare', 'environmental', 'temperature'],
            'network' => ['it_infrastructure', 'network', 'switch'],
            default => ['facilities', 'building_safety', null],
        };
    }

    // ── Status mapping ────────────────────────────────────────────

    private function mapLocationHardwareStatus(string $status): string
    {
        return match ($status) {
            'online' => DeviceStatus::Active->value,
            'offline' => DeviceStatus::Offline->value,
            'retired' => DeviceStatus::Decommissioned->value,
            default => DeviceStatus::Active->value, // 'unknown' → active (benefit of doubt)
        };
    }

    private function mapControlRoomStatus(string $status): string
    {
        return match ($status) {
            'online' => DeviceStatus::Active->value,
            'offline' => DeviceStatus::Offline->value,
            'maintenance' => DeviceStatus::Maintenance->value,
            'retired' => DeviceStatus::Decommissioned->value,
            default => DeviceStatus::Active->value,
        };
    }

    private function mapTrackerStatus(string $status): string
    {
        return match ($status) {
            'paired' => DeviceStatus::Active->value,
            'suspended' => DeviceStatus::Maintenance->value,
            'unpaired' => DeviceStatus::InStock->value,
            default => DeviceStatus::Active->value,
        };
    }

    private function inferHealthFromStatus(string $status): string
    {
        return match ($status) {
            'online' => HealthStatus::Healthy->value,
            'offline' => HealthStatus::Warning->value,
            'retired' => HealthStatus::Unknown->value,
            default => HealthStatus::Unknown->value,
        };
    }

    private function inferHealthFromCrStatus(string $status, $lastSeenAt): string
    {
        if ($status === 'offline') {
            return HealthStatus::Warning->value;
        }
        if ($status === 'online' && $lastSeenAt) {
            return HealthStatus::Healthy->value;
        }

        return HealthStatus::Unknown->value;
    }

    // ── Rollback ──────────────────────────────────────────────────

    private function handleRollback(): int
    {
        $this->warn('Rolling back migrated devices...');

        $deviceIds = $this->migratedDeviceIdsForRollback();
        $count = $deviceIds->count();

        if ($count === 0) {
            $this->info('No migrated devices found.');

            return self::SUCCESS;
        }

        $this->info("Found {$count} migrated devices.");

        // Clear the surviving Control Room projection bridge. The temporary
        // location_hardware.device_id and asset_trackers.device_id FKs were
        // removed in PR26 after audit confirmed they were no longer used.
        DB::table('control_room_devices')->whereNotNull('canonical_device_id')->update(['canonical_device_id' => null]);

        // Delete related records (assignments, links, events, etc.)
        DeviceAssignment::whereIn('device_id', $deviceIds)->delete();
        DeviceAssetLink::whereIn('device_id', $deviceIds)->delete();

        // Force-delete the devices (bypass soft delete for clean rollback).
        Device::whereIn('id', $deviceIds)->forceDelete();

        $this->info("Rolled back {$count} devices and their assignments/links.");

        return self::SUCCESS;
    }

    private function migratedDeviceIdsForRollback(): Collection
    {
        $canonicalDeviceIds = Device::query()
            ->where(function ($q) {
                $q->whereNotNull('legacy_location_hardware_id')
                    ->orWhereNotNull('legacy_asset_tracker_id');
            })
            ->pluck('id');

        $controlRoomDeviceIds = DB::table('control_room_devices')
            ->whereNotNull('canonical_device_id')
            ->pluck('canonical_device_id');

        return $canonicalDeviceIds
            ->merge($controlRoomDeviceIds)
            ->filter()
            ->unique()
            ->values();
    }

    // ── Reporting ─────────────────────────────────────────────────

    private function addWarning(string $message): void
    {
        $this->warnings[] = $message;
        $this->warn("  ⚠ {$message}");
    }

    private function printReport(bool $dryRun): void
    {
        $this->info('');
        $this->info($dryRun ? '═══ DRY RUN REPORT ═══' : '═══ MIGRATION REPORT ═══');
        $this->info('');

        $this->table(
            ['Metric', 'Count'],
            [
                ['location_hardware scanned', $this->locationHardwareScanned],
                ['control_room_devices scanned', $this->controlRoomDevicesScanned],
                ['asset_trackers scanned', $this->assetTrackersScanned],
                ['', ''],
                ['Devices created', $this->devicesCreated],
                ['Duplicates merged', $this->duplicatesMerged],
                ['Assignments created', $this->assignmentsCreated],
                ['Asset links created', $this->assetLinksCreated],
                ['Skipped (already migrated)', $this->skipped],
                ['Warnings', count($this->warnings)],
            ],
        );

        if (count($this->warnings) > 0) {
            $this->info('');
            $this->warn('Warnings requiring manual review:');
            foreach ($this->warnings as $i => $warning) {
                $this->line('  '.($i + 1).". {$warning}");
            }
        }

        $this->info('');
        if ($dryRun) {
            $this->info('No data was written. Run without --dry-run to execute.');
        } else {
            $this->info('Migration complete.');
        }
    }
}
