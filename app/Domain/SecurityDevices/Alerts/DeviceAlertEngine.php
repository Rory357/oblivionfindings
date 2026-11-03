<?php

namespace App\Domain\SecurityDevices\Alerts;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\EffectiveMonitoringPolicyResolver;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Setup\Models\SecurityDeviceSetting;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use App\Notifications\SecurityDeviceMorningSummaryNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Opens, refreshes and closes device alerts from the checks' effective states (S&D Phase D),
 * and records who did what on them.
 *
 * - A check going Failed opens a critical alert, Degraded a warning one; one alert per device.
 * - A device behind a failed upstream device is Suppressed by the monitoring engine and gets no
 *   alert of its own (cameras, alarms and health kit are never suppressed there).
 * - When every check on the device passes again, the alert closes by itself.
 * - A hub that stops answering opens an alert on the hub's device.
 */
final class DeviceAlertEngine
{
    public const int REMIND_AFTER_MINUTES = 60;

    /** Alerts about a device answering at all; closed by Oblivion when it's back. */
    public const array AVAILABILITY_KINDS = ['offline', 'failing', 'hub_offline'];

    /** IT alerts that tell the person on call first (ItOnCall); the rest of IT hears at the reminder. */
    public const array ON_CALL_KINDS = ['offline', 'failing', 'hub_offline', 'mqtt_logins'];

    /** Device, hub and battery alerts whose notices wait for the 6:00 am summary during quiet hours. */
    public const array QUIET_HOURS_KINDS = ['offline', 'failing', 'hub_offline', 'mqtt_logins', 'battery'];

    /** NZ date ("YYYY-MM-DD") whose 6:00 am summary has already gone out. */
    public const string SUMMARY_SENT_DAY = 'alerts.summary.sent_day';

    /** Today's 6:00 am summary items by Site, for Alerts › On call (pg-sdr-13d). */
    public const string SUMMARY_LAST = 'alerts.summary.last';

    /**
     * Safety events from the device itself (S&D Phase E): event type → [kind, severity, words].
     * They stay open until a person marks them done; Control Room gets them straight away too,
     * through its own signal pipeline.
     */
    public const array SAFETY_EVENTS = [
        'panic' => ['panic', 'critical', 'panic button pressed'],
        'alarm_triggered' => ['alarm', 'critical', 'alarm going off'],
        'alarm_trigger' => ['alarm', 'critical', 'alarm going off'],
        'water_leak_detected' => ['leak', 'critical', 'water leak'],
        'door_forced' => ['forced', 'critical', 'door forced open'],
        'tamper' => ['tamper', 'warning', 'tampered with'],
    ];

    public function __construct(private readonly EffectiveMonitoringPolicyResolver $policies) {}

    /** A safety event arrived from a device (panic button, alarm, leak, forced door, tamper). */
    public function onSafetyEvent(Device $device, string $eventType, CarbonImmutable $at): void
    {
        try {
            $safety = self::SAFETY_EVENTS[$eventType] ?? null;
            if ($safety === null) {
                return;
            }
            [$kind, $severity, $words] = $safety;
            $siteId = $this->siteId($device);
            $people = $siteId !== null ? app(StaffOnShift::class)->toTell($siteId) : collect();
            $settings = app(SecurityDevicesSettings::class);
            // Safety alerts always show in the app; email and push as Setup says.
            $channels = ['database', ...array_values(array_filter([
                $settings->bool(SecurityDevicesSettings::SAFETY_EMAIL) ? 'mail' : null,
                $settings->bool(SecurityDevicesSettings::SAFETY_PUSH) ? 'push' : null,
            ]))];
            $told = $people->isNotEmpty()
                ? ['names' => $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => $channels]
                : null;
            $created = $this->open($device, null, $kind, $severity, (string) $device->name.': '.$words, $at, ucfirst($words), 'Control Room has it too', $told);
            if ($created !== null && $told !== null) {
                $this->notify($people, $created, ucfirst($words).' · open the alert to say you’re on it', $channels);
            }
        } catch (Throwable $exception) {
            Log::error('Safety alert could not be opened', ['device_id' => $device->id, 'event_type' => $eventType, 'error' => $exception->getMessage()]);
        }
    }

