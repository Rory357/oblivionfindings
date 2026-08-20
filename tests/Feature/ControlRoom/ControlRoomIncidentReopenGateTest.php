<?php

namespace Tests\Feature\ControlRoom;

use App\Jobs\DispatchIncidentLifecycleSignalOutbox;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\IncidentLifecycleSignalOutbox;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlRoomIncidentReopenGateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Site $site;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
    }

    public function test_explicit_operational_reopen_requires_the_matching_incident_attention_marker(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create([
            ...$this->alertProvenance(),
            'context' => ['source_marker' => 'preserved'],
        ]);
        ClientIncident::factory()->reviewed()->create([
            ...$this->incidentProvenance(),
            'control_room_alert_id' => $alert->id,
            'reopened_at' => now(),
            'reopened_by' => $this->admin->id,
            'reopened_reason' => 'New information was received.',
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/reopen-for-incident", [
                'reason' => 'Restart the immediate response.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('alert');

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->status);
        $this->assertSame('preserved', data_get($alert->context, 'source_marker'));
    }

    public function test_incident_reopen_commits_its_outbox_without_mutating_the_alert_before_delivery(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create($this->alertProvenance());
        $incident = ClientIncident::factory()->create([
            ...$this->incidentProvenance(),
            'status' => 'closed',
            'control_room_alert_id' => $alert->id,
            'closed_by' => $this->admin->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Original closure outcome',
            'closed_notes' => 'Original closure notes',
        ]);
        Bus::fake([DispatchIncidentLifecycleSignalOutbox::class]);

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'A material witness statement was received.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $incident->refresh();
        $this->assertSame('reviewed', $incident->status);
        $this->assertSame($this->admin->id, $incident->reopened_by);
        $this->assertSame('A material witness statement was received.', $incident->reopened_reason);
        $this->assertNull($incident->closed_by);
        $this->assertNull($incident->closed_at);
        $this->assertSame('pending', IncidentLifecycleSignalOutbox::query()->sole()->status);
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->fresh()->status);
        $this->assertArrayNotHasKey('journey_attention', $alert->fresh()->context ?? []);
    }

    public function test_incident_reopen_rejects_a_poisoned_foreign_alert_link_without_mutating_either_record(): void
    {
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'status' => 'active',
        ]);
        $foreignAlert = ControlRoomAlert::factory()->closed()->create([
            'site_id' => $foreignSite->id,
            'client_id' => $foreignClient->id,
            'context' => ['foreign_marker' => 'must remain untouched'],
        ]);
        $incident = ClientIncident::factory()->create([
            ...$this->incidentProvenance(),
            'status' => 'closed',
            'control_room_alert_id' => $foreignAlert->id,
            'closed_by' => $this->admin->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Original local closure',
        ]);

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'This forged link must not cross the Site ownership boundary.',
            ])
            ->assertNotFound();

        $incident->refresh();
        $foreignAlert->refresh();

        $this->assertSame('closed', $incident->status);
        $this->assertNull($incident->reopened_at);
        $this->assertSame('Original local closure', $incident->closed_outcome);
        $this->assertSame(['foreign_marker' => 'must remain untouched'], $foreignAlert->context);
        $this->assertArrayNotHasKey('journey_attention', $foreignAlert->context ?? []);
    }

    public function test_incident_reopen_locks_the_source_parent_before_validating_its_alert_link(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create($this->alertProvenance());
        SlaDefinition::create([
            'name' => 'Operational reopen test SLA',
            'code' => 'operational-reopen-'.$alert->id,
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);
        $incident = ClientIncident::factory()->create([
            ...$this->incidentProvenance(),
            'status' => 'closed',
            'control_room_alert_id' => $alert->id,
            'closed_by' => $this->admin->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Controlled at the time',
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower(str_replace(['`', '"'], '', $query->sql));
        });
        Bus::fake([DispatchIncidentLifecycleSignalOutbox::class]);

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'New evidence changes the immediate risk picture.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertIncidentBeforeAlertLocks($queries, 'incident reopen');
    }

    public function test_operational_reopen_rejects_a_foreign_incident_that_claims_the_local_alert(): void
    {
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
        ]);
        $alert = ControlRoomAlert::factory()->closed()->create([
            ...$this->alertProvenance(),
            'context' => ['preserved' => true],
        ]);
        $foreignIncident = ClientIncident::factory()->reviewed()->create([
            'site_id' => $foreignSite->id,
            'client_id' => $foreignClient->id,
            'control_room_alert_id' => $alert->id,
            'reopened_at' => now(),
            'reopened_by' => $this->admin->id,
            'reopened_reason' => 'Poisoned cross-Site relationship.',
        ]);
        $alert->forceFill([
            'context' => [
                'preserved' => true,
                'journey_attention' => [
                    'type' => 'incident_reopened',
                    'incident_id' => $foreignIncident->id,
                    'requires_operational_reopen' => true,
                ],
            ],
        ])->save();

        $this->actingAs($this->admin)
            ->from("/control-room/alerts/{$alert->id}")
            ->post("/control-room/alerts/{$alert->id}/reopen-for-incident", [
                'reason' => 'This must not cross the ownership boundary.',
            ])
            ->assertRedirect("/control-room/alerts/{$alert->id}")
            ->assertSessionHasErrors([
                'alert' => 'The linked incident is not available for this operational alert.',
            ]);

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->status);
        $this->assertTrue((bool) data_get($alert->context, 'preserved'));
        $this->assertSame($foreignIncident->id, data_get($alert->context, 'journey_attention.incident_id'));
    }

    /** @param array<int, string> $queries */
    private function assertIncidentBeforeAlertLocks(array $queries, string $operation): void
    {
        $alertLock = collect($queries)->search(
            fn (string $query): bool => str_contains($query, 'from control_room_alerts')
                && str_contains($query, 'for update'),
        );
        $incidentLock = collect($queries)->search(
            fn (string $query): bool => str_contains($query, 'from client_incidents')
                && str_contains($query, 'for update'),
        );

        $this->assertNotFalse($alertLock, "The {$operation} must lock its Control Room alert.");
        $this->assertNotFalse($incidentLock, "The {$operation} must lock its incident.");
        $this->assertLessThan(
            $alertLock,
            $incidentLock,
            "The {$operation} must lock its source incident before its linked alert.",
        );
    }

    /** @return array{site_id: int, client_id: int} */
    private function alertProvenance(): array
    {
        return [
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
        ];
    }

    /** @return array{site_id: int, client_id: int} */
    private function incidentProvenance(): array
    {
        return $this->alertProvenance();
    }
}
