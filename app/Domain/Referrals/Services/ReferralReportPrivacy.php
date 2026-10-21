<?php

namespace App\Domain\Referrals\Services;

/**
 * The small-group rule for referral reports and summary exports (specification §15: "suppress small
 * or identifying aggregate groups"). Configured in config/referral-reports.php, not referral settings.
 *
 * - A count from 1 to N-1 is hidden and marked `small` ("Fewer than N"). Zero stays zero.
 * - In a breakdown, the next-smallest groups are also hidden and marked `paired` ("Hidden") until no
 *   hidden small group can be worked out from the total: at least two groups are hidden, their sum is
 *   at least N, and that sum doesn't pin any small group to one value. A paired group is at least N.
 * - Free-text reported regions that are hidden are combined into one "Other reported regions" row,
 *   because the region name itself can identify someone.
 * - It applies to every viewer. Exact counts are only in the record list and the records export, which
 *   show individual referrals under the same record access as the referral pages.
 */
final class ReferralReportPrivacy
{
    public function threshold(): int
    {
        return max(1, min(100, (int) config('referral-reports.small_group_threshold', 5)));
    }

    public function label(): string
    {
        return 'Fewer than '.$this->threshold();
    }

    public function pairedLabel(): string
    {
        return 'Hidden';
    }

    /** What the report shows instead of a hidden number; exports print the same words. */
    public function display(mixed $value, mixed $suppressed): mixed
    {
        return match ($suppressed) {
            'small' => $this->label(),
            'paired' => $this->pairedLabel(),
            default => $value,
        };
    }

    /** A mean or total drawn from too few referrals: never shown as a number of hours. */
    public function hiddenMeasure(): string
    {
        return 'Not shown: fewer than '.$this->threshold().' referrals';
    }

    public function hides(mixed $count): bool
    {
        return is_int($count) && $count > 0 && $count < $this->threshold();
    }

    /** The whole report as people may see it: every small group hidden, totals left exact. */
    public function protect(array $report): array
    {
        $report['metrics'] = array_map(fn (array $metric) => $this->hides($metric['value'])
            ? [...$metric, 'value' => null, 'suppressed' => 'small'] : $metric, $report['metrics']);
        $report['trend'] = $this->groups($this->groups($report['trend'], 'received', 'received_suppressed'), 'contacted', 'contacted_suppressed');
        foreach ($report['delays'] as $type => $delay) {
            if ($this->hides($delay['count'])) {
                $report['delays'][$type] = [...$delay, 'count' => null, 'hours' => null, 'mean_hours' => null, 'suppressed' => 'small'];
            }
        }
        foreach ($report['groups'] as $name => $rows) {
            $report['groups'][$name] = $name === 'region' ? $this->regions($rows) : $this->groups($rows);
        }
        if ($report['funding'] !== null) {
            $report['funding'] = $this->funding($report['funding']);
        }
        if (! empty($report['working_time'])) {
            $report['working_time'] = array_map(fn (array $row) => $this->measure($row), $report['working_time']);
        }
        if (! empty($report['history'])) {
            foreach (['stages', 'holds'] as $kind) {
                $report['history'][$kind] = array_map(fn (array $row) => $this->measure($row), $report['history'][$kind]);
            }
            foreach (['reopenings', 'reopened_referrals', 'incomplete_referrals', 'referrals'] as $key) {
                if ($this->hides($report['history'][$key])) {
                    $report['history'][$key] = null;
                    $report['history'][$key.'_suppressed'] = 'small';
                }
            }
        }
        $report['privacy'] = [
            'threshold' => $this->threshold(),
            'label' => $this->label(),
            'paired_label' => $this->pairedLabel(),
            'active' => $this->threshold() > 1,
        ];
        $report['definitions']['privacy'] = $this->threshold() > 1
            ? 'Groups of fewer than '.$this->threshold().' referrals show as “'.$this->label().'”, and one more group may show as “Hidden”, so nobody can be picked out or worked out from a total. Zero is shown as zero. The matching referrals list shows the records you can open.'
            : 'Small groups are shown in full under the current privacy setting.';

        return $report;
    }

