<?php

namespace App\Domain\SecurityDevices\Care;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\SiteHazard;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * S&D Phase F (pg-sdr-7c): a temperature probe on a hot-water tap. Three readings in a row over
 * 45 °C open a critical "scald risk" alert for the staff on shift, with an H&S hazard ready to
 * raise (a person raises it, so a real person is its reporter). Once the tap stays at or under
 * 45 °C for 24 hours the alert closes and whoever has the hazard is told it can be closed.
 */
final class ScaldWatch
{
    public const float LIMIT_C = 45.0;

    public const int READINGS_IN_A_ROW = 3;

    public const string CONFIG_KEY = 'care.scald_watch';

    public function __construct(private readonly DeviceAlertEngine $alerts) {}

    public static function watches(Device $device): bool
    {
        return data_get($device->config ?? [], self::CONFIG_KEY) === true;
    }

    /** Turn the hot-water watch on or off for a temperature sensor (Settings tab). */
    public function set(User $actor, Device $device, bool $on): void
    {
        abort_unless($actor->canDo('securityDevices.devices.update'), 403);
        app(SecurityDevicesAccessService::class)->assertCanViewDevice($actor, $device);
        abort_unless(DevicePersonLinks::linkable($device), 404);
        DB::transaction(function () use ($actor, $device, $on): void {
            $locked = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $config = (array) ($locked->config ?? []);
            data_set($config, self::CONFIG_KEY, $on);
            $locked->forceFill(['config' => $config])->save();
            AuditLogger::logOrFail('security_devices.scald_watch.changed', $locked, ['actor_id' => (int) $actor->id, 'on' => $on]);
        });
    }

    /** A temperature reading arrived (already stored in the reading history). */
    public function onTemperature(Device $device, float $celsius, CarbonImmutable $at): void
    {
        if (! self::watches($device) || $celsius <= self::LIMIT_C) {
            return;
        }
        try {
            $recent = DB::table('security_device_sensor_readings')
                ->where('device_id', $device->id)->where('metric', 'temperature')
                ->where('observed_at', '<=', $at->setTimezone('UTC')->format('Y-m-d H:i:s'))
                ->orderByDesc('observed_at')->limit(self::READINGS_IN_A_ROW)->pluck('value');
            if ($recent->count() < self::READINGS_IN_A_ROW || $recent->contains(fn (mixed $value): bool => (float) $value <= self::LIMIT_C)) {
                return;
            }
            $reading = rtrim(rtrim(number_format($celsius, 1, '.', ''), '0'), '.');
            $this->alerts->openCare($device, 'scald_risk', 'Scald risk: '.$device->name.' is over 45 °C', $at,
                "{$reading} °C, over 45 °C three readings in a row", 'Hot water above 45 °C can scald quickly. Raise the H&S hazard and get the tempering valve checked.');
        } catch (Throwable $exception) {
            Log::error('Scald watch could not be checked', ['device_id' => $device->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * A person raises the H&S hazard from the scald alert: they are its reporter. Returns the
     * hazard (the same one if it was already raised).
     */
    public function raiseHazard(User $actor, DeviceAlert $alert): SiteHazard
    {
        abort_unless($actor->canDo('hazards.create'), 403);
        abort_unless($alert->kind === 'scald_risk' && $alert->site_id !== null, 404);
        app(SecurityDevicesAccessService::class)->assertCanViewSite($actor, (int) $alert->site_id);

        return DB::transaction(function () use ($actor, $alert): SiteHazard {
            $locked = DeviceAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            if ($locked->hazard_id !== null && ($existing = SiteHazard::query()->find($locked->hazard_id)) instanceof SiteHazard) {
                return $existing;
            }
            $device = Device::query()->find($locked->device_id);
            $hazard = SiteHazard::query()->create([
                'site_id' => (int) $locked->site_id,
                'hazard_type' => 'hot_water_temperature',
                'severity' => 'high',
                'likelihood' => 'likely',
                'description' => 'Scald risk: hot water at '.($device?->name ?? 'a tap').' measured over 45 °C (three readings in a row). Raised from Security & devices.',
                'location' => mb_substr((string) (data_get($device?->config ?? [], 'room') ?? $device?->name ?? ''), 0, 191) ?: null,
                'immediate_action_taken' => 'Staff on shift were told by Oblivion.',
                'reported_by_user_id' => $actor->id,
                'status' => 'open',
            ]);
            $locked->forceFill(['hazard_id' => $hazard->id])->save();
            DB::table('security_device_alert_events')->insert([
                'alert_id' => $locked->id, 'at' => now(), 'kind' => 'incident', 'actor_user_id' => $actor->id,
                'text' => mb_substr("{$actor->name} raised the H&S hazard", 0, 255), 'detail' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLogger::logOrFail('security_devices.scald_risk.hazard_raised', $hazard, ['actor_id' => (int) $actor->id, 'alert_id' => (int) $locked->id]);

            return $hazard;
        });
    }

    /** Every few minutes: close a scald alert once the tap has stayed at or under 45 °C for 24 hours. */
    public function tick(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $closed = 0;
        DeviceAlert::query()->active()->where('kind', 'scald_risk')->where('opened_at', '<=', $now->subDay())->orderBy('id')->limit(200)->get()
            ->each(function (DeviceAlert $alert) use ($now, &$closed): void {
                $window = DB::table('security_device_sensor_readings')
                    ->where('device_id', $alert->device_id)->where('metric', 'temperature')
                    ->where('observed_at', '>=', $now->subDay()->format('Y-m-d H:i:s'));
                if ((int) (clone $window)->count() < 3 || (float) (clone $window)->max('value') > self::LIMIT_C) {
                    return;
                }
                $device = Device::query()->find($alert->device_id);
                if (! $device instanceof Device) {
                    return;
                }
                $this->alerts->closeCare($device, 'scald_risk', $now, fn (): string => 'The tap has stayed at or under 45 °C for 24 hours');
                $closed++;
                $hazard = $alert->hazard_id ? SiteHazard::query()->find($alert->hazard_id) : null;
                $person = $hazard ? User::query()->find($hazard->assigned_to_user_id ?? $hazard->reported_by_user_id) : null;
                if ($person instanceof User) {
                    try {
                        $person->notify(new SecurityDeviceAlertNotification(
                            alertId: (int) $alert->id,
                            severity: 'warning',
                            title: (string) $alert->title,
                            line: 'The tap has stayed under 45 °C for 24 hours, so the H&S hazard can be closed.',
                            channels: ['database'],
                        ));
                    } catch (Throwable) {
                        // The alert timeline still records it.
                    }
                }
            });

        return $closed;
    }
}
