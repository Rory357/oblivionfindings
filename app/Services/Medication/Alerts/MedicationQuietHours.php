<?php

namespace App\Services\Medication\Alerts;

use App\Models\AppSetting;
use App\Models\MedicationSiteSetting;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\Medication\Settings\QuietHoursCodec;
use App\Services\Medication\Settings\TimeCodec;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Quiet hours for medication alerts (P11 v5 Delivery › Quiet hours, B2
 * chunk 5). Times are New Zealand wall-clock times, so "9:00 pm to 7:00 am"
 * means that every night, across midnight and across a daylight-saving
 * change.
 *
 * Each house follows the organisation's hours, sets its own, or has none
 * (Q12). During a house's window, email and push for alerts without Follow
 * up wait until it ends; the bell never waits, and Follow up alerts,
 * re-alerts and escalations are never held.
 *
 * After hours on the alert log (Main's answer) is the house's own window,
 * else the organisation default, recording which applied.
 */
class MedicationQuietHours
{
    public const SOURCE_HOUSE = 'house';

    public const SOURCE_ORGANISATION = 'organisation';

    private const TIMEZONE = 'Pacific/Auckland';

    public function __construct(private readonly MedicationSettingsRegistry $registry) {}

    /**
     * The window that holds email and push at this house, or null when
     * nothing is held there.
     *
     * @return array{from: string, until: string, source: string}|null
     */
    public function window(?int $siteId): ?array
    {
        $house = $this->house($siteId);

        return match ($house['mode']) {
            QuietHoursCodec::NONE => null,
            QuietHoursCodec::OWN => self::usable($house['from'], $house['until'], self::SOURCE_HOUSE),
            default => $this->organisation(),
        };
    }

    /**
     * The window that counts as after hours for an alert at this house: its
     * own hours, else the organisation default — even where the house holds
     * nothing.
     *
     * @return array{from: string, until: string, source: string}|null
     */
    public function afterHoursWindow(?int $siteId): ?array
    {
        $house = $this->house($siteId);
        if ($house['mode'] === QuietHoursCodec::OWN) {
            $own = self::usable($house['from'], $house['until'], self::SOURCE_HOUSE);
            if ($own !== null) {
                return $own;
            }
        }

        return $this->organisation();
    }

    /** When email and push for an alert raised now at this house may go, or null to send now. */
    public function heldUntil(?int $siteId, CarbonInterface $now): ?Carbon
    {
        $window = $this->window($siteId);

        return $window === null ? null : self::endsAt($window, $now);
    }

    /**
     * When this window ends, if `$now` is inside it; null when it isn't. The
     * end is worked out on the New Zealand calendar: tonight's 7:00 am is
     * 7:00 am NZ tomorrow, whatever the offset is by then.
     *
     * @param  array{from: string, until: string}  $window
     */
    public static function endsAt(array $window, CarbonInterface $now): ?Carbon
    {
        $local = Carbon::instance($now)->setTimezone(self::TIMEZONE);
        $minute = $local->hour * 60 + $local->minute;
        $from = self::minutes($window['from']);
        $until = self::minutes($window['until']);
        if ($from === $until) {
            return null;
        }
        if ($from < $until) {
            // The same day: 1:00 pm to 3:00 pm.
            $inside = $minute >= $from && $minute < $until;
            $endDay = $local->copy();
        } else {
            // Across midnight: 9:00 pm to 7:00 am.
            $inside = $minute >= $from || $minute < $until;
            $endDay = $minute >= $from ? $local->copy()->addDay() : $local->copy();
        }
        if (! $inside) {
            return null;
        }

        return $endDay->setTimeFromTimeString($window['until'])->utc();
    }

    /** @param array{from: string, until: string} $window */
    public static function within(array $window, CarbonInterface $now): bool
    {
        return self::endsAt($window, $now) !== null;
    }

    /** @return array{mode: string, from: string, until: string} */
    private function house(?int $siteId): array
    {
        $codec = new QuietHoursCodec;
        $definition = $this->registry->definition('quietHouse', 'hours');
        if ($siteId === null || $definition === null) {
            return ['mode' => QuietHoursCodec::ORG, 'from' => '', 'until' => ''];
        }
        $stored = MedicationSiteSetting::query()
            ->where('site_id', $siteId)
            ->where('key', $definition->storageKey)
            ->value('value');

        return $codec->decode($definition->normalise($stored)) ?? ['mode' => QuietHoursCodec::ORG, 'from' => '', 'until' => ''];
    }

    /** @return array{from: string, until: string, source: string}|null */
    private function organisation(): ?array
    {
        $value = function (string $key): string {
            $definition = $this->registry->definition('delivery', $key);

            return $definition === null
                ? TimeCodec::OFF
                : $definition->normalise(AppSetting::query()->where('key', $definition->storageKey)->value('value'));
        };

        return self::usable($value('quiet_from'), $value('quiet_until'), self::SOURCE_ORGANISATION);
    }

    /** @return array{from: string, until: string, source: string}|null */
    private static function usable(string $from, string $until, string $source): ?array
    {
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (preg_match($time, $from) !== 1 || preg_match($time, $until) !== 1 || $from === $until) {
            return null;
        }

        return ['from' => $from, 'until' => $until, 'source' => $source];
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
