<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Queclink\BulkActionRequest;
use App\Http\Requests\Queclink\UpdateSectionRequest;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\Queclink\QueclinkAuditEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkPreset;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\User;
use App\Services\ConsentValidationService;
use App\Services\Queclink\CommandBuilder;
use App\Services\Queclink\ConfigurationSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 * The existing QueclinkController.php remains the home for IMS cloud
 * credentials; this controller owns everything to do with direct
 * device-to-server intake via the TCP listener.
 */
class QueclinkHubController extends Controller
{
    /** Canonical section keys a preset payload may contain. */
    private const PRESET_SECTIONS = [
        'server', 'tracking', 'pin', 'dog', 'time', 'non_movement',
        'power', 'wifi', 'geo', 'bluetooth', 'beacons', 'allowlist',
        'firmware_update', 'firmware_version',
    ];

    public function index(Request $request, ConfigurationSnapshotService $configurations)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $tenantId = $this->resolveTenantId($request->user());

        $port = (int) (AppSetting::query()->where('key', 'queclink.listener.port')->value('value')
            ?? config('services.queclink.port', 8090));
        $hostname = (string) (AppSetting::query()->where('key', 'queclink.public_hostname')->value('value')
            ?? config('services.queclink.public_hostname')
            ?? '');

        $serviceState = $this->systemdState();

