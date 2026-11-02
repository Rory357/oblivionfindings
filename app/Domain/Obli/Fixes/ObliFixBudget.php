<?php

namespace App\Domain\Obli\Fixes;

use App\Domain\Obli\ObliAiSettings;
use Illuminate\Support\Facades\DB;

/**
 * Obli's daily and monthly spending limits (Settings › Obli) for fixes. The same budget rows Obli
 * chat uses: each AI call reserves a conservative amount before it is sent and settles to the
 * real token use afterwards, so fixes and chat share one limit. Prices are configured estimates.
 */
final class ObliFixBudget
{
    /** Bytes are an upper bound for tokens; a picture costs ⌈w/28⌉ × ⌈h/28⌉ tokens at most. */
    public static function estimate(int $textBytes, int $width = 0, int $height = 0): int
    {
        $image = $width > 0 && $height > 0 ? (int) (ceil($width / 28) * ceil($height / 28)) : 0;

        // Tools and the toolset add a few thousand tokens; 6,000 keeps the reservation on the safe side.
        return $textBytes + $image + 6000;
    }

    /**
     * Call inside a transaction, after locking the settings row. Returns the reservation, or null
     * when either limit would be passed (the caller then stops politely and offers an IT ticket).
     *
     * @param  array<string, mixed>  $policy  ObliAiSettings::current(true)
     * @return array{reserved: int, periods: list<string>, input_rate: int, output_rate: int}|null
     */
    public function reserve(array $policy, int $inputTokens): ?array
    {
        $inputRate = ObliAiSettings::micros((string) $policy['input_price_usd']);
        $outputRate = ObliAiSettings::micros((string) $policy['output_price_usd']);
        // The calls send at least 1,024 output tokens (ObliFixEngine), so reserve what they can spend.
        $reserve = ObliAiSettings::cost($inputTokens, max(1024, (int) $policy['max_output_tokens']), $inputRate, $outputRate);
        $periods = ['day:'.now('UTC')->format('Y-m-d'), 'month:'.now('UTC')->format('Y-m')];
        $limits = [ObliAiSettings::micros((string) $policy['daily_budget_usd']), ObliAiSettings::micros((string) $policy['monthly_budget_usd'])];
        foreach ($periods as $index => $period) {
            DB::table('obli_ai_budget_periods')->insertOrIgnore(['period' => $period, 'committed_micros' => 0]);
            $budget = DB::table('obli_ai_budget_periods')->where('period', $period)->lockForUpdate()->first();
            if ((int) $budget->committed_micros + $reserve > $limits[$index]) {
                return null;
            }
        }
        foreach ($periods as $period) {
            DB::table('obli_ai_budget_periods')->where('period', $period)->increment('committed_micros', $reserve);
        }

        return ['reserved' => $reserve, 'periods' => $periods, 'input_rate' => $inputRate, 'output_rate' => $outputRate];
    }

    /**
     * Settle a reservation to what was really used (or keep the full reservation when the answer
     * couldn't be confirmed). Call inside a transaction. Returns the amount charged.
     *
     * @param  list<string>  $periods
     */
    public function settle(array $periods, int $reserved, int $inputRate, int $outputRate, ?int $inputTokens, ?int $outputTokens): int
    {
        foreach ($periods as $period) {
            DB::table('obli_ai_budget_periods')->where('period', $period)->lockForUpdate()->first();
        }
        $cost = $inputTokens !== null && $outputTokens !== null ? ObliAiSettings::cost($inputTokens, $outputTokens, $inputRate, $outputRate) : $reserved;
        $delta = $cost - $reserved;
        if ($delta !== 0) {
            foreach ($periods as $period) {
                DB::table('obli_ai_budget_periods')->where('period', $period)->increment('committed_micros', $delta);
            }
        }

        return $cost;
    }
}
