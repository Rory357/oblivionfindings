<?php

namespace Tests\Feature\Safeguarding;

use App\Models\ClientIncident;
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

    public function test_closing_a_concern_closes_the_linked_hs_event_and_resolves_the_alert(): void
    {
        $user = $this->makeUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create(['status' => 'monitoring']);

        $alert = ControlRoomAlert::factory()->create(['status' => ControlRoomAlert::STATUS_OPEN]);
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
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
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
