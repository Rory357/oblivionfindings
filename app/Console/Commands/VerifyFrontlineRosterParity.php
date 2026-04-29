<?php

namespace App\Console\Commands;

use App\Models\Shift;
use Illuminate\Console\Command;

class VerifyFrontlineRosterParity extends Command
{
    protected $signature = 'rostering:verify-frontline-parity {--organization_id=}';

    protected $description = 'Verify that existing assigned frontline shifts have publish timestamps after cutover backfill.';

    public function handle(): int
    {
        $organizationId = $this->option('organization_id');

        $base = Shift::query()
            ->whereNotNull('user_id')
            ->whereIn('status', ['scheduled', 'in_progress', 'completed', 'clocked_out', 'finished'])
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId));

        $total = (clone $base)->count();
        $hidden = (clone $base)->whereNull('published_at')->count();

        $this->line("Assigned frontline shifts checked: {$total}");
        $this->line("Missing published_at: {$hidden}");

        if ($hidden > 0) {
            $this->error('Frontline parity failed. Backfill or publish the missing shifts before enabling the publish flag.');

            return self::FAILURE;
        }

        $this->info('Frontline parity verified with zero diff.');

        return self::SUCCESS;
    }
}