    /** Wrong logins at a hub's sensor relay (5 in 10 minutes), on the hub's device; IT is told. */
    public function onRelayLoginFailures(MonitoringCollector $hub, string $address, int $count, CarbonImmutable $at): void
    {
        try {
            $device = $hub->collector_device_id ? Device::query()->find($hub->collector_device_id) : null;
            if (! $device instanceof Device) {
                return;
            }
            $settings = app(SecurityDevicesSettings::class);
            $held = $settings->isQuietHours($at);
            $people = ! $held && (int) $hub->site_id > 0
                ? app(ItOnCall::class)->first($this->policies->resolveItRecipients((int) $hub->site_id, $settings->role(SecurityDevicesSettings::DEVICES_ROLE)), $at)
                : collect();
            $channels = $held ? [] : $settings->channels(SecurityDevicesSettings::DEVICES_APP, SecurityDevicesSettings::DEVICES_EMAIL, SecurityDevicesSettings::DEVICES_PUSH);
            $told = $people->isNotEmpty() && $channels !== []
                ? ['names' => $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => $channels]
                : null;
            $label = CoveringHubResolver::label($hub);
            $created = $this->open($device, null, 'mqtt_logins', 'warning', "{$label}: {$count} wrong sensor logins from {$address}", $at,
                "{$count} wrong logins in 10 minutes from {$address}", 'The hub ignores that address for 10 minutes. Check the gateway’s login, or who else is on the network.', $told, $held ? 'Quiet hours' : null);
            if ($created !== null && $told !== null) {
                $this->notify($people, $created, "{$count} wrong sensor logins in 10 minutes", $channels);
            }
        } catch (Throwable $exception) {
            Log::error('Relay login alert could not be opened', ['collector_id' => $hub->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * A hub's daily public-port check (D21): Oblivion's public address must refuse MQTT. An answer
     * opens a critical alert on the hub for IT; the next clean check closes it.
     *
     * @param  list<string>  $answered  ports that answered (1883, 8883)
     */
    public function onBrokerExposure(MonitoringCollector $hub, array $answered, CarbonImmutable $at): void
    {
        try {
            $device = $hub->collector_device_id ? Device::query()->find($hub->collector_device_id) : null;
            if (! $device instanceof Device) {
                return;
            }
            if ($answered === []) {
                $this->closeKind($device, 'mqtt_exposed', $at, fn (): string => 'Oblivion’s public address refuses MQTT again');

                return;
            }
            $settings = app(SecurityDevicesSettings::class);
            $people = (int) $hub->site_id > 0
                ? $this->policies->resolveItRecipients((int) $hub->site_id, $settings->role(SecurityDevicesSettings::DEVICES_ROLE))
                : collect();
            // Always at least in the app: sensor readings must never be reachable from the internet.
            $channels = array_values(array_unique(['database', ...$settings->channels(SecurityDevicesSettings::DEVICES_APP, SecurityDevicesSettings::DEVICES_EMAIL, SecurityDevicesSettings::DEVICES_PUSH)]));
            $told = $people->isNotEmpty() ? ['names' => $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => $channels] : null;
            $ports = implode(' and ', $answered);
            $created = $this->open($device, null, 'mqtt_exposed', 'critical', "Oblivion’s public address answers on port {$ports}", $at,
                "Port {$ports} answered from ".CoveringHubResolver::label($hub), 'Sensor readings must never be reachable from the internet. Close the port on the server’s firewall.', $told);
            if ($created !== null && $told !== null) {
                $this->notify($people, $created, "Oblivion’s public address answers on port {$ports}", $channels);
            }
        } catch (Throwable $exception) {
            Log::error('Broker exposure alert could not be updated', ['collector_id' => $hub->id, 'error' => $exception->getMessage()]);
        }
    }

    public const int BATTERY_LOW = 20;

    /** A low-battery alert closes once the battery is back at or above this (a new battery). */
    public const int BATTERY_OK = 30;

    /**
     * A sensor reading changed state (S&D Phase E): out of range opens an alert for that reading,
     * back in range closes it. Staff on shift at the house are told in the app.
     */
    public function onReading(Device $device, string $metric, string $label, string $state, float $value, string $unit, CarbonImmutable $at): void
    {
        try {
            $kind = mb_substr('reading_'.preg_replace('/[^a-z0-9_]/', '', strtolower($metric)), 0, 32);
            $reading = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').($unit !== '' ? ' '.self::unitWords($unit) : '');
            if (in_array($state, ['warning', 'critical'], true)) {
                // "Temperature" becomes "temperature", but "CO2" stays "CO2".
                $wording = preg_match('/\A\p{Lu}\p{Ll}/u', $label) === 1 ? lcfirst($label) : $label;
                $this->openForHouse($device, $kind, $state, (string) $device->name.': '.$wording.' '.$reading, $at, ucfirst($label).' '.$reading, 'Out of the usual range');

                return;
            }
            $this->closeKind($device, $kind, $at, fn (DeviceAlert $alert): string => ucfirst($label).' back to '.$reading.' after '.self::duration($alert->opened_at, $at));
        } catch (Throwable $exception) {
            Log::error('Reading alert could not be updated', ['device_id' => $device->id, 'metric' => $metric, 'error' => $exception->getMessage()]);
        }
    }

    /** A battery level arrived: below 20% opens a warning (its notice goes in the 6:00 am summary), a new battery (30% or more) closes it. */
    public function onBattery(Device $device, int $percent, CarbonImmutable $at): void
    {
        try {
            if ($percent < self::BATTERY_LOW) {
                $this->open($device, null, 'battery', 'warning', (string) $device->name.": battery {$percent}%", $at, "Battery {$percent}%", 'Change it soon so it keeps working', null, '6:00 am summary');

                return;
            }
            if ($percent >= self::BATTERY_OK) {
                $this->closeKind($device, 'battery', $at, fn (): string => "Battery back to {$percent}%");
            }
        } catch (Throwable $exception) {
            Log::error('Battery alert could not be updated', ['device_id' => $device->id, 'error' => $exception->getMessage()]);
        }
    }

    /** S&D Phase F: care-rule alerts (a linked person's plan) and scald risk from a hot-water probe. */
    public const array CARE_KINDS = ['care_out_of_bed', 'care_door_night', 'scald_risk'];

    /**
     * S&D Phase F: open (or refresh) a care or scald alert for the staff on shift at the house.
     * Returns the new alert, or null when one was already open.
     */
    public function openCare(Device $device, string $kind, string $title, CarbonImmutable $at, string $what, ?string $detail): ?DeviceAlert
    {
        if (! in_array($kind, self::CARE_KINDS, true)) {
            throw new \InvalidArgumentException('Not a care alert kind.');
        }
        try {
            return $this->openForHouse($device, $kind, 'critical', $title, $at, $what, $detail, ['database', 'push']);
        } catch (Throwable $exception) {
            Log::error('Care alert could not be opened', ['device_id' => $device->id, 'kind' => $kind, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /** S&D Phase F: what a house mode's check found: a door not locked, or an alarm area not armed. */
    public const array MODE_CHECK_KINDS = ['mode_door_check', 'mode_alarm_check'];

    /**
     * S&D Phase F: open (or refresh) an alert for what a house mode's check found, for the staff on
     * shift at the house (in the app). A person marks it done once they've locked or armed by hand.
     * Returns the new alert, or null when one was already open.
     */
    public function openModeCheck(Device $device, string $kind, string $title, CarbonImmutable $at, string $what, ?string $detail): ?DeviceAlert
    {
        if (! in_array($kind, self::MODE_CHECK_KINDS, true)) {
            throw new \InvalidArgumentException('Not a house mode check kind.');
        }
        try {
            return $this->openForHouse($device, $kind, 'warning', $title, $at, $what, $detail);
        } catch (Throwable $exception) {
            Log::error('House mode check alert could not be opened', ['device_id' => $device->id, 'kind' => $kind, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /** S&D Phase F: an automation's "Open an alert", on the device that started it. */
    public const array AUTOMATION_KINDS = ['automation'];

    /**
     * S&D Phase F: open (or refresh) an automation's alert for the staff on shift at the house (in
     * the app). A person marks it done. Returns the new alert, or null when one was already open;
     * a failure is thrown so the automation's run log can say it didn't work.
     */
    public function openAutomation(Device $device, string $title, CarbonImmutable $at, string $what, ?string $detail): ?DeviceAlert
    {
        return $this->openForHouse($device, 'automation', 'warning', $title, $at, $what, $detail);
    }

    /** @param  callable(DeviceAlert): string  $words */
    /** S&D Phase H: a Site in lockdown or evacuating (UniFi Access), on the console's device. */
    public const array EMERGENCY_KINDS = ['access_lockdown', 'access_evacuation'];

    /**
     * S&D Phase H: open the Site's lockdown or evacuation alert for the staff on shift (in the app
     * and by push). It closes by itself when the emergency ends.
     */
    public function openEmergency(Device $console, string $mode, CarbonImmutable $at, string $what, ?string $detail): ?DeviceAlert
    {
        $kind = 'access_'.$mode;
        if (! in_array($kind, self::EMERGENCY_KINDS, true)) {
            throw new \InvalidArgumentException('Not an emergency kind.');
        }
        try {
            // One emergency at a time: the other kind closes first.
            foreach (array_diff(self::EMERGENCY_KINDS, [$kind]) as $other) {
                $this->closeKind($console, $other, $at, fn (): string => 'Changed to '.($mode === 'evacuation' ? 'evacuation' : 'lockdown').'.');
            }

            return $this->openForHouse($console, $kind, 'critical', $mode === 'evacuation' ? 'Evacuating' : 'Lockdown', $at, $what, $detail, ['database', 'push']);
        } catch (Throwable $exception) {
            Log::error('Emergency alert could not be opened', ['device_id' => $console->id, 'mode' => $mode, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /** S&D Phase H: the emergency ended; its alert closes. */
    public function closeEmergency(Device $console, CarbonImmutable $at): void
    {
        foreach (self::EMERGENCY_KINDS as $kind) {
            $this->closeKind($console, $kind, $at, fn (): string => 'Ended: the doors are back to their usual schedules.');
        }
    }

    public function closeCare(Device $device, string $kind, CarbonImmutable $at, callable $words): void
    {
        if (in_array($kind, self::CARE_KINDS, true)) {
            $this->closeKind($device, $kind, $at, $words);
        }
    }

    /**
     * Open (or refresh) an alert the staff on shift at the device's house hear about in the app.
     *
     * @param  list<string>  $channels
     */
    private function openForHouse(Device $device, string $kind, string $severity, string $title, CarbonImmutable $at, string $what, ?string $detail, array $channels = ['database']): ?DeviceAlert
    {
        $siteId = $this->siteId($device);
        $people = $siteId !== null ? app(StaffOnShift::class)->toTell($siteId) : collect();
        $told = $people->isNotEmpty() ? ['names' => $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => $channels] : null;
        $created = $this->open($device, null, $kind, $severity, $title, $at, $what, $detail, $told);
        if ($created !== null && $told !== null) {
            $this->notify($people, $created, $what, $channels);
        }

        return $created;
    }

    /** @param  callable(DeviceAlert): string  $words */
    private function closeKind(Device $device, string $kind, CarbonImmutable $at, callable $words): void
    {
        DB::transaction(function () use ($device, $kind, $at, $words): void {
            $alert = DeviceAlert::query()->where('device_id', $device->id)->active()->where('kind', $kind)->lockForUpdate()->latest('id')->first();
            if ($alert === null) {
                return;
            }
            $alert->forceFill(['state' => DeviceAlert::STATE_DONE, 'closed_at' => $at, 'close_reason' => 'recovered', 'snoozed_until' => null, 'remind_at' => null])->save();
            $this->event($alert, $at, 'recovered', $words($alert));
        }, 3);
    }

    private static function unitWords(string $unit): string
    {
        return match ($unit) {
            'C' => '°C', 'ug/m3' => 'µg/m³', default => $unit,
        };
    }

    /** A person says a safety alert is dealt with (safety alerts don't clear by themselves). */
    public function done(DeviceAlert $alert, User $actor, ?string $note): DeviceAlert
    {
        return DB::transaction(function () use ($alert, $actor, $note): DeviceAlert {
            $locked = DeviceAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isActive(), 409, 'This alert is already done.');
            abort_unless(in_array($locked->kind, self::safetyKinds(), true), 409, 'This alert closes by itself when the device is back to normal.');
            $now = CarbonImmutable::now('UTC');
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $locked->forceFill(['state' => DeviceAlert::STATE_DONE, 'closed_at' => $now, 'close_reason' => 'done', 'snoozed_until' => null, 'remind_at' => null])->save();
            $this->event($locked, $now, 'recovered', "{$actor->name} marked it done", $note, $actor);
            AuditLogger::logOrFail('security_devices.alert.done', $locked, ['actor_id' => (int) $actor->id, 'site_id' => $locked->site_id]);

            return $locked;
        }, 3);
    }

    /**
     * A check's effective state changed.
     *
     * @param  array{names: list<string>, channels: list<string>}|null  $told  who the check's alert route told
     */
    public function onCheck(Monitor $monitor, MonitorState $from, MonitorState $to, ?int $rootCauseMonitorId, CarbonImmutable $at, ?array $told = null): void
    {
        try {
            $monitor->loadMissing('device');
            $device = $monitor->device;
            if (! $device instanceof Device) {
                return;
            }

            match (true) {
                in_array($to, [MonitorState::Failed, MonitorState::Degraded], true) => $this->failing($device, $monitor, $to, $at, $told),
                $to === MonitorState::Suppressed && $rootCauseMonitorId !== null => $this->behind($device, $rootCauseMonitorId, $at),
                $to === MonitorState::Healthy && $from !== MonitorState::Healthy => $this->maybeRecovered($device, $at),
                default => null,
            };
            // S&D Phase F: automations that start when a device goes offline or comes back (never throws).
            app(\App\Domain\SecurityDevices\Automations\AutomationEngine::class)->onCheck($device, $monitor, $from, $to, $at);
        } catch (Throwable $exception) {
            // An alert must never stop a check result from being stored.
            Log::error('Device alert could not be updated', ['monitor_id' => $monitor->id, 'error' => $exception->getMessage()]);
        }
    }

    /** A hub stopped answering (true) or is answering again (false). */
    public function onHub(MonitoringCollector $hub, bool $offline, CarbonImmutable $at): void
    {
        try {
            $device = $hub->collector_device_id ? Device::query()->find($hub->collector_device_id) : null;
            if (! $device instanceof Device) {
                return;
            }
            $label = CoveringHubResolver::label($hub);
            if ($offline) {
                // Told as Setup › Alerts & checks says for "a device or hub stops answering" (or held in quiet hours).
                $settings = app(SecurityDevicesSettings::class);
                $held = $settings->isQuietHours($at);
                $people = ! $held && (int) $hub->site_id > 0
                    ? app(ItOnCall::class)->first($this->policies->resolveItRecipients((int) $hub->site_id, $settings->role(SecurityDevicesSettings::DEVICES_ROLE)), $at)
                    : collect();
                $channels = $held ? [] : $settings->channels(SecurityDevicesSettings::DEVICES_APP, SecurityDevicesSettings::DEVICES_EMAIL, SecurityDevicesSettings::DEVICES_PUSH);
                $told = $people->isNotEmpty() && $channels !== []
                    ? ['names' => $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => $channels]
                    : null;
                $created = $this->open($device, null, 'hub_offline', 'critical', "{$label} has stopped answering", $at,
                    'Stopped answering', 'Devices at its Sites show “Can’t check” until it’s back', $told, $held ? 'Quiet hours' : null);
                if ($created !== null && $told !== null) {
                    $this->notify($people, $created, 'Devices at its Sites can’t be checked until it’s back', $channels);
                }

                return;
            }
            $this->close($device, $at, fn (DeviceAlert $alert): string => 'Answering again after '.self::duration($alert->opened_at, $at));
        } catch (Throwable $exception) {
            Log::error('Hub alert could not be updated', ['collector_id' => $hub->id, 'error' => $exception->getMessage()]);
        }
    }

    /** @param  array{names: list<string>, channels: list<string>}|null  $told */
    private function failing(Device $device, Monitor $monitor, MonitorState $to, CarbonImmutable $at, ?array $told): void
    {
        $critical = $to === MonitorState::Failed;
        $offline = $critical && self::isReachability($monitor);
        $name = (string) $device->name;
        $check = (string) $monitor->name;
        $kind = $offline ? 'offline' : 'failing';
        $title = $offline ? "{$name} is offline" : ($critical ? "{$name}: {$check} isn’t passing" : "{$name}: {$check} needs attention");
        $confirmations = max(1, (int) ($monitor->profile?->failure_confirmations ?? 1));
        $detail = $confirmations > 1 ? "{$confirmations} missed checks in a row" : null;
        $held = $told === null && app(SecurityDevicesSettings::class)->isQuietHours($at);

        $this->open($device, $monitor, $kind, $critical ? 'critical' : 'warning', $title, $at,
            $offline ? 'Stopped answering' : "{$check} started failing", $detail, $told, $held ? 'Quiet hours' : null);
    }

    /**
     * Open an alert, or make an open one worse. One active alert per device. Returns the alert
     * when this call opened it, null when one was already open.
     *
     * @param  array{names: list<string>, channels: list<string>}|null  $told
     */
    private function open(Device $device, ?Monitor $monitor, string $kind, string $severity, string $title, CarbonImmutable $at, string $what, ?string $detail, ?array $told, ?string $heldReason = null): ?DeviceAlert
    {
        return DB::transaction(function () use ($device, $monitor, $kind, $severity, $title, $at, $what, $detail, $told, $heldReason): ?DeviceAlert {
            Device::query()->whereKey($device->id)->lockForUpdate()->first();
            $alert = DeviceAlert::query()->where('device_id', $device->id)->active()->whereIn('kind', self::family($kind))->lockForUpdate()->latest('id')->first();

            if ($alert === null) {
                $alert = DeviceAlert::query()->create([
                    'device_id' => $device->id,
                    'site_id' => $this->siteId($device),
                    'kind' => $kind,
                    'severity' => $severity,
                    'title' => mb_substr($title, 0, 191),
                    'state' => DeviceAlert::STATE_OPEN,
                    'monitor_id' => $monitor?->id,
                    'opened_at' => $at,
                    'last_failing_at' => $at,
                ]);
                $this->event($alert, $at, 'opened', $what, $detail);
                if ($heldReason !== null && $told === null) {
                    $this->event($alert, $at, 'held', 'Held for the morning summary', $heldReason);
                } else {
                    $this->toldEvent($alert, $at, $told);
                }

                return $alert;
            }

            $worse = $alert->severity === 'warning' && $severity === 'critical';
            $alert->last_failing_at = $at;
            if (in_array($kind, self::safetyKinds(), true)) {
                // The same event again (a second press): one line on the timeline, no new alert.
                $this->event($alert, $at, 'worse', $what.' again');
            }
            if ($worse) {
                $alert->forceFill(['severity' => 'critical', 'kind' => $kind, 'title' => mb_substr($title, 0, 191), 'monitor_id' => $monitor?->id ?? $alert->monitor_id]);
                $this->event($alert, $at, 'worse', $what, $detail);
                if ($alert->state === DeviceAlert::STATE_SNOOZED) {
                    $alert->forceFill(['state' => DeviceAlert::STATE_OPEN, 'snoozed_until' => null]);
                    $this->event($alert, $at, 'back', 'Back from snooze because it got worse');
                }
                if ($heldReason !== null && $told === null) {
                    if (! $alert->events()->where('kind', 'held')->exists()) {
                        $this->event($alert, $at, 'held', 'Held for the morning summary', $heldReason);
                    }
                } else {
                    $this->toldEvent($alert, $at, $told);
                }
            }
            $alert->save();

            return null;
        }, 3);
    }

    /** The device is behind a failed upstream device now: its own alert folds into that one. */
    private function behind(Device $device, int $rootCauseMonitorId, CarbonImmutable $at): void
    {
        $root = Monitor::query()->with('device:id,name')->find($rootCauseMonitorId);
        $rootName = (string) ($root?->device?->name ?? 'the device in front of it');
        $this->close($device, $at, fn (): string => "Now behind {$rootName}, which is down: covered by that alert", 'behind');
    }

    private function maybeRecovered(Device $device, CarbonImmutable $at): void
    {
        $stillFailing = Monitor::query()
            ->where('device_id', $device->id)
            ->where('is_enabled', true)
            ->whereIn('effective_state', [MonitorState::Failed->value, MonitorState::Degraded->value])
            ->exists();
        if ($stillFailing) {
            return;
        }
        $this->close($device, $at, fn (DeviceAlert $alert): string => ($alert->kind === 'offline' ? 'Back online after ' : 'Passing again after ').self::duration($alert->opened_at, $at));
    }

    /** @param  callable(DeviceAlert): string  $words */
    private function close(Device $device, CarbonImmutable $at, callable $words, string $reason = 'recovered'): void
    {
        DB::transaction(function () use ($device, $at, $words, $reason): void {
            $alert = DeviceAlert::query()->where('device_id', $device->id)->active()->whereIn('kind', self::AVAILABILITY_KINDS)->lockForUpdate()->latest('id')->first();
            if ($alert === null) {
                return;
            }
            $alert->forceFill(['state' => DeviceAlert::STATE_DONE, 'closed_at' => $at, 'close_reason' => $reason, 'snoozed_until' => null, 'remind_at' => null])->save();
            $this->event($alert, $at, 'recovered', $words($alert));
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /*  What people do                                                     */
    /* ------------------------------------------------------------------ */

    public function acknowledge(DeviceAlert $alert, User $actor, ?string $note, bool $remind): DeviceAlert
    {
        return DB::transaction(function () use ($alert, $actor, $note, $remind): DeviceAlert {
            $locked = DeviceAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isActive(), 409, 'This alert is already done.');
            $now = CarbonImmutable::now('UTC');
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $locked->forceFill([
                'acknowledged_at' => $now,
                'acknowledged_by_user_id' => $actor->id,
                'acknowledgement_note' => $note,
                'remind_at' => $remind ? $now->addMinutes(self::REMIND_AFTER_MINUTES) : null,
            ])->save();
            $this->event($locked, $now, 'acknowledged', "{$actor->name} has it", $note, $actor);
            AuditLogger::logOrFail('security_devices.alert.acknowledged', $locked, ['actor_id' => (int) $actor->id, 'site_id' => $locked->site_id, 'remind' => $remind]);

            return $locked;
        }, 3);
    }

    public function assign(DeviceAlert $alert, User $actor, User $assignee, ?string $note): DeviceAlert
    {
        $locked = DB::transaction(function () use ($alert, $actor, $assignee, $note): DeviceAlert {
            $locked = DeviceAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isActive(), 409, 'This alert is already done.');
            $now = CarbonImmutable::now('UTC');
            $locked->forceFill(['assigned_to_user_id' => $assignee->id, 'assigned_at' => $now])->save();
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $this->event($locked, $now, 'assigned', $assignee->is($actor) ? "{$actor->name} took it" : "{$actor->name} asked {$assignee->name} to look after it", $note, $actor);
            AuditLogger::logOrFail('security_devices.alert.assigned', $locked, ['actor_id' => (int) $actor->id, 'assignee_id' => (int) $assignee->id, 'site_id' => $locked->site_id]);

            return $locked;
        }, 3);

        if (! $assignee->is($actor)) {
            $this->notify(collect([$assignee]), $locked, "{$actor->name} asked you to look after this alert", ['database', 'mail']);
        }

        return $locked;
    }

    public function snooze(DeviceAlert $alert, User $actor, CarbonImmutable $until, ?string $why): DeviceAlert
    {
        return DB::transaction(function () use ($alert, $actor, $until, $why): DeviceAlert {
            $locked = DeviceAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isActive(), 409, 'This alert is already done.');
            $now = CarbonImmutable::now('UTC');
            $locked->forceFill(['state' => DeviceAlert::STATE_SNOOZED, 'snoozed_until' => $until, 'snoozed_by_user_id' => $actor->id])->save();
            $why = $why !== null && trim($why) !== '' ? trim($why) : null;
            $this->event($locked, $now, 'snoozed', "{$actor->name} snoozed it until {until}", $why, $actor, $until);
            AuditLogger::logOrFail('security_devices.alert.snoozed', $locked, ['actor_id' => (int) $actor->id, 'site_id' => $locked->site_id, 'until' => $until->toIso8601String()]);

            return $locked;
        }, 3);
    }

    public function incidentReported(DeviceAlert $alert, User $actor): void
    {
        $this->event($alert, CarbonImmutable::now('UTC'), 'incident', "{$actor->name} opened the incident form from this alert", null, $actor);
    }

    /**
     * Every few minutes: snoozes that have ended come back, and open critical alerts nobody has
     * acknowledged after an hour remind whoever is told about the device (once). Returns counts.
     *
     * The IT alerts that go to the person on call first (ItOnCall) get the same one reminder at
     * warning level too, so the rest of IT hears about them (Stephan, 3 Nov: "Yes, after an hour").
     *
     * @return array{back: int, reminded: int}
     */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $back = 0;
        DeviceAlert::query()->where('state', DeviceAlert::STATE_SNOOZED)->where('snoozed_until', '<=', $now)->orderBy('id')
            ->each(function (DeviceAlert $alert) use ($now, &$back): void {
                DB::transaction(function () use ($alert, $now, &$back): void {
                    $locked = DeviceAlert::query()->whereKey($alert->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->state !== DeviceAlert::STATE_SNOOZED) {
                        return;
                    }
                    $locked->forceFill(['state' => DeviceAlert::STATE_OPEN, 'snoozed_until' => null])->save();
                    $this->event($locked, $now, 'back', 'Snooze ended');
                    $back++;
                });
            });

        $this->sendMorningSummary($now);

        $settings = app(SecurityDevicesSettings::class);
        $reminded = 0;
        DeviceAlert::query()->with(['device', 'acknowledgedBy'])->where('state', DeviceAlert::STATE_OPEN)
            ->where(fn ($query) => $query
                ->where(fn ($due) => $due->whereNotNull('remind_at')->where('remind_at', '<=', $now))
                ->orWhere(fn ($due) => $due->where(fn ($level) => $level->where('severity', 'critical')->orWhereIn('kind', self::ON_CALL_KINDS))
                    ->whereNull('acknowledged_at')->whereNull('reminded_at')->where('opened_at', '<=', $now->subMinutes(self::REMIND_AFTER_MINUTES))))
            ->orderBy('id')
            ->each(function (DeviceAlert $alert) use ($now, $settings, &$reminded): void {
                $mine = $alert->remind_at !== null && $alert->acknowledgedBy !== null;
                if (! $mine && in_array($alert->kind, self::QUIET_HOURS_KINDS, true)) {
                    if ($settings->isQuietHours($now)) {
                        return;
                    }
                    $wasHeld = DeviceAlertEvent::query()->where('alert_id', $alert->id)->where('kind', 'held')->exists();
                    if ($wasHeld) {
                        $toldAt = DeviceAlertEvent::query()->where('alert_id', $alert->id)->where('kind', 'told')->latest('at')->value('at');
                        $anchor = $toldAt !== null
                            ? CarbonImmutable::parse($toldAt)->utc()
                            : $this->morningCutoff($now, $settings);
                        if ($anchor->gt($now->subMinutes(self::REMIND_AFTER_MINUTES))) {
                            return;
                        }
                    }
                }
                $people = $mine ? collect([$alert->acknowledgedBy]) : $this->peopleToTell($alert);
                $alert->forceFill(['reminded_at' => $now, 'remind_at' => null])->save();
                if ($people->isEmpty()) {
                    return;
                }
                $this->notify($people, $alert, 'Still open after '.self::duration($alert->opened_at, $now), ['database', 'mail']);
                $this->event($alert, $now, 'reminded', 'Still open', 'Reminder sent to '.self::names($people->pluck('name')->all()));
                $reminded++;
            });

        return ['back' => $back, 'reminded' => $reminded];
    }

    /**
     * At 6:00 am NZ time (or once quiet hours end, if later), send one morning summary per IT
     * recipient covering held overnight alerts, low batteries and overnight hub update reports,
     * scoped to that recipient's Sites. Never sends twice on the same NZ day.
     */
    public function sendMorningSummary(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        try {
            $settings = app(SecurityDevicesSettings::class);
            $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
            $local = $now->setTimezone($zone);
            if ($local->hour < 6 || $settings->isQuietHours($now)) {
                return 0;
            }
            $day = $local->format('Y-m-d');
            if (SecurityDeviceSetting::query()->where('key', self::SUMMARY_SENT_DAY)->first()?->value === $day) {
                return 0;
            }

            $cutoff = $this->morningCutoff($now, $settings);
            $since = $cutoff->subHours(24);

            $heldAlerts = DeviceAlert::query()
                ->with(['device', 'monitor', 'site:id,name'])
                ->whereBetween('opened_at', [$since, $cutoff])
                ->whereHas('events', fn ($q) => $q->where('kind', 'held'))
                ->whereDoesntHave('events', fn ($q) => $q->where('kind', 'told'))
                ->orderBy('opened_at')
                ->orderBy('id')
                ->get();

            $hubUpdates = MonitoringCollector::query()
                ->whereNull('revoked_at')
                ->whereNotNull('enrolled_at')
                ->whereNotNull('site_id')
                ->whereNotNull('update_state')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->filter(function (MonitoringCollector $hub) use ($since, $cutoff): bool {
                    $state = is_array($hub->update_state) ? $hub->update_state : [];
                    $outcome = $state['outcome'] ?? null;
                    $reported = $state['reported_at'] ?? null;
                    if (! in_array($outcome, ['installed', 'rolled_back', 'failed'], true) || ! is_string($reported)) {
                        return false;
                    }
                    $at = CarbonImmutable::parse($reported)->utc();

                    return $at->gte($since) && $at->lte($cutoff);
                })
                ->values();

            $deviceUpdates = Device::query()
                ->operational()
                ->whereIn('provider', ['unifi', 'ubiquiti'])
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(function (Device $device): ?array {
                    $meta = is_array($device->meta) ? $device->meta : [];
                    if (($meta['tell_it_update_ready'] ?? true) === false) {
                        return null;
                    }
                    $update = \App\Domain\SecurityDevices\Screens\Devices\DeviceUpdates::available($device);
                    if ($update === null) {
                        return null;
                    }
                    $marker = $update['next'] ?? 'ready';
                    if (($meta['update_ready_notified_version'] ?? null) === $marker) {
                        return null;
                    }
                    $siteId = $this->siteId($device);
                    if ($siteId === null) {
                        return null;
                    }

                    return ['device' => $device, 'site_id' => $siteId, 'next' => $update['next'], 'marker' => $marker];
                })
                ->filter()
                ->values();

            if ($heldAlerts->isEmpty() && $hubUpdates->isEmpty() && $deviceUpdates->isEmpty()) {
                return 0;
            }

            $claimed = DB::transaction(function () use ($day): bool {
                $row = SecurityDeviceSetting::query()->where('key', self::SUMMARY_SENT_DAY)->lockForUpdate()->first();
                if ($row !== null && $row->value === $day) {
                    return false;
                }
                $row ??= new SecurityDeviceSetting(['key' => self::SUMMARY_SENT_DAY]);
                $row->value = $day;
                $row->save();

                return true;
            }, 3);
            if (! $claimed) {
                return 0;
            }

            $channels = $settings->channels(SecurityDevicesSettings::SUMMARY_APP, SecurityDevicesSettings::SUMMARY_EMAIL, SecurityDevicesSettings::SUMMARY_PUSH);
            $defaultRole = $settings->role(SecurityDevicesSettings::DEVICES_ROLE);
            $siteRecipients = [];
            $recipientsFor = function (int $siteId, ?string $role) use (&$siteRecipients, $now): \Illuminate\Support\Collection {
                if ($siteId <= 0) {
                    return collect();
                }
                $cacheKey = $siteId.':'.($role ?? '');

                return $siteRecipients[$cacheKey] ??= app(ItOnCall::class)->first($this->policies->resolveItRecipients($siteId, $role), $now);
            };

            /** @var array<int, array{user: User, lines: list<string>, alert_ids: list<int>}> $perUser */
            $perUser = [];
            /** @var array<int, list<string>> $alertRecipientNames */
            $alertRecipientNames = [];
            /** @var list<array{site_id: int, label: string}> $summaryItems */
            $summaryItems = [];

            foreach ($heldAlerts as $alert) {
                $siteId = (int) ($alert->site_id ?? ($alert->device ? $this->siteId($alert->device) : 0));
                $openedClock = strtolower($alert->opened_at->setTimezone($zone)->format('g:i a'));
                $line = $alert->state === DeviceAlert::STATE_DONE && $alert->closed_at !== null
                    ? "{$alert->title} (opened {$openedClock}, back after ".self::duration($alert->opened_at, $alert->closed_at).')'
                    : "{$alert->title} (since {$openedClock}, still open)";
                $summaryItems[] = [
                    'site_id' => $siteId,
                    'label' => $alert->state === DeviceAlert::STATE_DONE ? "{$alert->title} (cleared overnight)" : (string) $alert->title,
                ];
                $role = $defaultRole;
                if (in_array($alert->kind, ['offline', 'failing'], true) && $alert->device instanceof Device && $siteId > 0) {
                    $route = $this->policies->resolveAlertRoute($alert->device, $alert->monitor, $siteId);
                    $role = $route['recipient_role'] ?? null;
                }
                $people = $recipientsFor($siteId, $role);
                $alertRecipientNames[$alert->id] = $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all();
                foreach ($people as $person) {
                    $perUser[$person->id] ??= ['user' => $person, 'lines' => [], 'alert_ids' => []];
                    if (! in_array($line, $perUser[$person->id]['lines'], true)) {
                        $perUser[$person->id]['lines'][] = $line;
                    }
                    $perUser[$person->id]['alert_ids'][] = (int) $alert->id;
                }
            }

            foreach ($hubUpdates as $hub) {
                $siteId = (int) $hub->site_id;
                $state = (array) $hub->update_state;
                $label = CoveringHubResolver::label($hub);
                $to = is_string($state['to_version'] ?? null) ? $state['to_version'] : 'the new version';
                $from = is_string($state['from_version'] ?? null) ? $state['from_version'] : null;
                $line = match ($state['outcome'] ?? null) {
                    'installed' => "{$label} updated itself to {$to}",
                    'rolled_back' => "{$label} couldn’t update to {$to} and went back".($from !== null ? " to {$from}" : ''),
                    default => "{$label} couldn’t update to {$to}",
                };
                $summaryItems[] = ['site_id' => $siteId, 'label' => $line];
                foreach ($recipientsFor($siteId, $defaultRole) as $person) {
                    $perUser[$person->id] ??= ['user' => $person, 'lines' => [], 'alert_ids' => []];
                    if (! in_array($line, $perUser[$person->id]['lines'], true)) {
                        $perUser[$person->id]['lines'][] = $line;
                    }
                }
            }

            foreach ($deviceUpdates as $item) {
                /** @var Device $updateDevice */
                $updateDevice = $item['device'];
                $siteId = (int) $item['site_id'];
                $line = $item['next'] !== null
                    ? "{$updateDevice->name}: software update {$item['next']} ready"
                    : "{$updateDevice->name}: software update ready";
                $summaryItems[] = ['site_id' => $siteId, 'label' => $line];
                foreach ($recipientsFor($siteId, $defaultRole) as $person) {
                    $perUser[$person->id] ??= ['user' => $person, 'lines' => [], 'alert_ids' => []];
                    if (! in_array($line, $perUser[$person->id]['lines'], true)) {
                        $perUser[$person->id]['lines'][] = $line;
                    }
                }
                $meta = is_array($updateDevice->meta) ? $updateDevice->meta : [];
                $meta['update_ready_notified_version'] = $item['marker'];
                $updateDevice->forceFill(['meta' => $meta])->saveQuietly();
            }

            SecurityDeviceSetting::query()->updateOrCreate(
                ['key' => self::SUMMARY_LAST],
                ['value' => ['day' => $day, 'at' => $now->toIso8601String(), 'items' => $summaryItems]],
            );

            if ($channels === []) {
                return 0;
            }

            $sent = 0;
            foreach ($perUser as $entry) {
                $count = count($entry['lines']);
                $title = $count === 1 ? '1 overnight item' : "{$count} overnight items";
                Notification::send(collect([$entry['user']]), new SecurityDeviceMorningSummaryNotification(
                    day: $day,
                    title: $title,
                    lines: $entry['lines'],
                    channels: $channels,
                    alertIds: array_values(array_unique($entry['alert_ids'])),
                ));
                $sent++;
            }

            $how = array_map(fn (string $channel): string => match ($channel) {
                'database' => 'app', 'mail' => 'email', 'push' => 'push', default => $channel,
            }, $channels);
            foreach ($heldAlerts as $alert) {
                $names = $alertRecipientNames[$alert->id] ?? [];
                if ($names !== []) {
                    $this->event($alert, $now, 'told', self::names($names).' told', 'Morning summary · '.self::names($how));
                }
            }

            return $sent;
        } catch (Throwable $exception) {
            Log::error('Morning summary could not be sent', ['error' => $exception->getMessage()]);

            return 0;
        }
    }

    private function morningCutoff(CarbonImmutable $now, SecurityDevicesSettings $settings): CarbonImmutable
    {
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $local = $now->setTimezone($zone);
        $cutoff = $local->startOfDay()->setTime(6, 0);
        if ($settings->bool(SecurityDevicesSettings::QUIET_HOURS_ON)) {
            [$endHour, $endMinute] = array_map('intval', explode(':', $settings->time(SecurityDevicesSettings::QUIET_HOURS_END)));
            $quietEnd = $local->startOfDay()->setTime($endHour, $endMinute);
            if ($quietEnd->gt($cutoff)) {
                $cutoff = $quietEnd;
            }
        }

        return $cutoff->utc();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /** The people the device's alert route tells (IT devices), for reminders. */
    private function peopleToTell(DeviceAlert $alert): \Illuminate\Support\Collection
    {
        $device = $alert->device;
        if (! $device instanceof Device || $alert->site_id === null) {
            return collect();
        }
        $route = $this->policies->resolveAlertRoute($device, $alert->monitor, (int) $alert->site_id);

        return $this->policies->resolveItRecipients((int) $alert->site_id, $route['recipient_role'] ?? null);
    }

    /** @param  list<string>  $channels */
    private function notify(\Illuminate\Support\Collection $people, DeviceAlert $alert, string $line, array $channels): void
    {
        try {
            Notification::send($people, new SecurityDeviceAlertNotification(
                alertId: (int) $alert->id,
                severity: $alert->severity,
                title: $alert->title,
                line: $line,
                channels: $channels,
            ));
        } catch (Throwable $exception) {
            Log::warning('Device alert notification could not be sent', ['alert_id' => $alert->id, 'error' => $exception->getMessage()]);
        }
    }

    /** @param  array{names: list<string>, channels: list<string>}|null  $told */
    private function toldEvent(DeviceAlert $alert, CarbonImmutable $at, ?array $told): void
    {
        if ($told === null || $told['names'] === []) {
            return;
        }
        $how = array_map(fn (string $channel): string => match ($channel) {
            'database' => 'app', 'mail' => 'email', 'push' => 'push', default => $channel,
        }, $told['channels']);
        $this->event($alert, $at, 'told', self::names($told['names']).' told', ucfirst(self::names($how)));
    }

    private function event(DeviceAlert $alert, CarbonImmutable $at, string $kind, string $text, ?string $detail = null, ?User $actor = null, ?CarbonImmutable $until = null): void
    {
        DeviceAlertEvent::query()->create([
            'alert_id' => $alert->id,
            'at' => $at,
            'kind' => $kind,
            'actor_user_id' => $actor?->id,
            // "{until}" is filled in NZ time on screen; the event keeps the instant in `detail` when needed.
            'text' => mb_substr($until !== null ? str_replace('{until}', $until->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->format('D j M, g:i a'), $text) : $text, 0, 255),
            'detail' => $detail === null ? null : mb_substr($detail, 0, 500),
        ]);
    }

    private function siteId(Device $device): ?int
    {
        $siteId = app(\App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver::class)->resolve((int) $device->id);

        return $siteId > 0 ? $siteId : null;
    }

    /** @return list<string> the alert kinds a person closes (safety events) */
    public static function safetyKinds(): array
    {
        // Wrong sensor logins are an event too: someone says when they've looked into it. So are the
        // Phase F care and scald alerts: a person says they've checked.
        // A house mode's check finding too: a person locks or arms by hand, then says so.
        // S&D Phase F: an automation's alert too (AUTOMATION_KINDS): a person says it's dealt with.
        return array_values(array_unique([...array_column(self::SAFETY_EVENTS, 0), 'mqtt_logins', ...self::CARE_KINDS, ...self::MODE_CHECK_KINDS, ...self::AUTOMATION_KINDS]));
    }

    /** @return list<string> the kinds that share one open alert per device */
    private static function family(string $kind): array
    {
        return in_array($kind, self::AVAILABILITY_KINDS, true) ? self::AVAILABILITY_KINDS : [$kind];
    }

    private static function isReachability(Monitor $monitor): bool
    {
        $kind = $monitor->kind instanceof MonitorKind ? $monitor->kind : MonitorKind::tryFrom((string) $monitor->kind);

        return in_array($kind, [MonitorKind::Icmp, MonitorKind::Tcp, MonitorKind::Collector], true);
    }

    public static function duration(?CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from === null) {
            return 'a while';
        }
        $minutes = max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));
        if ($minutes < 1) {
            return 'under a minute';
        }
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours < 48) {
            return $rest > 0 ? "{$hours} h {$rest} min" : "{$hours} h";
        }

        return intdiv($hours, 24).' days';
    }

    /** @param  list<string>  $names */
    private static function names(array $names): string
    {
        $names = array_values(array_unique(array_filter($names)));
        if (count($names) <= 1) {
            return $names[0] ?? 'Nobody';
        }
        if (count($names) > 3) {
            return implode(', ', array_slice($names, 0, 3)).' and '.(count($names) - 3).' more';
        }
        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
}
