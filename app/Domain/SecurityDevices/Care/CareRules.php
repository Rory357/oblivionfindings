<?php

namespace App\Domain\SecurityDevices\Care;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * S&D Phase F: the rules of a sensor linked to a person's care (pg-sdr-7b).
 *
 * - Falls plan (bed sensor): out of bed for longer than the plan's minutes during its night hours
 *   tells the staff on shift; back in bed closes the alert with how long it was.
 * - Wandering plan (door contact): the door opening during the night hours tells the staff on shift.
 *
 * Night hours are NZ wall-clock times and may run past midnight. Nothing here locks, unlocks,
 * arms or disarms anything: it only tells people.
 */
final class CareRules
{
    public const string ZONE = 'Pacific/Auckland';

    public function __construct(private readonly DeviceAlertEngine $alerts) {}

    /** A bed sensor reported in bed (true) or out of bed (false). */
    public function onOccupancy(Device $device, bool $inBed, CarbonImmutable $at): void
    {
        try {
            $link = $this->link($device, 'falls');
            if ($link === null) {
                return;
            }
            $state = (array) ($link->state ?? []);
            if ($inBed) {
                if (isset($state['out_since'])) {
                    $since = CarbonImmutable::parse((string) $state['out_since']);
                    $this->alerts->closeCare($device, 'care_out_of_bed', $at, fn (): string => 'Back in bed after '.DeviceAlertEngine::duration($since, $at));
                    $this->save($link, []);
                }

                return;
            }
            if (! isset($state['out_since'])) {
                $this->save($link, ['out_since' => $at->toIso8601String()]);
            }
        } catch (Throwable $exception) {
            Log::error('Bed sensor care rule could not be updated', ['device_id' => $device->id, 'error' => $exception->getMessage()]);
        }
    }

    /** A door contact opened. */
    public function onDoorOpened(Device $device, CarbonImmutable $at): void
    {
        try {
            $link = $this->link($device, 'wandering');
            if ($link === null || ! self::insideNight((array) $link->rule, $at) || ! $link->client instanceof Client) {
                return;
            }
            $name = DevicePersonLinks::name($link->client);
            $this->alerts->openCare($device, 'care_door_night', "{$name}’s door opened at night", $at,
                "{$name}’s door opened at ".$at->setTimezone(self::ZONE)->format('g:i a'), 'From '.$name.'’s wandering plan: check on them now.');
        } catch (Throwable $exception) {
            Log::error('Door care rule could not be checked', ['device_id' => $device->id, 'error' => $exception->getMessage()]);
        }
    }

    /** Every few minutes: tell staff about anyone out of bed past their plan's minutes at night. */
    public function tick(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $told = 0;
        DevicePersonLink::query()->current()->where('purpose', 'falls')->with(['device', 'client'])->orderBy('id')->limit(1000)->get()
            ->each(function (DevicePersonLink $link) use ($now, &$told): void {
                $state = (array) ($link->state ?? []);
                $rule = (array) ($link->rule ?? []);
                if (! isset($state['out_since']) || ($state['alerted_for'] ?? null) === $state['out_since']
                    || ! $link->device instanceof Device || ! $link->client instanceof Client) {
                    return;
                }
                $since = CarbonImmutable::parse((string) $state['out_since']);
                $minutes = (int) ($rule['minutes'] ?? 10);
                // Only during the night hours, and only once the plan's minutes have passed then.
                $from = self::nightStartBefore($rule, $now);
                if ($from === null || $now->lt($since->max($from)->addMinutes($minutes))) {
                    return;
                }
                $name = DevicePersonLinks::name($link->client);
                $alert = $this->alerts->openCare($link->device, 'care_out_of_bed', "{$name} out of bed for over {$minutes} minutes", $now,
                    "{$name} out of bed since ".$since->setTimezone(self::ZONE)->format('g:i a'),
                    'From '.$name.'’s falls plan: check on them now.');
                $this->save($link, [...$state, 'alerted_for' => $state['out_since']]);
                if ($alert !== null) {
                    $told++;
                }
            });

        return $told;
    }

    /** @param array<string, mixed> $rule */
    public static function insideNight(array $rule, CarbonImmutable $at): bool
    {
        return self::nightStartBefore($rule, $at) !== null;
    }

    /**
     * When the night hours that contain $at started, or null when $at is outside them.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function nightStartBefore(array $rule, CarbonImmutable $at): ?CarbonImmutable
    {
        $from = (string) ($rule['from'] ?? '22:00');
        $to = (string) ($rule['to'] ?? '06:00');
        $local = $at->setTimezone(self::ZONE);
        foreach ([0, -1] as $offset) {
            $day = $local->startOfDay()->addDays($offset)->format('Y-m-d');
            $start = CarbonImmutable::parse($day.' '.$from, self::ZONE);
            $end = $to > $from
                ? CarbonImmutable::parse($day.' '.$to, self::ZONE)
                : CarbonImmutable::parse($local->startOfDay()->addDays($offset + 1)->format('Y-m-d').' '.$to, self::ZONE);
            if ($local->gte($start) && $local->lt($end)) {
                return $start->utc();
            }
        }

        return null;
    }

    private function link(Device $device, string $purpose): ?DevicePersonLink
    {
        return DevicePersonLink::query()->current()->where('device_id', $device->id)->where('purpose', $purpose)->with('client')->latest('id')->first();
    }

    /** @param array<string, mixed> $state */
    private function save(DevicePersonLink $link, array $state): void
    {
        DB::table('security_device_person_links')->where('id', $link->id)->update([
            'state' => $state === [] ? null : json_encode($state, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $link->state = $state === [] ? null : $state;
    }
}
