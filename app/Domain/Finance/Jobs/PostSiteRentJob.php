<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Finance\Services\FinancialEventService;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Monthly job: post rent expense for all active sites with rent configured.
 *
 * Calculates exact rent for the period using day-based proration:
 *   - Full month: normalised monthly equivalent of the configured rent.
 *   - Partial month (lease starts/ends mid-month): daily rate × days in period.
 *
 * Daily rate = annual rent / 365.
 * Annual rent derived from: weekly × 52, fortnightly × 26, monthly × 12, etc.
 *
 * Idempotent per site per month — safe to re-run.
 *
 * Schedule: monthly on the 1st at 02:00 NZT.
 */
class PostSiteRentJob implements ShouldQueue
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
        $monthStart = $period->copy()->startOfMonth();
        $monthEnd = $period->copy()->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;

        $sites = Site::query()
            ->active()
            ->whereNotNull('rent_amount')
            ->where('rent_amount', '>', 0)
            ->whereNotNull('rent_frequency')
            ->get();

        $posted = 0;
        $errors = [];

        foreach ($sites as $site) {
            try {
                $result = $this->calculateRentForPeriod($site, $monthStart, $monthEnd, $daysInMonth);

                if (! $result) {
                    continue;
                }

                [$amount, $description] = $result;

                if (bccomp($amount, '0', 2) <= 0) {
                    continue;
                }

                $orgId = $site->tenant_id;
                if (! $orgId) {
                    continue;
                }

                $accountConfig = config('finance.event_accounts.site_rent_expense');

                $service->record([
                    'organization_id' => $orgId,
                    'source_type' => Site::class,
                    'source_id' => $site->id,
                    'event_type' => 'site_rent_expense',
                    'description' => $description,
                    'amount' => $amount,
                    'event_date' => $monthEnd->toDateString(),
                    'debit_account_code' => $accountConfig['debit'],
                    'payment_type' => FinFinancialEvent::PAYMENT_AP,
                    'journal_type' => $accountConfig['journal_type'] ?? 'recurring',
                    'site_id' => $site->id,
                    'source_updated_at' => $periodStr,
                ]);

                $posted++;
            } catch (\Throwable $e) {
                $errorMsg = "Site #{$site->id} ({$site->name}): {$e->getMessage()}";
                Log::error("PostSiteRentJob: Failed for {$errorMsg}");
                $errors[] = $errorMsg;
            }
        }

        if ($errors !== []) {
            $failedSummary = implode('; ', $errors);
            Log::error("PostSiteRentJob: Completed with partial failures ({$posted} posted, " . count($errors) . " failed): {$failedSummary}");
            throw new \RuntimeException("PostSiteRentJob encountered partial failures: {$failedSummary}");
        }

        Log::info("PostSiteRentJob: Posted rent for {$posted} sites for period {$periodStr}.");
    }

    /**
     * Calculate exact rent for the posting month, handling proration.
     *
     * @return array{string, string}|null [amount, description] or null if not applicable
     */
    private function calculateRentForPeriod(Site $site, Carbon $monthStart, Carbon $monthEnd, int $daysInMonth): ?array
    {
        $leaseStart = $site->lease_start_date;
        $leaseEnd = $site->lease_end_date;

        // Determine the billable window within this month
        $billableStart = $monthStart->copy();
        $billableEnd = $monthEnd->copy();

        // Lease hasn't started yet — skip entire month
        if ($leaseStart && $leaseStart->gt($monthEnd)) {
            return null;
        }

        // Lease ended before this month — skip
        if ($leaseEnd && $leaseEnd->lt($monthStart)) {
            return null;
        }

        // Clamp to lease boundaries
        if ($leaseStart && $leaseStart->gt($billableStart)) {
            $billableStart = $leaseStart->copy();
        }
        if ($leaseEnd && $leaseEnd->lt($billableEnd)) {
            $billableEnd = $leaseEnd->copy();
        }

        $billableDays = $billableStart->diffInDays($billableEnd) + 1; // Inclusive

        if ($billableDays <= 0) {
            return null;
        }

        $periodStr = $monthStart->format('Y-m');
        $isFullMonth = $billableDays === $daysInMonth;

        // Calculate the daily rate from annual equivalent
        $annualRent = $this->toAnnual((float) $site->rent_amount, $site->rent_frequency);
        $dailyRate = bcdiv((string) $annualRent, '365', 4); // 4dp precision for daily rate

        if ($isFullMonth) {
            // Full month: use normalised monthly (annual / 12) for cleaner numbers
            $amount = bcdiv((string) $annualRent, '12', 2);
            $description = "Rent: {$site->name} — {$periodStr}"
                . ($site->landlord_name ? " ({$site->landlord_name})" : '');
        } else {
            // Partial month: exact day-based proration
            $amount = bcmul($dailyRate, (string) $billableDays, 2);
            $description = "Rent: {$site->name} — {$periodStr}"
                . " [prorated: {$billableStart->format('d M')}–{$billableEnd->format('d M')},"
                . " {$billableDays}/{$daysInMonth} days]"
                . ($site->landlord_name ? " ({$site->landlord_name})" : '');
        }

        return [$amount, $description];
    }

    /**
     * Convert any rent frequency to annual equivalent.
     */
    private function toAnnual(float $amount, string $frequency): float
    {
        return match ($frequency) {
            'weekly' => $amount * 52,
            'fortnightly' => $amount * 26,
            'monthly' => $amount * 12,
            'quarterly' => $amount * 4,
            'annually' => $amount,
            default => $amount * 12,
        };
    }
}
