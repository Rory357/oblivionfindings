<?php

namespace App\Domain\SecurityDevices\Access;

use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * S&D Phase H (pop-up 86): a Site goes into lockdown or evacuation, or comes out of it, whether
 * Oblivion asked (a governed command the hub confirmed) or someone did it in UniFi Access itself.
 * Control Room is told (a device event on the console, which Control Room's own pipeline picks up
 * straight away), the staff on shift get a critical alert, and it's audited. Saying it twice is
 * harmless: only a real change does anything.
 */
final class AccessEmergency
{
    public const array MODES = ['normal', 'lockdown', 'evacuation'];

    /** Governed commands whose confirmed result changes the Site's emergency mode. */
    public const array CAPABILITY_MODES = [
        'access.site.lockdown' => 'lockdown',
        'access.site.evacuate' => 'evacuation',
        'access.site.emergency_end' => 'normal',
    ];

    public function __construct(
        private readonly AccessConsoles $consoles,
        private readonly DeviceAlertEngine $alerts,
    ) {}

    /** @param 'oblivion'|'unifi' $source */
    public function changed(AccessConsole $console, string $mode, string $source, ?CarbonImmutable $at = null): void
    {
        if (! in_array($mode, self::MODES, true)) {
            return;
        }
        $at ??= CarbonImmutable::now('UTC');
        $from = DB::transaction(function () use ($console, $mode): ?string {
            $locked = AccessConsole::query()->whereKey($console->id)->lockForUpdate()->first();
            if (! $locked instanceof AccessConsole || $locked->emergency_mode === $mode) {
                return null;
            }
            $from = (string) $locked->emergency_mode;
            $locked->forceFill(['emergency_mode' => $mode])->save();

            return $from;
        });
        if ($from === null) {
            return;
        }
        try {
            $device = $this->consoles->device($console->fresh());
            $doors = AccessDoor::query()->where('console_id', $console->id)->count();
            DeviceEvent::query()->create([
                'device_id' => $device->id,
                'event_type' => 'access_emergency',
                'severity' => $mode === 'normal' ? 'info' : 'critical',
                'source' => 'unifi_access',
                'occurred_at' => $at,
                'payload' => [
                    'site_id' => (int) $console->site_id,
                    'mode' => $mode,
                    'was' => $from,
                    'source' => $source,
                    'doors' => $doors,
                ],
            ]);
            if ($mode === 'normal') {
                $this->alerts->closeEmergency($device, $at);
            } else {
                $this->alerts->openEmergency($device, $mode, $at,
                    $mode === 'evacuation'
                        ? "Every door is unlocked so everyone can get out ({$doors} doors). Open the roll call on the Access page."
                        : "Cards, PINs and phones no longer open the doors from outside ({$doors} doors). People inside can always leave by the exit button and handle.",
                    $source === 'unifi' ? 'Set in UniFi Access, not from Oblivion.' : null);
            }
            AuditLogger::logOrFail('security_devices.access.emergency_'.$mode, $console, [
                'site_id' => (int) $console->site_id,
                'was' => $from,
                'source' => $source,
            ]);
        } catch (Throwable $exception) {
            // The mode is recorded; telling people must never undo it.
            Log::error('Site emergency could not be announced', ['console_id' => $console->id, 'mode' => $mode, 'error' => $exception->getMessage()]);
        }
    }
}