        $devices = QueclinkDevice::query()
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (QueclinkDevice $d) => $this->serialiseDevice($d, $configurations))
            ->values();

        $pending = $devices->where('status', QueclinkDevice::STATUS_PENDING)->values();
        $paired = $devices->where('status', QueclinkDevice::STATUS_PAIRED)->values();
        $rejected = $devices->where('status', QueclinkDevice::STATUS_REJECTED)->values();

        $imsSecret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', 'queclink')
            ->first();

        return Inertia::render('security-devices/integrations/queclink-hub', [
            'listener' => [
                'port' => $port,
                'public_hostname' => $hostname,
                'service_state' => $serviceState,
                'connected_count' => QueclinkDevice::connected()->count(),
            ],
            'devices' => [
                'paired' => $paired,
                'pending' => $pending,
                'rejected' => $rejected,
                'total' => $devices->count(),
            ],
            'statistics' => [
                'frames_last_hour' => QueclinkRawFrame::query()->where('created_at', '>=', now()->subHour())->count(),
                'last_frame_at' => optional(QueclinkRawFrame::query()->max('created_at'))?->toDateTimeString(),
            ],
            'imsCloud' => $imsSecret ? [
                'status' => $imsSecret->status,
                'secret_last4' => $imsSecret->secret_last4,
                'last_tested_at' => $imsSecret->last_tested_at?->toDateTimeString(),
            ] : null,
            'targets' => [
                'vehicles' => Asset::query()
                    ->where(function ($q) {
                        $q->where('category', 'vehicle')
                            ->orWhereHas('categoryRef', fn ($qq) => $qq->where('slug', 'vehicle'));
                    })
                    ->limit(500)
                    ->orderBy('name')
                    ->get(['id', 'name', 'registration_number'])
                    ->map(fn (Asset $a) => [
                        'id' => $a->id,
                        'label' => trim($a->name.($a->registration_number ? " ({$a->registration_number})" : '')),
                    ])->values(),
                'staff' => User::query()
                    ->whereNotNull('approved_at')
                    ->orderBy('name')
                    ->limit(500)
                    ->get(['id', 'name', 'email'])
                    ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->name." <{$u->email}>"])
                    ->values(),
                'clients' => Client::query()
                    ->orderBy('first_name')
                    ->limit(500)
                    ->get(['id', 'first_name', 'last_name'])
                    ->map(fn (Client $c) => [
                        'id' => $c->id,
                        'label' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                    ])->values(),
            ],
            'presets' => QueclinkPreset::query()
                ->availableTo($tenantId)
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
        AppSetting::updateOrCreate(['key' => 'queclink.public_hostname'], ['value' => (string) ($validated['public_hostname'] ?? '')]);

        if (PHP_OS_FAMILY === 'Linux' && (int) $validated['port'] !== $previousPort) {
            try {
                Artisan::call('queclink:install', ['--port' => $validated['port']]);
            } catch (\Throwable $e) {
                return back()->with('warning', 'Settings saved, but the listener could not be restarted automatically: '.$e->getMessage());
            }
        }

        return back()->with('success', 'Listener settings saved.');
    }

    public function claimDevice(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        abort_unless($queclinkDevice->isPending(), 422, 'Device is not in the pending state.');

        $validated = $request->validate([
            'pairing_type' => ['required', 'in:vehicle,staff,client'],
            'target_id' => ['required', 'integer'],
            'consent_id' => ['nullable', 'integer', 'exists:client_consents,id'],
            'create_personal_tracker_asset' => ['nullable', 'boolean'],
        ]);

        return DB::transaction(function () use ($queclinkDevice, $validated, $request) {
            $tenantId = $this->resolveTenantId($request->user());
            $pairingType = $validated['pairing_type'];
            $targetId = (int) $validated['target_id'];
            $client = $pairingType === 'client'
                ? Client::query()->findOrFail($targetId)
                : null;
            $consentId = $client
                ? $this->resolveClientTrackingConsentId($client, $validated['consent_id'] ?? null)
                : null;

            $asset = match ($pairingType) {
                'vehicle' => $this->resolveVehicleAsset($targetId),
                'staff' => $this->ensurePersonalTrackerAsset(
                    type: 'staff',
                    targetId: $targetId,
                    tenantId: $tenantId,
                ),
                'client' => $this->ensurePersonalTrackerAsset(
                    type: 'client',
                    targetId: $targetId,
                    tenantId: $tenantId,
                ),
            };

            $device = $this->ensureCanonicalDevice($queclinkDevice, $tenantId, $pairingType);

            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $pairingType,
                'assignable_id' => $targetId,
                'assignment_type' => AssignmentType::Permanent->value,
                'assigned_at' => now(),
                'assigned_by_user_id' => $request->user()->id,
                'consent_id' => $consentId,
            ]);

            AssetTracker::updateOrCreate(
                [
                    'vendor' => 'queclink',
                    'device_uid' => $queclinkDevice->imei,
                ],
                [
                    'asset_id' => $asset->id,
                    'imei' => $queclinkDevice->imei,
                    'status' => 'paired',
                    'paired_at' => now(),
                    'consent_id' => $consentId,
                ],
            );

            $queclinkDevice->update([
                'status' => QueclinkDevice::STATUS_PAIRED,
                'pending_pairing_type' => null,
                'device_id' => $device->id,
                'tenant_id' => $tenantId,
            ]);

            $this->logAudit($request, $queclinkDevice, 'claim', null, null, [
                'pairing_type' => $pairingType,
                'target_id' => $targetId,
                'device_id' => $device->id,
                'consent_id' => $consentId,
            ]);

            return back()->with('success', "Device {$queclinkDevice->imei} paired.");
        });
    }

    private function resolveClientTrackingConsentId(Client $client, ?int $requestedConsentId): int
    {
        if ($requestedConsentId) {
            $consent = ClientConsent::query()
                ->where('client_id', $client->id)
                ->find($requestedConsentId);

            if ($this->isUsableConsent($consent)) {
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

    private function isUsableConsent(?ClientConsent $consent): bool
    {
        return $consent !== null
            && ! $consent->withdrawn_at
            && $consent->isValid();
    }

    public function rejectDevice(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $before = ['status' => $queclinkDevice->status];
        $queclinkDevice->update(['status' => QueclinkDevice::STATUS_REJECTED]);
        $this->logAudit($request, $queclinkDevice, 'release', null, $before, ['status' => QueclinkDevice::STATUS_REJECTED], null, 'Pending device rejected.');

        return back()->with('success', "Device {$queclinkDevice->imei} rejected.");
    }

    public function releaseDevice(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        abort_unless($queclinkDevice->isPaired(), 422);

        DB::transaction(function () use ($queclinkDevice, $request) {
            DeviceAssignment::query()
                ->where('device_id', $queclinkDevice->device_id)
                ->whereNull('released_at')
                ->update([
                    'released_at' => now(),
                    'released_by_user_id' => $request->user()->id,
                ]);

            AssetTracker::query()
                ->where('vendor', 'queclink')
                ->where('device_uid', $queclinkDevice->imei)
                ->update(['status' => 'unpaired', 'unpaired_at' => now()]);

            $queclinkDevice->update([
                'status' => QueclinkDevice::STATUS_PENDING,
                'device_id' => null,
            ]);

            $this->logAudit($request, $queclinkDevice, 'release', null, null, [
                'status' => QueclinkDevice::STATUS_PENDING,
                'device_id' => null,
            ]);
        });

        return back()->with('success', "Device {$queclinkDevice->imei} released — moved back to pending.");
    }

    public function sendCommand(Request $request, QueclinkDevice $queclinkDevice, CommandBuilder $builder)
    {
        abort_unless($this->userCanManage($request->user()), 403);
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
            'tenant_id' => $queclinkDevice->tenant_id,
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
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');
        abort_unless($this->presetVisibleTo($preset, $request->user()), 404);

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
     * Save a reusable preset for the operator's tenant. Each section is built
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

        $tenantId = $this->resolveTenantId($request->user());

        $preset = QueclinkPreset::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'slug' => $this->uniquePresetSlug($validated['name'], $tenantId),
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
        abort_if($preset->is_system, 422, 'Built-in presets cannot be deleted.');
        abort_unless(
            $preset->tenant_id !== null && (int) $preset->tenant_id === $this->resolveTenantId($request->user()),
            404,
        );

        $name = $preset->name;
        $preset->delete();

        return back()->with('success', "Preset \"{$name}\" deleted.");
    }

    public function updateSectionConfiguration(UpdateSectionRequest $request, QueclinkDevice $queclinkDevice, string $section, CommandBuilder $builder)
    {
        abort_unless($queclinkDevice->isPaired(), 422, 'Configuration can only be changed for paired devices.');
        abort_unless($this->guessFamily($queclinkDevice) === CommandBuilder::FAMILY_GL30M, 422, 'Only GL30 settings are supported in this first version.');

        return $this->queueSectionConfiguration($request, $queclinkDevice, $builder, $section, $request->validated());
    }

    public function cancelCommand(Request $request, QueclinkPendingCommand $command)
    {
        abort_unless($this->userCanManage($request->user()), 403);
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
        abort_unless(in_array($command->status, [
            QueclinkPendingCommand::STATUS_FAILED,
            QueclinkPendingCommand::STATUS_EXPIRED,
            QueclinkPendingCommand::STATUS_CANCELLED,
        ], true), 422, 'Only failed, expired, or cancelled commands can be retried.');

        $retry = QueclinkPendingCommand::create([
            'queclink_device_id' => $command->queclink_device_id,
            'imei' => $command->imei,
            'tenant_id' => $command->tenant_id,
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

        $devices = QueclinkDevice::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($devices->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'device_ids' => 'One or more selected devices could not be found.',
            ]);
        }

        $notPaired = $devices->first(fn (QueclinkDevice $device) => ! $device->isPaired());
        if ($notPaired) {
            throw ValidationException::withMessages([
                'device_ids' => 'Bulk actions can only be queued for paired devices.',
            ]);
        }

        $preset = null;
        if ($action === 'apply_preset') {
            $preset = QueclinkPreset::query()
                ->availableTo($this->resolveTenantId($request->user()))
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

        $query = QueclinkRawFrame::query()->orderByDesc('id')->limit(200);

        if ($request->filled('imei')) {
            $query->where('imei', $request->string('imei')->toString());
        }
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
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(function ($q) use ($search) {
                $q->where('raw_frame', 'like', $search)
                    ->orWhere('imei', 'like', $search)
                    ->orWhere('parse_error', 'like', $search);
            });
        }
        if ($request->filled('since_id')) {
            $query->where('id', '>', (int) $request->input('since_id'))->orderBy('id');
        }

        return response()->json([
            'frames' => $query->get()->map(fn (QueclinkRawFrame $f) => [
                'id' => $f->id,
                'imei' => $f->imei,
                'direction' => $f->direction,
                'frame_type' => $f->frame_type,
                'command_word' => $f->command_word,
                'raw_frame' => $f->raw_frame,
                'parse_ok' => $f->parse_ok,
                'parse_error' => $f->parse_error,
                'created_at' => $f->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Server-Sent Events stream of new frames. The debug console subscribes
     * here for real-time updates without needing Reverb.
     */
    public function stream(Request $request): StreamedResponse
    {
        abort_unless($this->userCanManage($request->user()), 403);

        $imei = $request->string('imei')->toString() ?: null;
        $direction = $request->string('direction')->toString() ?: null;
        $commandWord = $request->string('command_word')->toString() ?: null;
        $parseStatus = $request->string('parse_status')->toString() ?: null;
        $search = $request->string('search')->toString() ?: null;
        $cursor = (int) ($request->input('since_id') ?? QueclinkRawFrame::query()->max('id') ?? 0);

        return new StreamedResponse(function () use (&$cursor, $imei, $direction, $commandWord, $parseStatus, $search) {
            ignore_user_abort(false);
            @set_time_limit(0);
            echo "retry: 3000\n\n";
            @flush();

            $deadline = microtime(true) + 60; // 60s connection lifetime; client reconnects automatically

            while (microtime(true) < $deadline) {
                if (connection_aborted()) {
                    break;
                }

                $query = QueclinkRawFrame::query()
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(100);
                if ($imei !== null) {
                    $query->where('imei', $imei);
                }
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
                if ($search !== null) {
                    $like = '%'.$search.'%';
                    $query->where(function ($q) use ($like) {
                        $q->where('raw_frame', 'like', $like)
                            ->orWhere('imei', 'like', $like)
                            ->orWhere('parse_error', 'like', $like);
                    });
                }

                $rows = $query->get();
                foreach ($rows as $row) {
                    $payload = json_encode([
                        'id' => $row->id,
                        'imei' => $row->imei,
                        'direction' => $row->direction,
                        'frame_type' => $row->frame_type,
                        'command_word' => $row->command_word,
                        'raw_frame' => $row->raw_frame,
                        'parse_ok' => $row->parse_ok,
                        'parse_error' => $row->parse_error,
                        'created_at' => $row->created_at?->toIso8601String(),
                    ]);
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
        $port = (int) (AppSetting::query()->where('key', 'queclink.listener.port')->value('value') ?? 8090);
        $family = strtolower($request->string('family', 'gv500cg')->toString());

        if ($hostname === '') {
            return response()->json(['error' => 'Set the public hostname under Listener settings first.'], 422);
        }

        if ($family === CommandBuilder::FAMILY_GL30M) {
            // GL30MEUR01 @Track v2.04: ReportMode, ManualNetreg, BufferMode,
            // Main/Backup domains, heartbeat, SACK, SMS ACK, PSM hold, ASCII.
            $line = sprintf(
                'AT+GTSRI=gl30,3,0,1,%s,%d,%s,%d,,5,1,0,30,0,0,FFFF$',
                $hostname,
                $port,
                $hostname,
                $port,
            );
        } else {
            // GV500CG @Track v5.01: ReportMode, Reserved, BufferMode,
            // Main/Backup domains, heartbeat, SACK, protocol, DNS, serial.
            $line = sprintf(
                'AT+GTSRI=gv500cg,3,,1,%s,%d,%s,%d,,5,1,0,0,0,30,0.0.0.0,0.0.0.0,FFFF$',
                $hostname,
                $port,
                $hostname,
                $port,
            );
        }

        return response()->json([
            'config_string' => $line,
            'instructions' => [
                'Connect the device via the CH340G USB cable.',
                'Open the Queclink @Track MT Setup tool.',
                'Open a session with the COM port; click Read to confirm the device responds.',
                'Paste the configuration string below into the command box and click Send.',
                'Verify the device sends a +RESP:GTHBD frame to this server within 30 seconds.',
            ],
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function serialiseDevice(QueclinkDevice $d, ?ConfigurationSnapshotService $configurations = null): array
    {
        $assignment = $d->device_id
            ? DeviceAssignment::query()
                ->where('device_id', $d->device_id)
                ->whereNull('released_at')
                ->latest('assigned_at')
                ->first()
            : null;

        return [
            'id' => $d->id,
            'imei' => $d->imei,
            'status' => $d->status,
            'model_hint' => $d->model_hint,
            'protocol_version' => $d->protocol_version,
            'firmware_version' => $d->firmware_version,
            'connection_state' => $d->connection_state,
            'first_seen_at' => $d->first_seen_at?->toIso8601String(),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'last_frame_at' => $d->last_frame_at?->toIso8601String(),
            'remote_address' => $d->remote_address,
            'assignment' => $assignment ? [
                'type' => $assignment->assignable_type,
                'target_id' => $assignment->assignable_id,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'label' => $this->resolveAssignmentLabel($assignment),
            ] : null,
            'configuration' => $configurations?->latestForDevice($d),
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
            'payload' => $preset->sectionPayloads(),
            'created_at' => $preset->created_at?->toIso8601String(),
        ];
    }

    private function presetVisibleTo(QueclinkPreset $preset, ?User $user): bool
    {
        if ($preset->is_system) {
            return true;
        }

        return $preset->tenant_id !== null
            && (int) $preset->tenant_id === $this->resolveTenantId($user);
    }

    private function uniquePresetSlug(string $name, ?int $tenantId): string
    {
        $base = Str::slug($name) ?: 'preset';
        $slug = $base;
        $suffix = 2;

        while (QueclinkPreset::query()
            ->where('slug', $slug)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhere('is_system', true))
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
            'tenant_id' => $queclinkDevice->tenant_id,
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
    ): void {
        QueclinkAuditEvent::log([
            'tenant_id' => $queclinkDevice->tenant_id ?? $this->resolveTenantId($request->user()),
            'queclink_device_id' => $queclinkDevice->id,
            'imei' => $queclinkDevice->imei,
            'user_id' => $request->user()?->id,
            'event_type' => $eventType,
            'section' => $section,
            'payload_before' => $before,
            'payload_after' => $after,
            'raw_command' => $rawCommand,
            'notes' => $notes,
        ]);
    }

    private function recentCommands(QueclinkDevice $d): array
    {
        return QueclinkPendingCommand::query()
            ->recentFor($d, 12)
            ->get()
            ->map(fn (QueclinkPendingCommand $command) => [
                'id' => $command->id,
                'command_word' => $command->command_word,
                'raw_command' => $command->raw_command,
                'serial_number' => $command->serial_number,
                'status' => $command->status,
                'created_at' => $command->created_at?->toIso8601String(),
                'sent_at' => $command->sent_at?->toIso8601String(),
                'acked_at' => $command->acked_at?->toIso8601String(),
                'cancelled_at' => $command->cancelled_at?->toIso8601String(),
                'expires_at' => $command->expires_at?->toIso8601String(),
                'failed_reason' => $command->failed_reason,
                'ack_response' => $command->ack_response,
            ])
            ->values()
            ->all();
    }

    private function resolveAssignmentLabel(DeviceAssignment $assignment): string
    {
        $entity = $assignment->assignable();
        if (! $entity) {
            return "(unknown {$assignment->assignable_type} #{$assignment->assignable_id})";
        }

        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_VEHICLE => $entity->name.($entity->registration_number ? " ({$entity->registration_number})" : ''),
            DeviceAssignment::TARGET_STAFF => $entity->name,
            DeviceAssignment::TARGET_CLIENT => trim(($entity->first_name ?? '').' '.($entity->last_name ?? '')),
            default => (string) ($entity->name ?? $entity->id),
        };
    }

    private function resolveVehicleAsset(int $assetId): Asset
    {
        return Asset::query()->findOrFail($assetId);
    }

    private function ensurePersonalTrackerAsset(string $type, int $targetId, int $tenantId): Asset
    {
        $existing = Asset::query()
            ->where('category', 'personal_tracker')
            ->when($type === 'staff', fn ($q) => $q->where('primary_driver_user_id', $targetId))
            ->when($type === 'client', fn ($q) => $q->where('client_id', $targetId))
            ->first();

        if ($existing) {
            return $existing;
        }

        $name = match ($type) {
            'staff' => 'Personal tracker — '.(User::find($targetId)?->name ?? "user #{$targetId}"),
            'client' => 'Care tracker — '.trim(((Client::find($targetId)?->first_name) ?? '').' '.((Client::find($targetId)?->last_name) ?? '')),
        };

        return Asset::create([
            'name' => $name,
            'category' => 'personal_tracker',
            'status' => 'active',
            'primary_driver_user_id' => $type === 'staff' ? $targetId : null,
            'client_id' => $type === 'client' ? $targetId : null,
        ]);
    }

    private function ensureCanonicalDevice(QueclinkDevice $qd, int $tenantId, string $pairingType): Device
    {
        $existing = Device::query()
            ->where('provider', 'queclink')
            ->where(function ($q) use ($qd) {
                $q->where('imei', $qd->imei)
                    ->orWhere('device_uid', $qd->imei);
            })
            ->first();

        if ($existing) {
            $existing->fill([
                'imei' => $qd->imei,
                'device_uid' => $existing->device_uid ?: $qd->imei,
                'manufacturer' => $existing->manufacturer ?: 'Queclink',
                'model' => $existing->model ?: $qd->model_hint,
                'firmware_version' => $existing->firmware_version ?: $qd->firmware_version,
                'tenant_id' => $existing->tenant_id ?: $tenantId,
                'last_seen_at' => $qd->last_seen_at ?: $existing->last_seen_at,
            ])->save();

            return $existing;
        }

        return Device::create([
            'tenant_id' => $tenantId,
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
            return 'not_applicable';
        }
        $output = [];
        $code = 0;
        @exec('systemctl is-active oblivion-queclink.service 2>&1', $output, $code);

        return trim(implode(' ', $output)) ?: 'unknown';
    }

    private function userCanManage($user): bool
    {
        return $user && $user->canDo('securityDevices.integrations.manage');
    }

    private function resolveTenantId($user): int
    {
        return (int) ($user->tenant_id ?? $user->organization_id ?? 1);
    }
}
