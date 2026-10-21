<?php

namespace App\Domain\Referrals\Services;

use Illuminate\Database\Eloquent\Builder;

final class ReferralReportClassification
{
    public const LABELS = ['other' => 'Other enquiries and referrals', 'duplicate' => 'Confirmed duplicate closures', 'test' => 'Test-entry closures', 'spam' => 'Spam closures'];

    public static function filter(Builder $query, string $key): Builder
    {
        return $key === 'other'
            ? $query->where(fn ($q) => $q->where('stage', '!=', 'closed')->orWhereNull('closure_reason')->orWhereNotIn('closure_reason', ['duplicate', 'test', 'spam']))
            : $query->where('stage', 'closed')->where('closure_reason', $key);
    }

    public static function groups(Builder $query): array
    {
        $result = [];
        foreach (self::LABELS as $key => $label) {
            $result[] = ['key' => $key, 'label' => $label, 'count' => self::filter(clone $query, $key)->count()];
        }

        return $result;
    }
}
