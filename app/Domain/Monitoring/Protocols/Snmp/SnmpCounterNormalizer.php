<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

final class SnmpCounterNormalizer
{
    /**
     * @param  array<string, int|float|string|bool|null>  $previous
     * @return array{in_bps: ?int, out_bps: ?int, in_utilization_pct: ?float, out_utilization_pct: ?float, counter_discontinuity: bool}
     */
    public function rates(
        int $currentIn,
        int $currentOut,
        array $previous,
        int $observedUnix,
        int $uptimeTicks,
        int $discontinuityTicks,
        int $counterBits,
        int $speedBps,
    ): array {
        $previousIn = $this->integer($previous['counter_in_octets'] ?? null);
        $previousOut = $this->integer($previous['counter_out_octets'] ?? null);
        $previousBits = $this->integer($previous['counter_bits'] ?? null);
        $previousUptime = $this->integer($previous['uptime_ticks'] ?? null);
        $previousDiscontinuity = $this->integer($previous['counter_discontinuity_ticks'] ?? null);
        $previousObserved = $this->integer($previous['observed_unix'] ?? null);
        $elapsed = $previousObserved === null ? 0 : $observedUnix - $previousObserved;
        $discontinuous = $previousIn === null
            || $previousOut === null
            || $previousBits !== $counterBits
            || $previousUptime === null
            || $uptimeTicks < $previousUptime
            || $previousDiscontinuity === null
            || $discontinuityTicks !== $previousDiscontinuity
            || $elapsed < 1
            || $elapsed > 86_400;

        if ($discontinuous) {
            return $this->emptyRates(true);
        }

        $inDelta = $this->delta($currentIn, $previousIn, $counterBits);
        $outDelta = $this->delta($currentOut, $previousOut, $counterBits);
        if ($inDelta === null || $outDelta === null) {
            return $this->emptyRates(true);
        }

        $inBps = (int) round(($inDelta * 8) / $elapsed);
        $outBps = (int) round(($outDelta * 8) / $elapsed);

        return [
            'in_bps' => $inBps,
            'out_bps' => $outBps,
            'in_utilization_pct' => $speedBps > 0 ? round(min(100, ($inBps / $speedBps) * 100), 3) : null,
            'out_utilization_pct' => $speedBps > 0 ? round(min(100, ($outBps / $speedBps) * 100), 3) : null,
            'counter_discontinuity' => false,
        ];
    }

    private function delta(int $current, int $previous, int $bits): ?int
    {
        if ($current >= $previous) {
            return $current - $previous;
        }

        if ($bits === 32) {
            return (4_294_967_296 - $previous) + $current;
        }

        // PHP integers cannot represent the unsigned 64-bit rollover point.
        // Treat the rare wrap as a discontinuity rather than inventing a rate.
        return null;
    }

    /** @return array{in_bps: null, out_bps: null, in_utilization_pct: null, out_utilization_pct: null, counter_discontinuity: bool} */
    private function emptyRates(bool $discontinuous): array
    {
        return [
            'in_bps' => null,
            'out_bps' => null,
            'in_utilization_pct' => null,
            'out_utilization_pct' => null,
            'counter_discontinuity' => $discontinuous,
        ];
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
