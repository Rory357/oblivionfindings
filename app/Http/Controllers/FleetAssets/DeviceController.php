<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Services\DeviceLinkService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Services\AuditLogger;
use App\Services\Fleet\FleetDeviceRuntimeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceLinkService $linkService,
        private readonly FleetDeviceRuntimeService $deviceRuntime,
    ) {}

    /**
     * Fleet device list — reads from canonical device registry.
     */
    public function index(Request $request)
    {
        // CSV export — canonical devices.
        if ($request->input('export') === 'csv') {
            $allDevices = Device::query()
                ->where('domain', 'tracking')
                ->with(['activeAssetLinks.asset:id,name,asset_tag'])
                ->orderByDesc('last_seen_at')
                ->get();

            return response()->streamDownload(function () use ($allDevices) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['Vendor', 'Device UID', 'IMEI', 'Serial Number', 'Linked Asset', 'Status', 'Last Seen']);
                foreach ($allDevices as $d) {
                    $link = $d->activeAssetLinks->first();
                    $this->putCsv($handle, [
                        $d->provider, $d->device_uid, $d->imei, $d->serial_number,
                        $link?->asset?->name ?? '',
                        $d->status?->value ?? '',
                        $d->last_seen_at?->format('Y-m-d H:i:s') ?? '',
                    ]);
                }
                fclose($handle);
            }, 'devices-export.csv');
        }

        // Sorting.
        $allowedSorts = ['provider', 'device_uid', 'status', 'last_seen_at', 'name'];
        $sort = $request->input('sort', 'last_seen_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) { $sort = 'last_seen_at'; }
        if (!in_array($direction, ['asc', 'desc'])) { $direction = 'desc'; }

        // Canonical tracking devices.
        $query = Device::query()
            ->where('domain', 'tracking')
            ->with(['activeAssetLinks.asset:id,name,asset_tag'])
            ->orderBy($sort, $direction);

        $devices = $query->paginate(25)->withQueryString();

        // Stats across all tracking devices (not just page).
        $allStats = Device::where('domain', 'tracking');
        $totalCount = (clone $allStats)->count();
        $stats = [
            'total' => $totalCount,
            'online' => (clone $allStats)->where('status', DeviceStatus::Active->value)->count(),
            'offline' => (clone $allStats)->whereIn('status', [DeviceStatus::Offline->value, DeviceStatus::Degraded->value])->count(),
            'unpaired' => (clone $allStats)->whereDoesntHave('activeAssetLinks')->count(),
        ];

        return Inertia::render('fleet-assets/devices/index', [
            'devices' => [
                'data' => $devices->getCollection()->map(fn (Device $d) => $this->mapDeviceForFleet($d)),
                'links' => $devices->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $devices->currentPage(),
                    'last_page' => $devices->lastPage(),
                    'total' => $devices->total(),
                ],
            ],
            'stats' => $stats,
            'pairing_options' => [
                'devices' => Device::query()
                    ->where('domain', 'tracking')
                    ->whereDoesntHave('activeAssetLinks')
                    ->orderBy('provider')
                    ->orderBy('device_uid')
                    ->get()
                    ->map(fn (Device $device) => [
                        'id' => $device->id,
                        'label' => trim(collect([$device->provider, $device->device_uid])->filter()->implode(' - ')),
                    ])
                    ->values(),
                'assets' => Asset::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'name', 'asset_tag'])
                    ->map(fn (Asset $asset) => [
                        'id' => $asset->id,
                        'label' => trim(collect([$asset->name, $asset->asset_tag])->filter()->implode(' - ')),
                    ])
                    ->values(),
            ],
        ]);
    }

    /**
     * Device detail — reads from canonical device.
     */
    public function show(Request $request, Device $device)
    {
        $device->load([
            'activeAssetLinks.asset:id,name,asset_tag,category,status',
        ]);

        $telemetrySnapshots = $this->deviceRuntime
            ->recentSnapshotsForDevice($device)
            ->map(fn ($snapshot) => [
                'id' => $snapshot->id,
                'created_at' => $snapshot->created_at?->toISOString(),
                'data' => $snapshot->toArray(),
            ]);

        $activeLink = $device->activeAssetLinks->first();

        return Inertia::render('fleet-assets/devices/show', [
            'tracker' => [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'vendor' => $device->provider,
                'name' => $device->name,
                'imei' => $device->imei,
                'serial_number' => $device->serial_number,
                'device_status' => $device->status?->value,
                'link_status' => $activeLink ? 'paired' : 'unpaired',
                'health_status' => $device->health_status?->value,
                'paired_at' => $activeLink?->linked_at?->toISOString(),
                'unpaired_at' => null,
                'last_seen_at' => $device->last_seen_at?->toISOString(),
                'battery_level' => $device->battery_level,
                'vendor_metadata' => $device->external_ref,
                'detail_url' => "/security-devices/devices/{$device->id}",
                'asset' => $activeLink?->asset ? [
                    'id' => $activeLink->asset->id,
                    'name' => $activeLink->asset->name,
                    'asset_tag' => $activeLink->asset->asset_tag,
                    'category' => $activeLink->asset->category,
                    'status' => $activeLink->asset->status,
                ] : null,
                'telemetry_snapshots' => $telemetrySnapshots->values(),
            ],
        ]);
    }

    /**
     * Pair a device to a vehicle asset via device_asset_links.
     */
    public function pair(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'device_id' => ['required', 'integer', 'exists:devices,id'],
        ]);

        $asset = Asset::findOrFail($data['asset_id']);
        $device = Device::findOrFail($data['device_id']);

        // Check if already actively linked to another asset.
        $existingLink = DeviceAssetLink::where('device_id', $device->id)
            ->active()
            ->first();

        if ($existingLink && $existingLink->asset_id !== $asset->id) {
            return back()->withErrors(['device_id' => 'Device is already linked to another asset. Unlink it first.']);
        }

        if ($existingLink && $existingLink->asset_id === $asset->id) {
            return back()->with('info', 'Device is already linked to this asset.');
        }

        try {
            $this->linkService->link($device, $asset, $request->user()->id, LinkType::InstalledIn);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['device_id' => $e->getMessage()]);
        }

        AuditLogger::log('assets.device.linked', $asset, [
            'device_id' => $device->id,
            'device_uid' => $device->device_uid,
        ]);

        return back()->with('success', 'Device linked to vehicle.');
    }

    /**
     * Unpair a device from a vehicle asset.
     */
    public function unpair(Request $request, Device $device)
    {
        $link = $device->activeAssetLinks()->first();

        if (!$link) {
            return back()->with('info', 'Device has no active asset link.');
        }

        $asset = $link->asset;

        try {
            $this->linkService->unlink($link);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['device' => $e->getMessage()]);
        }

        AuditLogger::log('assets.device.unlinked', $asset, [
            'device_id' => $device->id,
            'device_uid' => $device->device_uid,
        ]);

        return back()->with('success', 'Device unlinked from vehicle.');
    }

    /**
     * Consent management now resolves through canonical devices first.
     * DeviceAssignment.consent_id is the primary source when present; legacy
     * AssetTracker consent remains a narrow compatibility fallback.
     */
    public function consentIndex(Request $request)
    {
        $devices = Device::query()
            ->where('domain', 'tracking')
            ->where(function ($query) {
                $query->whereNotNull('legacy_asset_tracker_id')
                    ->orWhereHas('activeAssetLinks');
            })
            ->with([
                'activeAssetLinks.asset:id,name,asset_tag,client_id',
                'activeAssetLinks.asset.client:id,first_name,last_name',
                'assignments' => fn ($query) => $query
                    ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                    ->whereNull('released_at')
                    ->with([
                        'consent:id,client_id,status,given_at,withdrawn_at,given_by_user_id,expires_at',
                        'consent.givenBy:id,name',
                    ])
                    ->latest('assigned_at'),
                'legacyAssetTracker.asset:id,name,asset_tag,client_id',
                'legacyAssetTracker.asset.client:id,first_name,last_name',
                'legacyAssetTracker.consent:id,client_id,status,given_at,withdrawn_at,given_by_user_id,expires_at',
                'legacyAssetTracker.consent.givenBy:id,name',
            ])
            ->orderByDesc('updated_at')
            ->get();

        $deviceRows = $devices->map(function (Device $device) {
            $assignment = $device->assignments->first();
            $tracker = $device->legacyAssetTracker;
            $consent = $assignment?->consent ?? $tracker?->consent;
            $asset = $device->activeAssetLinks->first()?->asset ?? $tracker?->asset;
            $client = $asset?->client;

            return [
                'id' => $device->id,
                'vendor' => $device->provider,
                'device_uid' => $device->device_uid,
                'status' => $device->status?->value,
                'consent_status' => $this->deviceRuntime->mapConsentStatus($consent),
                'consent_given_at' => optional($consent?->given_at)->toISOString(),
                'consent_withdrawn_at' => optional($consent?->withdrawn_at)->toISOString(),
                'consent_expires_at' => optional($consent?->expires_at)->toISOString(),
                'consent_given_by' => $consent?->givenBy?->name,
                'asset' => $asset ? [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                ] : null,
                'client_name' => $client
                    ? trim($client->first_name . ' ' . $client->last_name)
                    : null,
            ];
        });

        $stats = [
            'total' => $deviceRows->count(),
            'consented' => $deviceRows->where('consent_status', 'consented')->count(),
            'revoked' => $deviceRows->where('consent_status', 'revoked')->count(),
            'pending' => $deviceRows->where('consent_status', 'pending')->count(),
            'expired' => $deviceRows->where('consent_status', 'expired')->count(),
        ];

        return Inertia::render('fleet-assets/devices/consent', [
            'devices' => $deviceRows->values(),
            'stats' => $stats,
        ]);
    }

    public function grantConsent(Request $request, Device $device)
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $context = $this->deviceRuntime->resolveConsentContext($device);
        $assignment = $context['assignment'];
        $tracker = $context['tracker'];
        $asset = $context['asset'];
        $client = $context['client'];

        if (!$client) {
            return back()->withErrors(['consent' => 'A linked client is required before consent can be recorded.']);
        }

        $consentType = ConsentType::query()
            ->where('name', 'Fleet Tracking')
            ->first();

        if (!$consentType) {
            $consentType = ConsentType::create([
                'name' => 'Fleet Tracking',
                'category' => 'operational',
                'description' => 'Consent for vehicle location tracking.',
                'purpose' => 'Enable fleet vehicle GPS tracking.',
                'legal_basis' => 'consent',
                'is_mandatory' => false,
                'requires_capacity_assessment' => false,
                'allows_withdrawal' => true,
                'renewal_required' => false,
                'active' => true,
            ]);
        }

        $currentVersion = $consentType->currentVersion()->first();

        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $currentVersion?->id,
            'status' => 'given',
            'given_at' => now(),
            'given_by_user_id' => $request->user()->id,
            'given_method' => 'electronic',
            'given_notes' => $request->input('notes'),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        collect([
            $context['assignment_consent'],
            $context['tracker_consent'],
        ])->filter()->unique('id')->each(function (ClientConsent $oldConsent) use ($consent): void {
            $oldConsent->update(['superseded_by_consent_id' => $consent->id]);
        });

        if ($assignment) {
            $assignment->update(['consent_id' => $consent->id]);
        }

        if ($tracker) {
            $tracker->update(['consent_id' => $consent->id]);
        }

        AuditLogger::log('assets.tracker.consent.granted', $asset ?? $device, [
            'device_id' => $device->id,
            'tracker_id' => $tracker?->id,
            'assignment_id' => $assignment?->id,
            'client_id' => $client->id,
            'consent_id' => $consent->id,
            'granted_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Location tracking consent granted.');
    }

    public function revokeConsent(Request $request, Device $device)
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $context = $this->deviceRuntime->resolveConsentContext($device);
        $assignment = $context['assignment'];
        $tracker = $context['tracker'];
        $asset = $context['asset'];
        $consents = collect([
            $context['assignment_consent'],
            $context['tracker_consent'],
        ])->filter()->unique('id');

        if ($consents->isEmpty()) {
            return back()->withErrors(['consent' => 'No active consent to revoke.']);
        }

        $consents->each(function (ClientConsent $consent) use ($request): void {
            $consent->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
                'withdrawn_by_user_id' => $request->user()->id,
                'withdrawal_reason' => $request->input('reason'),
                'withdrawal_acknowledged' => true,
                'updated_by' => $request->user()->id,
            ]);
        });

        AuditLogger::log('assets.tracker.consent.revoked', $asset ?? $device, [
            'device_id' => $device->id,
            'tracker_id' => $tracker?->id,
            'assignment_id' => $assignment?->id,
            'consent_ids' => $consents->pluck('id')->values()->all(),
            'revoked_by' => $request->user()->id,
            'reason' => $request->input('reason'),
        ]);

        return back()->with('success', 'Location tracking consent revoked.');
    }

    // ── Mapping ───────────────────────────────────────────────────

    private function mapDeviceForFleet(Device $d): array
    {
        $link = $d->activeAssetLinks->first();

        return [
            'id' => $d->id,
            'source' => 'canonical',
            'vendor' => $d->provider,
            'device_uid' => $d->device_uid,
            'name' => $d->name,
            'imei' => $d->imei,
            'serial_number' => $d->serial_number,
            'status' => $d->status?->value,
            'health_status' => $d->health_status?->value,
            'last_seen_at' => $d->last_seen_at?->toISOString(),
            'battery_level' => $d->battery_level,
            'paired_at' => $link?->linked_at?->toISOString(),
            'detail_url' => "/security-devices/devices/{$d->id}",
            'asset' => $link?->asset ? [
                'id' => $link->asset->id,
                'name' => $link->asset->name,
                'asset_tag' => $link->asset->asset_tag,
            ] : null,
        ];
    }
}
