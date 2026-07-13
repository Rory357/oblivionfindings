<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\IncidentGovernanceEscalation;
use App\Domain\Governance\Models\NotifiableIncident;
use App\Domain\Governance\Services\IncidentEscalationService;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SafeguardingConcern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Verifies the cross-module escalation wires:
 *  - ClientIncident (critical) → IncidentGovernanceEscalation
 *  - SafeguardingConcern (critical) → NotifiableIncident
 *
 * Both are best-effort: failures must not break the source workflow,
 * idempotency must prevent duplicates.
 */
class GovernanceCrossModuleEscalationTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_critical_client_incident_creates_governance_escalation(): void
    {
        $reporter = $this->createAdminUser();
        $client = Client::factory()->create();

        ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'severity' => 'critical',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('incident_governance_escalations', [
            'escalation_reason' => 'serious_harm',
            'status' => 'pending',
        ]);
    }

    public function test_low_severity_incident_does_not_escalate(): void
    {
        $reporter = $this->createAdminUser();
        $client = Client::factory()->create();

        ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'severity' => 'low',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseCount('incident_governance_escalations', 0);
    }

    public function test_escalation_is_idempotent(): void
    {
        $reporter = $this->createAdminUser();
        $client = Client::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'severity' => 'critical',
            'status' => 'submitted',
        ]));

        $service = app(IncidentEscalationService::class);
        DB::enableQueryLog();
        $service->escalateClientIncident($incident);
        $service->escalateClientIncident($incident);

        $this->assertSame(1, IncidentGovernanceEscalation::query()->where('client_incident_id', $incident->id)->count());
        $lockQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'client_incidents')
                && str_contains(strtolower($query['query']), 'for update'),
        );
        $this->assertCount(2, $lockQueries, 'Every governance escalation attempt must serialize on the incident row.');
    }

    public function test_escalation_failure_is_not_masked_by_unavailable_reason_context(): void
    {
        $missingIncident = new ClientIncident;
        $missingIncident->forceFill([
            'id' => 999_999_999,
            'severity' => 'critical',
        ]);

        $this->assertNull(
            app(IncidentEscalationService::class)->escalateClientIncident($missingIncident),
        );
    }

    public function test_critical_safeguarding_concern_creates_notifiable_incident(): void
    {
        $reporter = $this->createAdminUser();

        $concern = SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $reporter->id,
            'severity' => 'critical',
            'concern_type' => 'abuse',
            'abuse_category' => 'physical',
        ]);

        $this->assertDatabaseHas('notifiable_incidents', [
            'incident_type' => 'safeguarding',
            'related_incident_id' => $concern->id,
            'severity' => 'critical',
            'status' => 'pending',
        ]);

        // Authority resolution: abuse → police
        $incident = NotifiableIncident::where('related_incident_id', $concern->id)->first();
        $this->assertSame('police', $incident->notification_authority);
    }

    public function test_safeguarding_concern_below_critical_does_not_create_notifiable(): void
    {
        $reporter = $this->createAdminUser();

        SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $reporter->id,
            'severity' => 'high',
        ]);

        $this->assertDatabaseCount('notifiable_incidents', 0);
    }

    public function test_safeguarding_escalation_to_critical_creates_notifiable(): void
    {
        $reporter = $this->createAdminUser();

        $concern = SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $reporter->id,
            'severity' => 'high',
        ]);

        $this->assertDatabaseCount('notifiable_incidents', 0);

        $concern->update(['severity' => 'critical']);

        $this->assertDatabaseHas('notifiable_incidents', [
            'related_incident_id' => $concern->id,
            'severity' => 'critical',
        ]);
    }

    public function test_safeguarding_concern_creates_only_one_notifiable_per_concern(): void
    {
        $reporter = $this->createAdminUser();

        $concern = SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $reporter->id,
            'severity' => 'critical',
            'concern_type' => 'abuse',
            'abuse_category' => 'physical',
        ]);

        // Touch the model again to verify idempotency.
        $concern->touch();
        $concern->refresh();
        $concern->update(['severity' => 'critical']);

        $this->assertSame(
            1,
            NotifiableIncident::where('related_incident_id', $concern->id)->count(),
            'Expected only one NotifiableIncident per concern despite multiple writes.'
        );
    }
}
