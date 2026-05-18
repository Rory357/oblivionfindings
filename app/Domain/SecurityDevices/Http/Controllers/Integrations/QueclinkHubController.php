<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\User;
use App\Services\Queclink\CommandBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request)
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
            ->map(fn (QueclinkDevice $d) => $this->serialiseDevice($d))
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
                        'label' => trim($a->name . ($a->registration_number ? " ({$a->registration_number})" : '')),
                    ])->values(),
                'staff' => User::query()
                    ->whereNotNull('approved_at')
                    ->orderBy('name')
                    ->limit(500)
                    ->get(['id', 'name', 'email'])
                    ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->name . " <{$u->email}>"])
                    ->values(),
                'clients' => Client::query()
                    ->orderBy('first_name')
                    ->limit(500)
                    ->get(['id', 'first_name', 'last_name'])
                    ->map(fn (Client $c) => [
                        'id' => $c->id,
                        'label' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                    ])->values(),
            ],
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
                return back()->with('warning', 'Settings saved, but the listener could not be restarted automatically: ' . $e->getMessage());
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
            'consent_id' => ['nullable', 'integer'],
            'create_personal_tracker_asset' => ['nullable', 'boolean'],
        ]);

        return DB::transaction(function () use ($queclinkDevice, $validated, $request) {
            $tenantId = $this->resolveTenantId($request->user());

            $asset = match ($validated['pairing_type']) {
                'vehicle' => $this->resolveVehicleAsset((int) $validated['target_id']),
                'staff' => $this->ensurePersonalTrackerAsset(
                    type: 'staff',
                    targetId: (int) $validated['target_id'],
                    tenantId: $tenantId,
                ),
                'client' => $this->ensurePersonalTrackerAsset(
                    type: 'client',
                    targetId: (int) $validated['target_id'],
                    tenantId: $tenantId,
                ),
            };

            $device = $this->ensureCanonicalDevice($queclinkDevice, $tenantId, $validated['pairing_type']);

            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $validated['pairing_type'],
                'assignable_id' => (int) $validated['target_id'],
                'assignment_type' => \App\Domain\SecurityDevices\Enums\AssignmentType::Permanent->value,
                'assigned_at' => now(),
                'assigned_by_user_id' => $request->user()->id,
                'consent_id' => $validated['pairing_type'] === 'client' ? ($validated['consent_id'] ?? null) : null,
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
                    'consent_id' => $validated['pairing_type'] === 'client' ? ($validated['consent_id'] ?? null) : null,
                ],
            );

            $queclinkDevice->update([
                'status' => QueclinkDevice::STATUS_PAIRED,
                'pending_pairing_type' => null,
                'device_id' => $device->id,
                'tenant_id' => $tenantId,
            ]);

            return back()->with('success', "Device {$queclinkDevice->imei} paired.");
        });
    }

    public function rejectDevice(Request $request, QueclinkDevice $queclinkDevice)
    {
        abort_unless($this->userCanManage($request->user()), 403);
        $queclinkDevice->update(['status' => QueclinkDevice::STATUS_REJECTED]);
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
        $cursor = (int) ($request->input('since_id') ?? QueclinkRawFrame::query()->max('id') ?? 0);

        return new StreamedResponse(function () use (&$cursor, $imei) {
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

    private function serialiseDevice(QueclinkDevice $d): array
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
        ];
    }

    private function resolveAssignmentLabel(DeviceAssignment $assignment): string
    {
        $entity = $assignment->assignable();
        if (! $entity) {
            return "(unknown {$assignment->assignable_type} #{$assignment->assignable_id})";
        }
        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_VEHICLE => $entity->name . ($entity->registration_number ? " ({$entity->registration_number})" : ''),
            DeviceAssignment::TARGET_STAFF => $entity->name,
            DeviceAssignment::TARGET_CLIENT => trim(($entity->first_name ?? '') . ' ' . ($entity->last_name ?? '')),
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
            'staff' => 'Personal tracker — ' . (User::find($targetId)?->name ?? "user #{$targetId}"),
            'client' => 'Care tracker — ' . trim(((Client::find($targetId)?->first_name) ?? '') . ' ' . ((Client::find($targetId)?->last_name) ?? '')),
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
