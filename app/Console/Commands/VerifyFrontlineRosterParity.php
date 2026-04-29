<?php

namespace App\Console\Commands;

use App\Models\Shift;
use Illuminate\Console\Command;

class VerifyFrontlineRosterParity extends Command
{
    protected $signature = 'rostering:verify-frontline-parity'
        .' {--organization_id= : Restrict the parity check to a single organization.}'
        .' {--include-loose : Include shifts with roster_period_id NULL (intentional post-cutover drafts) in the failure count. By default these are reported separately and do not cause failure since they could not have been part of any backfill.}'
        .' {--details : List the IDs of every shift that does not have published_at set, with their roster_period_id and created_at.}';

    protected $description = 'Verify that existing assigned frontline shifts have publish timestamps after the cutover backfill. Distinguishes between legacy backfill misses (shifts attached to a roster_period but missing published_at — a real bug) and intentional post-cutover drafts (shifts with no roster_period_id, e.g., from demo seeders or pre-publish manager work).';

    public function handle(): int
    {
        $organizationId = $this->option('organization_id');
        $includeLoose = (bool) $this->option('include-loose');
        $details = (bool) $this->option('details');

        $base = Shift::query()
            ->whereNotNull('user_id')
            ->whereIn('status', ['scheduled', 'in_progress', 'completed', 'clocked_out', 'finished'])
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId));

        $total = (clone $base)->count();

        // Backfill misses — shift has roster_period_id (so it was either backfilled
        // or published later) but published_at is missing. This is a real bug.
        $backfillMisses = (clone $base)
            ->whereNotNull('roster_period_id')
            ->whereNull('published_at')
            ->count();

        // Loose drafts — shift has no roster_period_id and is unpublished. Could not
        // have been part of any backfill (was created post-cutover or never attached).
        // Worth reporting but not a failure unless --include-loose is passed.
        $looseDrafts = (clone $base)
            ->whereNull('roster_period_id')
            ->whereNull('published_at')
            ->count();

        $this->line("Assigned frontline shifts checked: {$total}");
        $this->line("Backfill misses (roster_period_id set, published_at NULL): {$backfillMisses}");
        $this->line("Loose drafts (no roster_period_id, unpublished): {$looseDrafts}");

        if ($details && ($backfillMisses > 0 || $looseDrafts > 0)) {
            $this->line('');
            $this->line('Unpublished shift detail:');
            (clone $base)
                ->whereNull('published_at')
                ->orderBy('id')
                ->get(['id', 'user_id', 'status', 'roster_period_id', 'starts_at', 'created_at'])
                ->each(function (Shift $shift): void {
                    $kind = $shift->roster_period_id ? 'BACKFILL_MISS' : 'LOOSE_DRAFT';
                    $this->line(sprintf(
                        '  - shift #%d  user=%d  status=%s  period=%s  starts=%s  created=%s  [%s]',
                        $shift->id,
                        $shift->user_id,
                        $shift->status,
                        $shift->roster_period_id ?? 'NULL',
                        $shift->starts_at?->toDateTimeString() ?? '-',
                        $shift->created_at?->toDateTimeString() ?? '-',
                        $kind,
                    ));
                });
        }

        $failureCount = $backfillMisses + ($includeLoose ? $looseDrafts : 0);

        if ($failureCount > 0) {
            if ($backfillMisses > 0) {
                $this->error(sprintf(
                    'Frontline parity FAILED: %d backfill miss(es) found. These shifts have a roster_period_id but no published_at — re-run the backfill or publish them manually before enabling the publish flag.',
                    $backfillMisses,
                ));
            }

            if ($includeLoose && $looseDrafts > 0) {
                $this->error(sprintf(
                    'Frontline parity FAILED with --include-loose: %d loose draft shift(s) would be hidden when the publish flag is enabled. If those shifts are intentional drafts (e.g., demo data), this is expected — re-run without --include-loose, or publish them.',
                    $looseDrafts,
                ));
            }

            return self::FAILURE;
        }

        if ($looseDrafts > 0) {
            $this->warn(sprintf(
                '%d loose draft shift(s) exist (no roster_period_id, unpublished). They will be hidden from frontline when the publish flag is enabled — this is expected if they are intentional drafts. Pass --include-loose to treat as a failure, or --details to list them.',
                $looseDrafts,
            ));
        }

        $this->info('Frontline parity verified: zero backfill misses.');

        return self::SUCCESS;
    }
}
