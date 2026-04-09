<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Finance\Services\FinancialEventService;
use App\Models\SiteUtility;
use App\Models\SiteUtilityActual;
use App\Models\SiteUtilityPosting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Monthly job: post utility costs for all active site utilities.
 *
 * Posting logic:
 *   1. Check if an actual has been recorded for this period (site_utility_actuals).
 *   2. If actual exists → post actual.
 *   3. If no actual → post estimate.
 *   4. Record what was posted in site_utility_postings for true-up tracking.
 *   5. If an estimate was previously posted and an actual now exists → true-up.
 *
 * Schedule: monthly on the 1st at 02:30 NZT.
 */
class PostSiteUtilitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $periodMonth = null,
    ) {}

    public function handle(FinancialEventService $service): void
    {
        $period = $this->periodMonth
            ? Carbon::parse($this->periodMonth . '-01')
            : Carbon::now()->subMonthNoOverflow()->startOfMonth();

        $periodStr = $period->format('Y-m');
        $eventDate = $period->endOfMonth()->toDateString();

        $utilities = SiteUtility::query()
            ->active()
            ->with('site')
            ->get();

        $posted = 0;
        $trueUps = 0;

        foreach ($utilities as $utility) {
            try {
                $site = $utility->site;
                if (! $site || ! $site->is_active) {
                    continue;
                }

                $orgId = $utility->tenant_id;
                if (! $orgId) {
                    continue;
                }

                // Check if an actual exists for this period
                $actual = SiteUtilityActual::where('site_utility_id', $utility->id)
                    ->forPeriod($periodStr)
                    ->first();

                // Check what was previously posted
                $previousPosting = SiteUtilityPosting::latestFor($utility->id, $periodStr);

                // Already posted an actual → nothing to do
                if ($previousPosting && $previousPosting->posting_type === 'actual') {
                    continue;
                }

                if ($actual && bccomp((string) $actual->amount, '0', 2) > 0) {
                    if ($previousPosting && $previousPosting->posting_type === 'estimate') {
                        // TRUE-UP: estimate was posted, actual now available → post delta
                        $this->postTrueUp($service, $utility, $site, $orgId, $previousPosting, $actual, $periodStr, $eventDate);
                        $trueUps++;
                    } else {
                        // First post for this period, and we have an actual
                        $this->postCost($service, $utility, $site, $orgId, (string) $actual->amount, 'actual', $periodStr, $eventDate);
                        $posted++;
                    }
                } elseif (! $previousPosting) {
                    // No actual, no previous posting → post estimate
                    $estimate = (string) $utility->monthly_estimate;
                    if (bccomp($estimate, '0', 2) > 0) {
                        $this->postCost($service, $utility, $site, $orgId, $estimate, 'estimate', $periodStr, $eventDate);
                        $posted++;
                    }
                }
                // If no actual and estimate already posted → nothing to do
            } catch (\Throwable $e) {
                Log::error("PostSiteUtilitiesJob: Failed for utility #{$utility->id} ({$utility->type}): {$e->getMessage()}");
            }
        }

        Log::info("PostSiteUtilitiesJob: Period {$periodStr} — posted {$posted}, true-ups {$trueUps}.");
    }

    private function postCost(
        FinancialEventService $service,
        SiteUtility $utility,
        $site,
        int $orgId,
        string $amount,
        string $postingType,
        string $periodStr,
        string $eventDate,
    ): void {
        $accountConfig = config('finance.event_accounts.site_utilities_expense');
        $label = $postingType === 'actual' ? '[actual]' : '[estimate]';

        $event = $service->record([
            'organization_id' => $orgId,
            'source_type' => SiteUtility::class,
            'source_id' => $utility->id,
            'event_type' => 'site_utilities_expense',
            'description' => ucfirst($utility->type) . ": {$site->name} — {$periodStr}"
                . ($utility->provider ? " ({$utility->provider})" : '')
                . " {$label}",
            'amount' => $amount,
            'event_date' => $eventDate,
            'debit_account_code' => $accountConfig['debit'],
            'payment_type' => FinFinancialEvent::PAYMENT_AP,
            'journal_type' => $accountConfig['journal_type'] ?? 'standard',
            'site_id' => $utility->site_id,
            'source_updated_at' => "{$periodStr}:{$postingType}:{$amount}",
        ]);

        SiteUtilityPosting::create([
            'site_utility_id' => $utility->id,
            'period' => $periodStr,
            'posting_type' => $postingType,
            'amount' => $amount,
            'financial_event_id' => $event->id,
            'journal_id' => $event->journal_id,
        ]);
    }

    /**
     * True-up: post only the delta between the estimate already posted and the actual.
     *
     * If actual > estimate → post additional expense (debit expense, credit AP)
     * If actual < estimate → reverse the excess (debit AP, credit expense)
     */
    private function postTrueUp(
        FinancialEventService $service,
        SiteUtility $utility,
        $site,
        int $orgId,
        SiteUtilityPosting $estimatePosting,
        SiteUtilityActual $actual,
        string $periodStr,
        string $eventDate,
    ): void {
        $estimateAmount = (string) $estimatePosting->amount;
        $actualAmount = (string) $actual->amount;
        $delta = bcsub($actualAmount, $estimateAmount, 2);

        // No difference — mark as actual anyway for tracking
        if (bccomp($delta, '0', 2) === 0) {
            SiteUtilityPosting::create([
                'site_utility_id' => $utility->id,
                'period' => $periodStr,
                'posting_type' => 'actual',
                'amount' => $actualAmount,
                'financial_event_id' => $estimatePosting->financial_event_id,
                'journal_id' => $estimatePosting->journal_id,
            ]);

            return;
        }

        $absDelta = ltrim($delta, '-');
        $accountConfig = config('finance.event_accounts.site_utilities_expense');

        if (bccomp($delta, '0', 2) > 0) {
            // Actual > estimate → additional expense
            $debitCode = $accountConfig['debit']; // 6410 Utilities Expense
            $creditCode = null; // Resolved by payment_type
        } else {
            // Actual < estimate → reverse excess (debit AP, credit expense)
            $debitCode = config('finance.payment_type_accounts.ap', '2000');
            $creditCode = $accountConfig['debit']; // 6410 Utilities Expense
        }

        $event = $service->record([
            'organization_id' => $orgId,
            'source_type' => SiteUtility::class,
            'source_id' => $utility->id,
            'event_type' => 'site_utilities_true_up',
            'description' => ucfirst($utility->type) . ": {$site->name} — {$periodStr}"
                . " [true-up: estimate \${$estimateAmount} → actual \${$actualAmount}, delta \${$delta}]",
            'amount' => $absDelta,
            'event_date' => $eventDate,
            'debit_account_code' => $debitCode,
            'credit_account_code' => $creditCode,
            'payment_type' => FinFinancialEvent::PAYMENT_AP,
            'journal_type' => 'adjustment',
            'site_id' => $utility->site_id,
            'source_updated_at' => "{$periodStr}:true_up:{$actualAmount}",
        ]);

        // Record that the actual has now been reconciled
        SiteUtilityPosting::create([
            'site_utility_id' => $utility->id,
            'period' => $periodStr,
            'posting_type' => 'actual',
            'amount' => $actualAmount,
            'financial_event_id' => $event->id,
            'journal_id' => $event->journal_id,
        ]);
    }
}
