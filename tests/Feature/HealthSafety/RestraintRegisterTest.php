<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\Site;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings;
use App\Services\HealthSafety\BehaviourSupportPlanApprovalService;
use App\Services\HealthSafety\BehaviourSupportPlanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Restraints & Behaviour Support — the old register cases that the restraint
 * workspace suites do not already cover, rewritten against the keyed
 * restraint commands (UUID idempotency_key, expected_version, current staff
 * and Client read at the Site): the two-Site register/picker/export contract,
 * wrong-Site restraint objects over HTTP, the named all-Sites H&S bypass,
 * the capture justification requirements, linking an in-plan event only to
 * an approved published plan, and the same-Client incident link. Every other
 * old case maps to a newer test; see docs/health-safety-build/PLAN.md.
 */
class RestraintRegisterTest extends RestraintEventCommandTestCase
{
    private function clientAt(Site $site): Client
    {
        return Client::factory()->create(['site_id' => $site->id]);
    }

    /** A pre-command (legacy) restraint record at another Client's Site. */
    private function legacyEventFor(Client $client, array $attributes = []): RestraintEvent
    {
        return RestraintEvent::factory()->create(array_merge([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'source_schema_version' => null,
            'started_at' => now()->subDay(),
            'ended_at' => now()->subDay()->addMinutes(10),
            'duration_minutes' => 10,
        ], $attributes));
    }

    private function legacyPlanFor(Client $client, array $attributes = []): BehaviourSupportPlan
    {
        return BehaviourSupportPlan::factory()->create(array_merge([
            'client_id' => $client->id,
            'source_schema_version' => null,
            'status' => 'active',
        ], $attributes));
    }

