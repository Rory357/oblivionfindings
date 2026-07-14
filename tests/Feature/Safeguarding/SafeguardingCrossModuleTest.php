<?php

namespace Tests\Feature\Safeguarding;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safeguarding redesign — Step 8 (cross-module X1/X3 + NZ authority currency).
 */
class SafeguardingCrossModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function makeUser(array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    public function test_incident_exposes_spawned_safeguarding_concerns_relation(): void
    {
        $incident = ClientIncident::factory()->create();
        $concern = SafeguardingConcern::factory()->create(['related_incident_id' => $incident->id]);

        $this->assertTrue($incident->safeguardingConcerns()->whereKey($concern->id)->exists());
    }

    public function test_incident_detail_surfaces_spawned_concern(): void
    {
        $user = $this->makeUser(['incidents.viewAny', 'safeguarding.viewAny']);
        $incident = ClientIncident::factory()->create();
        $concern = SafeguardingConcern::factory()->create(['related_incident_id' => $incident->id]);

        $this->actingAs($user)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.safeguarding_concerns.0.reference_number', $concern->reference_number)
                ->where('detail.safeguarding_concerns.0.can_view', true)
            );
    }

    public function test_closing_a_concern_preserves_the_linked_alert_lifecycle_and_requests_an_operational_decision(): void
    {
        $user = $this->makeUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'monitoring']);

        $alert = ControlRoomAlert::factory()->create([
            'status' => ControlRoomAlert::STATUS_OPEN,
            'context' => ['existing_key' => 'preserved'],
        ]);
        $task = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Keep the client safe while safeguarding closes',
            'status' => AlertTask::STATUS_OPEN,
            'created_by_user_id' => $user->id,
        ]);
        $definition = SlaDefinition::query()->create([
            'name' => 'Safeguarding operational response',
            'code' => 'safeguarding-operational-response',
            'acknowledge_target_minutes' => 15,
            'response_target_minutes' => 30,
            'resolution_target_minutes' => 120,
            'is_active' => true,
        ]);
        $sla = AlertSla::createFromDefinition($alert, $definition);
        $key = HsEvent::buildIdempotencyKey(SafeguardingConcern::class, $concern->id, HsEvent::CATEGORY_SAFEGUARDING);

        // The concern observer records the HsEvent on create; reuse it (or create
        // one if the observer didn't run), then pin a known open alert to it.
        $hsEvent = HsEvent::query()->where('idempotency_key', $key)->first()
            ?? HsEvent::factory()->create([
                'idempotency_key' => $key,
                'source_type' => SafeguardingConcern::class,
                'source_id' => $concern->id,
                'event_category' => HsEvent::CATEGORY_SAFEGUARDING,
            ]);
        $hsEvent->forceFill(['status' => HsEvent::STATUS_OPEN, 'control_room_alert_id' => $alert->id])->save();

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/close", ['closure_summary' => 'Resolved and safe to close.'])
            ->assertRedirect();

        $this->assertSame('closed', $concern->fresh()->status);
        $this->assertSame(HsEvent::STATUS_CLOSED, $hsEvent->fresh()->status);

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertNull($alert->resolved_at);
        $this->assertSame('preserved', data_get($alert->context, 'existing_key'));
        $this->assertSame('safeguarding_terminal', data_get($alert->context, 'journey_attention.type'));
        $this->assertSame($concern->id, data_get($alert->context, 'journey_attention.safeguarding_concern_id'));
        $this->assertSame($hsEvent->id, data_get($alert->context, 'journey_attention.hs_event_id'));
        $this->assertSame($user->id, data_get($alert->context, 'journey_attention.actor_id'));
        $this->assertTrue(data_get($alert->context, 'journey_attention.requires_operational_decision'));

        $this->assertNull($sla->fresh()->resolved_at);
        $this->assertNull($sla->fresh()->ended_as);
        $this->assertSame(AlertTask::STATUS_OPEN, $task->fresh()->status);

        $audit = AuditLog::query()
            ->where('action', 'controlRoom.alert.safeguardingTerminalAttention')
            ->where('auditable_type', $alert->getMorphClass())
            ->where('auditable_id', $alert->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($concern->id, data_get($audit->meta, 'safeguarding_concern_id'));
        $this->assertTrue(data_get($audit->meta, 'requires_operational_decision'));
    }

    public function test_external_report_accepts_msd_dss_authority(): void
    {
        $user = $this->makeUser(['safeguarding.report.external']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'triaged']);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/external-reports", [
                'authority_type' => 'msd_dss',
                'authority_name' => 'MSD – Disability Support Services',
                'report_method' => 'email',
                'reported_at' => now()->toDateString(),
                'report_summary' => 'Notified MSD Disability Support Services.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_external_reports', [
            'safeguarding_concern_id' => $concern->id,
            'authority_type' => 'msd_dss',
        ]);
    }
}
