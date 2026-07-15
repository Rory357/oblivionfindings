<?php

namespace Tests\Feature\ControlRoom;

use App\Models\AuditLog;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ControlRoomAuditDurabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_transition_rolls_back_when_its_required_audit_write_fails(): void
    {
        $actor = User::factory()->create(['approved_at' => now()]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'triggered_at' => now(),
            'context' => ['source_marker' => 'preserved'],
        ]);
        $definition = SlaDefinition::query()->create([
            'name' => 'Audit durability SLA',
            'code' => 'audit-durability-'.$alert->id,
            'acknowledge_target_minutes' => 5,
            'is_active' => true,
        ]);
        $sla = AlertSla::createFromDefinition($alert, $definition);
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated lifecycle audit write failure.');
        });
        $caught = null;

        try {
            app(ControlRoomAlertLifecycleService::class)->acknowledge(
                $alert,
                $actor,
                'This transition must not survive without its audit record.',
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            Event::forget($eventName);
        }

        $alert->refresh();
        $sla->refresh();

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Simulated lifecycle audit write failure.', $caught?->getMessage());
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertNull($alert->acknowledged_at);
        $this->assertNull($alert->acknowledged_by_user_id);
        $this->assertSame('preserved', data_get($alert->context, 'source_marker'));
        $this->assertSame([], data_get($alert->context, 'activity_log', []));
        $this->assertNull($sla->acknowledged_at);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'controlRoom.alert.acknowledge',
            'auditable_id' => $alert->id,
        ]);
    }
}
