<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Presenters\IntegrationSiteCredentialsPresenter;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\DeviceFieldOwnershipService;
use App\Domain\SecurityDevices\Services\DeviceCustodySiteResolver;
use App\Domain\SecurityDevices\Services\DeviceLinkService;
use App\Domain\SecurityDevices\Services\QueclinkIntegrationAccessService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Queclink\BulkActionRequest;
use App\Http\Requests\Queclink\UpdateSectionRequest;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Queclink\QueclinkAuditEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkPreset;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ConsentValidationService;
use App\Services\Queclink\CommandBuilder;
use App\Services\Queclink\ConfigurationSnapshotService;
use App\Services\Queclink\LocateNowService;
use App\Services\Queclink\QueclinkConfigurationProfileService;
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
 * No cloud API capability is inferred. QueclinkController retains only the
 * cleanup route for credentials saved by the retired cloud scaffold.
 */
class QueclinkHubController extends Controller
{
    private const DEVICE_PAGE_SIZE = 25;

    public function __construct(
        private readonly QueclinkIntegrationAccessService $queclinkAccess,
        private readonly IntegrationSiteCredentialsPresenter $siteCredentials,
        private readonly SecurityDevicesAccessService $devicesAccess,
        private readonly DeviceLinkService $deviceLinks,
        private readonly DeviceAssignmentService $deviceAssignments,
        private readonly DeviceFieldOwnershipService $fieldOwnership,
        private readonly QueclinkConfigurationProfileService $configurationProfiles,
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
            'cloudIntegration' => function (): array {
                $connection = IntegrationProviderConnection::query()
                    ->forProvider('queclink')
                    ->first();

                return [
                    'status' => 'unavailable',
                    'legacy_credential_stored' => $connection !== null,
                    'legacy_credential_last4' => $connection?->secret_last4,
                ];
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
                ->active()
                ->with('configurationProfile')
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get()
                ->map(fn (QueclinkPreset $preset) => $this->serialisePreset($preset))
                ->values(),
            'retiredPresets' => fn (): Collection => QueclinkPreset::query()
                ->retired()
                ->with(['configurationProfile', 'retiredBy:id,name'])
                ->orderByDesc('retired_at')
                ->limit(100)
                ->get()
                ->map(fn (QueclinkPreset $preset) => $this->serialiseRetiredPreset($preset))
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

            $assignment = $this->deviceAssignments->assign(
                device: $device,
                assignableType: $pairingType,
                assignableId: $targetId,
                assignedByUserId: (int) $request->user()->id,
                assignmentType: AssignmentType::Permanent,
                consentId: $consentId,
            );

            $lockedDevice->update([
                'status' => QueclinkDevice::STATUS_PAIRED,
                'pending_pairing_type' => null,
                'device_id' => $device->id,
                'binding_uuid' => (string) Str::uuid(),
            ]);

            $this->logAudit($request, $lockedDevice, 'claim', null, null, [
                'pairing_type' => $pairingType,
                'target_id' => $targetId,
                'device_id' => $device->id,
                'device_assignment_id' => $assignment->id,
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

            if ($consent && ConsentValidationService::isValidTrackingConsent($consent, $client)) {
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
        $this->assertDeviceForRelease($request->user(), $queclinkDevice);

        DB::transaction(function () use ($queclinkDevice, $request) {
            $lockedDevice = QueclinkDevice::query()
                ->whereKey($queclinkDevice->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertDeviceForRelease($request->user(), $lockedDevice);
            abort_unless($lockedDevice->isPaired(), 422);

            $canonicalDeviceId = $lockedDevice->device_id ? (int) $lockedDevice->device_id : null;
            $auditSiteId = $this->resolveAuditSiteId($canonicalDeviceId);

            $canonicalDevice = Device::query()
                ->whereKey($lockedDevice->device_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanReleaseCanonicalDevice(
                $request->user(),
                $canonicalDevice,
                true,
            );
            $this->deviceAssignments->release($canonicalDevice, (int) $request->user()->id);

            $this->deviceLinks->unlinkAllForDevice($canonicalDevice);

            $lockedDevice->update([
                'status' => QueclinkDevice::STATUS_PENDING,
                'device_id' => null,
                'binding_uuid' => null,
            ]);

            $this->logAudit($request, $lockedDevice, 'release', null, null, [
                'status' => QueclinkDevice::STATUS_PENDING,
                'device_id' => null,
            ], canonicalDeviceId: $canonicalDeviceId, siteId: $auditSiteId);
        });

        return back()->with('success', "Device {$queclinkDevice->imei} released — moved back to pending.");
    }

    public function sendCommand(Request $request, QueclinkDevice $queclinkDevice, LocateNowService $locateNow)
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

        if ($validated['mode'] !== 'preset'
            || ! in_array($validated['preset'], ['request_location', 'reboot'], true)) {
            throw ValidationException::withMessages([
                'command' => 'Direct or state-changing Queclink commands are not available from the provider console. Use the governed Device Management workflow.',
            ]);
        }
        abort_unless($queclinkDevice->device, 422, 'This Queclink tracker is not linked to a canonical Device.');

        if ($validated['preset'] === 'reboot') {
            return $this->deviceManagementHandoff(
                $queclinkDevice,
                'device.reboot',
                'Review the governed restart, confirm the impact and approved IT Change, then dispatch from Device Management.',
            );
        }

        return redirect()->to($locateNow->managementUrlForDevice($queclinkDevice->device))->with(
            'success',
            'Review the governed location refresh, confirm your identity, and record the operational reason before dispatch.',
        );
    }

    public function readConfiguration(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be read from paired devices.');

        $validated = $request->validate([
            'section' => ['nullable', 'string', 'in:all,BSI,SRI,NTS,TLS,CFG,PIN,DOG,TMA,NMD,PDS,GEO,BTS,WFI,BID,UPC,WLT,FVR'],
        ]);

        $section = $validated['section'] ?? 'all';

        return $this->configurationRefreshHandoff($queclinkDevice, $section);
    }

    public function readConfigurationSection(Request $request, QueclinkDevice $queclinkDevice, string $section)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be read from paired devices.');

        $code = $this->resolveReadSectionCode($section, $request->string('command')->toString());

        return $this->configurationRefreshHandoff($queclinkDevice, $code);
    }

    public function updateServerConfiguration(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        if ($request->filled('command') && ! in_array($request->string('command')->toString(), ['server', 'sri'], true)) {
            return $this->configurationApplyDraftHandoff($request, $queclinkDevice, 'server', $request->all());
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

        return $this->configurationApplyDraftHandoff($request, $queclinkDevice, 'server', $validated);
    }

    public function updateGlobalConfiguration(Request $request, QueclinkDevice $queclinkDevice)
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

        return $this->configurationApplyDraftHandoff($request, $queclinkDevice, 'tracking', $validated);
    }

    public function applyResidentSafetyProfile(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        $preset = QueclinkPreset::query()->active()->where('slug', 'resident-safety')->firstOrFail();

        return $this->configurationApplyHandoff($queclinkDevice, $this->profileForPreset($preset));
    }

    /**
     * Apply every section in a saved preset to one paired GL30 device, queuing
     * one command per section in a single transaction.
     */
    public function applyPreset(Request $request, QueclinkDevice $queclinkDevice, QueclinkPreset $preset)
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

        if ($preset->sectionPayloads() === []) {
            throw ValidationException::withMessages([
                'preset' => 'This preset has no configuration sections to apply.',
            ]);
        }

        return $this->configurationApplyHandoff($queclinkDevice, $this->profileForPreset($preset));
    }

    /**
     * Save a reusable application preset. Each section is built
     * once up front so a preset that would fail to apply is never persisted.
     */
    public function storePreset(Request $request)
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

            $sections[$sectionKey] = array_filter(
                (array) $fields,
                fn ($value) => $value !== null && $value !== '',
            );
        }

        $slug = $this->uniquePresetSlug($validated['name']);
        $preset = DB::transaction(function () use ($validated, $sections, $request, $slug): QueclinkPreset {
            $profile = $this->configurationProfiles->createProfile(
                profileKey: QueclinkConfigurationProfileService::profileKey($slug),
                name: $validated['name'],
                description: $validated['description'] ?? null,
                targetCategory: $validated['target_category'] ?? 'personal_tracker',
                sections: $sections,
                isSystem: false,
                createdByUserId: (int) $request->user()->id,
            );

            return QueclinkPreset::query()->create([
                'device_configuration_profile_id' => $profile->id,
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'target_category' => $validated['target_category'] ?? 'personal_tracker',
                'payload' => [],
                'is_system' => false,
                'created_by_user_id' => $request->user()->id,
            ]);
        });

        return back()->with('success', "Preset \"{$preset->name}\" saved.");
    }

    public function destroyPreset(Request $request, QueclinkPreset $preset)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertPreset($request->user(), $preset);
        abort_if($preset->is_system, 422, 'Built-in presets cannot be retired.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
        ]);
        $actor = $request->user();

