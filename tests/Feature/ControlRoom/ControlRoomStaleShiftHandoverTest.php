<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomHandoverScopeService;
use Database\Seeders\IncidentHandoverE2ESeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomStaleShiftHandoverTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $outgoingLead;

    private User $incomingLead;

    private User $overrideManager;

    private User $providerManager;

    private User $ordinaryManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create([
            'tenant_id' => 1,
            'type' => 'house',
        ]);
        $this->outgoingLead = $this->staffAt($this->site, 'coordinator');
        $this->incomingLead = $this->staffAt($this->site, 'coordinator');
        $this->overrideManager = $this->staffAt($this->site, 'coordinator');
        $this->providerManager = $this->staffAt($this->site, 'provider_manager');
        $this->ordinaryManager = $this->staffAt($this->site);
        $this->allow($this->ordinaryManager, [
            'controlRoom.viewAny',
            'controlRoom.alerts.manage',
        ]);
    }

    public function test_stale_threshold_comes_from_configuration_and_page_exposes_override_ownership(): void
    {
        config(['control-room.handover_stale_after_hours' => 6]);

        $shift = $this->activeShift(now()->subHours(5));

        $this->actingAs($this->overrideManager)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shift.is_stale', false)
                ->where('shift.stale_after_hours', 6)
                ->where('shift.can_override', false)
                ->where('shift.can_prepare', false)
            );

        $shift->update(['starts_at' => now()->subHours(6)->subMinute()]);

        $this->actingAs($this->overrideManager)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shift.is_stale', true)
                ->where('shift.stale_after_hours', 6)
                ->where('shift.can_override', true)
                ->where('shift.can_prepare', true)
            );

        $this->assertTrue($this->outgoingLead->canDo('controlRoom.handovers.override'));
        $this->assertTrue($this->overrideManager->canDo('controlRoom.handovers.override'));
        $this->assertTrue($this->providerManager->canDo('controlRoom.handovers.override'));
        $this->assertFalse($this->ordinaryManager->canDo('controlRoom.handovers.override'));
    }

    public function test_only_a_permission_holder_can_edit_or_prepare_a_stale_shift_and_reason_is_required(): void
    {
        config(['control-room.handover_stale_after_hours' => 16]);
        $shift = $this->activeShift(now()->subHours(17));

        $this->actingAs($this->ordinaryManager)
            ->patch("/control-room/shifts/{$shift->id}/handover/draft", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'override_reason' => 'The outgoing lead is unexpectedly unavailable.',
                'expected_version' => $shift->fresh()->handover_version,
            ])
            ->assertForbidden();

        $this->actingAs($this->ordinaryManager)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [],
                'override_reason' => 'The outgoing lead is unexpectedly unavailable.',
                'expected_version' => $shift->fresh()->handover_version,
            ])
            ->assertForbidden();

        $this->actingAs($this->overrideManager)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [],
                'override_reason' => 'Too short',
                'expected_version' => $shift->fresh()->handover_version,
            ])
            ->assertSessionHasErrors('override_reason');

        $this->assertSame(Shift::HANDOVER_NONE, $shift->fresh()->handover_status);
        $this->assertNull($shift->fresh()->handover_snapshot);
    }

    public function test_override_keeps_scope_checks_records_immutable_audit_data_and_cannot_accept_for_the_incoming_lead(): void
    {
        config(['control-room.handover_stale_after_hours' => 16]);
        $shift = $this->activeShift(now()->subHours(17));
        $required = $this->alert('high', 'CR-E2E-OVERRIDE-REQUIRED');
        $this->alert('medium', 'CR-E2E-OVERRIDE-CARRY', [
            'triggered_at' => now()->subHours(20),
            'created_at' => now()->subHours(20),
            'updated_at' => now()->subHours(20),
        ]);
        $reason = 'The outgoing lead is unavailable after an emergency call-out.';

        $this->saveOverrideDraft($shift, $reason);
        $shift->refresh();

        $this->actingAs($this->overrideManager)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [],
                'override_reason' => $reason,
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasErrors('reviewed_alert_ids');

        $this->saveOverrideDraft($shift, $reason, [
            'reviewed_alert_ids' => [$required->id],
        ]);
        $shift->refresh();

        $this->actingAs($this->overrideManager)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [$required->id],
                'override_reason' => $reason,
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasErrors('carry_forward_acknowledged');

        $this->saveOverrideDraft($shift, $reason, [
            'reviewed_alert_ids' => [$required->id],
            'carry_forward_acknowledged' => true,
        ]);
        $shift->refresh();

        $this->actingAs($this->overrideManager)
            ->post("/control-room/shifts/{$shift->id}/handover", [
                'incoming_lead_user_id' => $this->incomingLead->id,
                'reviewed_alert_ids' => [$required->id],
                'override_reason' => $reason,
                'expected_version' => $shift->handover_version,
            ])
            ->assertSessionHasNoErrors();

        $prepared = $shift->fresh();
        $snapshot = $prepared->handover_snapshot;
        $this->assertSame(Shift::HANDOVER_PREPARED, $prepared->handover_status);
        $this->assertSame($this->overrideManager->id, data_get($snapshot, 'prepared_by.id'));
        $this->assertSame($this->overrideManager->id, data_get($snapshot, 'override.actor.id'));
        $this->assertSame($reason, data_get($snapshot, 'override.reason'));
        $this->assertNotNull(data_get($snapshot, 'override.at'));
        $this->assertSame([$required->id], data_get($snapshot, 'required_alert_ids'));
        $this->assertSame(1, data_get($snapshot, 'carry_forward.total'));

        $audit = AuditLog::query()
            ->where('action', 'controlRoom.shift.handoverPrepared')
            ->where('auditable_id', $shift->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($this->overrideManager->id, data_get($audit->meta, 'override.actor_id'));
        $this->assertSame($reason, data_get($audit->meta, 'override.reason'));
        $this->assertNotNull(data_get($audit->meta, 'override.at'));

        $this->actingAs($this->overrideManager)
            ->get("/control-room/shifts/{$shift->id}/handover")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shift.handover_snapshot.override.actor.id', $this->overrideManager->id)
                ->where('shift.handover_snapshot.override.reason', $reason)
            );

        $tamperedSnapshot = $snapshot;
        data_set(
            $tamperedSnapshot,
            'override.reason',
            'A different but structurally valid override reason was inserted.',
        );
        $prepared->updateQuietly(['handover_snapshot' => $tamperedSnapshot]);
        $this->actingAs($this->incomingLead)
            ->post("/control-room/shifts/{$shift->id}/accept-handover", [
                'expected_version' => $prepared->handover_version,
            ])
            ->assertSessionHasErrors('handover');
        $this->assertSame('active', $shift->fresh()->status);
        $prepared->updateQuietly(['handover_snapshot' => $snapshot]);

        $this->actingAs($this->overrideManager)
            ->post("/control-room/shifts/{$shift->id}/accept-handover", [
                'expected_version' => $prepared->handover_version,
            ])
            ->assertForbidden();

        $this->actingAs($this->incomingLead)
            ->post("/control-room/shifts/{$shift->id}/accept-handover", [
                'expected_version' => $prepared->handover_version,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Shift::HANDOVER_ACCEPTED, $shift->fresh()->handover_status);
        $this->assertSame(1, Shift::query()->active()->count());
        $this->assertSame($this->incomingLead->id, Shift::query()->active()->value('shift_lead_user_id'));
    }

    public function test_e2e_seeder_replaces_only_its_prior_shift_and_builds_a_site_bounded_required_set(): void
    {
        $unrelatedSite = Site::factory()->create([
            'tenant_id' => 1,
            'type' => 'house',
        ]);
        $unrelatedClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $unrelatedSite->id,
        ]);
        $unrelatedAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $unrelatedSite->id,
            'client_id' => $unrelatedClient->id,
            'reference_number' => 'CR-UNRELATED-DEMO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unrelatedShift = Shift::query()->create([
            'name' => 'Unrelated live demo shift',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'status' => 'completed',
            'shift_lead_user_id' => $this->ordinaryManager->id,
        ]);
        $priorFixtureShift = Shift::query()->create([
            'name' => IncidentHandoverE2ESeeder::SHIFT_NAME,
            'starts_at' => now()->subDay(),
            'status' => 'active',
            'shift_lead_user_id' => $this->outgoingLead->id,
        ]);

        $this->seed(IncidentHandoverE2ESeeder::class);

        $operator = User::query()->where('email', IncidentHandoverE2ESeeder::OPERATOR_EMAIL)->firstOrFail();
        $incoming = User::query()->where('email', IncidentHandoverE2ESeeder::INCOMING_EMAIL)->firstOrFail();
        $freshShift = Shift::query()
            ->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)
            ->where('status', 'active')
            ->firstOrFail();
        $fixtureAlerts = ControlRoomAlert::query()
            ->whereIn('reference_number', IncidentHandoverE2ESeeder::REQUIRED_ALERT_REFERENCES)
            ->orderBy('id')
            ->get();
        $scope = app(ControlRoomHandoverScopeService::class)->build($freshShift, $operator);

        $this->assertSame('completed', $priorFixtureShift->fresh()->status);
        $this->assertNotNull($priorFixtureShift->fresh()->ends_at);
        $this->assertSame('completed', $unrelatedShift->fresh()->status);
        $this->assertTrue($unrelatedAlert->fresh()->exists);
        $this->assertNotSame($priorFixtureShift->id, $freshShift->id);
        $this->assertSame($operator->id, $freshShift->shift_lead_user_id);
        $this->assertContains($incoming->id, $freshShift->team_members);
        $this->assertCount(count(IncidentHandoverE2ESeeder::REQUIRED_ALERT_REFERENCES), $fixtureAlerts);
        $this->assertEqualsCanonicalizing(
            $fixtureAlerts->pluck('id')->all(),
            collect($scope['required_alerts'])->pluck('id')->all(),
        );
        $this->assertNotContains($unrelatedAlert->id, collect($scope['required_alerts'])->pluck('id')->all());
        $this->assertSame(0, data_get($scope, 'carry_forward.total'));

        $this->seed(IncidentHandoverE2ESeeder::class);

        $this->assertSame(1, Shift::query()
            ->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)
            ->where('status', 'active')
            ->count());
        $this->assertSame('completed', $unrelatedShift->fresh()->status);
        $this->assertDatabaseHas('control_room_alerts', ['id' => $unrelatedAlert->id]);
    }

    public function test_e2e_seeder_refuses_to_mutate_an_unrelated_active_shift(): void
    {
        $unrelatedShift = Shift::query()->create([
            'name' => 'Real operator shift',
            'starts_at' => now()->subHour(),
            'status' => 'active',
            'shift_lead_user_id' => $this->ordinaryManager->id,
        ]);

        try {
            $this->seed(IncidentHandoverE2ESeeder::class);
            $this->fail('The fixture seeder should fail closed when another active shift exists.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Refusing to replace an unrelated active Control Room shift',
                $exception->getMessage(),
            );
            $this->assertStringContainsString((string) $unrelatedShift->id, $exception->getMessage());
        }

        $this->assertSame('active', $unrelatedShift->fresh()->status);
        $this->assertSame(0, Shift::query()->where('name', IncidentHandoverE2ESeeder::SHIFT_NAME)->count());
    }

    private function activeShift($startsAt): Shift
    {
        return Shift::query()->create([
            'name' => 'Stale outgoing control desk',
            'starts_at' => $startsAt,
            'status' => 'active',
            'shift_lead_user_id' => $this->outgoingLead->id,
            'team_members' => [$this->outgoingLead->id],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function alert(string $severity, string $reference, array $overrides = []): ControlRoomAlert
    {
        return ControlRoomAlert::factory()->open()->create(array_replace([
            'site_id' => $this->site->id,
            'severity' => $severity,
            'reference_number' => $reference,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function saveOverrideDraft(Shift $shift, string $reason, array $overrides = []): void
    {
        $scope = app(ControlRoomHandoverScopeService::class)->build(
            $shift->fresh(),
            $this->overrideManager,
        );
        $acknowledged = (bool) ($overrides['carry_forward_acknowledged'] ?? false);
        $payload = array_replace([
            'handover_notes' => '',
            'incoming_shift_name' => 'Incoming recovery desk',
            'incoming_lead_user_id' => $this->incomingLead->id,
            'incoming_team_members' => [$this->incomingLead->id],
            'reviewed_alert_ids' => [],
            'priority_alert_ids' => [],
            'carry_forward_acknowledged' => false,
            'carry_forward_signature' => $acknowledged
                ? data_get($scope, 'carry_forward.signature')
                : null,
            'override_reason' => $reason,
            'expected_version' => $shift->fresh()->handover_version,
        ], $overrides);

        $this->actingAs($this->overrideManager)
            ->patch("/control-room/shifts/{$shift->id}/handover/draft", $payload)
            ->assertSessionHasNoErrors();
    }

    private function staffAt(Site $site, ?string $roleName = null): User
    {
        $user = User::factory()->create([
            'role' => $roleName ?? 'staff',
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        if ($roleName !== null) {
            $user->roles()->syncWithoutDetaching([
                Role::query()->where('name', $roleName)->value('id'),
            ]);
        }

        HrEmployeeProfile::query()->create([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'employee_number' => 'EMP-STALE-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Control Room test user',
            'position_role' => $roleName ?? 'staff',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function allow(User $user, array $permissionKeys): void
    {
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->syncWithoutDetaching(
            $permissions->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );
    }
}
