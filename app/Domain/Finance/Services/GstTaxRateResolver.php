<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinTaxRate;
use InvalidArgumentException;

final class GstTaxRateResolver
{
    public function resolveInvoiceRate(
        int $organizationId,
        ?int $taxRateId,
        string $taxAmount,
        string $sourceLabel,
    ): ?FinTaxRate {
        if ($taxRateId !== null) {
            return $this->findOwnedRate($organizationId, $taxRateId, $sourceLabel);
        }

        if (bccomp($taxAmount, '0.00', 2) === 0) {
            return null;
        }

        $rates = FinTaxRate::query()
            ->where('organization_id', $organizationId)
            ->where('type', 'gst')
            ->where('is_default', true)
            ->get();

        if ($rates->isEmpty()) {
            return null;
        }

        if ($rates->count() !== 1) {
            throw new InvalidArgumentException(
                "{$sourceLabel} has GST but no unique default GST tax rate. Finance review is required."
            );
        }

        return $rates->first();
    }

    public function resolveStoredRate(
        int $organizationId,
        ?int $taxRateId,
        string $storedFraction,
        string $sourceLabel,
    ): ?FinTaxRate {
        if ($taxRateId !== null) {
            return $this->findOwnedRate($organizationId, $taxRateId, $sourceLabel);
        }

        $query = FinTaxRate::query()
            ->where('organization_id', $organizationId)
            ->where('rate', number_format((float) $storedFraction, 4, '.', ''));

        if (bccomp($storedFraction, '0.0000', 4) === 0) {
            // A stored numeric zero cannot distinguish zero-rated from exempt.
            // Resolve it only when the organisation has one unambiguous zero
            // classification; otherwise the source line needs explicit review.
            $query->whereIn('type', ['zero_rated', 'exempt']);
        } else {
            $query->where('type', 'gst');
        }

        $rates = $query->get();
        if ($rates->isEmpty()) {
            return null;
        }

        if ($rates->count() !== 1) {
            throw new InvalidArgumentException(
                "{$sourceLabel} does not map to one canonical GST tax rate. Finance review is required."
            );
        }

        return $rates->first();
    }

    public function matchInputRate(
        int $organizationId,
        ?int $taxRateId,
        string $percentage,
    ): ?FinTaxRate {
        if ($taxRateId !== null) {
            return $this->findOwnedRate($organizationId, $taxRateId, 'Tax selection');
        }

        $fraction = bcdiv($percentage, '100', 4);

        $rates = FinTaxRate::query()
            ->where('organization_id', $organizationId)
            ->where('rate', $fraction)
            ->when(
                bccomp($fraction, '0.0000', 4) === 0,
                fn ($query) => $query->whereIn('type', ['zero_rated', 'exempt']),
                fn ($query) => $query->where('type', 'gst'),
            )
            ->get();

        return $rates->count() === 1 ? $rates->first() : null;
    }

    private function findOwnedRate(
        int $organizationId,
        int $taxRateId,
        string $sourceLabel,
    ): FinTaxRate {
        $rate = FinTaxRate::query()
            ->where('organization_id', $organizationId)
            ->find($taxRateId);

        if ($rate === null) {
            throw new InvalidArgumentException(
                "{$sourceLabel} references a tax rate outside the organisation."
            );
        }

        return $rate;
    }
}
