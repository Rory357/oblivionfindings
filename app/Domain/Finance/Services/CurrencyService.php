<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCurrency;
use App\Domain\Finance\Models\FinFxRate;
use Illuminate\Support\Facades\Log;

class CurrencyService
{
    /**
     * Get the exchange rate between two currencies for a given date.
     * Falls back to the currency table's stored exchange_rate if no FX rate record is found.
     */
    public function getExchangeRate(?int $orgId, int $fromCurrencyId, int $toCurrencyId, ?string $date = null): float
    {
        // Same currency — rate is always 1
        if ($fromCurrencyId === $toCurrencyId) {
            return 1.0;
        }

        $date = $date ?? now()->toDateString();

        // Try to find a specific FX rate record
        $fxRate = FinFxRate::forOrganization($orgId)
            ->forPair($fromCurrencyId, $toCurrencyId)
            ->latestForDate($date)
            ->first();

        if ($fxRate) {
            return (float) $fxRate->rate;
        }

        // Try the reverse pair and invert
        $reverseFxRate = FinFxRate::forOrganization($orgId)
            ->forPair($toCurrencyId, $fromCurrencyId)
            ->latestForDate($date)
            ->first();

        if ($reverseFxRate && (float) $reverseFxRate->rate > 0) {
            return 1.0 / (float) $reverseFxRate->rate;
        }

        // Fallback: use the exchange_rate stored on each currency (both relative to NZD base)
        $fromCurrency = FinCurrency::find($fromCurrencyId);
        $toCurrency = FinCurrency::find($toCurrencyId);

        if (! $fromCurrency || ! $toCurrency) {
            return 1.0;
        }

        // Both rates are "1 unit of this currency = X NZD"
        // If from is NZD (rate 1.0) and to is AUD (rate 0.92),
        // then 1 NZD = 1/0.92 AUD — but we want "from -> to" so:
        // If from_rate = how many NZD per 1 FROM, to_rate = how many NZD per 1 TO
        // Then from->to rate = from_rate / to_rate
        $fromRate = (float) $fromCurrency->exchange_rate;
        $toRate = (float) $toCurrency->exchange_rate;

        if ($toRate <= 0) {
            return 1.0;
        }

        return $fromRate / $toRate;
    }

    /**
     * Convert an amount in a foreign currency to the base currency (NZD).
     *
     * @return array{base_amount: float, rate: float}
     */
    public function convertToBase(?int $orgId, float $amount, int $currencyId, ?string $date = null): array
    {
        $baseCurrency = FinCurrency::forOrganization($orgId)->base()->first();

        if (! $baseCurrency) {
            // No base currency configured — return amount as-is
            return ['base_amount' => $amount, 'rate' => 1.0];
        }

        if ($baseCurrency->id === $currencyId) {
            return ['base_amount' => $amount, 'rate' => 1.0];
        }

        $rate = $this->getExchangeRate($orgId, $currencyId, $baseCurrency->id, $date);
        $baseAmount = round($amount * $rate, 2);

        return [
            'base_amount' => $baseAmount,
            'rate' => $rate,
        ];
    }

    /**
     * Placeholder for fetching latest exchange rates from an external API.
     * Currently just logs that it was called.
     */
    public function fetchLatestRates(?int $orgId): void
    {
        Log::info('CurrencyService::fetchLatestRates called', [
            'organization_id' => $orgId,
            'message' => 'External rate fetch not yet implemented. Rates must be updated manually.',
        ]);
    }
}
