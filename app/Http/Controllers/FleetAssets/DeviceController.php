<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\LocationHardware;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        // CSV export
        if ($request->input('export') === 'csv') {
            $allTrackers = AssetTracker::query()->with('asset:id,name,asset_tag')->orderByDesc('last_seen_at')->get();
            return response()->streamDownload(function () use ($allTrackers) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Vendor', 'Device UID', 'IMEI', 'Serial Number', 'Paired Asset', 'Status', 'Last Seen']);
                foreach ($allTrackers as $t) {
                    fputcsv($handle, [
                        $t->vendor, $t->device_uid, $t->imei, $t->serial_number,
                        $t->asset?->name ?? '', $t->status,
                        optional($t->last_seen_at)->format('Y-m-d H:i:s') ?? '',
                    ]);
                }
                fclose($handle);
            }, 'devices-export.csv');
        }

        // Sorting
        $allowedSorts = ['vendor', 'device_uid', 'status', 'last_seen_at'];
        $sort = $request->input('sort', 'last_seen_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) $sort = 'last_seen_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'desc';

        // Asset trackers
        $trackers = AssetTracker::query()
            ->with('asset:id,name,asset_tag')
            ->orderBy($sort, $direction)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'source' => 'asset_tracker',
                'vendor' => $t->vendor,
                'device_uid' => $t->device_uid,
                'imei' => $t->imei,
                'serial_number' => $t->serial_number,
                'status' => $t->status,
                'last_seen_at' => optional($t->last_seen_at)->toISOString(),
                'paired_at' => optional($t->paired_at)->toISOString(),
                'asset' => $t->asset ? [
                    'id' => $t->asset->id,
                    'name' => $t->asset->name,
                    'asset_tag' => $t->asset->asset_tag,
                ] : null,
            ]);

        // Location hardware trackers
        $hardwareTrackers = LocationHardware::query()
            ->where('category', 'tracker')
            ->with('linkedAsset:id,name,asset_tag')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'source' => 'location_hardware',
                'vendor' => $h->provider,
                'device_uid' => $h->serial,
                'imei' => $h->mac,
                'serial_number' => $h->serial,
                'status' => $h->status,
                'last_seen_at' => optional($h->last_seen_at)->toISOString(),
                'paired_at' => null,
                'asset' => $h->linkedAsset ? [
                    'id' => $h->linkedAsset->id,
                    'name' => $h->linkedAsset->name,
                    'asset_tag' => $h->linkedAsset->asset_tag,
                ] : null,
            ]);

        $allDevices = $trackers->concat($hardwareTrackers)->values();

        // Paginate the combined collection
        $perPage = 25;
        $page = (int) $request->input('page', 1);
        $paginated = new LengthAwarePaginator(
            $allDevices->forPage($page, $perPage)->values(),
            $allDevices->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        // Summary stats across all devices (not just current page)
        $stats = [
            'total' => $allDevices->count(),
            'online' => $allDevices->where('status', 'online')->count(),
            'offline' => $allDevices->filter(fn ($d) => in_array($d['status'], ['offline', 'stale']))->count(),
            'unpaired' => $allDevices->where('asset', null)->count(),
        ];

        return Inertia::render('fleet-assets/devices/index', [
            'devices' => $paginated,
            'stats' => $stats,
        ]);
    }

    public function show(Request $request, AssetTracker $tracker)
    {
        $tracker->load([
            'asset:id,name,asset_tag,category,status',
            'telemetrySnapshots' => fn ($q) => $q->latest()->limit(20),
        ]);

        return Inertia::render('fleet-assets/devices/show', [
            'tracker' => [
                'id' => $tracker->id,
                'vendor' => $tracker->vendor,
                'device_uid' => $tracker->device_uid,
                'imei' => $tracker->imei,
                'serial_number' => $tracker->serial_number,
                'status' => $tracker->status,
                'paired_at' => optional($tracker->paired_at)->toISOString(),
                'unpaired_at' => optional($tracker->unpaired_at)->toISOString(),
                'last_seen_at' => optional($tracker->last_seen_at)->toISOString(),
                'vendor_metadata' => $tracker->vendor_metadata,
                'asset' => $tracker->asset ? [
                    'id' => $tracker->asset->id,
                    'name' => $tracker->asset->name,
                    'asset_tag' => $tracker->asset->asset_tag,
                    'category' => $tracker->asset->category,
                    'status' => $tracker->asset->status,
                ] : null,
                'telemetry_snapshots' => $tracker->telemetrySnapshots->map(fn ($s) => [
                    'id' => $s->id,
                    'created_at' => optional($s->created_at)->toISOString(),
                    'data' => $s->data ?? null,
                ])->values(),
            ],
        ]);
    }

    public function pair(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'vendor' => ['required', 'string', 'max:80'],
            'device_uid' => ['required', 'string', 'max:120'],
            'imei' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'consent_id' => ['nullable', 'integer', 'exists:client_consents,id'],
            'vendor_metadata' => ['nullable', 'array'],
        ]);

        $asset = Asset::findOrFail($data['asset_id']);

        $existing = AssetTracker::query()
            ->where('vendor', $data['vendor'])
            ->where('device_uid', $data['device_uid'])
            ->first();

        if ($existing && $existing->asset_id !== $asset->id && $existing->status === 'paired') {
            return back()->withErrors(['device_uid' => 'Tracker is already paired to another asset.']);
        }

        $tracker = $existing ?? new AssetTracker();
        $tracker->fill([
            'asset_id' => $asset->id,
            'vendor' => $data['vendor'],
            'device_uid' => $data['device_uid'],
            'imei' => $data['imei'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'consent_id' => $data['consent_id'] ?? null,
            'vendor_metadata' => $data['vendor_metadata'] ?? null,
            'status' => 'paired',
            'paired_at' => now(),
            'unpaired_at' => null,
        ]);
        $tracker->save();

        AuditLogger::log('assets.tracker.paired', $asset, [
            'tracker_id' => $tracker->id,
            'vendor' => $tracker->vendor,
            'device_uid' => $tracker->device_uid,
        ]);

        return back()->with('success', 'Tracker paired successfully.');
    }

    public function unpair(Request $request, AssetTracker $tracker)
    {
        $tracker->update([
            'status' => 'unpaired',
            'unpaired_at' => now(),
        ]);

        AuditLogger::log('assets.tracker.unpaired', $tracker->asset, [
            'tracker_id' => $tracker->id,
        ]);

        return back()->with('success', 'Tracker unpaired successfully.');
    }

    public function consentIndex(Request $request)
    {
        $trackers = AssetTracker::query()
            ->with([
                'asset:id,name,asset_tag,client_id',
                'asset.client:id,first_name,last_name',
                'consent:id,status,given_at,withdrawn_at,given_by_user_id,expires_at',
                'consent.givenBy:id,name',
            ])
            ->orderByDesc('updated_at')
            ->get();

        $devices = $trackers->map(function (AssetTracker $t) {
            $consent = $t->consent;
            $consentValid = $consent ? $consent->isValid() : false;

            if (!$consent) {
                $consentStatus = 'pending';
            } elseif ($consent->status === 'withdrawn' || $consent->withdrawn_at) {
                $consentStatus = 'revoked';
            } elseif ($consentValid) {
                $consentStatus = 'consented';
            } elseif ($consent->isExpired()) {
                $consentStatus = 'expired';
            } else {
                $consentStatus = 'pending';
            }

            return [
                'id' => $t->id,
                'vendor' => $t->vendor,
                'device_uid' => $t->device_uid,
                'status' => $t->status,
                'consent_status' => $consentStatus,
                'consent_given_at' => optional($consent?->given_at)->toISOString(),
                'consent_withdrawn_at' => optional($consent?->withdrawn_at)->toISOString(),
                'consent_expires_at' => optional($consent?->expires_at)->toISOString(),
                'consent_given_by' => $consent?->givenBy?->name,
                'asset' => $t->asset ? [
                    'id' => $t->asset->id,
                    'name' => $t->asset->name,
                    'asset_tag' => $t->asset->asset_tag,
                ] : null,
                'client_name' => $t->asset?->client
                    ? trim($t->asset->client->first_name . ' ' . $t->asset->client->last_name)
                    : null,
            ];
        });

        $stats = [
            'total' => $devices->count(),
            'consented' => $devices->where('consent_status', 'consented')->count(),
            'revoked' => $devices->where('consent_status', 'revoked')->count(),
            'pending' => $devices->where('consent_status', 'pending')->count(),
            'expired' => $devices->where('consent_status', 'expired')->count(),
        ];

        return Inertia::render('fleet-assets/devices/consent', [
            'devices' => $devices->values(),
            'stats' => $stats,
        ]);
    }

    public function grantConsent(Request $request, AssetTracker $tracker)
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $tracker->load('asset.client');

        // Find or create the Fleet Tracking consent type
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

        // If an existing consent is linked but withdrawn/expired, supersede it
        $oldConsent = $tracker->consent;

        $consent = ClientConsent::create([
            'client_id' => $tracker->asset?->client_id,
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

        if ($oldConsent) {
            $oldConsent->update(['superseded_by_consent_id' => $consent->id]);
        }

        $tracker->update(['consent_id' => $consent->id]);

        AuditLogger::log('assets.tracker.consent.granted', $tracker->asset, [
            'tracker_id' => $tracker->id,
            'consent_id' => $consent->id,
            'granted_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Location tracking consent granted.');
    }

    public function revokeConsent(Request $request, AssetTracker $tracker)
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $consent = $tracker->consent;

        if (!$consent) {
            return back()->withErrors(['consent' => 'No active consent to revoke.']);
        }

        $consent->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
            'withdrawn_by_user_id' => $request->user()->id,
            'withdrawal_reason' => $request->input('reason'),
            'withdrawal_acknowledged' => true,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::log('assets.tracker.consent.revoked', $tracker->asset, [
            'tracker_id' => $tracker->id,
            'consent_id' => $consent->id,
            'revoked_by' => $request->user()->id,
            'reason' => $request->input('reason'),
        ]);

        return back()->with('success', 'Location tracking consent revoked. Telemetry location data will be blocked.');
    }
}
