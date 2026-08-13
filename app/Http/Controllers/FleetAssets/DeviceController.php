<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceLinkService;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ConsentValidationService;
use App\Services\Fleet\FleetDeviceRuntimeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceLinkService $linkService,
        private readonly FleetDeviceRuntimeService $deviceRuntime,
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
        private readonly SecurityDevicesAccessService $deviceAccess,
    ) {}

    /**
     * Fleet device list — reads from canonical device registry.
     */
    public function index(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);

        $visibleTrackingDevices = $this->visibleTrackingDevices($viewer);

        // CSV export — canonical, Site-scoped devices.
        if ($request->input('export') === 'csv') {
            $exportQuery = (clone $visibleTrackingDevices)
                ->with(['activeAssetLinks.asset:id,name,asset_tag'])
                ->orderByDesc('last_seen_at');

            return response()->streamDownload(function () use ($exportQuery) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['Vendor', 'Device UID', 'IMEI', 'Serial Number', 'Linked Asset', 'Status', 'Last Seen']);
                foreach ($exportQuery->lazy(200) as $d) {
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
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'last_seen_at';
        }
        if (! in_array($direction, ['asc', 'desc'])) {
            $direction = 'desc';
        }

        // Canonical tracking devices.
        $query = (clone $visibleTrackingDevices)
            ->with([
                'activeAssetLinks.asset:id,name,asset_tag,category,asset_category_id,site_id,client_id',
                'activeAssetLinks.asset.categoryRef:id,slug',
            ])
            ->orderBy($sort, $direction);

        $devices = $query->paginate(25)->withQueryString();

        // Consent payload — merged from the retired /devices/consent page; the
        // tracking-device population is small so it ships on every index render
        // (instant tab switching, and it feeds the hero consent stat).
        $consent = $this->consentPayload($viewer);

        // Stats across all tracking devices (not just page).
        $allStats = clone $visibleTrackingDevices;
        $totalCount = (clone $allStats)->count();
        $stats = [
            'total' => $totalCount,
            'online' => (clone $allStats)->where('status', DeviceStatus::Active->value)->count(),
            'offline' => (clone $allStats)->whereIn('status', [DeviceStatus::Offline->value, DeviceStatus::Degraded->value])->count(),
            'unpaired' => (clone $allStats)->whereDoesntHave('activeAssetLinks')->count(),
            'low_battery' => (clone $allStats)->whereNotNull('battery_level')->where('battery_level', '<=', 20)->count(),
            'consent_granted' => $consent['stats']['consented'],
            'consent_blocked' => max(0, $consent['stats']['total'] - $consent['stats']['consented']),
        ];

        // Detail payload — the retired /devices/{device} page now opens as a
        // dialog; deep links redirect here with ?device={id}.
        $deviceDetail = null;
        if ($request->filled('device')) {
            $detailDevice = (clone $visibleTrackingDevices)
                ->find($request->integer('device'));
            abort_unless($detailDevice, 404);
            $deviceDetail = $this->deviceDetailPayload($detailDevice, $viewer);
        }

        return Inertia::render('fleet-assets/devices/index', [
            'tab' => $request->input('tab') === 'consent' ? 'consent' : 'devices',
            'consent_devices' => $consent['rows'],
            'consent_stats' => $consent['stats'],
            'device_detail' => $deviceDetail,
            'devices' => [
                'data' => $devices->getCollection()->map(fn (Device $d) => $this->mapDeviceForFleet($d, $viewer)),
                'links' => $devices->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $devices->currentPage(),
                    'last_page' => $devices->lastPage(),
                    'total' => $devices->total(),
                ],
            ],
            'stats' => $stats,
            'pairing_options' => [
                'devices' => (clone $visibleTrackingDevices)
                    ->whereDoesntHave('activeAssetLinks')
                    ->orderBy('provider')
                    ->orderBy('device_uid')
                    ->limit(20)
                    ->get()
                    ->map(fn (Device $device) => [
                        'id' => $device->id,
                        'label' => trim(collect([$device->provider, $device->device_uid])->filter()->implode(' - ')),
                    ])
                    ->values(),
                'assets' => $this->visibleAssets($viewer)
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->limit(20)
                    ->get(['id', 'name', 'asset_tag'])
                    ->map(fn (Asset $asset) => [
                        'id' => $asset->id,
                        'label' => trim(collect([$asset->name, $asset->asset_tag])->filter()->implode(' - ')),
                    ])
                    ->values(),
            ],
        ]);
    }

    public function searchPairingOptions(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);
        $data = $request->validate([
            'type' => ['required', 'in:assets,devices'],
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $term = $data['q'];
        $results = $data['type'] === 'devices'
            ? $this->visibleTrackingDevices($viewer)
                ->whereDoesntHave('activeAssetLinks')
                ->where(fn ($query) => $query
                    ->where('provider', 'like', "%{$term}%")
                    ->orWhere('device_uid', 'like', "%{$term}%")
                    ->orWhere('serial_number', 'like', "%{$term}%"))
                ->orderBy('provider')
                ->orderBy('device_uid')
                ->limit(20)
                ->get()
                ->map(fn (Device $device) => [
                    'id' => $device->id,
                    'label' => trim(collect([$device->provider, $device->device_uid])->filter()->implode(' - ')),
                ])
                ->values()
            : $this->visibleAssets($viewer)
                ->where('status', 'active')
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('asset_tag', 'like', "%{$term}%"))
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'asset_tag'])
                ->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'label' => trim(collect([$asset->name, $asset->asset_tag])->filter()->implode(' - ')),
                ])
                ->values();

        return response()->json(['results' => $results]);
    }

    /**
     * Device detail — the standalone page is retired; deep links land on the
     * index which opens the detail dialog for ?device={id}.
     */
    public function show(Request $request, Device $device)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);
        $this->assertVisibleTrackingDevice($viewer, $device);

        return redirect()->route(
            'fleet-assets.devices.index',
            array_merge($request->query(), ['device' => $device->id]),
        );
    }

    /**
     * Detail payload for the index-page device dialog.
     */
    private function deviceDetailPayload(Device $device, User $viewer): array
    {
        $device->load([
            'activeAssetLinks.asset:id,name,asset_tag,category,asset_category_id,status,site_id,client_id',
            'activeAssetLinks.asset.categoryRef:id,slug',
        ]);

        $activeAssignment = DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->whereNull('released_at')
            ->latest('assigned_at')
            ->latest('id')
            ->first();
        $latestPersonalAssignment = DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->whereIn('assignable_type', [
                DeviceAssignment::TARGET_CLIENT,
                DeviceAssignment::TARGET_STAFF,
            ])
            ->latest('assigned_at')
            ->latest('id')
            ->first();
        $hasPersonalLineage = $latestPersonalAssignment !== null;
        $activePersonalAssignment = $activeAssignment
            && in_array($activeAssignment->assignable_type, [
                DeviceAssignment::TARGET_CLIENT,
                DeviceAssignment::TARGET_STAFF,
            ], true);
        $activePersonalCollection = $activePersonalAssignment
            && $activeAssignment->isCollectionActive();
        $canViewTelemetry = $viewer->canDo('assets.telemetry.view');

        // Personal location belongs only in the consent-aware Tracking and
        // Client surfaces. The generic Fleet device dialog deliberately never
        // serialises those snapshots, even while consent is active, so cached
        // or deep-linked device pages cannot become a privacy bypass. When a
        // formerly personal tracker is explicitly reassigned to a non-personal
        // target, only telemetry observed after that assignment may appear.
        $telemetryAllowed = $canViewTelemetry
            && ! $activePersonalAssignment
            && ($activeAssignment !== null || ! $hasPersonalLineage);
        $notBefore = $activeAssignment && $hasPersonalLineage
            ? $activeAssignment->assigned_at
            : null;
        $telemetrySnapshots = $telemetryAllowed
            ? $this->deviceRuntime
                ->recentSnapshotsForDevice($device, notBefore: $notBefore)
                ->map(fn ($snapshot) => [
                    'id' => $snapshot->id,
                    'created_at' => $snapshot->created_at?->toISOString(),
                    'data' => $snapshot->toArray(),
                ])
            : collect();

        $activeLink = $device->activeAssetLinks->first();

        return [
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
            'vendor_metadata' => $hasPersonalLineage
                ? $this->trackingPrivacy->redactLocationPayload($device->external_ref ?? [])
                : $device->external_ref,
            'detail_url' => "/security-devices/devices/{$device->id}",
            'asset' => $activeLink?->asset ? [
                'id' => $activeLink->asset->id,
                'name' => $activeLink->asset->name,
                'asset_tag' => $activeLink->asset->asset_tag,
                'category' => $activeLink->asset->category,
                'status' => $activeLink->asset->status,
                'href' => $this->assetHref($viewer, $activeLink->asset),
            ] : null,
            'telemetry_snapshots' => $telemetrySnapshots->values(),
            'telemetry_access' => [
                'allowed' => $telemetryAllowed,
                'reason' => match (true) {
                    ! $canViewTelemetry => 'permission_required',
                    $activePersonalCollection => 'use_governed_tracking_workspace',
                    $activePersonalAssignment => 'personal_assignment_ended',
                    $activeAssignment === null && $hasPersonalLineage => 'personal_assignment_ended',
                    default => 'available',
                },
            ],
        ];
    }

    /**
     * Pair a device to a vehicle asset via device_asset_links.
     */
    public function pair(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'device_id' => ['required', 'integer'],
        ]);
        $viewer = $request->user();
        abort_unless($viewer, 403);

        $asset = $this->visibleAssets($viewer)
            ->where('status', 'active')
            ->findOrFail($data['asset_id']);
        $device = $this->visibleTrackingDevices($viewer)->findOrFail($data['device_id']);

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
            $this->linkService->link($device, $asset, $viewer->id, LinkType::InstalledIn);
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
        $viewer = $request->user();
        abort_unless($viewer, 403);
        $this->assertVisibleTrackingDevice($viewer, $device);
        $link = $device->activeAssetLinks()->first();

        if (! $link) {
            return back()->with('info', 'Device has no active asset link.');
        }

        $asset = $link->asset;
        abort_unless($asset && $this->visibleAssets($viewer)->whereKey($asset->id)->exists(), 404);

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
     * The standalone consent page is retired — it now lives as the "Consent"
     * tab on the devices index.
     */
    public function consentIndex(Request $request)
    {
        return redirect()->route(
            'fleet-assets.devices.index',
            array_merge($request->query(), ['tab' => 'consent']),
        );
    }

    /**
     * Consent rows + stats for the devices index "Consent" tab.
     * Resolves through canonical devices first: DeviceAssignment.consent_id is
     * the primary source when present; legacy AssetTracker consent remains a
     * narrow compatibility fallback.
     */
    private function consentPayload(User $viewer): array
    {
        $devices = $this->visibleTrackingDevices($viewer)
            ->where(function ($query) {
                $query->whereHas('activeAssetLinks')
                    ->orWhereHas('assignments', fn (Builder $assignment): Builder => $assignment
                        ->active()
                        ->where('assignable_type', DeviceAssignment::TARGET_CLIENT));
            })
            ->with([
                'activeAssetLinks.asset:id,name,asset_tag,category,asset_category_id,site_id,client_id',
                'activeAssetLinks.asset.categoryRef:id,slug',
                'activeAssetLinks.asset.client:id,first_name,last_name',
                'assignments' => fn ($query) => $query
                    ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                    ->whereNull('released_at')
                    ->with([
                        'consent:id,client_id,status,given_at,withdrawn_at,given_by_user_id,expires_at',
                        'consent.givenBy:id,name',
                    ])
                    ->latest('assigned_at')
                    ->latest('id'),
                'legacyAssetTracker.consent:id,client_id,status,given_at,withdrawn_at,given_by_user_id,expires_at',
                'legacyAssetTracker.consent.givenBy:id,name',
            ])
            ->orderByDesc('updated_at')
            ->get();

        $assignmentClients = Client::query()
            ->whereKey($devices
                ->flatMap->assignments
                ->pluck('assignable_id')
                ->filter(fn (mixed $clientId): bool => is_numeric($clientId) && (int) $clientId > 0)
                ->map(fn (mixed $clientId): int => (int) $clientId)
                ->unique()
                ->values())
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $deviceRows = $devices->map(function (Device $device) use ($assignmentClients, $viewer) {
            $assignment = $device->assignments->first();
            $tracker = $device->legacyAssetTracker;
            $asset = $device->activeAssetLinks->first()?->asset;
            $client = $assignment
                ? $assignmentClients->get((int) $assignment->assignable_id)
                : $asset?->client;
            $assignmentConsent = $assignment?->consent;
            if ($assignmentConsent && (int) $assignmentConsent->client_id !== (int) $assignment->assignable_id) {
                $assignmentConsent = null;
            }
            $legacyConsent = $assignment ? null : $tracker?->consent;
            if ($legacyConsent && (! $client || (int) $legacyConsent->client_id !== (int) $client->id)) {
                $legacyConsent = null;
            }
            $consent = $assignmentConsent ?? $legacyConsent;

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
                    'href' => $this->assetHref($viewer, $asset),
                ] : null,
                'client_name' => $client
                    ? trim($client->first_name.' '.$client->last_name)
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

        return [
            'rows' => $deviceRows->values(),
            'stats' => $stats,
        ];
    }

    public function grantConsent(Request $request, Device $device)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);
        $this->assertVisibleTrackingDevice($viewer, $device);
        $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $context = $this->deviceRuntime->resolveConsentContext($device);
        $assignment = $context['assignment'];
        $tracker = $context['tracker'];
        $asset = $context['asset'];
        $client = $context['client'];

        if (! $assignment || ! $client) {
            return back()->withErrors(['consent' => 'An active canonical Client assignment is required before consent can be recorded.']);
        }

        $consent = $context['client_consent'];
        if (! $consent
            || ! ConsentValidationService::isValidTrackingConsent($consent, $client)) {
            return back()->withErrors([
                'consent' => 'No authoritative tracking consent is available. Record Client self-consent or complete a verified representative consent request first.',
            ]);
        }

        try {
            DB::transaction(function () use (
                $request,
                $client,
                $consent,
                $context,
                $assignment,
                $tracker,
                $asset,
                $device,
            ): void {
                $consentIds = collect([
                    $consent->id,
                    $context['assignment_consent']?->id,
                ])->filter()->unique()->sort()->values();
                $lockedConsents = ClientConsent::query()
                    ->with([
                        'consentType',
                        'consentTypeVersion',
                        'sourceConsentRequest',
                        'authorityScope.nextOfKin',
                        'authorityScope.capacityEvidenceConsent',
                    ])
                    ->whereKey($consentIds->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $authoritativeConsent = $lockedConsents->get($consent->id);
                if (! $authoritativeConsent) {
                    throw new \InvalidArgumentException(
                        'The authoritative tracking consent is no longer available.',
                    );
                }
                if (! ConsentValidationService::isValidTrackingConsent($authoritativeConsent, $client)) {
                    throw new \InvalidArgumentException(
                        'The authoritative tracking consent is no longer current.',
                    );
                }

                $lockedConsents
                    ->filter(fn (ClientConsent $oldConsent): bool => (int) $oldConsent->id
                        !== (int) $authoritativeConsent->id)
                    ->unique('id')->sortBy('id')->each(
                        function (ClientConsent $oldConsent) use ($authoritativeConsent, $request): void {
                            $oldConsent->update(['superseded_by_consent_id' => $authoritativeConsent->id]);
                            $this->trackingPrivacy->stopForConsent($oldConsent, $request->user()->id);
                        },
                    );

                $this->trackingPrivacy->resumeClientAssignment(
                    $assignment,
                    $authoritativeConsent,
                    $request->user()->id,
                );

                AuditLogger::logOrFail('assets.device_assignment.consent.granted', $asset ?? $device, [
                    'actor_id' => $request->user()->id,
                    'device_id' => $device->id,
                    'legacy_tracker_id' => $tracker?->id,
                    'assignment_id' => $assignment?->id,
                    'client_id' => $client->id,
                    'consent_id' => $authoritativeConsent->id,
                ]);
            });
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['consent' => $exception->getMessage()]);
        }

        return back()->with('success', 'Authoritative location tracking consent linked.');
    }

    public function revokeConsent(Request $request, Device $device)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);
        $this->assertVisibleTrackingDevice($viewer, $device);
        $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $context = $this->deviceRuntime->resolveConsentContext($device);
        $assignment = $context['assignment'];
        $tracker = $context['tracker'];
        $asset = $context['asset'];
        $consents = collect([$context['assignment_consent']])
            ->filter(fn (?ClientConsent $consent): bool => $consent !== null
            && ConsentValidationService::isValidTrackingConsent($consent, $consent->client_id))
            ->unique('id')
            ->sortBy('id')
            ->values();

        if ($consents->isEmpty()) {
            return back()->withErrors(['consent' => 'No active consent to revoke.']);
        }

        DB::transaction(function () use (
            $request,
            $consents,
            $asset,
            $device,
            $tracker,
            $assignment,
        ): void {
            foreach ($consents as $consent) {
                $lockedConsent = ClientConsent::query()
                    ->with('consentType')
                    ->lockForUpdate()
                    ->findOrFail($consent->id);

                if (! ConsentValidationService::isValidTrackingConsent($lockedConsent, $lockedConsent->client_id)) {
                    continue;
                }

                $lockedConsent->update([
                    'status' => 'withdrawn',
                    'withdrawn_at' => now(),
                    'withdrawn_by_user_id' => $request->user()->id,
                    'withdrawal_reason' => $request->string('reason')->trim()->toString(),
                    'withdrawal_acknowledged' => true,
                    'updated_by' => $request->user()->id,
                ]);
                $this->trackingPrivacy->stopForConsent($lockedConsent, $request->user()->id);
            }

            AuditLogger::logOrFail('assets.device_assignment.consent.revoked', $asset ?? $device, [
                'actor_id' => $request->user()->id,
                'device_id' => $device->id,
                'legacy_tracker_id' => $tracker?->id,
                'assignment_id' => $assignment?->id,
                'consent_ids' => $consents->pluck('id')->values()->all(),
                'reason_recorded' => true,
            ]);
        });

        return back()
            ->with('success', 'Location tracking consent revoked. Collection and live location access stopped.')
            ->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
            ]);
    }

    // ── Mapping ───────────────────────────────────────────────────

    private function mapDeviceForFleet(Device $d, User $viewer): array
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
                'href' => $this->assetHref($viewer, $link->asset),
            ] : null,
        ];
    }

    private function assetHref(User $viewer, Asset $asset): ?string
    {
        $isVehicle = strtolower(trim((string) $asset->category)) === 'vehicle'
            || $asset->categoryRef?->slug === 'vehicle';
        if ($isVehicle && $viewer->canDo('fleet.viewAny')) {
            return "/fleet-assets/vehicles/{$asset->id}";
        }

        $canUseAssetRoute = ($viewer->canDo('assets.viewAny') || $viewer->canDo('assets.viewAssigned'))
            && Gate::forUser($viewer)->allows('view', $asset);

        return $canUseAssetRoute ? "/fleet-assets/assets/{$asset->id}" : null;
    }

    private function visibleTrackingDevices(User $viewer): Builder
    {
        return $this->deviceAccess->visibleDevices($viewer)->where('domain', 'tracking');
    }

    private function visibleAssets(User $viewer): Builder
    {
        $assetIds = $this->deviceAccess->authorizedAssetIds($viewer);

        return Asset::query()
            ->when($assetIds === [], fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($assetIds !== [], fn (Builder $query): Builder => $query->whereKey($assetIds));
    }

    private function assertVisibleTrackingDevice(User $viewer, Device $device): void
    {
        abort_unless($device->domain === 'tracking', 404);
        $this->deviceAccess->assertCanViewDevice($viewer, $device);
    }
}
