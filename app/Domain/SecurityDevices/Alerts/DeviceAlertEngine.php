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
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
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
            $people = (int) $hub->site_id > 0
                ? $this->policies->resolveItRecipients((int) $hub->site_id, $settings->role(SecurityDevicesSettings::DEVICES_ROLE))
                : collect();
            $channels = $settings->channels(SecurityDevicesSettings::DEVICES_APP, SecurityDevicesSettings::DEVICES_EMAIL, SecurityDevicesSettings::DEVICES_PUSH);
            $told = $people->isNotEmpty() && $channels !== []
                ? ['names' => $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => $channels]
                : null;
            $label = CoveringHubResolver::label($hub);
            $created = $this->open($device, null, 'mqtt_logins', 'warning', "{$label}: {$count} wrong sensor logins from {$address}", $at,
                "{$count} wrong logins in 10 minutes from {$address}", 'The hub ignores that address for 10 minutes. Check the gateway’s login, or who else is on the network.', $told);
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
                $this->openForHouse($device, $kind, $state, (string) $device->name.': '.lcfirst($label).' '.$reading, $at, ucfirst($label).' '.$reading, 'Out of the usual range');

                return;
            }
            $this->closeKind($device, $kind, $at, fn (DeviceAlert $alert): string => ucfirst($label).' back to '.$reading.' after '.self::duration($alert->opened_at, $at));
        } catch (Throwable $exception) {
            Log::error('Reading alert could not be updated', ['device_id' => $device->id, 'metric' => $metric, 'error' => $exception->getMessage()]);
        }
    }

    /** A battery level arrived: below 20% opens a warning, a new battery (30% or more) closes it. */
    public function onBattery(Device $device, int $percent, CarbonImmutable $at): void
    {
        try {
            if ($percent < self::BATTERY_LOW) {
                $this->openForHouse($device, 'battery', 'warning', (string) $device->name.": battery {$percent}%", $at, "Battery {$percent}%", 'Change it soon so it keeps working');

                return;
            }
            if ($percent >= self::BATTERY_OK) {
                $this->closeKind($device, 'battery', $at, fn (): string => "Battery back to {$percent}%");
            }
        } catch (Throwable $exception) {
            Log::error('Battery alert could not be updated', ['device_id' => $device->id, 'error' => $exception->getMessage()]);
        }
    }

    /** Open (or refresh) an alert the staff on shift at the device's house hear about in the app. */
    private function openForHouse(Device $device, string $kind, string $severity, string $title, CarbonImmutable $at, string $what, ?string $detail): void
    {
        $siteId = $this->siteId($device);
        $people = $siteId !== null ? app(StaffOnShift::class)->toTell($siteId) : collect();
        $told = $people->isNotEmpty() ? ['names' => $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => ['database']] : null;
        $created = $this->open($device, null, $kind, $severity, $title, $at, $what, $detail, $told);
        if ($created !== null && $told !== null) {
            $this->notify($people, $created, $what, ['database']);
        }
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
                // Told as Setup › Alerts & checks says for "a device or hub stops answering".
                $settings = app(SecurityDevicesSettings::class);
                $people = (int) $hub->site_id > 0
                    ? $this->policies->resolveItRecipients((int) $hub->site_id, $settings->role(SecurityDevicesSettings::DEVICES_ROLE))
                    : collect();
                $channels = $settings->channels(SecurityDevicesSettings::DEVICES_APP, SecurityDevicesSettings::DEVICES_EMAIL, SecurityDevicesSettings::DEVICES_PUSH);
                $told = $people->isNotEmpty() && $channels !== []
                    ? ['names' => $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => $channels]
                    : null;
                $created = $this->open($device, null, 'hub_offline', 'critical', "{$label} has stopped answering", $at,
                    'Stopped answering', 'Devices at its Sites show “Can’t check” until it’s back', $told);
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

        $this->open($device, $monitor, $kind, $critical ? 'critical' : 'warning', $title, $at,
            $offline ? 'Stopped answering' : "{$check} started failing", $detail, $told);
    }

    /**
     * Open an alert, or make an open one worse. One active alert per device. Returns the alert
     * when this call opened it, null when one was already open.
     *
     * @param  array{names: list<string>, channels: list<string>}|null  $told
     */
    private function open(Device $device, ?Monitor $monitor, string $kind, string $severity, string $title, CarbonImmutable $at, string $what, ?string $detail, ?array $told): ?DeviceAlert
    {
        return DB::transaction(function () use ($device, $monitor, $kind, $severity, $title, $at, $what, $detail, $told): ?DeviceAlert {
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
                $this->toldEvent($alert, $at, $told);

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
                $this->toldEvent($alert, $at, $told);
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

        $reminded = 0;
        DeviceAlert::query()->with(['device', 'acknowledgedBy'])->where('state', DeviceAlert::STATE_OPEN)
            ->where(fn ($query) => $query
                ->where(fn ($due) => $due->whereNotNull('remind_at')->where('remind_at', '<=', $now))
                ->orWhere(fn ($due) => $due->where('severity', 'critical')->whereNull('acknowledged_at')->whereNull('reminded_at')->where('opened_at', '<=', $now->subMinutes(self::REMIND_AFTER_MINUTES))))
            ->orderBy('id')
            ->each(function (DeviceAlert $alert) use ($now, &$reminded): void {
                $mine = $alert->remind_at !== null && $alert->acknowledgedBy !== null;
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
        // Wrong sensor logins are an event too: someone says when they've looked into it.
        return array_values(array_unique([...array_column(self::SAFETY_EVENTS, 0), 'mqtt_logins']));
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
