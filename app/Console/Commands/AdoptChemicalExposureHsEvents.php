<?php

namespace App\Console\Commands;

use App\Models\ChemicalExposureHsProjection;
use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureSystemDeliveryService;
use Illuminate\Console\Command;

/**
 * One-off (job 3, Stephan 26 Oct): adopt the H&S events the restored exposure observer created for saved exposures
 * before the automatic delivery existed, so later corrections reach them. Exact matches only; everything else is
 * listed for repair and left untouched. Run with --dry-run first.
 */
class AdoptChemicalExposureHsEvents extends Command
{
    protected $signature = 'chemical-exposures:adopt-hs-events {--dry-run : List what would be adopted without writing anything}';

    protected $description = 'Adopt H&S events created for saved exposures before the automatic delivery, after an exact match.';

    public function handle(ChemicalExposureSystemDeliveryService $delivery): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ids = SubstanceExposureRecord::query()->where('source_schema_version', 1)
            ->whereNotIn('id', ChemicalExposureHsProjection::query()->select('exposure_id'))
            ->whereIn('id', HsEvent::withTrashed()->where('event_category', HsEvent::CATEGORY_EXPOSURE)
                ->whereIn('source_type', array_values(array_unique([SubstanceExposureRecord::class, (new SubstanceExposureRecord)->getMorphClass()])))
                ->select('source_id'))
            ->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            $this->info('No saved exposure has an H&S event waiting to be adopted.');

            return self::SUCCESS;
        }
        $repair = 0;
        foreach ($ids as $id) {
            $result = $delivery->adopt((int) $id, $dryRun);
            $event = isset($result['hs_event_id']) ? " (H&S event #{$result['hs_event_id']})" : '';
            $reasons = isset($result['reasons']) ? ': '.implode('; ', $result['reasons']) : '';
            $this->line("Exposure #{$id}{$event}: {$result['state']}{$reasons}");
            $repair += $result['state'] === 'needs_repair' ? 1 : 0;
        }
        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Done.');
        if ($repair > 0) {
            $this->warn("{$repair} exposure(s) need repair by hand; their H&S events were left as they are.");
        }

        return self::SUCCESS;
    }
}
