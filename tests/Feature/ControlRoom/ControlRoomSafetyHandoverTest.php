<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorklistQuery;
use App\Services\HealthSafety\HsEventService;
use App\Services\Incidents\IncidentJourney;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomSafetyHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->operator = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->operator->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
        $this->client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
    }

    public function test_active_alerts_use_the_canonical_priority_worklist_and_explicit_history_lens(): void
    {
        $critical = $this->alert(['severity' => 'critical', 'reference_number' => 'CR-2026-0301']);
        $high = $this->alert(['severity' => 'high', 'reference_number' => 'CR-2026-0302']);
        $history = $this->alert([
            'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'reference_number' => 'CR-2026-0303',
        ]);
        $this->alert(['severity' => 'critical', 'status' => ControlRoomAlert::STATUS_DISMISSED]);
        $this->alert(['severity' => 'critical', 'snoozed_until' => now()->addHour()]);

        $playbook = Playbook::factory()->create(['name' => 'Critical response']);
        PlaybookRun::query()->create([
            'alert_id' => $critical->id,
            'playbook_id' => $playbook->id,
            'status' => PlaybookRun::STATUS_IN_PROGRESS,
            'current_step' => 1,
            'completed_steps' => 1,
            'total_steps' => 3,
        ]);

        $expected = app(AlertWorklistQuery::class)
            ->forUser($this->operator)
            ->pluck('control_room_alerts.id')
            ->all();

        $this->actingAs($this->operator)
            ->get('/control-room/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data', fn ($rows) => collect($rows)->pluck('id')->all() === $expected)
                ->where('alerts.data.0.summary', fn ($summary) => is_string($summary) && $summary !== '')
                ->has('alerts.data.0.playbook')
                ->has('alerts.data.0.sla')
                ->where('sort.label', 'Priority: SLA breach, severity, escalation, next deadline, oldest')
                ->where('filters.lens', 'active')
            );

        $this->assertSame([$critical->id, $high->id], $expected);

        $this->actingAs($this->operator)
            ->get('/control-room/alerts?lens=history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.lens', 'history')
                ->where('alerts.data', fn ($rows) => collect($rows)->pluck('id')->contains($history->id))
            );
    }

    public function test_alert_can_create_one_canonical_incident_and_hs_handover_with_official_references(): void
    {
        $alert = $this->alert([
            'reference_number' => 'CR-2026-0310',
            'alert_type' => 'welfare_check',
            'severity' => 'high',
        ]);
        $payload = [
            'type' => 'welfare_check',
            'title' => 'Missed welfare check',
            'description' => 'The scheduled welfare check was missed.',
            'occurred_at' => now()->subMinutes(15)->toIso8601String(),
        ];

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->operator)
                ->postJson("/control-room/alerts/{$alert->id}/create-incident", $payload)
                ->assertOk()
                ->assertJsonPath('journey.alert.id', $alert->id)
                ->assertJsonPath('journey.alert.reference_number', 'CR-2026-0310')
                ->assertJsonPath('journey.incident.reference_number', fn ($value) => is_string($value) && str_starts_with($value, 'INC-'))
                ->assertJsonPath('journey.health_safety.reference_number', fn ($value) => is_string($value) && str_starts_with($value, 'HS-'))
                ->assertJsonPath('journey.health_safety.handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE);
        }

        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertSame($alert->id, $alert->fresh()->clientIncident?->control_room_alert_id);
    }

    public function test_safety_handover_lenses_read_the_canonical_journey_and_paginate_in_the_database(): void
    {
        $needsIncident = $this->alert(['reference_number' => 'CR-2026-0320']);
        $awaiting = $this->journey('CR-2026-0321');
        $accepted = $this->journey('CR-2026-0322');
        app(HsEventService::class)->acceptHandover(
            $accepted->hsEvent,
            $this->operator,
            $this->operator,
            'Accepted for governance',
        );
        $governanceOpen = $this->journey('CR-2026-0323');
        $governanceOpen->alert->forceFill(['status' => ControlRoomAlert::STATUS_RESOLVED])->saveQuietly();
        $complete = $this->journey('CR-2026-0324');
        $complete->alert->forceFill(['status' => ControlRoomAlert::STATUS_CLOSED])->saveQuietly();
        $complete->hsEvent->forceFill(['status' => HsEvent::STATUS_CLOSED])->saveQuietly();

        $expectLens = function (string $lens, int $expectedId): void {
            $this->actingAs($this->operator)
                ->get('/control-room/incidents?lens='.$lens)
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('control-room/incidents')
                    ->where('filters.lens', $lens)
                    ->where('journeys.data', fn ($rows) => collect($rows)->pluck('alert.id')->contains($expectedId))
                    ->has('journeys.meta')
                    ->has('lenses')
                );
        };

        $expectLens('needs_incident', $needsIncident->id);
        $expectLens('awaiting_health_safety', $awaiting->alert->id);
        $expectLens('accepted_in_progress', $accepted->alert->id);
        $expectLens('operational_complete_governance_open', $governanceOpen->alert->id);
        $expectLens('complete', $complete->alert->id);

        ControlRoomAlert::factory()->count(27)->create([
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
        ]);

        $this->actingAs($this->operator)
            ->get('/control-room/incidents?lens=needs_incident')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('journeys.data', 25)
                ->where('journeys.meta.per_page', 25)
                ->where('journeys.meta.total', 28)
            );
    }

    private function alert(array $attributes = []): ControlRoomAlert
    {
        return ControlRoomAlert::factory()->create(array_replace([
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
        ], $attributes));
    }

    private function journey(string $reference): IncidentJourney
    {
        return app(IncidentJourneyService::class)->submitFromAlert(
            $this->alert(['reference_number' => $reference]),
            [
                'type' => 'injury',
                'title' => 'Safety handover '.$reference,
                'description' => 'Canonical handover test',
                'occurred_at' => now(),
            ],
            $this->operator,
        );
    }
}
