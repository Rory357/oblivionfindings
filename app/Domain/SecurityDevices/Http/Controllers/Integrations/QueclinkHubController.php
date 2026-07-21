<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Presenters\IntegrationSiteCredentialsPresenter;
use App\Domain\SecurityDevices\Services\DeviceLinkService;
use App\Domain\SecurityDevices\Services\QueclinkIntegrationAccessService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Queclink\BulkActionRequest;
use App\Http\Requests\Queclink\UpdateSectionRequest;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Queclink\QueclinkAuditEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkPreset;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\ConsentValidationService;
use App\Services\Queclink\CommandBuilder;
use App\Services\Queclink\ConfigurationSnapshotService;
use App\Support\LegacyStorageContext;
use App\Support\SafeOperationalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Queclink integration hub — TCP listener configuration, pending-tray
 * pairing, paired-device management, debug console, and AT command REPL.
 *
 * The existing QueclinkController.php remains the home for the optional
 * provider API connection; this controller owns everything to do with direct
 * device-to-server intake via the TCP listener.
 */
class QueclinkHubController extends Controller
{
    private const DEVICE_PAGE_SIZE = 25;

    public function __construct(
        private readonly QueclinkIntegrationAccessService $queclinkAccess,
        private readonly IntegrationSiteCredentialsPresenter $siteCredentials,
        private readonly SecurityDevicesAccessService $devicesAccess,
        private readonly DeviceLinkService $deviceLinks,
    ) {}

    /** Canonical section keys a preset payload may contain. */
    private const PRESET_SECTIONS = [
        'server', 'tracking', 'pin', 'dog', 'time', 'non_movement',
        'power', 'wifi', 'geo', 'bluetooth', 'beacons', 'allowlist',
        'firmware_update', 'firmware_version',
    ];

    public function index(Request $request, ConfigurationSnapshotService $configurations, SecurityDevicesAccessService $access)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $user = $request->user();
        $targetType = in_array($request->string('target_type')->toString(), ['vehicle', 'staff', 'client'], true)
            ? $request->string('target_type')->toString()
            : null;
        $targetSearch = Str::limit(trim($request->string('target_search')->toString()), 100, '');
        $selectedTargetId = $request->integer('selected_target_id') > 0
            ? $request->integer('selected_target_id')
            : null;
        $deviceSearch = Str::limit(trim($request->string('device_search')->toString()), 100, '');

        return Inertia::render('security-devices/integrations/queclink-hub', [
            'listener' => function () use ($user): array {
                $port = (int) (AppSetting::query()->where('key', 'queclink.listener.port')->value('value')
                    ?? config('services.queclink.port', 8090));
                $hostname = (string) (AppSetting::query()->where('key', 'queclink.public_hostname')->value('value')
                    ?? config('services.queclink.public_hostname')
                    ?? '');

                return [
                    'port' => $port,
                    'endpoint_configured' => $hostname !== '',
                    'service_state' => $this->systemdState(),
                    'connected_count' => $this->visibleDeviceQuery($user)->connected()->count(),
                ];
            },
            'devices' => fn (): array => $this->devicePagePayload(
                $request,
                $configurations,
                $deviceSearch,
            ),
            'statistics' => function () use ($user): array {
                $lastFrameAt = $this->visibleFrameQuery($user)->max('created_at');

                return [
                    'frames_last_hour' => $this->visibleFrameQuery($user)->where('created_at', '>=', now()->subHour())->count(),
                    'last_frame_at' => $lastFrameAt ? Carbon::parse($lastFrameAt)->toDateTimeString() : null,
                ];
            },
            'providerConnection' => function (): ?array {
                $connection = IntegrationProviderConnection::query()
                    ->forProvider('queclink')
                    ->first();

                return $connection ? [
                    'status' => $connection->status,
                    'secret_last4' => $connection->secret_last4,
                    'last_tested_at' => $connection->last_tested_at?->toDateTimeString(),
                ] : null;
            },
            'siteCredentials' => fn (): array => $this->siteCredentials->present($user, 'queclink'),
            'targets' => fn (): array => [
                'vehicles' => $access->assignableVehicles(
                    $request->user(),
                    $targetType === 'vehicle' ? $targetSearch : null,
                    $targetType === 'vehicle' ? $selectedTargetId : null,
                )
                    ->sortBy('name')
                    ->map(fn (Asset $a) => [
                        'id' => $a->id,
                        'label' => trim($a->name.($a->registration_number ? " ({$a->registration_number})" : '')),
                    ])->values(),
                'staff' => $access->assignableStaffTargets(
                    $request->user(),
                    $targetType === 'staff' ? $targetSearch : null,
                    $targetType === 'staff' ? $selectedTargetId : null,
                )
                    ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->name])
                    ->values(),
                'clients' => $access->assignableClients(
                    $request->user(),
                    $targetType === 'client' ? $targetSearch : null,
                    $targetType === 'client' ? $selectedTargetId : null,
                )
                    ->sortBy('first_name')
                    ->map(fn (Client $c) => [
                        'id' => $c->id,
                        'label' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                    ])->values(),
            ],
            'presets' => fn (): Collection => QueclinkPreset::query()
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get()
                ->map(fn (QueclinkPreset $preset) => $this->serialisePreset($preset))
                ->values(),
            'can' => [
                'manage' => true,
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        abort_unless($this->userCanManage($request->user()), 403);

        $validated = $request->validate([
            'port' => ['required', 'integer', 'between:1024,65535'],
            'public_hostname' => ['nullable', 'string', 'max:255'],
        ]);

        $previousPort = (int) (AppSetting::query()->where('key', 'queclink.listener.port')->value('value') ?? 8090);
        AppSetting::updateOrCreate(['key' => 'queclink.listener.port'], ['value' => (int) $validated['port']]);
        if (filled($validated['public_hostname'] ?? null)) {
            AppSetting::updateOrCreate(['key' => 'queclink.public_hostname'], ['value' => trim((string) $validated['public_hostname'])]);
        }

        if (PHP_OS_FAMILY === 'Linux' && (int) $validated['port'] !== $previousPort) {
            try {
                Artisan::call('queclink:install', ['--port' => $validated['port']]);
            } catch (\Throwable) {
                return back()->with('warning', 'Settings saved, but the listener could not be restarted automatically. Review the bounded server diagnostics.');
            }
        }

        return back()->with('success', 'Listener settings saved.');
    }

    public function claimDevice(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);

        $validated = $request->validate([
            'pairing_type' => ['required', 'in:vehicle,staff,client'],
            'target_id' => ['required', 'integer'],
            'consent_id' => ['nullable', 'integer', 'prohibited_unless:pairing_type,client'],
            'create_personal_tracker_asset' => ['nullable', 'boolean'],
        ]);

