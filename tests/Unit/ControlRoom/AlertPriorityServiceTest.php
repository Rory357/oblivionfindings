<?php

namespace Tests\Unit\ControlRoom;

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\AlertPriorityService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AlertPriorityServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_orders_the_actionable_worklist_by_operational_urgency_and_excludes_hidden_states(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');
        $service = new AlertPriorityService;

        $alerts = collect([
            $this->alert(11, severity: 'medium', escalation: 1, deadline: '2026-07-15 12:40:00', triggeredAt: '2026-07-15 10:00:00'),
            $this->alert(9, severity: 'medium', escalation: 1, deadline: '2026-07-15 12:20:00', triggeredAt: '2026-07-15 11:00:00'),
            $this->alert(3, severity: 'low', priority: 'critical'),
            $this->alert(4, severity: 'high'),
            $this->alert(2, severity: 'low', breached: true),
            $this->alert(6, severity: 'medium', escalation: 3),
            $this->alert(21, severity: 'medium', escalation: 1, deadline: '2026-07-15 12:20:00', triggeredAt: '2026-07-15 09:00:00'),
            $this->alert(22, severity: 'medium', escalation: 1, deadline: '2026-07-15 12:20:00', triggeredAt: '2026-07-15 09:00:00'),
            $this->alert(30, status: ControlRoomAlert::STATUS_CONFIRMED, severity: 'low'),
            $this->alert(31, status: ControlRoomAlert::STATUS_DISMISSED, severity: 'critical', breached: true),
            $this->alert(32, severity: 'critical', snoozedUntil: '2026-07-15 13:00:00'),
        ]);

        $ordered = $service->sortActionable($alerts)->pluck('id')->all();

        $this->assertSame([2, 3, 4, 6, 21, 22, 9, 11, 30], $ordered);
    }

    public function test_assignment_does_not_change_priority_and_ties_end_with_the_record_id(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');
        $service = new AlertPriorityService;
        $unassigned = $this->alert(41, severity: 'high');
        $assigned = $this->alert(40, severity: 'high');
        $assigned->assigned_to_user_id = 99;

        $this->assertSame(
            [40, 41],
            $service->sortActionable(collect([$unassigned, $assigned]))->pluck('id')->all(),
        );
    }

    private function alert(
        int $id,
        string $status = ControlRoomAlert::STATUS_OPEN,
        string $severity = 'medium',
        ?string $priority = null,
        int $escalation = 0,
        ?string $deadline = null,
        string $triggeredAt = '2026-07-15 10:00:00',
        bool $breached = false,
        ?string $snoozedUntil = null,
    ): ControlRoomAlert {
        $alert = new ControlRoomAlert([
            'reference_number' => "CR-2026-{$id}",
            'status' => $status,
            'severity' => $severity,
            'priority' => $priority,
            'escalation_level' => $escalation,
            'triggered_at' => $triggeredAt,
            'snoozed_until' => $snoozedUntil,
        ]);
        $alert->id = $id;

        if ($deadline !== null || $breached) {
            $sla = new AlertSla([
                'sla_definition_id' => 1,
                'acknowledge_deadline' => $deadline ?? '2026-07-15 11:55:00',
                'acknowledge_breached' => $breached,
            ]);
            $sla->setRelation('alert', $alert);
            $alert->setRelation('sla', $sla);
        } else {
            $alert->setRelation('sla', null);
        }

        return $alert;
    }
}
