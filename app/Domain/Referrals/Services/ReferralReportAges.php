<?php

namespace App\Domain\Referrals\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

final class ReferralReportAges
{
    public const LABELS = ['0_6' => 'Under 7 elapsed days', '7_13' => '7–13 elapsed days', '14_29' => '14–29 elapsed days', '30_plus' => '30+ elapsed days', 'unknown' => 'Date missing or invalid'];

    public const PENDING = ['submitted', 'awaiting_assessment', 'awaiting_decision'];

    public static function empty(): array
    {
        return array_map(fn ($key, $label) => ['key' => $key, 'label' => $label, 'count' => 0], array_keys(self::LABELS), self::LABELS);
    }

    public static function bucket(mixed $value, CarbonImmutable $cutoff, ?DateTimeInterface $minimum = null): string
    {
        try {
            if ($value instanceof DateTimeInterface) {
                $date = CarbonImmutable::instance($value);
            } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
                $date = CarbonImmutable::parse($value);
                if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                    return 'unknown';
                }
            } else {
                return 'unknown';
            }
            if ($date->gt($cutoff) || ($minimum && $date->lt($minimum))) {
                return 'unknown';
            }
            $days = $date->diffInSeconds($cutoff) / 86400;

            return $days < 7 ? '0_6' : ($days < 14 ? '7_13' : ($days < 30 ? '14_29' : '30_plus'));
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    public static function stageLabel($record, CarbonImmutable $cutoff): string
    {
        return in_array($record->stage, ['closed', 'converted'], true) ? 'Not an open intake stage' : self::LABELS[self::bucket($record->stage_changed_at, $cutoff, $record->created_at)];
    }

    public static function stage(Builder $query, string $bucket, CarbonImmutable $cutoff): void
    {
        $query->whereNotIn('stage', ['closed', 'converted']);
        if ($bucket === 'unknown') {
            $query->where(fn ($q) => $q->whereNull('stage_changed_at')->orWhereColumn('stage_changed_at', '<', 'created_at')->orWhere('stage_changed_at', '>', $cutoff));

            return;
        }
        $query->whereNotNull('stage_changed_at')->whereColumn('stage_changed_at', '>=', 'created_at')->where('stage_changed_at', '<=', $cutoff);
        [$minimum, $maximum] = match ($bucket) {
            '0_6' => [0, 7], '7_13' => [7, 14], '14_29' => [14, 30], default => [30, null]
        };
        if ($minimum > 0) {
            $query->where('stage_changed_at', '<=', $cutoff->subSeconds($minimum * 86400));
        }
        if ($maximum !== null) {
            $query->where('stage_changed_at', '>', $cutoff->subSeconds($maximum * 86400));
        }
    }
}
