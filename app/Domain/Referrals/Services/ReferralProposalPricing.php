<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Finance\Models\FinTaxRate;
use App\Services\CurrentAuthorizationReads;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Proposal estimates only: canonical finance owns agreements, consumption and claims. */
final class ReferralProposalPricing
{
    public function snapshot(array $line): array
    {
        $tax = $line['tax_rate_id'] ? FinTaxRate::query()->where('is_active', true)->lockForUpdate()->findOrFail($line['tax_rate_id']) : null;
        if ($tax) {
            abort_unless(in_array($tax->type, ['gst', 'zero_rated', 'exempt'], true) && BigDecimal::of($tax->rate)->compareTo('0') >= 0 && BigDecimal::of($tax->rate)->compareTo('1') <= 0, 422, 'Choose a supported active canonical tax classification.');
            abort_if(in_array($tax->type, ['zero_rated', 'exempt'], true) && ! BigDecimal::of($tax->rate)->isZero(), 422, 'The canonical tax classification and rate are inconsistent. Ask finance to review this tax rate.');
        }
        $snapshot = $tax ? ['id' => $tax->id, 'name' => $tax->name, 'code' => $tax->code, 'type' => $tax->type, 'rate' => $tax->rate] : null;

        return [...$line, 'tax' => $snapshot, 'estimate' => $this->estimate($line, $snapshot)];
    }

    public function estimate(array $line, ?array $tax): array
    {
        if ($line['quantity'] === null || $line['unit_rate'] === null || $tax === null || $line['price_basis'] === 'unknown') {
            return ['known' => false, 'net' => null, 'tax' => null, 'gross' => null];
        }
        $amount = BigDecimal::of($line['quantity'])->multipliedBy($line['unit_rate']);
        $rate = BigDecimal::of($tax['rate']);
        if ($line['price_basis'] === 'inclusive') {
            $gross = $amount->toScale(2, RoundingMode::HALF_UP);
            $net = $gross->dividedBy($rate->plus('1'), 2, RoundingMode::HALF_UP);
            $taxAmount = $gross->minus($net);
        } else {
            $net = $amount->toScale(2, RoundingMode::HALF_UP);
            $taxAmount = $net->multipliedBy($rate)->toScale(2, RoundingMode::HALF_UP);
            $gross = $net->plus($taxAmount);
        }

        return ['known' => true, 'net' => (string) $net, 'tax' => (string) $taxAmount, 'gross' => (string) $gross];
    }

    public function taxCurrent(array $line, ?CurrentAuthorizationReads $reads = null): bool
    {
        $snapshot = $line['tax'] ?? null;
        if (! $snapshot) {
            return false;
        }
        $query = FinTaxRate::query()->where('is_active', true)->whereKey($snapshot['id']);
        $rate = ($reads ? $reads->query($query) : $query)->first();

        return $rate && $rate->type === $snapshot['type'] && BigDecimal::of($rate->rate)->isEqualTo($snapshot['rate']);
    }

    /** Never combine currencies or different budget periods. */
    public function totals(array $lines): array
    {
        $totals = [];
        foreach ($lines as $line) {
            $key = $line['currency'].':'.$line['period'];
            $totals[$key] ??= ['currency' => $line['currency'], 'period' => $line['period'], 'known' => true, 'known_gross' => '0.00', 'unknown_lines' => 0];
            if ($line['estimate']['known']) {
                $totals[$key]['known_gross'] = (string) BigDecimal::of($totals[$key]['known_gross'])->plus($line['estimate']['gross'])->toScale(2);
            } else {
                $totals[$key]['known'] = false;
                $totals[$key]['unknown_lines']++;
            }
        }

        return array_values($totals);
    }
}
