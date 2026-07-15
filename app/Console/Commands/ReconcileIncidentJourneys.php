<?php

namespace App\Console\Commands;

use App\Services\Incidents\IncidentJourneyReconciler;
use Illuminate\Console\Command;

final class ReconcileIncidentJourneys extends Command
{
    protected $signature = 'incidents:reconcile-journeys
        {--apply : Apply deterministic repairs; the default is report-only}
        {--incident= : Limit the audit to one incident ID}
        {--chunk=200 : Number of incidents to inspect per chunk}';

    protected $description = 'Audit incident, Control Room and H&S journey links and safely repair deterministic drift';

    public function handle(IncidentJourneyReconciler $reconciler): int
    {
        $apply = (bool) $this->option('apply');
        $incidentId = $this->option('incident') !== null ? (int) $this->option('incident') : null;
        $chunk = max(1, (int) $this->option('chunk'));
        $result = $reconciler->reconcile($apply, $incidentId, $chunk);

        $this->info($apply ? 'Incident journey reconciliation (apply)' : 'Incident journey reconciliation (dry-run)');
        $this->table(
            ['Issue', 'Found', 'Repaired'],
            collect($result->issues)->map(fn (int $count, string $issue) => [
                $issue,
                $count,
                $result->repairs[$issue],
            ])->values()->all(),
        );
        $this->line("Scanned: {$result->scanned}; issues: {$result->totalIssues()}; repairs: {$result->totalRepairs()}");

        foreach ($result->fatal as $fatal) {
            $this->error("Incident {$fatal['incident_id']}: {$fatal['error']}");
        }

        if (! $apply) {
            $this->comment('Dry-run only: no records were changed. Re-run with --apply to perform deterministic repairs.');
        }

        return $result->hasFatalErrors() ? self::FAILURE : self::SUCCESS;
    }
}
