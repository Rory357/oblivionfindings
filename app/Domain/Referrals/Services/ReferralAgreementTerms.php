<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralProposal;
use App\Models\ServiceAgreement;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/** Canonical operational agreements are NZD. Never infer FX or native-unit conversions. */
final class ReferralAgreementTerms
{
    public function snapshot(ServiceAgreement $agreement, bool $lock = false): array
    {
        $lines = $agreement->lineItems()->orderBy('id');
        $rates = $agreement->rates()->orderBy('id');
        if ($lock) {
            $lines->lockForUpdate();
            $rates->lockForUpdate();
        }

        return [
            'agreement' => Arr::except($agreement->getRawOriginal(), ['organization_id', 'budget_used', 'hours_used', 'carer_support_days_used', 'created_at', 'updated_at']),
            'lines' => $lines->get()->map(fn ($line) => Arr::except($line->getRawOriginal(), ['organization_id', 'budget_used', 'created_at', 'updated_at']))->all(),
            'rates' => $rates->get()->map(fn ($rate) => Arr::except($rate->getRawOriginal(), ['organization_id', 'created_at', 'updated_at']))->all(),
        ];
    }

    public function fingerprint(ServiceAgreement $agreement, bool $lock = false): string
    {
        return hash_hmac('sha256', json_encode(Arr::sortRecursive($this->snapshot($agreement, $lock)), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** Exact units/rates and explicit dated capacity; no invoice or consumption is created. */
    public function match(ReferralProposal $proposal, ServiceAgreement $agreement, array $mapping, bool $lock = false): array
    {
        $issues = [];
        $start = CarbonImmutable::parse($proposal->proposal['starts_on'], 'Pacific/Auckland');
        $end = CarbonImmutable::parse($proposal->proposal['ends_on'], 'Pacific/Auckland');
        if (! $agreement->starts_at || ! $agreement->ends_at || $agreement->starts_at->toDateString() > $start->toDateString() || $agreement->ends_at->toDateString() < $end->toDateString()) {
            $issues[] = 'The canonical agreement must cover the full proposal date range.';
        }
        $keys = array_column($proposal->proposal['lines'], 'key');
        $mappedKeys = array_column($mapping, 'line_key');
        sort($keys);
        sort($mappedKeys);
        if ($keys !== $mappedKeys) {
            $issues[] = 'Map each proposal line exactly once to an agreement line.';
        }
        $query = $agreement->lineItems()->orderBy('id');
        $items = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $mapped = collect($mapping)->keyBy('line_key');
        $requirements = [];
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($proposal->proposal['lines'] as $line) {
            $item = $items->get($mapped->get($line['key'])['line_item_id'] ?? 0);
            if (! $item) {
                $issues[] = $line['description'].': choose a line from this canonical agreement.';

                continue;
            }
            if ($line['currency'] !== 'NZD') {
                $issues[] = $line['description'].': operational agreements are recorded in NZD; finance must resolve the currency before a service commitment. No exchange rate is assumed.';
            }
            $unit = match ($line['unit']) {
                'hours' => 'hour', 'days' => 'day', 'visits' => 'visit', default => mb_strtolower(trim($line['unit_label'] ?? ''))
            };
            if ($unit !== $item->unit || $line['unit_rate'] === null || ! BigDecimal::of($line['unit_rate'])->isEqualTo((string) $item->unit_price)) {
                $issues[] = $line['description'].': the native unit and rate must match the agreement; review a mismatch with finance.';
            }
            if (($line['price_basis'] === 'inclusive') !== $agreement->gst_inclusive || ! ($line['estimate']['known'] ?? false)) {
                $issues[] = $line['description'].': confirm known costs and matching GST treatment.';

                continue;
            }
            $periods = $this->periods($start, $end, $line['period']);
            if ($periods === null || $line['quantity'] === null) {
                $issues[] = $line['description'].': the dated quantity needs finance review.';

                continue;
            }
            // Reserve full stated periods, including a final partial period. Never silently prorate.
            $quantity = BigDecimal::of($line['quantity'])->multipliedBy($periods);
            $cost = BigDecimal::of($line['estimate'][$agreement->gst_inclusive ? 'gross' : 'net'])->multipliedBy($periods);
            $requirements[$item->id] ??= ['quantity' => BigDecimal::zero(), 'cost' => BigDecimal::zero()];
            $requirements[$item->id]['quantity'] = $requirements[$item->id]['quantity']->plus($quantity);
            $requirements[$item->id]['cost'] = $requirements[$item->id]['cost']->plus($cost);
            $total = $total->plus($cost);
            $rows[] = ['line_key' => $line['key'], 'line_item_id' => $item->id, 'period' => $line['period'], 'periods' => $periods, 'quantity' => (string) $quantity, 'agreement_unit' => $unit, 'required_budget' => (string) $cost];
        }
        foreach ($requirements as $id => $required) {
            $item = $items[$id];
            if ($item->quantity === null || BigDecimal::of($item->quantity)->isLessThan($required['quantity'])
                || $item->budget_allocated === null || BigDecimal::of($item->budget_allocated)->minus($item->budget_used ?? '0')->isLessThan($required['cost'])) {
                $issues[] = $item->description.': agreement quantity or remaining budget is insufficient for the mapped proposal periods.';
            }
        }
        $lineUsage = $items->reduce(fn (BigDecimal $sum, $item) => $sum->plus($item->budget_used ?? '0'), BigDecimal::zero());
        $headerUsage = BigDecimal::of($agreement->budget_used ?? '0');
        $used = $lineUsage->isGreaterThan($headerUsage) ? $lineUsage : $headerUsage;
        if ($agreement->total_budget === null || BigDecimal::of($agreement->total_budget)->minus($used)->isLessThan($total)) {
            $issues[] = 'The remaining canonical agreement budget does not cover this proposal.';
        }

        return ['ready' => $issues === [], 'issues' => array_values(array_unique($issues)), 'mapping' => $rows,
            'required_budget' => (string) $total, 'currency' => 'NZD', 'budget_basis' => $agreement->gst_inclusive ? 'inclusive' : 'exclusive'];
    }

    /** Full stated periods in the proposal dates, including a final partial one; null when uncountable. */
    public function periods(CarbonImmutable $start, CarbonImmutable $end, string $period): ?int
    {
        if ($period === 'total') {
            return 1;
        }
        for ($count = 1; $count <= 2000; $count++) {
            $next = match ($period) {
                'week' => $start->addWeeks($count), 'fortnight' => $start->addWeeks($count * 2),
                'month' => $start->addMonthsNoOverflow($count), 'year' => $start->addYearsNoOverflow($count), default => null,
            };
            if (! $next) {
                return null;
            }
            if ($next->gt($end)) {
                return $count;
            }
        }

        return null;
    }
}
