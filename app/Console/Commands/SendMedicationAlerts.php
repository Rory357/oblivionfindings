<?php

namespace App\Console\Commands;

use App\Services\MarScheduleService;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\OverdueDoseAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendMedicationAlerts extends Command
{
    protected $signature = 'emar:send-alerts';

    protected $description = 'Check for overdue medications, low stock, expiring competencies, refusal clusters and overdue follow-ups and send notifications';

    public function handle(MedicationAlertSources $alerts): int
    {
        $this->info('Running eMAR medication alerts...');

        $this->checkOverdueMedications($alerts);
        // Who is told comes from Medication Settings › Alerts & access (P11 B2).
        $alerts->lowStock();
        $alerts->renewals();
        $alerts->refusalClusters();
        $alerts->overdueFollowUps();

        $this->info('eMAR medication alerts complete.');

        return self::SUCCESS;
    }

    /**
     * The overdue job (P01 C6f): the doses the dose-slot projection calls
     * overdue — the window has ended with nothing recorded, yesterday and
     * today — raised in the Control Room (one signal per dose), with alerts
     * for doses no longer overdue resolved. People are told once per overdue
     * spell of a dose, as Medication Settings › Alerts & access says (P11 B2):
     * the alert log's open record is keyed by the same spell.
     */
    protected function checkOverdueMedications(MedicationAlertSources $alerts): void
    {
        $this->info('Checking for overdue medications...');

        $now = Carbon::now(app(MarScheduleService::class)->workerTimezone());
        $overdue = app(OverdueDoseAlerts::class)->sweep($now);
        $told = $alerts->overdueDoses($overdue, $now);

        $this->info("Sent {$told} overdue medication alerts.");
    }
}
