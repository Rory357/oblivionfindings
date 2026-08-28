<?php

namespace App\Console\Commands;

use App\Models\MedicationRoundTemplate;
use App\Services\Medication\MedicationRoundGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateMedicationRounds extends Command
{
    protected $signature = 'emar:generate-rounds
        {--date= : Date to generate for (default today)}
        {--generate-all : Ignore day-of-week filters and generate any missing rounds for the date}';

    protected $description = 'Generate medication rounds from active templates for the given date';

    public function handle(MedicationRoundGenerationService $roundGeneration): int
    {
        $workerTimezone = config('app.worker_timezone', 'Pacific/Auckland');
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'), $workerTimezone)
            : CarbonImmutable::now($workerTimezone);
        $generateAll = (bool) $this->option('generate-all');

        $templateIds = MedicationRoundTemplate::query()
            ->active()
            ->orderBy('id')
            ->pluck('id');
        $generated = 0;
        $alreadyExisted = 0;
        $skipped = 0;
        $skipReasons = [];

        foreach ($templateIds as $templateId) {
            $result = $roundGeneration->generate(
                (int) $templateId,
                $date,
                $generateAll,
            );

            if ($result['status'] === MedicationRoundGenerationService::STATUS_CREATED) {
                $generated++;
            } elseif ($result['status'] === MedicationRoundGenerationService::STATUS_ALREADY_EXISTS) {
                $alreadyExisted++;
            } else {
                $skipped++;
                $skipReasons[$result['reason']] = ($skipReasons[$result['reason']] ?? 0) + 1;
            }
        }

        $this->info("Generated {$generated} rounds; {$alreadyExisted} already existed; skipped {$skipped}.");
        foreach ($skipReasons as $reason => $count) {
            $this->line("Skipped {$count}: {$reason}");
        }

        return self::SUCCESS;
    }
}
