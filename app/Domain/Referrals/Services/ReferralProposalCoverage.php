<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralProposal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ReferralProposalCoverage
{
    public function __construct(private readonly ReferralFundingDecisionService $funding) {}

    /** Allocations retain source units and explicitly identify which support quantity they cover. */
    public function inspect(CareReferral $referral, array $proposal, bool $includeInternal = true): array
    {
        $issues = [];
        $byLine = [];
        $sourceTotals = [];
        $sources = [];
        $lines = array_column($proposal['lines'], null, 'key');
        foreach ($proposal['allocations'] as $allocation) {
            $line = $lines[$allocation['line_key']] ?? null;
            if (! $line) {
                $issues[] = 'An allocation no longer matches a support line.';

                continue;
            }
            $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->find($allocation['funding_case_id']);
            $decision = $case ? ReferralFundingDecision::query()->where('funding_case_id', $case->id)->find($allocation['decision_id']) : null;
            $award = $decision?->decision['lines'][$allocation['decision_line']] ?? null;
            $prefix = $line['description'].': ';
            if (! $case || ! $decision || ! $award || $case->personal_income) {
                $issues[] = $prefix.'a provider funding source is missing or incompatible.';

                continue;
            }
            $sourceKey = $decision->id.':'.$allocation['decision_line'];
            $sourceTotals[$sourceKey] = ($sourceTotals[$sourceKey] ?? BigDecimal::zero())->plus($allocation['quantity']);
            $sources[$sourceKey] = $award;
            $valid = true;
            if (($case->details['latest_decision_id'] ?? null) !== $decision->id) {
                $issues[] = $prefix.'the funding decision has been replaced.';
                $valid = false;
            }
            foreach ([$proposal['starts_on'], $proposal['ends_on']] as $date) {
                $availability = $this->funding->availability($case, $line['service_id'], $date);
                if (! $availability['usable']) {
                    $valid = false;
                    foreach ($availability['issues'] as $issue) {
                        $issues[] = $prefix.$issue;
                    }
                }
            }
            if ($award['service_id'] !== $line['service_id'] || $award['period'] !== $line['period']) {
                $issues[] = $prefix.'source service and budget period must match; no period conversion is assumed.';
                $valid = false;
            }
            $quantity = BigDecimal::of($allocation['quantity']);
            $support = BigDecimal::of($allocation['support_quantity']);
            if ($award['unit'] === 'money') {
                if ($award['currency'] !== $line['currency'] || ! $line['estimate']['known'] || $line['quantity'] === null || BigDecimal::of($line['quantity'])->isZero()) {
                    $issues[] = $prefix.'a monetary allocation needs a costed line in the same currency.';
                    $valid = false;
                } else {
                    // An explicit costed line permits the money-to-support comparison; never infer a rate.
                    $required = BigDecimal::of($line['estimate']['gross'])->multipliedBy($support)->dividedBy($line['quantity'], 2, RoundingMode::HALF_UP);
                    if (! $quantity->isEqualTo($required)) {
                        $issues[] = $prefix.'the monetary allocation does not match the support quantity at this line’s recorded price.';
                        $valid = false;
                    }
                }
            } elseif ($award['unit'] !== $line['unit'] || ($award['unit'] === 'other' && $award['unit_label'] !== $line['unit_label']) || ! $quantity->isEqualTo($support)) {
                $issues[] = $prefix.'native funding units must match the proposed support units.';
                $valid = false;
            }
            if ($valid) {
                $byLine[$line['key']] = ($byLine[$line['key']] ?? BigDecimal::zero())->plus($support);
            }
        }
        foreach ($sourceTotals as $key => $total) {
            $limit = $sources[$key]['provider_quantity'];
            if ($limit === null || $total->isGreaterThan($limit)) {
                $issues[] = 'Allocations exceed an available provider award, or its limit is unknown.';
            }
        }
        $internal = null;
        if ($includeInternal) {
            // Only an exact persisted proposal may use its own company commitment.
            $record = ReferralProposal::where('referral_id', $referral->id)->orderByDesc('version')->first();
            if ($record && $record->proposal === $proposal) {
                $service = app(ReferralInternalFunding::class);
                $arrangement = $service->arrangement($record);
                if ($arrangement) {
                    $availability = $service->availability($referral, $arrangement);
                    $internal = ['id' => $arrangement->id, 'payer' => $arrangement->terms['payer'], ...$availability];
                    $issues = [...$issues, ...$availability['issues']];
                    if ($availability['usable']) {
                        foreach ($arrangement->terms['allocations'] as $allocation) {
                            $byLine[$allocation['line_key']] = ($byLine[$allocation['line_key']] ?? BigDecimal::zero())->plus($allocation['support_quantity']);
                        }
                    }
                }
            }
        }
        $coverage = [];
        foreach ($lines as $line) {
            $covered = $byLine[$line['key']] ?? BigDecimal::zero();
            $requested = $line['quantity'] === null ? null : BigDecimal::of($line['quantity']);
            if ($requested === null) {
                $issues[] = $line['description'].': the proposed quantity is unknown.';
            } elseif ($covered->isLessThan($requested)) {
                $issues[] = $line['description'].': part of the proposed support remains unfunded.';
            } elseif ($covered->isGreaterThan($requested)) {
                $issues[] = $line['description'].': allocations overlap or exceed the proposed support.';
            }
            $coverage[] = ['line_key' => $line['key'], 'covered_quantity' => (string) $covered, 'requested_quantity' => $line['quantity'], 'unit' => $line['unit'], 'period' => $line['period']];
        }

        return ['usable' => $issues === [], 'issues' => array_values(array_unique($issues)), 'lines' => $coverage, 'internal_arrangement' => $internal];
    }
}
