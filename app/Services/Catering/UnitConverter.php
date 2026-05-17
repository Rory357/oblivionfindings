<?php

namespace App\Services\Catering;

class UnitConverter
{
    /**
     * Canonical units we normalise to internally:
     * - mass: grams (g)
     * - volume: millilitres (ml)
     * - count: each
     */
    private const MASS = ['g' => 1, 'kg' => 1000, 'mg' => 0.001];
    private const VOLUME = ['ml' => 1, 'l' => 1000, 'cl' => 10];
    private const COUNT = ['each' => 1, 'pack' => 1, 'tin' => 1, 'bottle' => 1, 'box' => 1];

    /**
     * Convert qty in $fromUnit to $toUnit. If both units are different
     * dimensions, attempts to use $packSize/$packUnit to bridge them
     * (e.g. 1 "each" of milk where pack is 2L → 2000 ml).
     */
    public function convert(
        float $qty,
        string $fromUnit,
        string $toUnit,
        ?float $packSize = null,
        ?string $packUnit = null,
    ): ?float {
        $from = strtolower(trim($fromUnit));
        $to = strtolower(trim($toUnit));

        if ($from === $to) {
            return $qty;
        }

        $direct = $this->within($qty, $from, $to);
        if ($direct !== null) {
            return $direct;
        }

        if ($packSize !== null && $packUnit !== null) {
            $packUnitLower = strtolower($packUnit);
            if ($from === 'each' || isset(self::COUNT[$from])) {
                $asPack = $qty * $packSize;
                return $this->within($asPack, $packUnitLower, $to) ?? ($packUnitLower === $to ? $asPack : null);
            }
            if ($to === 'each' || isset(self::COUNT[$to])) {
                $inPackUnit = $this->within($qty, $from, $packUnitLower) ?? ($from === $packUnitLower ? $qty : null);
                if ($inPackUnit !== null && $packSize > 0) {
                    return $inPackUnit / $packSize;
                }
            }
        }

        return null;
    }

    private function within(float $qty, string $from, string $to): ?float
    {
        foreach ([self::MASS, self::VOLUME, self::COUNT] as $table) {
            if (isset($table[$from]) && isset($table[$to])) {
                return $qty * $table[$from] / $table[$to];
            }
        }
        return null;
    }
}
