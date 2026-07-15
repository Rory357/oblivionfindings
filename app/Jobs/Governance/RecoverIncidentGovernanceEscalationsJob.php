<?php

namespace App\Jobs\Governance;

use App\Models\ClientIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecoverIncidentGovernanceEscalationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 240;

    public function uniqueId(): string
    {
        return 'incident-governance-escalation-recovery';
    }

    public function handle(): void
    {
        ClientIncident::query()
            ->whereIn('severity', ['critical', 'high', 'major'])
            ->where('status', '!=', 'draft')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('incident_governance_escalations')
                    ->whereColumn(
                        'incident_governance_escalations.client_incident_id',
                        'client_incidents.id',
                    );
            })
            ->orderBy('id')
            ->chunkById(100, function ($incidents): void {
                foreach ($incidents as $incident) {
                    RegisterIncidentGovernanceEscalationJob::dispatch((int) $incident->id);
                }
            });
    }
}