        $name = DB::transaction(function () use ($actor, $preset, $validated): string {
            $locked = QueclinkPreset::query()
                ->whereKey($preset->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->queclinkAccess->assertPreset($actor, $locked);
            abort_if($locked->is_system, 422, 'Built-in presets cannot be retired.');

            $profile = DeviceConfigurationProfile::query()
                ->whereKey($locked->device_configuration_profile_id)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $profile instanceof DeviceConfigurationProfile,
                409,
                'This preset has no governed configuration profile. Repair its retained evidence before retrying.',
            );
            abort_unless(
                $profile->status === DeviceConfigurationProfile::STATUS_ACTIVE,
                409,
                'This preset configuration is already retired. Reconcile its retained preset evidence before retrying.',
            );

            $activeVersions = DeviceConfigurationProfile::query()
                ->where('profile_key', $profile->profile_key)
                ->where('status', DeviceConfigurationProfile::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($activeVersions as $activeVersion) {
                $activeVersion->retire();
            }
            $locked->retireGoverned((int) $actor->id, $validated['reason']);

            AuditLogger::logOrFail('security_devices.queclink.preset.retired', $locked, [
                'actor_id' => (int) $actor->id,
                'application_scope' => 'single_application',
                'reason' => trim($validated['reason']),
                'profile_version' => (int) $profile->version,
                'profile_status' => DeviceConfigurationProfile::STATUS_RETIRED,
                'profile_versions_retired' => $activeVersions->count(),
            ]);

            return $locked->name;
        }, 3);

        return back()->with('success', "Preset \"{$name}\" retired. Its governed history remains available for audit.");
    }

