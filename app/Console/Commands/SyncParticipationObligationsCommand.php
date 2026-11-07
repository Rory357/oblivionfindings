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
        // O5 (readiness audit, 6 Nov 2026): the role is health_safety_officer ("h&s" never existed, so every reminder went
        // to the first admin). An approved H&S officer owns these duties; an admin only when there is none.
        $approved = fn ($q) => $q->whereNotNull('approved_at');
        $owner = User::query()->tap($approved)->whereHas('roles', fn ($q) => $q->where('name', 'health_safety_officer'))->orderBy('id')->first()
            ?? User::query()->tap($approved)->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->orderBy('id')->first();
        $created = 0;

        if (! $owner) {
            $this->warn('No owner user found — skipping.');

            return self::SUCCESS;
        }

        // HSC 3-month cadence — flag committees with no meeting in the last quarter.
        foreach (HsCommittee::where('status', 'active')->get() as $committee) {
            // Only a meeting that was held counts: a cancelled or still-scheduled one is not a meeting (O5).
            $last = $committee->meetings()->where('status', 'completed')->where('scheduled_at', '<=', now())->max('scheduled_at');
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

        // HSR below the 2-day/yr training entitlement. Only a running total of days is recorded, so it is compared with
        // 2 days for each year (or part year) in office, and a new reminder can open each year (O5: it used to compare
        // the lifetime total with 2, so it went quiet for good after the first year).
        foreach (HsRepresentative::where('status', 'active')->with('user:id,name')->get() as $rep) {
            $yearsInOffice = $rep->elected_at ? max(1, (int) ceil(Carbon::parse($rep->elected_at)->floatDiffInYears(now()))) : 1;
            if ((int) $rep->training_days_completed >= 2 * $yearsInOffice) {
                continue;
            }
            $code = "HSR-TRAINING-{$rep->id}-Y{$yearsInOffice}";
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
