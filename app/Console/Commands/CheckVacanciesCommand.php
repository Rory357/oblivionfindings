<?php

namespace App\Console\Commands;

use App\Domain\Hr\Services\PositionService;
use Illuminate\Console\Command;

/**
 * Reconciles stored position headcounts (the backstop for paths that bypass the
 * HrEmployeeProfile observer, e.g. the People bulk bar's mass updates) and
 * reports understaffed positions — budget minus filled minus openings already
 * in open requisitions.
 */
class CheckVacanciesCommand extends Command
{
    protected $signature = 'hr:check-vacancies';

    protected $description = 'Reconcile position headcounts and report understaffed positions.';

    public function handle(PositionService $positions): int
    {
        $totalClosed = $positions->syncAllHeadcounts();
        $understaffed = $positions->getUnderstaffed();

        foreach ($understaffed as $position) {
            $this->line(sprintf(
                'Understaffed: %s (%s) — %d to hire',
                $position->title,
                $position->code,
                $positions->actionableVacancies($position),
            ));
        }

        if ($totalClosed > 0) {
            $this->line("Auto-closed {$totalClosed} filled requisition(s).");
        }

        $this->info("Vacancy check complete. {$understaffed->count()} understaffed position(s).");

        return self::SUCCESS;
    }
}