    public function updateSectionConfiguration(UpdateSectionRequest $request, QueclinkDevice $queclinkDevice, string $section)
    {
        $this->queclinkAccess->assertDevice($request->user(), $queclinkDevice);
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        return $this->configurationApplyDraftHandoff($request, $queclinkDevice, $section, $request->validated());
    }

    public function cancelCommand(Request $request, QueclinkPendingCommand $command)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $this->queclinkAccess->assertCommand($request->user(), $command);
        abort_if(
            $command->device_command_request_id !== null,
            409,
            'Governed commands must be cancelled from the canonical Device Management workflow.',
        );
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

    public function bulkAction(BulkActionRequest $request)
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

        $profile = null;
        if (in_array($action, ['resident_safety_profile', 'apply_preset'], true)) {
            $preset = $action === 'resident_safety_profile'
                ? QueclinkPreset::query()->active()->where('slug', 'resident-safety')->first()
                : QueclinkPreset::query()->active()->find((int) ($validated['preset_id'] ?? 0));

            if (! $preset || $preset->sectionPayloads() === [] || $preset->target_category === 'vehicle_tracker') {
                throw ValidationException::withMessages([
                    'preset_id' => 'The selected preset cannot be applied to these devices.',
                ]);
            }
            $profile = $this->profileForPreset($preset);
        }

        if (in_array($action, ['resident_safety_profile', 'apply_preset'], true)) {
            $nonGl30 = $devices->first(fn (QueclinkDevice $device) => $this->guessFamily($device) !== CommandBuilder::FAMILY_GL30M);
            if ($nonGl30) {
                throw ValidationException::withMessages([
                    'action' => 'This action can only be applied to GL30 devices.',
                ]);
            }
        }

