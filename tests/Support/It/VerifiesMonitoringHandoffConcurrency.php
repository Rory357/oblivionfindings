<?php

namespace Tests\Support\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItControlRoomHandoffService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Reuses each standalone suite's real source fixture, barriers and cleanup. */
trait VerifiesMonitoringHandoffConcurrency
{
    private function assertHumanHandoffRaces(string $source): void
    {
        foreach ([true, false] as $operatorFirst) {
            $outbox = $this->pendingDelivery('high');
            $siteId = $outbox->it_scope['site_id'];
            $actor = User::factory()->create(['approved_at' => now()]);
            $role = Role::query()->create(['name' => 'worker-handoff-'.Str::uuid(), 'label' => 'Isolated handoff worker', 'level' => 60, 'type' => 'custom']);
            foreach (['it.view', 'it.manage', 'controlRoom.alerts.manage'] as $key) {
                $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
                $role->permissions()->attach($permission);
            }
            $actor->roles()->attach($role);
            HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $siteId,
                'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
            $target = ItTicket::factory()->create(['site_id' => $siteId, 'is_organisation_wide' => false,
                'source' => 'agent', 'work_type' => 'incident', 'status' => 'open']);
            $count = ItTicket::query()->count();
            $barrier = $this->barrier();
            $workers = [];
            $handoffWorker = function (string $mode, int $side) use ($outbox, $barrier, $source, $actor, $target): Process {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/It/monitoring-delivery-concurrency-worker.php'),
                    $mode, (string) $outbox->id, $barrier, (string) $side, $source, (string) $actor->id, (string) $target->id], base_path(), timeout: 45);
                $worker->start();

                return $worker;
            };
            try {
                $workers[] = $operatorFirst ? $handoffWorker('hold_handoff', 0) : $this->worker('hold_ticket', $outbox, $barrier, 0);
                $this->awaitReady([$barrier.'-0.ready'], $workers);
                $workers[] = $operatorFirst ? $this->worker('deliver', $outbox, $barrier, 1) : $handoffWorker('handoff', 1);
                $this->awaitReady([$barrier.'-1.ready'], $workers);
                $this->assertTrue($workers[1]->isRunning());
                $this->assertSame($count, ItTicket::query()->count(), 'No partial handoff or automatic ticket is externally visible.');
                touch($barrier.'.release');
                $results = [$this->workerResult($workers[0]), $this->workerResult($workers[1])];
                $outbox->refresh();
                $this->assertSame('applied', $outbox->it_status);
                $this->assertSame($count + ($operatorFirst ? 0 : 1), ItTicket::query()->count());
                $this->assertCount(1, $outbox->it_ticket_ids);
                $ticket = ItTicket::query()->findOrFail($outbox->it_ticket_ids[0]);
                $this->assertSame('open', $ticket->status);
                $this->assertTrue(MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->sole()->hasValidChecksum());
                if ($operatorFirst) {
                    $this->assertSame('linked', $results[0]['result']);
                    $this->assertSame($target->id, $ticket->id);
                    $this->assertSame('agent', $ticket->source);
                    $this->assertSame('ticket_updated', $outbox->it_outcome_code);
                    $this->assertSame($actor->id, $ticket->links()->where('relationship', 'source_alert')->sole()->created_by_user_id);
                    $this->assertSame(1, $ticket->events()->where('type', 'monitoring_handoff_bound')->count());
                } else {
                    $this->assertSame('handoff_rejected', $results[1]['result']);
                    $this->assertNotSame($target->id, $ticket->id);
                    $this->assertSame('system', $ticket->source);
                    $this->assertSame(0, $target->links()->where('relationship', 'source_alert')->count());
                }
                $this->assertSame($operatorFirst ? 1 : 0, ItTicketCommandReceipt::query()
                    ->where('operation', ItControlRoomHandoffService::OPERATION)->where('actor_user_id', $actor->id)->count());
            } finally {
                $this->cleanup($workers, $barrier);
            }
        }
    }
}
