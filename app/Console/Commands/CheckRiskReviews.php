<?php

namespace App\Console\Commands;

use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Notifications\RiskReviewDueNotification;
use Illuminate\Console\Command;

class CheckRiskReviews extends Command
{
    protected $signature = 'governance:check-risk-reviews';
    protected $description = 'Check for risks due for review and notify owners';

    public function handle(): int
    {
        $this->info('Checking for risks due for review...');

        $dueRisks = RiskRegisterEntry::reviewDue()->get();

        foreach ($dueRisks as $risk) {
            $risk->riskOwner?->notify(new RiskReviewDueNotification($risk));
            $this->info("Notified owner for risk: {$risk->risk_reference}");
        }

        $this->info("Processed {$dueRisks->count()} risks due for review.");

        return self::SUCCESS;
    }
}
