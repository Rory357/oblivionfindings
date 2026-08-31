<?php

namespace App\Console\Commands;

use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Notifications\RiskReviewDueNotification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;

class CheckRiskReviews extends Command
{
    protected $signature = 'governance:check-risk-reviews';

    protected $description = 'Check for risks due for review and notify owners';

    public function handle(): int
    {
        $this->info('Checking for risks due for review...');

        $dueRisks = RiskRegisterEntry::query()
            ->active()
            ->reviewDue()
            ->get();
        $notified = 0;

        foreach ($dueRisks as $risk) {
            $owner = $risk->risk_owner_id !== null
                ? User::query()->find($risk->risk_owner_id)
                : null;
            if (! $owner
                || ! $owner->canDo('governance.risks.view')
                || Gate::forUser($owner)->denies('view', $risk)) {
                continue;
            }

            $owner->notify(new RiskReviewDueNotification($risk));
            $this->info("Notified owner for risk: {$risk->risk_reference}");
            $notified++;
        }

        $this->info("Notified {$notified} of {$dueRisks->count()} active risks due for review.");

        return self::SUCCESS;
    }
}
