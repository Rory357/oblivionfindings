<?php

namespace App\Services\Medication\Controlled;

use App\Services\Medication\Settings\MedicationSettingCodec;
use App\Services\Medication\Settings\TimeCodec;

/** One reviewed weekly day and local time; partial schedules never publish. */
final class WeeklyCountAnchorCodec implements MedicationSettingCodec
{
    public const OFF = 'off';

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function __construct(private readonly string $timezone = 'Pacific/Auckland') {}

    public function kind(): string
    {
        return 'weekly_anchor';
    }

    public function accepts(string $value): bool
    {
        return $value === self::OFF || $this->decode($value) !== null;
    }

    /** @return array{day: int, time: string}|null */
    public function decode(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        $anchor = json_decode($value, true);
        if (! is_array($anchor) || count($anchor) !== 2
            || ! isset($anchor['day'], $anchor['time'])
            || ! is_int($anchor['day']) || ! isset(self::DAYS[$anchor['day']])
            || ! is_string($anchor['time'])
            || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $anchor['time']) !== 1) {
            return null;
        }
        // NZ's repeated/missing Sunday hour has no unambiguous clinical instant.
        if ($this->timezone === 'Pacific/Auckland' && $anchor['day'] === 7 && str_starts_with($anchor['time'], '02:')) {
            return null;
        }

        return ['day' => $anchor['day'], 'time' => $anchor['time']];
    }

    public function normalise(mixed $value, string $default): string
    {
        $anchor = $this->decode($value);

        return $anchor === null ? ($value === self::OFF ? self::OFF : $default) : json_encode($anchor, JSON_THROW_ON_ERROR);
    }

    public function format(string $value): string
    {
        $anchor = $this->decode($value);

        return $anchor === null ? 'Not configured'
            : self::DAYS[$anchor['day']].' at '.TimeCodec::clock($anchor['time']).' ('.$this->timezone.')';
    }

    public function loosens(string $from, string $to): bool
    {
        return $this->decode($from) !== null
            && $this->normalise($from, self::OFF) !== $this->normalise($to, self::OFF);
    }

    public function invalidMessage(string $label): string
    {
        return 'Choose a weekday and a time for “'.$label.'”. Sunday 2:00–2:59 am is unavailable in Pacific/Auckland because the clocks change.';
    }

    public function toClient(): array
    {
        return [
            'weekday_options' => array_map(fn (int $day): array => ['value' => (string) $day, 'label' => self::DAYS[$day]], array_keys(self::DAYS)),
            'timezone' => $this->timezone,
            'off_label' => 'Not configured',
        ];
    }
}