        $canonicalIds = $devices->pluck('device_id')->filter()->map(fn ($id): int => (int) $id)->values();
        if ($canonicalIds->count() !== $devices->count()) {
            throw ValidationException::withMessages([
                'device_ids' => 'Every tracker must be linked to one canonical Device before governed bulk management.',
            ]);
        }
        $capability = match ($action) {
            'read_configuration' => 'configuration.refresh',
            'reboot' => 'device.reboot',
            'resident_safety_profile', 'apply_preset' => 'configuration.apply',
        };
        if ($canonicalIds->count() === 1) {
            $device = $devices->firstOrFail();
            $parameters = $capability === 'configuration.apply'
                ? ['command_configuration_profile_id' => (string) $profile?->id]
                : ($capability === 'configuration.refresh' ? ['command_section' => $section] : []);

            return $this->deviceManagementHandoff(
                $device,
                $capability,
                'Review the governed action, its current Device evidence, and the required approvals before dispatch.',
                $parameters,
            );
        }

        return redirect()->to('/security-devices/tracking?'.http_build_query([
            'tab' => 'management',
            'bulk_action' => $capability,
            'bulk_device_ids' => $canonicalIds->implode(','),
            ...($capability === 'configuration.apply' ? ['bulk_configuration_profile_id' => $profile?->id] : []),
            ...($capability === 'configuration.refresh' ? ['bulk_section' => $section] : []),
        ], '', '&', PHP_QUERY_RFC3986))->with(
            'success',
            'Review every included Device and Site, attach the required IT Change per Device, and approve the governed bulk request before dispatch.',
        );
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
     * Confirms that protected provisioning can proceed for a supported family.
     * The endpoint never returns the provider target or a raw device command.
     */
    public function provisioningString(Request $request)
    {
        abort_unless($this->userCanManage($request->user()), 403);

        $validated = $request->validate([
            'family' => ['sometimes', 'string', Rule::in([
                CommandBuilder::FAMILY_GV500CG,
                CommandBuilder::FAMILY_GL30M,
            ])],
        ]);

        $hostname = trim((string) (AppSetting::query()->where('key', 'queclink.public_hostname')->value('value')
            ?? config('services.queclink.public_hostname')
            ?? ''));
        $family = (string) ($validated['family'] ?? CommandBuilder::FAMILY_GV500CG);

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
            'canonical_device_id' => $canonicalDevice?->id,
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
            'profile_uuid' => $preset->configurationProfile?->uuid,
            'profile_version' => $preset->configurationProfile?->version,
            'payload_hash' => $preset->configurationProfile?->payload_hash,
            'created_at' => $preset->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serialiseRetiredPreset(QueclinkPreset $preset): array
    {
        return [
            ...$this->serialisePreset($preset),
            'retired_at' => $preset->retired_at?->toIso8601String(),
            'retired_by' => $preset->retiredBy?->name,
            'retirement_reason' => $preset->retirement_reason,
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

    /** @param array<string, mixed> $payload */
    private function configurationApplyDraftHandoff(
        Request $request,
        QueclinkDevice $queclinkDevice,
        string $section,
        array $payload,
    ) {
        $commandKey = strtolower((string) ($payload['command'] ?? $section));
        $device = $queclinkDevice->device;
        abort_unless($device, 422, 'This Queclink tracker is not linked to a canonical Device.');
        $profile = $this->configurationProfiles->createProfile(
            profileKey: 'queclink:device-'.$device->id.':draft:'.Str::orderedUuid(),
            name: $device->name.' · '.Str::headline($commandKey).' change',
            description: 'Immutable one-use desired state authored from the Queclink configuration workspace.',
            targetCategory: $device->category,
            sections: [$commandKey => $payload],
            isSystem: false,
            createdByUserId: (int) $request->user()->id,
        );
        $this->logAudit($request, $queclinkDevice, 'configuration_profile_authored', $section, null, [
            'profile_uuid' => $profile->uuid,
            'profile_version' => $profile->version,
            'payload_hash' => $profile->payload_hash,
        ]);

        return $this->configurationApplyHandoff($queclinkDevice, $profile);
    }

    private function configurationApplyHandoff(
        QueclinkDevice $queclinkDevice,
        DeviceConfigurationProfile $profile,
    ) {
        $device = $queclinkDevice->device;
        abort_unless($device, 422, 'This Queclink tracker is not linked to a canonical Device.');
        $this->configurationProfiles->assertCompatible($device, (int) $profile->id);

        return $this->deviceManagementHandoff(
            $queclinkDevice,
            'configuration.apply',
            'The desired state was saved as an encrypted immutable profile. Review the impact, attach the approved IT Change, obtain independent approval, and dispatch from Device Management.',
            ['command_configuration_profile_id' => (string) $profile->id],
        );
    }

    private function profileForPreset(QueclinkPreset $preset): DeviceConfigurationProfile
    {
        $profile = $preset->configurationProfile()->first();
        if ($profile) {
            return $profile;
        }

        return DB::transaction(function () use ($preset): DeviceConfigurationProfile {
            $locked = QueclinkPreset::query()->lockForUpdate()->findOrFail($preset->id);
            abort_if($locked->isRetired(), 404);
            $profile = $locked->configurationProfile()->first();
            if ($profile) {
                return $profile;
            }
            $profile = $this->configurationProfiles->createProfile(
                profileKey: QueclinkConfigurationProfileService::profileKey($locked->slug),
                name: $locked->name,
                description: $locked->description,
                targetCategory: $locked->target_category,
                sections: $locked->sectionPayloads(),
                isSystem: (bool) $locked->is_system,
                createdByUserId: $locked->created_by_user_id,
            );
            $locked->forceFill([
                'device_configuration_profile_id' => $profile->id,
                'payload' => [],
            ])->save();

            return $profile;
        });
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

    private function configurationRefreshHandoff(QueclinkDevice $queclinkDevice, string $section)
    {
        $device = $queclinkDevice->device;
        abort_unless($device, 422, 'This Queclink tracker is not linked to a canonical Device.');
        $parameter = strtoupper(trim($section));
        if ($parameter === '' || $parameter === 'ALL') {
            $parameter = 'all';
        }

        return redirect()->to('/security-devices/devices/'.$device->id.'?'.http_build_query([
            'section' => 'management',
            'action' => 'configuration.refresh',
            'command_section' => $parameter,
        ], '', '&', PHP_QUERY_RFC3986))->with(
            'success',
            'Review the governed configuration refresh, confirm your identity, and record the operational reason before dispatch.',
        );
    }

    private function deviceManagementHandoff(
        QueclinkDevice $queclinkDevice,
        string $action,
        string $message,
        array $parameters = [],
    ) {
        $device = $queclinkDevice->device;
        abort_unless($device, 422, 'This Queclink tracker is not linked to a canonical Device.');

        return redirect()->to('/security-devices/devices/'.$device->id.'?'.http_build_query([
            'section' => 'management',
            'action' => $action,
            ...$parameters,
        ], '', '&', PHP_QUERY_RFC3986))->with('success', $message);
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
            ->first(['assignable_type', 'assignable_id', 'custody_site_id']);

        if (! $assignment) {
            return null;
        }

        if (is_numeric($assignment->custody_site_id) && (int) $assignment->custody_site_id > 0) {
            return (int) $assignment->custody_site_id;
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
            ?? ($asset->room_id ? SiteHouseRoom::query()->whereKey($asset->room_id)->value('site_id') : null)
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
                'fulfilled_at' => $command->fulfilled_at?->toIso8601String(),
                'cancelled_at' => $command->cancelled_at?->toIso8601String(),
                'expires_at' => $command->expires_at?->toIso8601String(),
                'governed' => $command->device_command_request_id !== null,
                'failure_category' => $command->status === QueclinkPendingCommand::STATUS_FAILED ? 'provider_failure' : null,
            ])
            ->values()
            ->all();
    }

    private function visibleDeviceQuery(User $viewer): Builder
    {
        $query = QueclinkDevice::query()->where(function (Builder $query) use ($viewer): void {
            $query->whereIn('device_id', $this->devicesAccess->visibleDevices($viewer)->select('devices.id'))
                ->orWhereIn('device_id', $this->devicesAccess->releasableDevices($viewer)->select('devices.id'))
                ->orWhere(function (Builder $historical) use ($viewer): void {
                    $historical->where('status', QueclinkDevice::STATUS_PAIRED)
                        ->whereIn('device_id', $this->historicalClientReleaseDevices($viewer)->select('devices.id'));
                });

            if ($this->devicesAccess->canViewUnassigned($viewer)) {
                $query->orWhereNull('device_id');
            }
        });

        return $query->where(function (Builder $integrity) use ($viewer): void {
            $integrity->whereNull('device_id')
                ->orWhereNotIn('device_id', $this->historicalClientAssignedDevices()->select('devices.id'))
                ->orWhere(function (Builder $historical) use ($viewer): void {
                    $historical->where('status', QueclinkDevice::STATUS_PAIRED)
                        ->whereIn('device_id', $this->historicalClientReleaseDevices($viewer)->select('devices.id'));
                });
        });
    }

    private function historicalClientAssignedDevices(): Builder
    {
        return Device::query()->whereHas('assignments', fn (Builder $assignment): Builder => $assignment
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->whereExists(fn ($clients) => $clients
                ->selectRaw('1')
                ->from('clients as historical_clients')
                ->whereColumn('historical_clients.id', 'device_assignments.assignable_id')
                ->whereNotNull('historical_clients.deleted_at')));
    }

    private function historicalClientReleaseDevices(User $viewer): Builder
    {
        $siteIds = $this->devicesAccess->accessibleSiteIds($viewer);
        $query = Device::query()->where('status', '!=', DeviceStatus::Quarantined->value);
        if (! $this->userCanManage($viewer) || ! $viewer->canDo('clients.viewAny') || $siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('assignments', function (Builder $assignment) use ($siteIds): void {
            $assignment->active()
                ->where('assigned_at', '<=', now())
                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                ->whereIn('custody_site_id', $siteIds)
                ->whereExists(fn ($clients) => $clients
                    ->selectRaw('1')
                    ->from('clients as historical_clients')
                    ->whereColumn('historical_clients.id', 'device_assignments.assignable_id')
                    ->whereColumn('historical_clients.site_id', 'device_assignments.custody_site_id')
                    ->whereNotNull('historical_clients.deleted_at'))
                ->whereExists(fn ($links) => $links
                    ->selectRaw('1')
                    ->from('device_asset_links as historical_links')
                    ->join('assets as historical_assets', 'historical_assets.id', '=', 'historical_links.asset_id')
                    ->whereColumn('historical_links.device_id', 'device_assignments.device_id')
                    ->whereNull('historical_links.unlinked_at')
                    ->whereColumn('historical_assets.client_id', 'device_assignments.assignable_id')
                    ->whereColumn('historical_assets.site_id', 'device_assignments.custody_site_id'))
                ->whereNotExists(fn ($links) => $links
                    ->selectRaw('1')
                    ->from('device_asset_links as conflicting_links')
                    ->leftJoin('assets as conflicting_assets', 'conflicting_assets.id', '=', 'conflicting_links.asset_id')
                    ->whereColumn('conflicting_links.device_id', 'device_assignments.device_id')
                    ->whereNull('conflicting_links.unlinked_at')
                    ->where(function ($mismatch): void {
                        $mismatch->whereNull('conflicting_assets.id')
                            ->orWhereNull('conflicting_assets.client_id')
                            ->orWhereColumn('conflicting_assets.client_id', '!=', 'device_assignments.assignable_id')
                            ->orWhereNull('conflicting_assets.site_id')
                            ->orWhereColumn('conflicting_assets.site_id', '!=', 'device_assignments.custody_site_id');
                    }));
        });
    }

    private function visibleFrameQuery(User $viewer): Builder
    {
        return QueclinkRawFrame::query()
            ->whereIn('queclink_device_id', $this->visibleDeviceQuery($viewer)->select('queclink_devices.id'));
    }

    private function assertDeviceForRelease(User $user, QueclinkDevice $providerDevice): void
    {
        abort_unless(
            $this->userCanManage($user)
                && $providerDevice->isPaired()
                && is_numeric($providerDevice->device_id),
            404,
        );

        $canonicalDevice = Device::query()->find((int) $providerDevice->device_id);
        abort_unless($canonicalDevice instanceof Device, 404);
        $this->assertCanReleaseCanonicalDevice($user, $canonicalDevice);
    }

    private function assertCanReleaseCanonicalDevice(User $user, Device $device, bool $lockForUpdate = false): void
    {
        $isNormallyReleasable = $this->devicesAccess->releasableDevices($user)
            ->whereKey($device->getKey())
            ->exists();
        $query = $device->assignments()->active();
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $assignments = $query->get();
        if ($assignments->isEmpty()) {
            abort_unless($isNormallyReleasable, 404);

            return;
        }

        foreach ($assignments as $assignment) {
            if ($isNormallyReleasable && $this->devicesAccess->canAccessCurrentAssignment($user, $assignment)) {
                continue;
            }

            abort_unless(
                $device->status !== DeviceStatus::Quarantined
                    && $this->canReleaseFromRetainedProvenance($user, $assignment),
                404,
            );
        }
    }

    private function canReleaseFromRetainedProvenance(User $user, DeviceAssignment $assignment): bool
    {
        if ($assignment->released_at !== null
            || ! $assignment->assigned_at?->lessThanOrEqualTo(now())
            || ! is_numeric($assignment->custody_site_id)
            || ! in_array((int) $assignment->custody_site_id, $this->devicesAccess->accessibleSiteIds($user), true)) {
            return false;
        }

        $targetType = (string) $assignment->assignable_type;
        $targetId = (int) $assignment->assignable_id;
        $custodySiteId = (int) $assignment->custody_site_id;
        $currentSiteId = app(DeviceCustodySiteResolver::class)->tryResolve($targetType, $targetId);
        if ($currentSiteId !== null && $currentSiteId !== $custodySiteId) {
            return false;
        }

        if ($targetType === DeviceAssignment::TARGET_CLIENT) {
            return $this->historicalClientReleaseDevices($user)
                ->whereKey((int) $assignment->device_id)
                ->exists();
        }

        $targetColumn = $targetType === DeviceAssignment::TARGET_STAFF
            ? 'primary_driver_user_id'
            : null;
        if ($targetColumn === null
            || ! $user->canDo('staff.viewAny')
            || ! $user->canDo('hazards.view')) {
            return false;
        }

        $matchingLinks = DeviceAssetLink::query()
            ->active()
            ->forDevice((int) $assignment->device_id)
            ->whereHas('asset', fn (Builder $asset): Builder => $asset
                ->where('site_id', $custodySiteId)
                ->where($targetColumn, $targetId))
            ->count();

        return $matchingLinks > 0
            && $matchingLinks === DeviceAssetLink::query()
                ->active()
                ->forDevice((int) $assignment->device_id)
                ->count();
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
            $observed = array_filter([
                'imei' => $qd->imei,
                'manufacturer' => 'Queclink',
                'model' => $qd->model_hint,
                'firmware_version' => $qd->firmware_version,
                'last_seen_at' => $qd->last_seen_at,
                'provider' => 'queclink',
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            return $this->fieldOwnership->applyProviderObservation(
                $existing,
                'queclink',
                $observed,
                $qd->last_seen_at,
                providerAttributes: [
                    'device_uid' => $existing->device_uid ?: $qd->imei,
                    'config' => $this->withNativeManagementCapability($existing->config ?? []),
                ],
            );
        }

        return $this->fieldOwnership->applyProviderObservation(
            new Device,
            'queclink',
            [
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
            ],
            $qd->last_seen_at,
            providerAttributes: [
                'device_uid' => $qd->imei,
                'config' => $this->withNativeManagementCapability([]),
            ],
        );
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function withNativeManagementCapability(array $config): array
    {
        $capabilities = data_get($config, 'management.capabilities', []);
        if (! is_array($capabilities)) {
            $capabilities = [];
        }
        if (array_is_list($capabilities)) {
            foreach (['tracking.location_refresh', 'configuration.refresh', 'configuration.apply', 'device.reboot'] as $capability) {
                if (! in_array($capability, $capabilities, true)) {
                    $capabilities[] = $capability;
                }
            }
        } else {
            $capabilities['tracking.location_refresh'] = true;
            $capabilities['configuration.refresh'] = true;
            $capabilities['configuration.apply'] = true;
            $capabilities['device.reboot'] = true;
        }
        data_set($config, 'management.capabilities', $capabilities);

        return $config;
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
