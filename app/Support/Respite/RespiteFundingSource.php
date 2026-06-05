<?php

namespace App\Support\Respite;

/**
 * Canonical NZ disability-respite funder list — current bodies/mechanisms only,
 * no Australian NDIS/DSS and no disestablished "DHB"/"MoH (disability)" labels.
 * Single source of truth for the intake picker, store validation and display.
 *
 * (The broader consolidation with fin_funding_streams.funder_type and the
 * Add-client wizard's FUNDING_OPTIONS is tracked in the NZ gap analysis.)
 */
class RespiteFundingSource
{
    /** @var array<string,string> stable key => display label */
    public const OPTIONS = [
        'whaikaha' => 'Whaikaha',
        'carer_support' => 'Carer Support',
        'nasc' => 'NASC-allocated',
        'egl_if' => 'EGL / Individualised Funding',
        'acc' => 'ACC',
        'te_whatu_ora' => 'Te Whatu Ora',
        'msd' => 'MSD',
        'private' => 'Private',
        'other' => 'Other',
    ];

    /** @return array<int,array{value:string,label:string}> */
    public static function options(): array
    {
        return collect(self::OPTIONS)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function label(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        return self::OPTIONS[$key] ?? $key;
    }
}
