<?php

namespace App\Jobs\Governance;

use App\Domain\Governance\Services\IncidentEscalationService;
use App\Models\ClientIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RegisterIncidentGovernanceEscalationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public int $uniqueFor = 3600;

    public function __construct(public int $incidentId) {}

    public function uniqueId(): string
    {
        return (string) $this->incidentId;
    }

    public function handle(IncidentEscalationService $escalations): void
    {
        $incident = ClientIncident::query()->find($this->incidentId);
        if ($incident === null) {
            return;
        }

        $escalations->escalateClientIncidentOrFail($incident);
    }
}