        return DB::transaction(function () use ($queclinkDevice, $validated, $request) {
            $lockedDevice = QueclinkDevice::query()
                ->whereKey($queclinkDevice->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->queclinkAccess->assertDevice($request->user(), $lockedDevice);
            abort_unless($lockedDevice->isPending(), 422, 'Device is not in the pending state.');

            $pairingType = $validated['pairing_type'];
            $targetId = (int) $validated['target_id'];
            $target = match ($pairingType) {
                'vehicle' => $this->queclinkAccess->vehicle($request->user(), $targetId, true),
                'staff' => $this->queclinkAccess->staff($request->user(), $targetId, true),
                'client' => $this->queclinkAccess->client($request->user(), $targetId, true),
            };
            $client = $target instanceof Client ? $target : null;
            $consentId = $client
                ? $this->resolveClientTrackingConsentId($client, $validated['consent_id'] ?? null)
                : null;

            $asset = match ($pairingType) {
                'vehicle' => $target,
                'staff' => $this->ensurePersonalTrackerAsset(
                    type: 'staff',
                    target: $target,
                    viewer: $request->user(),
                ),
                'client' => $this->ensurePersonalTrackerAsset(
                    type: 'client',
                    target: $target,
                    viewer: $request->user(),
                ),
            };
            $this->queclinkAccess->assertAsset($request->user(), $asset);

            if (in_array($pairingType, ['staff', 'client'], true)) {
                $activeAssignments = DeviceAssignment::query()
                    ->forTarget($pairingType, $targetId)
                    ->active()
                    ->lockForUpdate()
                    ->get();
                abort_if(
                    $activeAssignments->isNotEmpty(),
                    409,
                    'This person already has an active tracker assignment.',
                );

                $activeAssetTrackers = AssetTracker::query()
                    ->where('asset_id', $asset->id)
                    ->where('status', 'paired')
                    ->lockForUpdate()
                    ->get();
                $onlyActiveAssetTracker = $activeAssetTrackers->count() === 1
                    ? $activeAssetTrackers->first()
                    : null;
                abort_if(
                    $activeAssetTrackers->count() > 1
                    || ($onlyActiveAssetTracker !== null
                        && ($onlyActiveAssetTracker->vendor !== 'queclink'
                            || $onlyActiveAssetTracker->device_uid !== $lockedDevice->imei)),
                    409,
                    'This personal asset already has an active tracker.',
                );
            }

            $existingTracker = AssetTracker::query()
                ->where('vendor', 'queclink')
                ->where('device_uid', $lockedDevice->imei)
                ->with('asset')
                ->lockForUpdate()
                ->first();
            if ($existingTracker) {
                $this->queclinkAccess->assertAsset($request->user(), $existingTracker->asset);
                abort_unless(
                    (int) $existingTracker->asset_id === (int) $asset->id,
                    409,
                    'This provider identity is already paired to another canonical asset.',
                );
            }

            $device = $this->ensureCanonicalDevice($lockedDevice, $request->user(), $pairingType);

            $activeAssetLinks = $device->activeAssetLinks()->lockForUpdate()->get();
            abort_if(
                $activeAssetLinks->count() > 1
                || ($activeAssetLinks->isNotEmpty() && (int) $activeAssetLinks->first()->asset_id !== (int) $asset->id),
                409,
                'This provider identity is already linked to another canonical asset.',
            );
            if ($activeAssetLinks->isEmpty()) {
                $this->deviceLinks->link($device, $asset, (int) $request->user()->id);
            }

            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $pairingType,
                'assignable_id' => $targetId,
                'assignment_type' => AssignmentType::Permanent->value,
                'assigned_at' => now(),
                'assigned_by_user_id' => $request->user()->id,
                'consent_id' => $consentId,
            ]);

            if ($existingTracker) {
                $existingTracker->update([
                    'asset_id' => $asset->id,
                    'imei' => $lockedDevice->imei,
                    'status' => 'paired',
                    'paired_at' => now(),
                    'unpaired_at' => null,
                    'consent_id' => $consentId,
                ]);
            } else {
                AssetTracker::create([
                    'vendor' => 'queclink',
                    'device_uid' => $lockedDevice->imei,
                    'asset_id' => $asset->id,
                    'imei' => $lockedDevice->imei,
                    'status' => 'paired',
                    'paired_at' => now(),
                    'consent_id' => $consentId,
                ]);
            }

            $lockedDevice->update([
                'status' => QueclinkDevice::STATUS_PAIRED,
                'pending_pairing_type' => null,
                'device_id' => $device->id,
                'tenant_id' => LegacyStorageContext::id(),
            ]);

            $this->logAudit($request, $lockedDevice, 'claim', null, null, [
                'pairing_type' => $pairingType,
                'target_id' => $targetId,
                'device_id' => $device->id,
                'consent_id' => $consentId,
            ]);

            return back()->with('success', "Device {$lockedDevice->imei} paired.");
        });
    }

    private function resolveClientTrackingConsentId(Client $client, ?int $requestedConsentId): int
    {
        if ($requestedConsentId) {
            $consent = ClientConsent::query()
                ->where('client_id', $client->id)
                ->with('consentType')
                ->find($requestedConsentId);

            if ($consent && ConsentValidationService::isValidTrackingConsent($consent)) {
                return (int) $consent->id;
            }

            throw ValidationException::withMessages([
                'consent_id' => 'The selected tracking consent is not active for this client.',
            ]);
        }

        $consent = ConsentValidationService::latestValidTrackingConsentForClient($client);

        if ($consent) {
            return (int) $consent->id;
        }

        throw ValidationException::withMessages([
            'consent_id' => 'Client tracker pairing requires an active location tracking consent.',
        ]);
    }

    public function rejectDevice(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);

        DB::transaction(function () use ($request, $queclinkDevice): void {
            $lockedDevice = QueclinkDevice::query()
                ->whereKey($queclinkDevice->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->queclinkAccess->assertDevice($request->user(), $lockedDevice);
            abort_unless($lockedDevice->isPending(), 422, 'Only pending devices can be rejected.');

            $before = ['status' => $lockedDevice->status];
            $lockedDevice->update(['status' => QueclinkDevice::STATUS_REJECTED]);
            $this->logAudit($request, $lockedDevice, 'reject', null, $before, ['status' => QueclinkDevice::STATUS_REJECTED]);
        });

        return back()->with('success', "Device {$queclinkDevice->imei} rejected.");
    }

    public function restoreDevice(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);

        DB::transaction(function () use ($request, $queclinkDevice): void {
            $lockedDevice = QueclinkDevice::query()
                ->whereKey($queclinkDevice->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->queclinkAccess->assertDevice($request->user(), $lockedDevice);
            abort_unless($lockedDevice->status === QueclinkDevice::STATUS_REJECTED, 422, 'Only rejected devices can be restored.');

            $before = ['status' => $lockedDevice->status];
            $lockedDevice->update(['status' => QueclinkDevice::STATUS_PENDING]);
            $this->logAudit($request, $lockedDevice, 'restore', null, $before, ['status' => QueclinkDevice::STATUS_PENDING]);
        });

        return back()->with('success', "Device {$queclinkDevice->imei} restored to pending.");
    }

    public function releaseDevice(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDeviceForRelease($request->user(), $queclinkDevice);

        DB::transaction(function () use ($queclinkDevice, $request) {
            $lockedDevice = QueclinkDevice::query()
                ->whereKey($queclinkDevice->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->queclinkAccess->assertDeviceForRelease($request->user(), $lockedDevice);
            abort_unless($lockedDevice->isPaired(), 422);

            $canonicalDeviceId = $lockedDevice->device_id ? (int) $lockedDevice->device_id : null;
            $auditSiteId = $this->resolveAuditSiteId($canonicalDeviceId);

            $tracker = AssetTracker::query()
                ->where('vendor', 'queclink')
                ->where('device_uid', $lockedDevice->imei)
                ->with('asset')
                ->lockForUpdate()
                ->first();
            if ($tracker) {
                $this->queclinkAccess->assertHistoricalAsset($request->user(), $tracker->asset);
            }

            $canonicalDevice = Device::query()
                ->whereKey($lockedDevice->device_id)
                ->lockForUpdate()
                ->firstOrFail();
            $assignments = $this->devicesAccess->assertCanReleaseActiveAssignment(
                $request->user(),
                $canonicalDevice,
                true,
            );
            foreach ($assignments as $assignment) {
                $assignment->update([
                    'released_at' => now(),
                    'released_by_user_id' => $request->user()->id,
                ]);
            }

            $this->deviceLinks->unlinkAllForDevice($canonicalDevice);

            $tracker?->update(['status' => 'unpaired', 'unpaired_at' => now()]);

            $lockedDevice->update([
                'status' => QueclinkDevice::STATUS_PENDING,
                'device_id' => null,
            ]);

            $this->logAudit($request, $lockedDevice, 'release', null, null, [
                'status' => QueclinkDevice::STATUS_PENDING,
                'device_id' => null,
            ], canonicalDeviceId: $canonicalDeviceId, siteId: $auditSiteId);
        });

        return back()->with('success', "Device {$queclinkDevice->imei} released — moved back to pending.");
    }

    public function sendCommand(Request $request, QueclinkDevice $queclinkDevice, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Commands can only be sent to paired devices.');

        $validated = $request->validate([
            'mode' => ['required', 'in:preset,raw'],
            'preset' => ['nullable', 'in:request_location,reboot,set_interval'],
            'raw' => ['nullable', 'string'],
            'interval_seconds' => ['nullable', 'integer', 'between:5,86400'],
        ]);

        $family = $this->guessFamily($queclinkDevice);

        try {
            $built = match ($validated['mode']) {
                'preset' => match ($validated['preset']) {
                    'request_location' => $builder->requestLocation($family),
                    'reboot' => $builder->reboot($family),
                    'set_interval' => $builder->setReportingInterval($family, (int) $validated['interval_seconds']),
                    default => throw new \InvalidArgumentException('Unknown preset'),
                },
                'raw' => $builder->fromRaw((string) $validated['raw'], $family),
            };
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['raw' => $e->getMessage()]);
        }

        QueclinkPendingCommand::create([
            'queclink_device_id' => $queclinkDevice->id,
            'imei' => $queclinkDevice->imei,
            'tenant_id' => LegacyStorageContext::id(),
            'command_word' => $built['command_word'],
            'raw_command' => $built['raw'],
            'serial_number' => $built['serial'],
            'status' => QueclinkPendingCommand::STATUS_QUEUED,
            'created_by_user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(5),
        ]);

        return back()->with('success', 'Command queued — it will be sent on the device\'s next frame.');
    }

    public function readConfiguration(Request $request, QueclinkDevice $queclinkDevice, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be read from paired devices.');

        $validated = $request->validate([
            'section' => ['nullable', 'string', 'in:all,BSI,SRI,NTS,TLS,CFG,PIN,DOG,TMA,NMD,PDS,GEO,BTS,WFI,BID,UPC,WLT,FVR'],
        ]);

        $section = $validated['section'] ?? 'all';
        $family = $this->guessFamily($queclinkDevice);

        try {
            $built = $builder->readConfiguration($family, $section);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['section' => $e->getMessage()]);
        }

        $this->queueCommand($request, $queclinkDevice, $built, now()->addMinutes(10));

        return back()->with('success', 'Configuration read queued — it will be sent on the device\'s next frame.');
    }

    public function readConfigurationSection(Request $request, QueclinkDevice $queclinkDevice, string $section, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be read from paired devices.');

        $code = $this->resolveReadSectionCode($section, $request->string('command')->toString());
        $family = $this->guessFamily($queclinkDevice);

        try {
            $built = $builder->readConfiguration($family, $code);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['section' => $e->getMessage()]);
        }

        $command = $this->queueCommand($request, $queclinkDevice, $built, now()->addMinutes(10));
        $this->logAudit($request, $queclinkDevice, 'config_read', $section, null, ['section_code' => $code], $command->raw_command);

        return back()->with('success', "{$code} configuration read queued.");
    }

    public function updateServerConfiguration(Request $request, QueclinkDevice $queclinkDevice, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        if ($request->filled('command') && ! in_array($request->string('command')->toString(), ['server', 'sri'], true)) {
            return $this->queueSectionConfiguration($request, $queclinkDevice, $builder, 'server', $request->all());
        }

        $validated = $request->validate([
            'report_mode' => ['required', 'integer', 'between:0,7'],
            'manual_netreg' => ['required', 'integer', 'between:0,1'],
            'buffer_mode' => ['required', 'integer', 'between:0,2'],
            'main_host' => ['required', 'string', 'max:60'],
            'main_port' => ['required', 'integer', 'between:0,65535'],
            'backup_host' => ['nullable', 'string', 'max:60'],
            'backup_port' => ['required', 'integer', 'between:0,65535'],
            'sms_gateway' => ['nullable', 'string', 'max:20'],
            'heartbeat_interval_minutes' => ['required', 'integer', 'between:0,360'],
            'sack_enable' => ['required', 'integer', 'between:0,2'],
            'sms_ack_enable' => ['required', 'integer', 'between:0,1'],
            'psm_network_hold_time_seconds' => ['required', 'integer', 'between:0,86400'],
            'protocol_format' => ['required', 'integer', 'in:0'],
        ]);

        $built = $builder->gl30ServerRegistration($validated);
        $command = $this->queueCommand($request, $queclinkDevice, $built);
        $this->logAudit($request, $queclinkDevice, 'config_write', 'server', null, $validated, $command->raw_command);

        return back()->with('success', 'Server registration update queued.');
    }

    public function updateGlobalConfiguration(Request $request, QueclinkDevice $queclinkDevice, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'gnss_timeout_seconds' => ['required', 'integer', 'between:120,600'],
            'event_mask' => ['required', 'string', 'max:4', 'regex:/^[0-9A-Fa-f]{1,4}$/'],
            'report_item_mask' => ['required', 'string', 'max:4', 'regex:/^[0-9A-Fa-f]{1,4}$/'],
            'mode_selection' => ['required', 'integer', 'between:0,1'],
            'continuous_send_interval_seconds' => ['required', 'integer', 'between:0,65535', 'not_in:1,2,3,4'],
            'start_mode' => ['required', 'integer', 'between:0,2'],
            'specified_time_of_day' => ['required', 'string', 'regex:/^([01][0-9]|2[0-3])[0-5][0-9]$/'],
            'wakeup_interval_hours' => ['required', 'integer', 'between:1,168'],
            'gnss_enable' => ['required', 'integer', 'between:0,1'],
            'agps_mode' => ['required', 'integer', 'between:0,1'],
            'gsm_report' => ['required', 'string', 'max:4', 'regex:/^[0-9A-Fa-f]{1,4}$/'],
            'battery_low_percentage' => ['required', 'integer', 'between:0,30'],
            'function_button_mode' => ['required', 'integer', 'between:0,2'],
            'sos_report_mode' => ['required', 'integer', 'between:0,2'],
            'wifi_report' => ['required', 'integer', 'in:1,2,4,8'],
            'led_on' => ['required', 'integer', 'between:0,2'],
            'charge_standby_mode' => ['required', 'integer', 'between:0,1'],
        ]);

        $built = $builder->gl30GlobalConfiguration($validated);
        $command = $this->queueCommand($request, $queclinkDevice, $built);
        $this->logAudit($request, $queclinkDevice, 'config_write', 'tracking', null, $validated, $command->raw_command);

        return back()->with('success', 'Global tracking settings update queued.');
    }

    public function applyResidentSafetyProfile(Request $request, QueclinkDevice $queclinkDevice, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        $built = $builder->gl30ResidentSafetyProfile();
        $command = $this->queueCommand($request, $queclinkDevice, $built);
        $this->logAudit($request, $queclinkDevice, 'preset_apply', 'tracking', null, ['preset' => 'resident-safety'], $command->raw_command);

        return back()->with('success', 'Resident safety profile queued.');
    }

    /**
     * Apply every section in a saved preset to one paired GL30 device, queuing
     * one command per section in a single transaction.
     */
    public function applyPreset(Request $request, QueclinkDevice $queclinkDevice, QueclinkPreset $preset, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        $this->queclinkAccess->assertPreset($request->user(), $preset);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        if ($preset->target_category === 'vehicle_tracker') {
            throw ValidationException::withMessages([
                'preset' => 'Vehicle-tracker presets cannot be applied to a GL30 pendant.',
            ]);
        }

        $sections = $preset->sectionPayloads();
        if ($sections === []) {
            throw ValidationException::withMessages([
                'preset' => 'This preset has no configuration sections to apply.',
            ]);
        }

        $queued = 0;

        DB::transaction(function () use ($sections, $request, $queclinkDevice, $builder, $preset, &$queued) {
            foreach ($sections as $sectionKey => $fields) {
                try {
                    $built = $this->buildSectionCommand($builder, (string) $sectionKey, is_array($fields) ? $fields : []);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['preset' => $e->getMessage()]);
                }

                $command = $this->queueCommand($request, $queclinkDevice, $built);
                $this->logAudit($request, $queclinkDevice, 'preset_apply', (string) $sectionKey, null, [
                    'preset_id' => $preset->id,
                    'preset_slug' => $preset->slug,
                    'fields' => $fields,
                ], $command->raw_command);

                $queued++;
            }
        });

        return back()->with('success', "Preset \"{$preset->name}\" queued ({$queued} command".($queued === 1 ? '' : 's').').');
    }

    /**
     * Save a reusable application preset. Each section is built
     * once up front so a preset that would fail to apply is never persisted.
     */
    public function storePreset(Request $request, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'target_category' => ['nullable', 'string', Rule::in(['personal_tracker', 'all'])],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['array'],
        ]);

        $sections = [];
        foreach ($validated['sections'] as $key => $fields) {
            $sectionKey = strtolower((string) $key);

            if (! in_array($sectionKey, self::PRESET_SECTIONS, true)) {
                throw ValidationException::withMessages([
                    'sections' => "Unknown configuration section [{$key}].",
                ]);
            }

            try {
                $this->buildSectionCommand($builder, $sectionKey, is_array($fields) ? $fields : []);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['sections' => $e->getMessage()]);
            }

            $sections[$sectionKey] = array_filter(
                (array) $fields,
                fn ($value) => $value !== null && $value !== '',
            );
        }

        $preset = QueclinkPreset::create([
            'tenant_id' => LegacyStorageContext::id(),
            'name' => $validated['name'],
            'slug' => $this->uniquePresetSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'target_category' => $validated['target_category'] ?? 'personal_tracker',
            'payload' => $sections,
            'is_system' => false,
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', "Preset \"{$preset->name}\" saved.");
    }

    public function destroyPreset(Request $request, QueclinkPreset $preset)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertPreset($request->user(), $preset);
        abort_if($preset->is_system, 422, 'Built-in presets cannot be deleted.');
        $name = $preset->name;
        $preset->delete();

        return back()->with('success', "Preset \"{$name}\" deleted.");
    }

    public function updateSectionConfiguration(UpdateSectionRequest $request, QueclinkDevice $queclinkDevice, string $section, CommandBuilder $builder)
    {
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        return $this->queueSectionConfiguration($request, $queclinkDevice, $builder, $section, $request->validated());
    }

    public function cancelCommand(Request $request, QueclinkPendingCommand $command)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertCommand($request->user(), $command);
        abort_unless($command->status === QueclinkPendingCommand::STATUS_QUEUED, 422, 'Only queued commands can be cancelled.');

        $before = $command->only(['status', 'raw_command', 'serial_number']);
        $command->cancel((int) $request->user()->id);

        $device = $command->device;
        if ($device) {
            $this->logAudit($request, $device, 'cancel', null, $before, [
                'status' => $command->status,
                'command_id' => $command->id,
            ], $command->raw_command);
        }

        return back()->with('success', 'Queued command cancelled.');
    }

    public function retryCommand(Request $request, QueclinkPendingCommand $command)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertCommand($request->user(), $command);
        abort_unless(in_array($command->status, [
            QueclinkPendingCommand::STATUS_FAILED,
            QueclinkPendingCommand::STATUS_EXPIRED,
            QueclinkPendingCommand::STATUS_CANCELLED,
        ], true), 422, 'Only failed, expired, or cancelled commands can be retried.');

        $retry = QueclinkPendingCommand::create([
            'queclink_device_id' => $command->queclink_device_id,
            'imei' => $command->imei,
            'tenant_id' => LegacyStorageContext::id(),
            'command_word' => $command->command_word,
            'raw_command' => $command->raw_command,
            'serial_number' => $command->serial_number,
            'status' => QueclinkPendingCommand::STATUS_QUEUED,
            'created_by_user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(5),
        ]);

        $device = $command->device;
        if ($device) {
            $this->logAudit($request, $device, 'retry', null, [
                'command_id' => $command->id,
                'status' => $command->status,
            ], [
                'command_id' => $retry->id,
                'status' => $retry->status,
            ], $retry->raw_command);
        }

        return back()->with('success', 'Command retry queued.');
    }

    public function bulkAction(BulkActionRequest $request, CommandBuilder $builder)
    {
        $validated = $request->validated();
        $ids = array_values(array_unique(array_map('intval', $validated['device_ids'])));
        $action = $validated['action'];
        $section = $validated['section'] ?? 'all';

        $devices = $this->queclinkAccess->devicesForBulk($request->user(), $ids);

        $notPaired = $devices->first(fn (QueclinkDevice $device) => ! $device->isPaired());
        if ($notPaired) {
            throw ValidationException::withMessages([
                'device_ids' => 'Bulk actions can only be queued for paired devices.',
            ]);
        }

        $preset = null;
        if ($action === 'apply_preset') {
            $preset = QueclinkPreset::query()
                ->find((int) ($validated['preset_id'] ?? 0));

            if (! $preset || $preset->sectionPayloads() === [] || $preset->target_category === 'vehicle_tracker') {
                throw ValidationException::withMessages([
                    'preset_id' => 'The selected preset cannot be applied to these devices.',
                ]);
            }
        }

        if (in_array($action, ['resident_safety_profile', 'apply_preset'], true)) {
            $nonGl30 = $devices->first(fn (QueclinkDevice $device) => $this->guessFamily($device) !== CommandBuilder::FAMILY_GL30M);
            if ($nonGl30) {
                throw ValidationException::withMessages([
                    'action' => 'This action can only be applied to GL30 devices.',
                ]);
            }
        }

        $queued = 0;

        DB::transaction(function () use ($devices, $request, $builder, $action, $section, $preset, &$queued) {
            foreach ($devices as $device) {
                if ($action === 'apply_preset') {
                    foreach ($preset->sectionPayloads() as $sectionKey => $fields) {
                        $built = $this->buildSectionCommand($builder, (string) $sectionKey, is_array($fields) ? $fields : []);
                        $command = $this->queueCommand($request, $device, $built);
                        $this->logAudit($request, $device, 'bulk_apply', (string) $sectionKey, null, [
                            'action' => $action,
                            'preset_id' => $preset->id,
                            'command_id' => $command->id,
                        ], $command->raw_command);
                    }

                    $queued++;

                    continue;
                }

                $family = $this->guessFamily($device);
                $built = match ($action) {
                    'read_configuration' => $builder->readConfiguration($family, $section),
                    'reboot' => $builder->reboot($family),
                    'resident_safety_profile' => $builder->gl30ResidentSafetyProfile(),
                };

                $command = $this->queueCommand(
                    $request,
                    $device,
                    $built,
                    $action === 'read_configuration' ? now()->addMinutes(10) : null,
                );

                $this->logAudit($request, $device, 'bulk_apply', null, null, [
                    'action' => $action,
                    'section' => $section,
                    'command_id' => $command->id,
                ], $command->raw_command);

                $queued++;
            }
        });

        return back()->with('success', "Bulk action queued for {$queued} device(s).");
    }

    /**
     * Newest-first paged list of frames. Used by both the debug console
     * polling fallback and the initial render.
     */
    public function frames(Request $request)
    {
        abort_unless($this->userCanManage($request->user()), 403);

        $query = $this->visibleFrameQuery($request->user())->orderByDesc('id')->limit(200);

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction')->toString());
        }
        if ($request->filled('command_word')) {
            $query->where('command_word', $request->string('command_word')->toString());
        }
        if ($request->filled('parse_status')) {
            $parseStatus = $request->string('parse_status')->toString();
            if ($parseStatus === 'ok') {
                $query->where('parse_ok', true);
            } elseif ($parseStatus === 'error') {
                $query->where('parse_ok', false);
            }
        }
        if ($request->filled('since_id')) {
            $query->where('id', '>', (int) $request->input('since_id'))->orderBy('id');
        }

        return response()->json([
            'frames' => $query->get()->map(fn (QueclinkRawFrame $f) => $this->safeFrame($f))->values(),
        ]);
    }

    /**
     * Server-Sent Events stream of new frames. The debug console subscribes
     * here for real-time updates without needing Reverb.
     */
    public function stream(Request $request): StreamedResponse
    {
        abort_unless($this->userCanManage($request->user()), 403);

        $user = $request->user();
        $direction = $request->string('direction')->toString() ?: null;
        $commandWord = $request->string('command_word')->toString() ?: null;
        $parseStatus = $request->string('parse_status')->toString() ?: null;
        $cursor = (int) ($request->input('since_id') ?? $this->visibleFrameQuery($user)->max('id') ?? 0);

        return new StreamedResponse(function () use (&$cursor, $user, $direction, $commandWord, $parseStatus) {
            ignore_user_abort(false);
            @set_time_limit(0);
            echo "retry: 3000\n\n";
            @flush();

            $deadline = microtime(true) + 60; // 60s connection lifetime; client reconnects automatically

            while (microtime(true) < $deadline) {
                if (connection_aborted()) {
                    break;
                }

                $query = $this->visibleFrameQuery($user)
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(100);
                if ($direction !== null && in_array($direction, ['inbound', 'outbound'], true)) {
                    $query->where('direction', $direction);
                }
                if ($commandWord !== null) {
                    $query->where('command_word', $commandWord);
                }
                if ($parseStatus === 'ok') {
                    $query->where('parse_ok', true);
                } elseif ($parseStatus === 'error') {
                    $query->where('parse_ok', false);
                }
                $rows = $query->get();
                foreach ($rows as $row) {
                    $payload = json_encode($this->safeFrame($row));
                    echo "data: {$payload}\n\n";
                    $cursor = $row->id;
                }

                if ($rows->isEmpty()) {
                    echo ": heartbeat\n\n";
                }
                @flush();
                usleep(1_000_000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Generates the AT+GTSRI provisioning string operators paste into the
     *
     * @Track MT Setup tool to point a fresh device at this server.
     */
    public function provisioningString(Request $request)
    {
        abort_unless($this->userCanManage($request->user()), 403);

        $hostname = (string) (AppSetting::query()->where('key', 'queclink.public_hostname')->value('value') ?? '');
        $family = strtolower($request->string('family', 'gv500cg')->toString());

        if ($hostname === '') {
            return response()->json(['error' => 'Set the public hostname under Listener settings first.'], 422);
        }

        return response()->json([
            'state' => 'ready_for_secure_provisioning',
            'family' => $family === CommandBuilder::FAMILY_GL30M ? 'personal_tracker' : 'vehicle_tracker',
            'instructions' => [
                'Use the approved secure device-management process to retrieve and apply the protected server configuration.',
                'Return here to confirm the tracker appears and that bounded heartbeat state is current.',
            ],
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function devicePagePayload(
        Request $request,
        ConfigurationSnapshotService $configurations,
        string $search,
    ): array {
        $baseQuery = $this->visibleDeviceQuery($request->user())
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $match) use ($search): void {
                $match->where('imei', 'like', "%{$search}%")
                    ->orWhere('model_hint', 'like', "%{$search}%")
                    ->orWhere('protocol_version', 'like', "%{$search}%")
                    ->orWhere('firmware_version', 'like', "%{$search}%");
            }));

        $pages = collect([
            'paired' => QueclinkDevice::STATUS_PAIRED,
            'pending' => QueclinkDevice::STATUS_PENDING,
            'rejected' => QueclinkDevice::STATUS_REJECTED,
        ])->mapWithKeys(function (string $status, string $key) use ($baseQuery): array {
            $page = (clone $baseQuery)
                ->where('status', $status)
                ->with([
                    'device.assignments' => fn ($assignment) => $assignment
                        ->active()
                        ->orderByDesc('assigned_at')
                        ->orderByDesc('id')
                        ->limit(1),
                    'rawFrames' => fn ($frame) => $frame
                        ->where('direction', QueclinkRawFrame::DIRECTION_INBOUND)
                        ->where('command_word', 'GTALM')
                        ->where('parse_ok', true)
                        ->orderByDesc('id')
                        ->limit(30),
                    'pendingCommands' => fn ($command) => $command
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->limit(12),
                ])
                ->orderByDesc('last_seen_at')
                ->orderByDesc('id')
                ->paginate(self::DEVICE_PAGE_SIZE, ['*'], "{$key}_page")
                ->withQueryString();

            return [$key => $page];
        });

        $assignments = $pages
            ->flatMap(fn (LengthAwarePaginator $page): Collection => $page->getCollection())
            ->map(function (QueclinkDevice $tracker): ?DeviceAssignment {
                $device = $tracker->relationLoaded('device') ? $tracker->getRelation('device') : null;

                return $device instanceof Device && $device->relationLoaded('assignments')
                    ? $device->getRelation('assignments')->first()
                    : null;
            })
            ->filter()
            ->values();
        $assignmentLabels = $this->assignmentLabels($assignments, $request->user());

        $serialised = $pages->map(fn (LengthAwarePaginator $page): Collection => $page->getCollection()
            ->map(fn (QueclinkDevice $device): array => $this->serialiseDevice(
                $device,
                $configurations,
                $assignmentLabels,
            ))
            ->values());

        $counts = $pages->map(fn (LengthAwarePaginator $page): int => $page->total());

        return [
            'paired' => $serialised['paired'],
            'pending' => $serialised['pending'],
            'rejected' => $serialised['rejected'],
            'counts' => $counts,
            'total' => $counts->sum(),
            'search' => $search,
            'pagination' => $pages->map(fn (LengthAwarePaginator $page): array => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ]),
        ];
    }

    /**
     * @param  Collection<int, DeviceAssignment>  $assignments
     * @return array<string, string>
     */
    private function assignmentLabels(
        Collection $assignments,
        User $viewer,
    ): array {
        $idsFor = fn (string $type): array => $assignments
            ->where('assignable_type', $type)
            ->pluck('assignable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $labels = [];

        $assets = Asset::query()
            ->whereKey($idsFor(DeviceAssignment::TARGET_VEHICLE))
            ->whereIn('id', $this->devicesAccess->authorizedAssetIds($viewer))
            ->get(['id', 'name', 'registration_number']);
        foreach ($assets as $asset) {
            $labels[DeviceAssignment::TARGET_VEHICLE.':'.$asset->id] = $asset->name
                .($asset->registration_number ? " ({$asset->registration_number})" : '');
        }
        foreach ($this->devicesAccess->assignableStaff($viewer)->whereKey($idsFor(DeviceAssignment::TARGET_STAFF))->get(['id', 'name']) as $staff) {
            $labels[DeviceAssignment::TARGET_STAFF.':'.$staff->id] = $staff->name;
        }
        $clients = Client::query()
            ->whereKey($idsFor(DeviceAssignment::TARGET_CLIENT))
            ->whereIn('id', $this->devicesAccess->authorizedClientIds($viewer))
            ->get(['id', 'first_name', 'last_name']);
        foreach ($clients as $client) {
            $labels[DeviceAssignment::TARGET_CLIENT.':'.$client->id] = trim(($client->first_name ?? '').' '.($client->last_name ?? ''));
        }
        foreach (Site::query()->whereKey($idsFor(DeviceAssignment::TARGET_SITE))->whereIn('id', $this->devicesAccess->accessibleSiteIds($viewer))->get(['id', 'name']) as $site) {
            $labels[DeviceAssignment::TARGET_SITE.':'.$site->id] = $site->name;
        }
        foreach (SiteRoom::query()
            ->whereKey($idsFor(DeviceAssignment::TARGET_ROOM))
            ->whereIn('site_id', $this->devicesAccess->accessibleSiteIds($viewer))
            ->whereHas('site')
            ->get(['id', 'name']) as $room) {
            $labels[DeviceAssignment::TARGET_ROOM.':'.$room->id] = $room->name;
        }

        return $labels;
    }

    /** @param array<string, string> $assignmentLabels */
    private function serialiseDevice(
        QueclinkDevice $d,
        ?ConfigurationSnapshotService $configurations = null,
        array $assignmentLabels = [],
    ): array {
        $canonicalDevice = $d->relationLoaded('device') ? $d->getRelation('device') : null;
        $assignment = $canonicalDevice instanceof Device && $canonicalDevice->relationLoaded('assignments')
            ? $canonicalDevice->getRelation('assignments')->first()
            : null;

        $snapshot = $configurations?->latestForDevice($d);

        return [
            'id' => $d->id,
            'reference' => 'Tracker ending '.substr((string) $d->imei, -4),
            'status' => $d->status,
            'pending_pairing_type' => $d->pending_pairing_type,
            'model_hint' => $d->model_hint,
            'protocol_version' => $d->protocol_version,
            'firmware_version' => $d->firmware_version,
            'connection_state' => $d->connection_state,
            'first_seen_at' => $d->first_seen_at?->toIso8601String(),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'last_frame_at' => $d->last_frame_at?->toIso8601String(),
            'assignment' => $assignment ? [
                'type' => $assignment->assignable_type,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'label' => $assignmentLabels[$assignment->assignable_type.':'.$assignment->assignable_id]
                    ?? "(unknown {$assignment->assignable_type} #{$assignment->assignable_id})",
            ] : null,
            'configuration' => [
                'state' => ($snapshot['available'] ?? false) ? 'observed' : 'not_observed',
                'observed_at' => $snapshot['received_at'] ?? null,
                'sections' => array_values(array_keys($snapshot['sections'] ?? [])),
            ],
            'recent_commands' => $this->recentCommands($d),
        ];
    }

    /** @return array<string, mixed> */
    private function serialisePreset(QueclinkPreset $preset): array
    {
        return [
            'id' => $preset->id,
            'name' => $preset->name,
            'slug' => $preset->slug,
            'description' => $preset->description,
            'target_category' => $preset->target_category,
            'is_system' => $preset->is_system,
            'sections' => array_keys($preset->sectionPayloads()),
            'created_at' => $preset->created_at?->toIso8601String(),
        ];
    }

    private function uniquePresetSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'preset';
        $slug = $base;
        $suffix = 2;

        while (QueclinkPreset::query()
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function queueCommand(Request $request, QueclinkDevice $queclinkDevice, array $built, ?Carbon $expiresAt = null): QueclinkPendingCommand
    {
        return QueclinkPendingCommand::create([
            'queclink_device_id' => $queclinkDevice->id,
            'imei' => $queclinkDevice->imei,
            'tenant_id' => LegacyStorageContext::id(),
            'command_word' => $built['command_word'],
            'raw_command' => $built['raw'],
            'serial_number' => $built['serial'],
            'status' => QueclinkPendingCommand::STATUS_QUEUED,
            'created_by_user_id' => $request->user()->id,
            'expires_at' => $expiresAt ?? now()->addMinutes(5),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function queueSectionConfiguration(Request $request, QueclinkDevice $queclinkDevice, CommandBuilder $builder, string $section, array $payload)
    {
        $commandKey = strtolower((string) ($payload['command'] ?? $section));

        try {
            $built = $this->buildSectionCommand($builder, $commandKey, $payload);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['command' => $e->getMessage()]);
        }

        $command = $this->queueCommand($request, $queclinkDevice, $built);
        $this->logAudit($request, $queclinkDevice, 'config_write', $section, null, $payload, $command->raw_command);

        return back()->with('success', "{$built['command_word']} configuration update queued.");
    }

    /**
     * Resolve a section key (or alias) + payload into a built AT command.
     * Shared by single-section writes and preset application.
     *
     * @param  array<string, mixed>  $payload
     * @return array{command_word: string, raw: string, serial: string}
     */
    private function buildSectionCommand(CommandBuilder $builder, string $commandKey, array $payload): array
    {
        return match (strtolower($commandKey)) {
            'server', 'sri' => $builder->gl30ServerRegistration($payload),
            'tracking', 'global', 'cfg', 'cfg_alarm' => $builder->gl30GlobalConfiguration($payload),
            'pin' => $builder->gl30Pin($payload),
            'dog' => $builder->gl30Dog($payload),
            'time', 'tma' => $builder->gl30Tma($payload),
            'non_movement', 'nmd' => $builder->gl30Nmd($payload),
            'power', 'pds' => $builder->gl30Pds($payload),
            'wifi', 'wfi' => $builder->gl30Wifi($payload),
            'geo' => $builder->gl30Geo((int) ($payload['slot'] ?? 0), $payload),
            'bluetooth', 'bt', 'bts' => $builder->gl30Bts($payload),
            'beacons', 'bid' => $builder->gl30Bid($payload),
            'allowlist', 'wlt' => $builder->gl30Wlt($payload),
            'firmware_update', 'upc' => $builder->gl30Upc($payload),
            'firmware_version', 'fvr' => $builder->gl30Fvr($payload),
            default => throw new \InvalidArgumentException("Unsupported Queclink configuration command [{$commandKey}]."),
        };
    }

    private function resolveReadSectionCode(string $section, ?string $command = null): string
    {
        $candidate = strtoupper(trim((string) ($command ?: $section)));
        $aliases = [
            'SERVER' => 'SRI',
            'TRACKING' => 'CFG',
            'GLOBAL' => 'CFG',
            'ALARMS' => 'GEO',
            'POWER' => 'PDS',
            'CONNECTIVITY' => 'WFI',
            'WIFI' => 'WFI',
            'BLUETOOTH' => 'BTS',
            'BT' => 'BTS',
            'BEACONS' => 'BID',
            'IDENTITY' => 'BSI',
            'TIME' => 'TMA',
            'FIRMWARE' => 'FVR',
            'FIRMWARE_UPDATE' => 'UPC',
            'ALLOWLIST' => 'WLT',
            'NON_MOVEMENT' => 'NMD',
        ];

        $code = $aliases[$candidate] ?? $candidate;
        $allowed = ['BSI', 'SRI', 'CFG', 'PIN', 'DOG', 'TMA', 'NMD', 'PDS', 'GEO', 'BTS', 'WFI', 'BID', 'UPC', 'WLT', 'FVR'];

        if (! in_array($code, $allowed, true)) {
            throw ValidationException::withMessages(['section' => 'Unsupported Queclink configuration section.']);
        }

        return $code;
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function logAudit(
        Request $request,
        QueclinkDevice $queclinkDevice,
        string $eventType,
        ?string $section = null,
        ?array $before = null,
        ?array $after = null,
        ?string $rawCommand = null,
        ?string $notes = null,
        ?int $canonicalDeviceId = null,
        ?int $siteId = null,
    ): void {
        $canonicalDeviceId ??= $queclinkDevice->device_id ? (int) $queclinkDevice->device_id : null;

        QueclinkAuditEvent::log([
            'tenant_id' => LegacyStorageContext::id(),
            'provider_connection_id' => IntegrationProviderConnection::query()
                ->forProvider('queclink')
                ->oldest('id')
                ->value('id'),
            'site_id' => $siteId ?? $this->resolveAuditSiteId($canonicalDeviceId),
            'canonical_device_id' => $canonicalDeviceId,
            'queclink_device_id' => $queclinkDevice->id,
            'imei' => null,
            'user_id' => $request->user()?->id,
            'event_type' => $eventType,
            'outcome' => 'succeeded',
            'section' => $section,
            'payload_before' => $before === null ? null : ['fields' => SafeOperationalData::auditFields($before)],
            'payload_after' => $after === null ? null : ['fields' => SafeOperationalData::auditFields($after)],
            'raw_command' => null,
            'notes' => null,
        ]);
    }

    private function resolveAuditSiteId(?int $canonicalDeviceId): ?int
    {
        if (! $canonicalDeviceId) {
            return null;
        }

        $assignment = DeviceAssignment::query()
            ->where('device_id', $canonicalDeviceId)
            ->whereNull('released_at')
            ->latest('assigned_at')
            ->first(['assignable_type', 'assignable_id']);

        if (! $assignment) {
            return null;
        }

        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => Site::query()->whereKey($assignment->assignable_id)->value('id'),
            DeviceAssignment::TARGET_ROOM => SiteRoom::query()->whereKey($assignment->assignable_id)->value('site_id'),
            DeviceAssignment::TARGET_CLIENT => Client::query()->whereKey($assignment->assignable_id)->value('site_id'),
            DeviceAssignment::TARGET_STAFF => HrEmployeeProfile::query()
                ->where('user_id', $assignment->assignable_id)
                ->where('is_active', true)
                ->value('primary_site_id'),
            DeviceAssignment::TARGET_VEHICLE => $this->resolveAssetSiteId((int) $assignment->assignable_id),
            default => null,
        };
    }

    private function resolveAssetSiteId(int $assetId): ?int
    {
        $asset = Asset::query()->find($assetId, ['site_id', 'room_id', 'home_site_id', 'client_id']);

        if (! $asset) {
            return null;
        }

        return $asset->site_id
            ?? ($asset->room_id ? SiteRoom::query()->whereKey($asset->room_id)->value('site_id') : null)
            ?? $asset->home_site_id
            ?? ($asset->client_id ? Client::query()->whereKey($asset->client_id)->value('site_id') : null);
    }

    private function recentCommands(QueclinkDevice $d): array
    {
        $commands = $d->relationLoaded('pendingCommands')
            ? $d->pendingCommands->sortByDesc('created_at')->take(12)->values()
            : QueclinkPendingCommand::query()->recentFor($d, 12)->get();

        return $commands
            ->map(fn (QueclinkPendingCommand $command) => [
                'id' => $command->id,
                'command_word' => $command->command_word,
                'status' => $command->status,
                'created_at' => $command->created_at?->toIso8601String(),
                'sent_at' => $command->sent_at?->toIso8601String(),
                'acked_at' => $command->acked_at?->toIso8601String(),
                'cancelled_at' => $command->cancelled_at?->toIso8601String(),
                'expires_at' => $command->expires_at?->toIso8601String(),
                'failure_category' => $command->status === QueclinkPendingCommand::STATUS_FAILED ? 'provider_failure' : null,
            ])
            ->values()
            ->all();
    }

    private function visibleDeviceQuery(User $viewer): Builder
    {
        return QueclinkDevice::query()->where(function (Builder $query) use ($viewer): void {
            $query->whereIn('device_id', $this->devicesAccess->visibleDevices($viewer)->select('devices.id'))
                ->orWhereIn('device_id', $this->devicesAccess->releasableDevices($viewer)->select('devices.id'));

            if ($this->devicesAccess->canViewUnassigned($viewer)) {
                $query->orWhereNull('device_id');
            }
        });
    }

    private function visibleFrameQuery(User $viewer): Builder
    {
        return QueclinkRawFrame::query()
            ->whereIn('queclink_device_id', $this->visibleDeviceQuery($viewer)->select('queclink_devices.id'));
    }

    /** @return array<string, mixed> */
    private function safeFrame(QueclinkRawFrame $frame): array
    {
        return [
            'id' => $frame->id,
            'direction' => $frame->direction,
            'frame_type' => $frame->frame_type,
            'command_word' => $frame->command_word,
            'parse_ok' => (bool) $frame->parse_ok,
            'failure_category' => $frame->parse_ok ? null : 'parse_failure',
            'created_at' => $frame->created_at?->toIso8601String(),
        ];
    }

    private function ensurePersonalTrackerAsset(string $type, User|Client $target, User $viewer): Asset
    {
        $targetId = (int) $target->getKey();
        $existing = Asset::query()
            ->where('category', 'personal_tracker')
            ->when($type === 'staff', fn ($q) => $q->where('primary_driver_user_id', $targetId))
            ->when($type === 'client', fn ($q) => $q->where('client_id', $targetId))
            ->lockForUpdate()
            ->get();

        foreach ($existing as $candidate) {
            $this->queclinkAccess->assertAsset($viewer, $candidate);
        }
        abort_if($existing->count() > 1, 409, 'Multiple canonical personal assets require reconciliation before pairing.');
        if ($existing->isNotEmpty()) {
            return $existing->first();
        }

        $siteId = match ($type) {
            'staff' => HrEmployeeProfile::query()
                ->where('user_id', $targetId)
                ->where('is_active', true)
                ->value('primary_site_id'),
            'client' => $target->site_id,
        };
        abort_unless(is_numeric($siteId), 404);
        $this->devicesAccess->assertCanViewSite($viewer, (int) $siteId);

        $name = match ($type) {
            'staff' => 'Personal tracker — '.$target->name,
            'client' => 'Care tracker — '.trim(($target->first_name ?? '').' '.($target->last_name ?? '')),
        };

        return Asset::create([
            'name' => $name,
            'category' => 'personal_tracker',
            'status' => 'active',
            'site_id' => (int) $siteId,
            'primary_driver_user_id' => $type === 'staff' ? $targetId : null,
            'client_id' => $type === 'client' ? $targetId : null,
        ]);
    }

    private function ensureCanonicalDevice(QueclinkDevice $qd, User $viewer, string $pairingType): Device
    {
        $identityQuery = Device::query()
            ->where('provider', 'queclink')
            ->where(function ($q) use ($qd) {
                $q->where('imei', $qd->imei)
                    ->orWhere('device_uid', $qd->imei);
            });
        $existing = $identityQuery->first();

        if ($existing) {
            abort_unless($this->devicesAccess->visibleDevices($viewer)->whereKey($existing->id)->exists(), 404);
            $existing->fill([
                'imei' => $qd->imei,
                'device_uid' => $existing->device_uid ?: $qd->imei,
                'manufacturer' => $existing->manufacturer ?: 'Queclink',
                'model' => $existing->model ?: $qd->model_hint,
                'firmware_version' => $existing->firmware_version ?: $qd->firmware_version,
                'tenant_id' => LegacyStorageContext::id(),
                'last_seen_at' => $qd->last_seen_at ?: $existing->last_seen_at,
            ])->save();

            return $existing;
        }

        return Device::create([
            'tenant_id' => LegacyStorageContext::id(),
            'device_uid' => $qd->imei,
            'name' => match ($pairingType) {
                'vehicle' => "Vehicle tracker {$qd->imei}",
                'staff' => "Lone-worker tracker {$qd->imei}",
                'client' => "Care tracker {$qd->imei}",
            },
            'domain' => 'tracking',
            'category' => match ($pairingType) {
                'vehicle' => 'vehicle_tracker',
                'staff', 'client' => 'personal_tracker',
            },
            'manufacturer' => 'Queclink',
            'model' => $qd->model_hint,
            'imei' => $qd->imei,
            'firmware_version' => $qd->firmware_version,
            'provider' => 'queclink',
            'status' => 'active',
            'last_seen_at' => $qd->last_seen_at,
        ]);
    }

    private function guessFamily(QueclinkDevice $qd): string
    {
        $hint = strtolower((string) $qd->model_hint);
        if (str_contains($hint, 'gl30') || str_contains($hint, 'gl-30')) {
            return CommandBuilder::FAMILY_GL30M;
        }
        if (str_contains($hint, 'gv500')) {
            return CommandBuilder::FAMILY_GV500CG;
        }

        $category = strtolower((string) $qd->device?->category);
        if (in_array($category, ['personal_tracker', 'lone_worker_tracker', 'client_tracker'], true)) {
            return CommandBuilder::FAMILY_GL30M;
        }

        $assignmentType = $qd->device_id
            ? DeviceAssignment::query()
                ->where('device_id', $qd->device_id)
                ->whereNull('released_at')
                ->latest('assigned_at')
                ->value('assignable_type')
            : null;

        if (in_array($assignmentType, [DeviceAssignment::TARGET_STAFF, DeviceAssignment::TARGET_CLIENT], true)) {
            return CommandBuilder::FAMILY_GL30M;
        }

        return CommandBuilder::FAMILY_GV500CG;
    }

    private function systemdState(): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'unavailable';
        }
        $output = [];
        $code = 0;
        @exec('systemctl is-active oblivion-queclink.service 2>&1', $output, $code);

        return SafeOperationalData::serviceState(implode(' ', $output), $code);
    }

    private function userCanManage($user): bool
    {
        return $user && $user->canDo('securityDevices.integrations.manage');
    }
}