    /**
     * Primary and secondary suppression for one breakdown. Only hidden rows gain the flag, so a report
     * with nothing hidden is unchanged.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function groups(array $rows, string $key = 'count', string $flag = 'suppressed'): array
    {
        foreach ($this->hidden($rows, $key) as $index => $reason) {
            $rows[$index][$key] = null;
            $rows[$index][$flag] = $reason;
        }

        return $rows;
    }

    /**
     * Which rows to hide, and why. Secondary rows are the next-smallest non-zero groups, added until
     * two or more rows are hidden and the hidden total leaves every small group its full 1..N-1 range.
     *
     * @return array<int, 'small'|'paired'>
     */
    private function hidden(array $rows, string $key): array
    {
        $small = [];
        foreach ($rows as $index => $row) {
            if ($this->hides($row[$key] ?? null)) {
                $small[$index] = (int) $row[$key];
            }
        }
        if ($small === []) {
            return [];
        }
        $paired = [];
        while (! $this->settled($small, $paired)) {
            $next = null;
            foreach ($rows as $index => $row) {
                $count = $row[$key] ?? null;
                if (isset($small[$index]) || isset($paired[$index]) || ! is_int($count) || $count < 1) {
                    continue;
                }
                if ($next === null || $count < $rows[$next][$key]) {
                    $next = $index;
                }
            }
            if ($next === null) {
                break;
            }
            $paired[$next] = (int) $rows[$next][$key];
        }

        return array_map(fn () => 'small', $small) + array_map(fn () => 'paired', $paired);
    }

    /**
     * Whether the hidden rows are safe against subtraction from a known total. Small rows are 1..N-1 and
     * paired rows are at least N, so a reader who knows the total knows only the hidden sum. Settled when
     * two or more rows are hidden, their sum is at least N, and that sum doesn't pin the small rows
     * (all at 1, or all at N-1 when no larger row is hidden with them).
     *
     * @param  array<int, int>  $small
     * @param  array<int, int>  $paired
     */
    private function settled(array $small, array $paired): bool
    {
        $n = $this->threshold();
        $p = count($small);
        $q = count($paired);
        $sum = array_sum($small) + array_sum($paired);
        if ($p + $q < 2) {
            return false;
        }
        if ($n <= 2) {
            return true;
        }
        if ($sum < $n) {
            return false;
        }

        return $q === 0
            ? $sum > $p && $sum < $p * ($n - 1)
            : min($p * ($n - 1), $sum - $q * $n) > $p;
    }

    /** Hidden reported regions are combined: a region name typed at intake can itself identify someone. */
    private function regions(array $rows): array
    {
        $hidden = array_filter($this->hidden($rows, 'count'), fn ($reason, $index) => $rows[$index]['key'] !== 'not_recorded', ARRAY_FILTER_USE_BOTH);
        if ($hidden === []) {
            return $this->groups($rows);
        }
        $combined = ['key' => 'other_regions', 'label' => 'Other reported regions', 'count' => 0, 'regions' => count($hidden)];
        foreach (array_keys($hidden) as $index) {
            $combined['count'] += (int) $rows[$index]['count'];
            unset($rows[$index]);
        }

        return $this->groups([...array_values($rows), $combined]);
    }

    private function funding(array $funding): array
    {
        if ($this->hides($funding['cases'])) {
            $funding['cases'] = null;
            $funding['cases_suppressed'] = 'small';
        }
        // Provider and personal-income cases split the case total, so they are one breakdown.
        [$provider, $income] = $this->groups([['count' => $funding['provider_cases']], ['count' => $funding['income_cases']]]);
        foreach (['provider_cases' => $provider, 'income_cases' => $income] as $key => $row) {
            $funding[$key] = $row['count'];
            if (isset($row['suppressed'])) {
                $funding[$key.'_suppressed'] = $row['suppressed'];
            }
        }
        foreach (['by_pathway', 'by_status', 'outcomes'] as $group) {
            $funding[$group] = $this->groups($funding[$group]);
        }
        foreach ($funding['pending_age'] ?? [] as $kind => $rows) {
            $funding['pending_age'][$kind] = $this->groups($rows);
        }

        return $funding;
    }

    /** A time measure: its counts and the means or totals drawn from too few referrals are hidden. */
    private function measure(array $row): array
    {
        if (array_key_exists('count', $row) && $this->hides($row['count'])) {
            $row['count'] = null;
            $row['suppressed'] = 'small';
        }
        foreach (['elapsed', 'working'] as $clock) {
            if (isset($row[$clock]) && $this->hides($row[$clock]['count'])) {
                $row[$clock] = array_map(fn () => null, $row[$clock]);
                $row[$clock]['suppressed'] = 'small';
            }
        }
        foreach (['unknown_dates', 'unknown_calendar'] as $key) {
            if ($this->hides($row[$key] ?? null)) {
                $row[$key] = null;
                $row[$key.'_suppressed'] = 'small';
            }
        }

        return $row;
    }
}
