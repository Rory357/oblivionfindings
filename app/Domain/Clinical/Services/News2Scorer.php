<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\Acvpu;
use App\Domain\Clinical\Enums\News2Band;

/**
 * Royal College of Physicians National Early Warning Score 2 (NEWS2) scorer.
 *
 * Implements the full NEWS2 chart: respiratory rate, SpO₂ (Scale 1 default, plus
 * the Scale 2 chart for patients with a target range of 88–92%), air-or-oxygen,
 * systolic BP, pulse, ACVPU consciousness and temperature. The aggregate band
 * accounts for the single-parameter red flag (any parameter scoring 3 elevates a
 * low aggregate to "Low-medium").
 *
 * Reads the canonical vitals `data` JSON keys (`respiration_rate`,
 * `o2_saturation`, `systolic`, `pulse`, `temperature`) plus the NEWS2-specific
 * additions (`consciousness` = ACVPU value, `on_oxygen` bool, `spo2_scale` 1|2).
 * Returns null when the measured vitals are insufficient for a valid NEWS2.
 */
class News2Scorer
{
    /**
     * @param  array<string, mixed>  $data  the vitals observation `data` payload
     */
    public function score(array $data): ?News2Result
    {
        $respiratoryRate = $this->intOrNull($data['respiration_rate'] ?? null);
        $spo2 = $this->intOrNull($data['o2_saturation'] ?? null);
        $systolic = $this->intOrNull($data['systolic'] ?? null);
        $pulse = $this->intOrNull($data['pulse'] ?? null);
        $temperature = $this->floatOrNull($data['temperature'] ?? null);

        // NEWS2 requires the full physiological set; without it there is no valid score.
        if ($respiratoryRate === null || $spo2 === null || $systolic === null || $pulse === null || $temperature === null) {
            return null;
        }

        $onOxygen = (bool) ($data['on_oxygen'] ?? false);
        $scale = ((int) ($data['spo2_scale'] ?? 1)) === 2 ? 2 : 1;
        $consciousness = Acvpu::tryFrom((string) ($data['consciousness'] ?? Acvpu::Alert->value)) ?? Acvpu::Alert;

        $breakdown = [
            'respiratory_rate' => $this->scoreRespiratoryRate($respiratoryRate),
            'spo2' => $this->scoreSpo2($spo2, $scale, $onOxygen),
            'air_or_oxygen' => $onOxygen ? 2 : 0,
            'systolic' => $this->scoreSystolic($systolic),
            'pulse' => $this->scorePulse($pulse),
            'consciousness' => $consciousness->news2Points(),
            'temperature' => $this->scoreTemperature($temperature),
        ];

        $total = array_sum($breakdown);
        $redFlag = in_array(3, $breakdown, true);

        return new News2Result($total, $this->band($total, $redFlag), $redFlag, $breakdown);
    }

    private function scoreRespiratoryRate(int $rr): int
    {
        return match (true) {
            $rr <= 8 => 3,
            $rr <= 11 => 1,
            $rr <= 20 => 0,
            $rr <= 24 => 2,
            default => 3,
        };
    }

    private function scoreSpo2(int $spo2, int $scale, bool $onOxygen): int
    {
        if ($scale === 2) {
            // Scale 2 — target range 88–92% (e.g. hypercapnic respiratory failure).
            if ($onOxygen) {
                return match (true) {
                    $spo2 <= 83 => 3,
                    $spo2 <= 85 => 2,
                    $spo2 <= 87 => 1,
                    $spo2 <= 92 => 0,
                    $spo2 <= 94 => 1,
                    $spo2 <= 96 => 2,
                    default => 3,
                };
            }

            // On air: ≥93 scores 0; below the target range scores as the lower bands.
            return match (true) {
                $spo2 <= 83 => 3,
                $spo2 <= 85 => 2,
                $spo2 <= 87 => 1,
                default => 0,
            };
        }

        // Scale 1 (default).
        return match (true) {
            $spo2 <= 91 => 3,
            $spo2 <= 93 => 2,
            $spo2 <= 95 => 1,
            default => 0,
        };
    }

    private function scoreSystolic(int $sys): int
    {
        return match (true) {
            $sys <= 90 => 3,
            $sys <= 100 => 2,
            $sys <= 110 => 1,
            $sys <= 219 => 0,
            default => 3,
        };
    }

    private function scorePulse(int $pulse): int
    {
        return match (true) {
            $pulse <= 40 => 3,
            $pulse <= 50 => 1,
            $pulse <= 90 => 0,
            $pulse <= 110 => 1,
            $pulse <= 130 => 2,
            default => 3,
        };
    }

    private function scoreTemperature(float $temp): int
    {
        return match (true) {
            $temp <= 35.0 => 3,
            $temp <= 36.0 => 1,
            $temp <= 38.0 => 0,
            $temp <= 39.0 => 1,
            default => 2,
        };
    }

    private function band(int $total, bool $redFlag): News2Band
    {
        return match (true) {
            $total >= 7 => News2Band::High,
            $total >= 5 => News2Band::Medium,
            $redFlag => News2Band::LowMedium,
            default => News2Band::Low,
        };
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
