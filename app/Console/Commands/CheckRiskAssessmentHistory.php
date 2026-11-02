<?php

namespace App\Console\Commands;

use App\Models\HsRiskAssessment;
use App\Models\User;
use App\Services\HealthSafety\RiskAssessmentAcceptanceService;
use Illuminate\Console\Command;

/**
 * #13: how an administrator investigates, and if needed clears, a risk assessment whose sealed change history doesn't
 * check out (the page shows "History needs checking" and refuses changes; the break is in the error log).
 *
 *   php artisan health-safety:risk-history RA-2026-0014 --by=1
 *       Checks the chain as that administrator and says where it breaks. Changes nothing.
 *   php artisan health-safety:risk-history RA-2026-0014 --by=1 --reset --reason="Restored from backup after ..."
 *       After the break has been investigated: records who, when and why (audit row + reset row + log line), keeps
 *       every earlier entry in place (shown as older, unverified), and lets a new sealed chain start, so the team can
 *       change the assessment again. Refused when the chain still checks out.
 */
final class CheckRiskAssessmentHistory extends Command
{
    protected $signature = 'health-safety:risk-history {assessment : Reference (RA-…) or id}
        {--by= : The administrator\'s user id (needs hazards.manage and audit.viewAny)}
        {--reset : Start a new sealed chain after an investigated break}
        {--reason= : Why the history is being reset (required with --reset)}';

    protected $description = 'Check a risk assessment\'s sealed change history, or reset it after an investigated break';

    public function handle(RiskAssessmentAcceptanceService $acceptance): int
    {
        $key = (string) $this->argument('assessment');
        $risk = HsRiskAssessment::query()->where(ctype_digit($key) ? 'id' : 'reference_number', $key)->first(['id', 'reference_number']);
        $admin = User::query()->find((int) $this->option('by'));
        if ($risk === null || $admin === null) {
            $this->error($risk === null ? 'No risk assessment matches '.$key.'.' : 'Pass --by with an administrator\'s user id.');

            return self::FAILURE;
        }

        $state = $acceptance->chainState((int) $risk->id, $admin);
        $this->line($risk->reference_number.': '.match ($state['state']) {
            'checked' => 'the history checks out ('.$state['sealed'].' sealed changes'.($state['reset_after'] > 0 ? ', after a reset' : '').').',
            'unsealed' => 'nothing sealed yet.',
            default => 'the history does NOT check out: '.$state['problem'],
        });

        if (! $this->option('reset')) {
            return $state['state'] === 'broken' ? self::FAILURE : self::SUCCESS;
        }
        try {
            $reset = $acceptance->resetBrokenChain((int) $risk->id, $admin, (string) $this->option('reason'));
        } catch (\InvalidArgumentException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }
        $this->info('Reset recorded. Entries up to change '.$reset['after_sequence'].' stay as older, unverified entries; the next change starts a new sealed chain.');

        return self::SUCCESS;
    }
}
