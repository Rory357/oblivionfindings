<?php

namespace App\Console\Commands;

use App\Models\ClientMedication;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateMedicationRounds extends Command
{
    protected $signature = 'emar:generate-rounds {--date= : Date to generate for (default today)}';

    protected $description = 'Generate medication rounds from active templates for the given date';

    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : today();
        $dayOfWeek = $date->dayOfWeekIso; // 1=Mon, 7=Sun

        $templates = MedicationRoundTemplate::active()->get();
        $totalMedications = ClientMedication::active()->count();
        $generated = 0;
        $skipped = 0;

        foreach ($templates as $template) {
            // Check if this template runs on this day
            if (! $template->appliesToDay($dayOfWeek)) {
                $skipped++;
                continue;
            }

            // Skip if round already exists for this template + date
            $exists = MedicationRound::where('round_template_id', $template->id)
                ->whereDate('round_date', $date)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            MedicationRound::create([
                'name' => $template->name,
                'round_template_id' => $template->id,
                'round_type' => 'scheduled',
                'scheduled_time' => $template->scheduled_time,
                'window_minutes' => $template->window_minutes ?? 60,
                'round_date' => $date->toDateString(),
                'status' => 'pending',
                'assigned_to' => $template->default_assigned_to,
                'total_medications' => $totalMedications,
                'site_id' => $template->site_id,
                'service_context_id' => $template->service_context_id,
            ]);
            $generated++;
        }

        $this->info("Generated {$generated} rounds, skipped {$skipped} (already exist or not scheduled today).");

        return 0;
    }
}
