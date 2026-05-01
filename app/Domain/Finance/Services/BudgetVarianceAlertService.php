<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\SiteBudgetLine;
use App\Domain\Finance\Notifications\BudgetVarianceAlertNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class BudgetVarianceAlertService
{
    public function dispatchForOrganization(?int $tenantId, ?Carbon $periodDate = null): int
    {
        if (
            ! Schema::hasTable('site_budget_lines')
            || ! Schema::hasColumn('site_budget_lines', 'last_alerted_at')
        ) {
            return 0;
        }

        $periodDate ??= now();
        $period = $periodDate->format('Y-m');
        $debounceCutoff = now()->subHours((int) config('finance.budget_variance_alerts.debounce_hours', 24));

        $query = SiteBudgetLine::query()
            ->with(['site.primaryContact', 'createdBy'])
            ->where('period', $period)
            ->where('planned_amount', '>', 0)
            ->where(function ($query) use ($debounceCutoff) {
                $query->whereNull('last_alerted_at')
                    ->orWhere('last_alerted_at', '<', $debounceCutoff);
            });

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $alerted = 0;

        foreach ($query->get() as $line) {
            $planned = (float) $line->planned_amount;
            $actual = $this->actualForLine($line);
            $utilizationPct = $planned > 0 ? ($actual / $planned) * 100 : 0.0;
            $alertLevel = $this->alertLevel($utilizationPct);

            if ($alertLevel === null) {
                continue;
            }

            $recipients = $this->recipientsFor($line);

            if ($recipients->isEmpty()) {
                continue;
            }

            $variancePct = $planned > 0 ? (($actual - $planned) / $planned) * 100 : 0.0;
            $notification = new BudgetVarianceAlertNotification(
                category: $line->getCategoryLabel(),
                budgetAmount: $planned,
                actualAmount: $actual,
                variancePct: $variancePct,
                alertLevel: $alertLevel,
                utilizationPct: $utilizationPct,
                siteName: $line->site?->name,
            );

            foreach ($recipients as $recipient) {
                $recipient->notify($notification);
            }

            $line->forceFill(['last_alerted_at' => now()])->save();
            $alerted++;
        }

        return $alerted;
    }

    private function actualForLine(SiteBudgetLine $line): float
    {
        $eventTypes = $line->getEventTypes();

        if ($eventTypes === []) {
            return 0.0;
        }

        $start = Carbon::createFromFormat('Y-m-d', $line->period.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return (float) FinCostAllocation::query()
            ->where('site_id', $line->site_id)
            ->whereIn('event_type', $eventTypes)
            ->whereBetween('event_date', [$start, $end])
            ->sum('amount');
    }

    private function alertLevel(float $utilizationPct): ?string
    {
        $overPct = (float) config('finance.insight_thresholds.budget_over_pct', 100);
        $approachingPct = (float) config('finance.insight_thresholds.budget_approaching_pct', 85);

        if ($utilizationPct >= $overPct) {
            return 'over_budget';
        }

        if ($utilizationPct >= $approachingPct) {
            return 'approaching_budget';
        }

        return null;
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(SiteBudgetLine $line): Collection
    {
        $recipients = collect([
            $line->site?->primaryContact,
            $line->createdBy,
        ])->filter();

        if (Schema::hasTable('role_user')) {
            $financeUsers = User::query()
                ->when(
                    Schema::hasColumn('users', 'organization_id') && $line->tenant_id !== null,
                    fn ($query) => $query->where('organization_id', $line->tenant_id),
                )
                ->whereHas('roles', fn ($query) => $query->whereIn('name', ['finance', 'admin']))
                ->get();

            $recipients = $recipients->merge($financeUsers);
        }

        return $recipients
            ->unique(fn (User $user) => $user->id)
            ->values();
    }
}
