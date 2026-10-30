<?php

namespace App\Domain\Referrals\Services;

final class ReferralReportRegion
{
    /** The person's reported region is descriptive intake data, never an owning-site grant. */
    public static function group(mixed $value): array
    {
        $label = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';

        return $label === '' ? ['key' => 'not_recorded', 'label' => 'Not recorded']
            : ['key' => hash('sha256', mb_strtolower($label, 'UTF-8')), 'label' => $label];
    }
}
