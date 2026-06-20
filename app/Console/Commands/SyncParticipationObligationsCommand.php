<?php

namespace App\Console\Commands;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Models\HsCommittee;
use App\Models\HsRepresentative;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Generates ComplianceObligations ahead of recurring HSWA participation duties,
 * so reminders fire and the duties surface on the compliance register. Mirrors
 * App\Domain\Governance\Console\SyncDonorFundComplianceCommand.
 *
 * Scheduled daily in routes/console.php:
 *   Schedule::command('participation:sync-obligations')->daily();
 */
class SyncParticipationObligationsCommand extends Command
{
    protected $signature = 'participation:sync-obligations {--lead-days=90 : Days ahead to open an obligation}';

    protected $description = 'Create compliance obligations for upcoming HSWA participation duties (HSC cadence, HSR term, training).';

    public function handle(ComplianceEngineService $compliance): int
    {
        $lead = (int) $this->option('lead-days');
        $owner = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['h&s', 'admin']))->first() ?? User::first();
        $created = 0;

        if (! $owner) {
            $this->warn('No owner user found — skipping.');

            return self::SUCCESS;
        }

        // HSC 3-month cadence — flag committees with no meeting in the last quarter.
        foreach (HsCommittee::where('status', 'active')->get() as $committee) {
            $last = $committee->meetings()->max('scheduled_at');
            $overdue = ! $last || Carbon::parse($last)->lt(now()->subMonths(3));
            $code = "HSC-CADENCE-{$committee->id}";
            if (! $overdue || $this->exists($code)) {
                continue;
            }
            $ob = $compliance->createObligation(
                framework: 'hswa',
                title: "Committee meeting overdue: {$committee->name}",
                description: 'Health & Safety Committee has not met within the last 3 months (HSWA requires >= quarterly meetings).',
                frequency: 'quarterly',
                owner: $owner,
                dueDate: now(),
                obligationCode: $code,
                reminderDays: [14, 3],
            );
            $compliance->scheduleReminders($ob);
            $created++;
        }

        // HSR term re-election within the lead window.
        foreach (HsRepresentative::where('status', 'active')->whereNotNull('term_expires_at')->with('user:id,name')->get() as $rep) {
            $code = "HSR-TERM-{$rep->id}";
            if (Carbon::parse($rep->term_expires_at)->gt(now()->addDays($lead)) || $this->exists($code)) {
                continue;
            }
            $ob = $compliance->createObligation(
                framework: 'hswa',
                title: "HSR term re-election due: {$rep->user?->name}",
                description: 'HSR term (max 3 years) is approaching its end — initiate re-election.',
                frequency: 'event_driven',
                owner: $owner,
                dueDate: Carbon::parse($rep->term_expires_at),
                obligationCode: $code,
                reminderDays: [90, 30, 7],
            );
            $compliance->scheduleReminders($ob);
            $created++;
        }

        // HSR below the 2-day/yr training entitlement.
        foreach (HsRepresentative::where('status', 'active')->where('training_days_completed', '<', 2)->with('user:id,name')->get() as $rep) {
            $code = "HSR-TRAINING-{$rep->id}";
            if ($this->exists($code)) {
                continue;
            }
            $ob = $compliance->createObligation(
                framework: 'hswa',
                title: "HSR training due: {$rep->user?->name}",
                description: 'HSR is below the 2-day/yr paid training entitlement (NZQA US 29315 required before issuing PINs / cease-work).',
                frequency: 'annual',
                owner: $owner,
                obligationCode: $code,
                reminderDays: [60, 14],
            );
            $compliance->scheduleReminders($ob);
            $created++;
        }

        $this->info("Created {$created} participation obligation(s).");

        return self::SUCCESS;
    }

    private function exists(string $code): bool
    {
        return ComplianceObligation::where('obligation_code', $code)
            ->where('status', '!=', 'complete')
            ->exists();
    }
}
