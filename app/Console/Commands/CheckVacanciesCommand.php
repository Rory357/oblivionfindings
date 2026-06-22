<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrPosition;
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
        $tenantIds = HrPosition::query()->distinct()->pluck('tenant_id')->filter()->values();
        if ($tenantIds->isEmpty()) {
            $tenantIds = collect([1]);
        }

        $totalUnderstaffed = 0;

        foreach ($tenantIds as $tenantId) {
            $positions->syncAllHeadcounts((int) $tenantId);

            $understaffed = $positions->getUnderstaffed((int) $tenantId);
            $totalUnderstaffed += $understaffed->count();

            foreach ($understaffed as $position) {
                $this->line(sprintf(
                    'Understaffed: %s (%s) — %d to hire',
                    $position->title,
                    $position->code,
                    $positions->actionableVacancies($position),
                ));
            }
        }

        $this->info("Vacancy check complete. {$totalUnderstaffed} understaffed position(s).");

        return self::SUCCESS;
    }
}
