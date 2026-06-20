<?php

namespace App\Domain\Clinical\Enums;

/**
 * The standardised clinical risk-assessment tools supported by the
 * Assessments & Risk register. NZ supported-living defaults:
 *
 *  - falls_frat      — Falls Risk Assessment Tool (Peninsula Health FRAT), the
 *                      common AU/NZ aged & disability falls screen.
 *  - pressure_braden — Braden Scale for Predicting Pressure Sore Risk (aligned
 *                      with the NZ Health Quality & Safety Commission pressure
 *                      injury programme; cleaner sub-scale scoring than Waterlow).
 *  - malnutrition_must — Malnutrition Universal Screening Tool (BAPEN MUST).
 *  - dysphagia_iddsi — IDDSI framework level capture (NZ Speech-language Therapy
 *                      Association-adopted dysphagia texture standard). A
 *                      classification, not a risk score.
 *
 * Each maps to a scorer/classifier in Services\Assessments. The computed score
 * is transparent (full component breakdown) and clinician-signed — an aid to,
 * not a replacement for, clinical judgement.
 */
enum ClinicalAssessmentType: string
{
    case FallsFrat = 'falls_frat';
    case PressureBraden = 'pressure_braden';
    case MalnutritionMust = 'malnutrition_must';
    case DysphagiaIddsi = 'dysphagia_iddsi';

    public function label(): string
    {
        return match ($this) {
            self::FallsFrat => 'Falls risk (FRAT)',
            self::PressureBraden => 'Pressure injury (Braden)',
            self::MalnutritionMust => 'Malnutrition (MUST)',
            self::DysphagiaIddsi => 'Dysphagia (IDDSI)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::FallsFrat => 'FRAT',
            self::PressureBraden => 'Braden',
            self::MalnutritionMust => 'MUST',
            self::DysphagiaIddsi => 'IDDSI',
        };
    }

    public function domain(): string
    {
        return match ($this) {
            self::FallsFrat => 'Falls',
            self::PressureBraden => 'Pressure injury',
            self::MalnutritionMust => 'Nutrition',
            self::DysphagiaIddsi => 'Swallowing',
        };
    }

    /** The published tool version/source cited in the UI and stored with each record. */
    public function toolVersion(): string
    {
        return match ($this) {
            self::FallsFrat => 'Peninsula Health FRAT v4',
            self::PressureBraden => 'Braden Scale (1988)',
            self::MalnutritionMust => 'BAPEN MUST (2003)',
            self::DysphagiaIddsi => 'IDDSI Framework 2.0 (2019)',
        };
    }

    /** Whether the tool yields a numeric total + risk band (FRAT/Braden/MUST) vs a level classification (IDDSI). */
    public function isScored(): bool
    {
        return $this !== self::DysphagiaIddsi;
    }

    /** Default review interval (days) — when the next assessment falls due. */
    public function reviewIntervalDays(): int
    {
        return match ($this) {
            self::FallsFrat => 90,
            self::PressureBraden => 30,
            self::MalnutritionMust => 30,
            self::DysphagiaIddsi => 180,
        };
    }

    /** @return list<self> */
    public static function scored(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isScored()));
    }
}