    private function openIncidentFor(Client $client): ClientIncident
    {
        return ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'status' => 'open',
            'is_hr_confidential' => false,
        ]);
    }

    /* ---- Register payload + permissions ---- */

    public function test_two_site_register_counts_pickers_and_export_use_the_hs_site_contract(): void
    {
        $siteA = $this->site;
        $siteB = Site::factory()->create(['is_active' => true, 'name' => 'Hidden House']);
        $viewer = $this->staff(['restraints.view', 'restraints.manage', 'clients.viewAny', 'incidents.viewAny']);
        $clientA = $this->client;
        $clientB = $this->clientAt($siteB);
        $eventA = $this->event();
        $eventB = $this->legacyEventFor($clientB);
        $this->legacyPlanFor($clientA);
        $this->legacyPlanFor($clientB, ['title' => 'Hidden House plan']);
        $incidentA = $this->openIncidentFor($clientA);
        $incidentB = $this->openIncidentFor($clientB);
        $staffA = $this->staff([]);
        $staffB = $this->staff([], $siteB);

        $this->actingAs($viewer)
            ->get('/health-safety/restraints?period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/restraints/workspace-index')
                ->has('events.data', 1)
                ->where('events.data.0.id', $eventA->id)
                ->has('plans.data', 1)
                ->where('tabCounts.events.all', 1)
                ->where('tabCounts.plans.all', 1)
                ->where('hero.live.events_30d', 1)
                ->where('sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$siteA->id])
                ->where('clients', fn ($clients) => collect($clients)->pluck('id')->contains($clientA->id)
                    && ! collect($clients)->pluck('id')->contains($clientB->id))
                ->where('incidents', fn ($incidents) => collect($incidents)->pluck('id')->contains($incidentA->id)
                    && ! collect($incidents)->pluck('id')->contains($incidentB->id))
                ->where('staff', fn ($staff) => collect($staff)->pluck('id')->contains($staffA->id)
                    && ! collect($staff)->pluck('id')->contains($staffB->id)));

        $this->assertNotNull($eventB->reference_number);
        $csv = $this->actingAs($viewer)
            ->get('/health-safety/restraints/export?lens=events')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($eventA->reference_number, $csv);
        $this->assertStringNotContainsString($eventB->reference_number, $csv);
        $plansCsv = $this->actingAs($viewer)->get('/health-safety/restraints/export?lens=plans')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Hidden House plan', $plansCsv);

        $this->actingAs($viewer)->get('/health-safety/restraints?site_id='.$siteB->id)->assertForbidden();
        $this->actingAs($viewer)->get('/health-safety/restraints?client_id='.$clientB->id)->assertForbidden();
        $this->actingAs($viewer)->get('/health-safety/restraints/export?site_id='.$siteB->id)->assertForbidden();
        $this->actingAs($viewer)->get('/health-safety/restraints/export?client_id='.$clientB->id)->assertForbidden();
    }

    public function test_wrong_site_restraint_objects_are_denied_and_cannot_be_mutated(): void
    {
        Storage::fake('private');
        $siteB = Site::factory()->create(['is_active' => true]);
        // Client read, incident authority and every restraint grant are held; only the Site differs.
        $viewer = $this->staff([
            'restraints.view',
            'restraints.create',
            'restraints.review',
            'restraints.manage',
            'clients.viewAny',
            'incidents.viewAny',
            'incidents.update',
        ]);
        $clientB = $this->clientAt($siteB);
        $eventB = $this->legacyEventFor($clientB, ['severity' => 'high', 'reviewed_at' => null]);
        $planB = $this->legacyPlanFor($clientB, ['status' => 'draft']);
        $incidentB = $this->openIncidentFor($clientB);
        $attachment = RestraintEventAttachment::query()->create([
            'restraint_event_id' => $eventB->id,
            'disk' => 'private',
            'original_name' => 'hidden-evidence.pdf',
            'path' => 'restraint_attachments/hidden-evidence.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
        ]);
        Storage::disk('private')->put($attachment->path, 'hidden evidence bytes');
        $key = fn () => ['expected_version' => (int) $eventB->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid()];
        $planKey = fn () => ['expected_version' => (int) $planB->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid()];

        // The current restraint services refuse another Site's sources with 403 (main concealed them as 404).
        $this->actingAs($viewer)->getJson('/health-safety/restraints/clients/'.$clientB->id.'/summary')->assertForbidden();
        $this->actingAs($viewer)->getJson('/health-safety/restraints/events/'.$eventB->id)->assertForbidden();
        $this->actingAs($viewer)->putJson('/health-safety/restraints/events/'.$eventB->id,
            [...$key(), 'reviewed_at' => now()->subMinutes(5)->toIso8601String(), 'severity' => 'critical'])->assertForbidden();
        $this->actingAs($viewer)->postJson('/health-safety/restraints/events/'.$eventB->id.'/link-incident',
            [...$key(), 'related_incident_id' => $incidentB->id])->assertForbidden();
        $this->actingAs($viewer)->postJson('/health-safety/restraints/plans/'.$planB->id.'/activate', $planKey())->assertForbidden();
        $this->actingAs($viewer)->postJson('/health-safety/restraints/plans/'.$planB->id.'/review',
            [...$planKey(), 'outcome' => 'continued', 'reviewed_at' => now()->subMinutes(5)->toIso8601String()])->assertForbidden();
        $this->actingAs($viewer)->postJson('/health-safety/restraints/events/'.$eventB->id.'/attachments',
            [...$key(), 'file' => UploadedFile::fake()->create('cross-site.pdf', 10, 'application/pdf')])->assertForbidden();
        $this->actingAs($viewer)->get('/health-safety/restraints/events/'.$eventB->id.'/attachments/'.$attachment->id.'/download')->assertForbidden();
        $this->actingAs($viewer)->deleteJson('/health-safety/restraints/events/'.$eventB->id.'/attachments/'.$attachment->id,
            [...$key(), 'reason' => 'Cross-Site removal attempt'])->assertForbidden();

        $this->assertSame('high', $eventB->fresh()->severity);
        $this->assertNull($eventB->fresh()->reviewed_at);
        $this->assertNull($eventB->fresh()->related_incident_id);
        $this->assertSame('draft', $planB->fresh()->status);
        $this->assertDatabaseHas('restraint_event_attachments', ['id' => $attachment->id, 'removed_at' => null]);
        $this->assertSame(1, RestraintEventAttachment::where('restraint_event_id', $eventB->id)->count());
        Storage::disk('private')->assertExists($attachment->path);
        $this->assertDatabaseCount('behaviour_support_plan_reviews', 0);
        $this->assertDatabaseCount('restraint_command_receipts', 0);
    }

    public function test_named_global_hs_permission_bypasses_site_scope_but_manage_alone_does_not(): void
    {
        $siteB = Site::factory()->create(['is_active' => true]);
        $clientB = $this->clientAt($siteB);
        $eventA = $this->event();
        $eventB = $this->legacyEventFor($clientB, ['severity' => 'high']);
        $grants = ['restraints.view', 'restraints.review', 'restraints.manage', 'clients.viewAny'];
        $restricted = $this->staff($grants);
        // Each half of the bypass alone is not enough: the H&S all-Sites grant does not
        // replace an independent Client read at the other Site, and vice versa.
        $hsSitesOnly = $this->staff([...$grants, 'healthSafety.viewAllSites']);
        $clientSitesOnly = $this->staff([...$grants, 'clinical.accessAllSites']);
        $global = $this->staff([...$grants, 'healthSafety.viewAllSites', 'clinical.accessAllSites']);
        $review = fn () => [
            'expected_version' => (int) $eventB->fresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'reviewed_at' => now()->subMinutes(5)->toIso8601String(),
            'severity' => 'critical',
        ];

        foreach ([$restricted, $hsSitesOnly, $clientSitesOnly] as $actor) {
            $this->actingAs($actor)
                ->get('/health-safety/restraints?period=all')
                ->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.id', $eventA->id));
            $this->actingAs($actor)
                ->putJson('/health-safety/restraints/events/'.$eventB->id, $review())
                ->assertForbidden();
        }
        $this->assertSame('high', $eventB->fresh()->severity);

        $this->actingAs($global)
            ->get('/health-safety/restraints?period=all')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 2)->where('tabCounts.events.all', 2));
        $this->actingAs($global)
            ->putJson('/health-safety/restraints/events/'.$eventB->id, $review())
            ->assertOk()
            ->assertJsonPath('restraint_confirmation.command', 'review');
        $this->assertSame('critical', $eventB->fresh()->severity);
        $this->assertSame($global->id, (int) $eventB->fresh()->reviewed_by);
    }

    /* ---- Event create ---- */

    public function test_store_event_requires_the_justification_and_least_restrictive_record(): void
    {
        foreach (['trigger_description', 'de_escalation_attempted', 'restraint_description'] as $field) {
            $this->actingAs($this->actor)
                ->postJson('/health-safety/restraints/events', $this->capture([$field => '']))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
        $this->actingAs($this->actor)
            ->postJson('/health-safety/restraints/events', $this->capture(['within_support_plan' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deviation_reason');
        $this->actingAs($this->actor)
            ->postJson('/health-safety/restraints/events', $this->capture(['injury_occurred' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('injury_details');
        $this->assertDatabaseCount('restraint_events', 0);
        $this->assertDatabaseCount('restraint_command_receipts', 0);

        $response = $this->actingAs($this->actor)
            ->postJson('/health-safety/restraints/events', $this->capture([
                'started_at' => now()->subMinutes(15)->toIso8601String(),
                'ended_at' => now()->toIso8601String(),
                'severity' => 'high',
                'trigger_description' => 'Escalation in lounge',
                'de_escalation_attempted' => 'Offered quiet space, verbal de-escalation',
                'restraint_description' => 'Two-person guided hold',
            ]))
            ->assertOk();

        $event = RestraintEvent::findOrFail($response->json('restraint_confirmation.event_id'));
        $this->assertSame('high', $event->severity);
        $this->assertSame(15, $event->duration_minutes);
        $this->assertSame('Offered quiet space, verbal de-escalation', $event->de_escalation_attempted);
    }

    public function test_in_plan_event_auto_links_clients_active_plan(): void
    {
        $plans = app(BehaviourSupportPlanService::class);
        $planInput = fn (string $title) => ['client_id' => $this->client->id, 'title' => $title, 'triggers' => 'Noise',
            'de_escalation_strategies' => 'Quiet support', 'approved_interventions' => 'Documented plan content',
            'prohibited_interventions' => 'Recorded limitations', 'developed_at' => '2026-10-01', 'review_date' => '2026-11-30',
            'idempotency_key' => (string) Str::uuid()];
        $active = BehaviourSupportPlan::findOrFail($plans->create($this->actor, $planInput('Approved active plan'))['plan_id']);
        $candidate = BehaviourSupportPlanVersion::findOrFail($active->current_candidate_version_id);
        $target = ['candidate_version_id' => $candidate->id, 'content_digest' => $candidate->content_digest,
            'expected_policy_revision' => app(BehaviourSupportApprovalPolicySettings::class)->snapshot()['policy_revision']];
        $specialist = $this->staff(['restraints.approve', 'clients.viewAny']);
        app(BehaviourSupportPlanApprovalService::class)->decide($active, $specialist, ['expected_version' => $active->fresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(), ...$target, 'approval_decision' => 'approve', 'origin' => 'internal_specialist',
            'approver_role' => 'Organisation designated specialist', 'approved_at' => now()->subDay()->toIso8601String(),
            'effective_from' => now()->subDay()->toIso8601String(), 'basis' => 'Actual plan-specific decision',
            'evidence_references' => [], 'activate_requested' => false]);
        $published = $plans->activate($active, $this->actor, ['expected_version' => $active->fresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(), ...$target]);
        // A second, unapproved draft plan for the same Client is never the auto-linked authority.
        $plans->create($this->actor, $planInput('Unapproved draft plan'));

        $response = $this->actingAs($this->actor)
            ->postJson('/health-safety/restraints/events', $this->capture([
                'started_at' => now()->subMinutes(5)->toIso8601String(),
                'ended_at' => null,
                'within_support_plan' => true,
                // behaviour_support_plan_id intentionally omitted
            ]))
            ->assertOk();

        $event = RestraintEvent::findOrFail($response->json('restraint_confirmation.event_id'));
        $this->assertSame((int) $active->id, (int) $event->behaviour_support_plan_id);
        $this->assertSame((int) $published['published_version_id'], (int) $event->behaviour_support_plan_version_id);
        $this->assertSame('verified', $event->plan_authorization_state);
    }

    /* ---- Post-hoc incident link (D3) ---- */

    public function test_link_incident_rejects_cross_client_incident(): void
    {
        $event = $this->event();
        $otherClient = $this->clientAt($this->site);
        // An incident belonging to a DIFFERENT client must not be linkable.
        $otherClientIncident = $this->openIncidentFor($otherClient);

        $this->actingAs($this->actor)
            ->postJson('/health-safety/restraints/events/'.$event->id.'/link-incident', $this->command($event, [
                'related_incident_id' => $otherClientIncident->id,
            ]))
            ->assertNotFound();

        $this->assertNull($event->refresh()->related_incident_id);
        $this->assertSame(1, (int) $event->lock_version);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }
}
