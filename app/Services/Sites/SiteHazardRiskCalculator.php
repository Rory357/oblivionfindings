<?php

namespace App\Services\Sites;

class SiteHazardRiskCalculator
{
    /**
     * Risk matrix: Severity × Likelihood = Risk Rating
     */
    private const MATRIX = [
        'low' => [
            'rare' => 'low',
            'unlikely' => 'low',
            'possible' => 'medium',
            'likely' => 'medium',
            'almost_certain' => 'high',
        ],
        'medium' => [
            'rare' => 'low',
            'unlikely' => 'medium',
            'possible' => 'medium',
            'likely' => 'high',
            'almost_certain' => 'high',
        ],
        'high' => [
            'rare' => 'medium',
            'unlikely' => 'medium',
            'possible' => 'high',
            'likely' => 'high',
            'almost_certain' => 'extreme',
        ],
        'critical' => [
            'rare' => 'high',
            'unlikely' => 'high',
            'possible' => 'extreme',
            'likely' => 'extreme',
            'almost_certain' => 'extreme',
        ],
    ];

    public function calculate(string $severity, string $likelihood): string
    {
        return self::MATRIX[$severity][$likelihood] ?? 'medium';
    }

    /**
     * Determine if H&S Officer assignment is required
     */
    public function requiresAssignment(string $riskRating): bool
    {
        return in_array($riskRating, ['high', 'extreme']);
    }

    /**
     * Suggested due date based on risk rating
     */
    public function suggestedDueDays(string $riskRating): int
    {
        return match ($riskRating) {
            'extreme' => 1,
            'high' => 7,
            'medium' => 30,
            'low' => 90,
            default => 30,
        };
    }

    /**
     * Get all possible severity levels
     */
    public static function severities(): array
    {
        return ['low', 'medium', 'high', 'critical'];
    }

    /**
     * Get all possible likelihood levels
     */
    public static function likelihoods(): array
    {
        return ['rare', 'unlikely', 'possible', 'likely', 'almost_certain'];
    }

    /**
     * Get all possible risk ratings
     */
    public static function riskRatings(): array
    {
        return ['low', 'medium', 'high', 'extreme'];
    }
}
