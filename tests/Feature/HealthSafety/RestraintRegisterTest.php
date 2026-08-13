<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanReview;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Restraints & Behaviour Support redesign — register payload, dedicated
 * permission scheme, the critical-severity review fix (audit #13), plan
 * lifecycle + review history, and premium evidence upload.
 */
class RestraintRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function officer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    protected function supportWorker(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        if ($role = Role::where('name', 'support_worker')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    /** @param list<string> $permissions */
    private function siteBoundUser(Site $site, array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'name' => 'restraint_site_'.str()->uuid(),
            'label' => 'Restraint Site Test',
            'level' => 50,
            'type' => 'custom',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }

    private function clientAt(Site $site): Client
    {
        return Client::factory()->create(['site_id' => $site->id]);
    }

    private function eventFor(Client $client, array $attributes = []): RestraintEvent
    {
        return RestraintEvent::factory()->create(array_merge([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
        ], $attributes));
    }

    private function incidentFor(Client $client): ClientIncident
    {
        return ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
        ]);
    }

    /* ---- Register payload + permissions ---- */

    public function test_index_renders_gold_standard_payload(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        RestraintEvent::factory()->create(['site_id' => $site->id, 'started_at' => now()->subDay()]);
        BehaviourSupportPlan::factory()->create();

        $this->actingAs($this->officer())
            ->get('/health-safety/restraints')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/restraints/index')
                ->where('lens', 'events')
                ->has('events.data', 1)
                ->has('tabCounts.events')
                ->has('tabCounts.plans')
                ->has('hero.live')
                ->has('hero.attention')
                ->has('hero.badges')
                ->where('can.create', true)
                ->where('can.review', true)
                ->where('can.manage', true)
                ->has('incidents')
                ->has('clients')
                ->has('staff')
            );
    }

    public function test_plans_lens_renders_plan_rows(): void
    {
        BehaviourSupportPlan::factory()->create(['status' => 'active']);

        $this->actingAs($this->officer())
            ->get('/health-safety/restraints?lens=plans')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('lens', 'plans')
                ->has('plans.data', 1)
                ->where('plans.data.0.reference', fn ($r) => str_starts_with($r, 'BSP-'))
            );
    }

    public function test_view_requires_restraints_permission(): void
    {
        $this->actingAs($this->supportWorker())
            ->get('/health-safety/restraints')
            ->assertForbidden();
    }

    public function test_two_site_register_counts_pickers_and_export_use_the_hs_site_contract(): void
    {
        $siteA = Site::factory()->create(['name' => 'Allowed House']);
        $siteB = Site::factory()->create(['name' => 'Hidden House']);
        $viewer = $this->siteBoundUser($siteA, ['restraints.view', 'restraints.manage']);
        $clientA = $this->clientAt($siteA);
        $clientB = $this->clientAt($siteB);
        $eventA = $this->eventFor($clientA, ['started_at' => now()->subDay()]);
        $eventB = $this->eventFor($clientB, ['started_at' => now()->subDay()]);
        BehaviourSupportPlan::factory()->create(['client_id' => $clientA->id, 'status' => 'active']);
        BehaviourSupportPlan::factory()->create(['client_id' => $clientB->id, 'status' => 'active']);
        $incidentA = ClientIncident::factory()->create(['client_id' => $clientA->id, 'site_id' => $siteA->id]);
        $incidentB = ClientIncident::factory()->create(['client_id' => $clientB->id, 'site_id' => $siteB->id]);
        $staffA = $this->siteBoundUser($siteA, []);
        $staffB = $this->siteBoundUser($siteB, []);

        $this->actingAs($viewer)
            ->get('/health-safety/restraints?period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
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

        $csv = $this->actingAs($viewer)
            ->get('/health-safety/restraints/export?lens=events')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($eventA->reference_number, $csv);
        $this->assertStringNotContainsString($eventB->reference_number, $csv);

        $this->actingAs($viewer)->get('/health-safety/restraints?site_id='.$siteB->id)->assertForbidden();
        $this->actingAs($viewer)->get('/health-safety/restraints?client_id='.$clientB->id)->assertForbidden();
        $this->actingAs($viewer)->get('/health-safety/restraints/export?site_id='.$siteB->id)->assertForbidden();
    }

    public function test_wrong_site_restraint_objects_are_not_found_and_cannot_be_mutated(): void
    {
        Storage::fake('private');
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $viewer = $this->siteBoundUser($siteA, [
            'restraints.view',
            'restraints.create',
            'restraints.review',
            'restraints.manage',
        ]);
        $clientB = $this->clientAt($siteB);
        $eventB = $this->eventFor($clientB, ['severity' => 'high', 'reviewed_at' => null]);
        $planB = BehaviourSupportPlan::factory()->create(['client_id' => $clientB->id, 'status' => 'draft']);
        $incidentB = ClientIncident::factory()->create(['client_id' => $clientB->id, 'site_id' => $siteB->id]);
        $attachment = RestraintEventAttachment::query()->create([
            'restraint_event_id' => $eventB->id,
            'disk' => 'private',
            'original_name' => 'hidden-evidence.pdf',
            'path' => 'restraint_attachments/hidden-evidence.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
        ]);

        $this->actingAs($viewer)->get('/health-safety/restraints/clients/'.$clientB->id.'/summary')->assertNotFound();
        $this->actingAs($viewer)->put('/health-safety/restraints/events/'.$eventB->id, ['severity' => 'critical'])->assertNotFound();
        $this->actingAs($viewer)->post('/health-safety/restraints/events/'.$eventB->id.'/link-incident', ['related_incident_id' => $incidentB->id])->assertNotFound();
        $this->actingAs($viewer)->post('/health-safety/restraints/plans/'.$planB->id.'/activate')->assertNotFound();
        $this->actingAs($viewer)->post('/health-safety/restraints/plans/'.$planB->id.'/review', ['outcome' => 'continued'])->assertNotFound();
        $this->actingAs($viewer)->post('/health-safety/restraints/events/'.$eventB->id.'/attachments', [])->assertNotFound();
        $this->actingAs($viewer)->get('/health-safety/restraints/events/'.$eventB->id.'/attachments/'.$attachment->id.'/download')->assertNotFound();
        $this->actingAs($viewer)->delete('/health-safety/restraints/events/'.$eventB->id.'/attachments/'.$attachment->id)->assertNotFound();

        $this->assertSame('high', $eventB->fresh()->severity);
        $this->assertNull($eventB->fresh()->reviewed_at);
        $this->assertNull($eventB->fresh()->related_incident_id);
        $this->assertSame('draft', $planB->fresh()->status);
        $this->assertDatabaseHas('restraint_event_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseCount('behaviour_support_plan_reviews', 0);
    }

    public function test_named_global_hs_permission_bypasses_site_scope_but_manage_alone_does_not(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $clientA = $this->clientAt($siteA);
        $clientB = $this->clientAt($siteB);
        $eventA = $this->eventFor($clientA);
        $eventB = $this->eventFor($clientB, ['severity' => 'high']);
        $restricted = $this->siteBoundUser($siteA, ['restraints.view', 'restraints.manage']);
        $global = $this->siteBoundUser($siteA, [
            'restraints.view',
            'restraints.manage',
            'healthSafety.viewAllSites',
        ]);

        $this->actingAs($restricted)
            ->get('/health-safety/restraints?period=all')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.id', $eventA->id));
        $this->actingAs($restricted)
            ->put('/health-safety/restraints/events/'.$eventB->id, ['severity' => 'critical'])
            ->assertNotFound();

        $this->actingAs($global)
            ->get('/health-safety/restraints?period=all')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 2)->where('tabCounts.events.all', 2));
        $this->actingAs($global)
            ->put('/health-safety/restraints/events/'.$eventB->id, ['severity' => 'critical'])
            ->assertRedirect();
        $this->assertSame('critical', $eventB->fresh()->severity);
    }

    public function test_detail_loads_when_event_param_present(): void
    {
        $event = RestraintEvent::factory()->create();

        $this->actingAs($this->officer())
            ->get('/health-safety/restraints?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('detail.kind', 'event')
                ->where('detail.id', $event->id)
                ->where('detail.reference', fn ($r) => str_starts_with($r, RestraintEvent::REFERENCE_PREFIX.'-'))
                ->has('detail.flags')
            );
    }

    /* ---- Event create + the critical-severity review fix (audit #13) ---- */

    public function test_store_event_records_and_derives_duration(): void
    {
        $client = $this->clientAt(Site::factory()->create());

        $this->actingAs($this->officer())->post('/health-safety/restraints/events', [
            'client_id' => $client->id,
            'started_at' => now()->subMinutes(15)->toDateTimeString(),
            'ended_at' => now()->toDateTimeString(),
            'restraint_type' => 'physical',
            'severity' => 'high',
            'trigger_description' => 'Escalation in lounge',
            'de_escalation_attempted' => 'Offered quiet space, verbal de-escalation',
            'restraint_description' => 'Two-person guided hold',
            'within_support_plan' => true,
        ])->assertRedirect();

        $event = RestraintEvent::firstOrFail();
        $this->assertSame('high', $event->severity);
        $this->assertEqualsWithDelta(15, $event->duration_minutes, 1);
    }

    public function test_in_plan_event_auto_links_clients_active_plan(): void
    {
        $client = $this->clientAt(Site::factory()->create());
        $activePlan = BehaviourSupportPlan::factory()->create(['client_id' => $client->id, 'status' => 'active']);
        BehaviourSupportPlan::factory()->draft()->create(['client_id' => $client->id]);

        $this->actingAs($this->officer())->post('/health-safety/restraints/events', [
            'client_id' => $client->id,
            'started_at' => now()->subMinutes(5)->toDateTimeString(),
            'restraint_type' => 'physical',
            'severity' => 'medium',
            'trigger_description' => 'Escalation',
            'de_escalation_attempted' => 'Verbal de-escalation',
            'restraint_description' => 'Guided hold',
            'within_support_plan' => true,
            // behaviour_support_plan_id intentionally omitted
        ])->assertRedirect();

        $this->assertSame($activePlan->id, RestraintEvent::firstOrFail()->behaviour_support_plan_id);
    }

    public function test_event_review_accepts_critical_severity(): void
    {
        $event = RestraintEvent::factory()->create(['severity' => 'high', 'reviewed_at' => null]);
        $reviewer = $this->officer();

        $this->actingAs($reviewer)->put('/health-safety/restraints/events/'.$event->id, [
            'severity' => 'critical',
            'review_notes' => 'On review the episode was reclassified as critical.',
            'lessons_learned' => 'Earlier PRN review needed.',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame('critical', $event->severity);
        $this->assertNotNull($event->reviewed_at);
        $this->assertSame($reviewer->id, $event->reviewed_by);
    }

    public function test_re_review_preserves_original_reviewer_and_time(): void
    {
        $firstReviewer = $this->officer();
        $reviewedAt = now()->subDays(3);
        $event = RestraintEvent::factory()->create(['reviewed_at' => $reviewedAt, 'reviewed_by' => $firstReviewer->id]);

        // A different reviewer later corrects the lessons-learned note.
        $secondReviewer = $this->officer();
        $this->actingAs($secondReviewer)->put('/health-safety/restraints/events/'.$event->id, [
            'lessons_learned' => 'Corrected after team debrief.',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame('Corrected after team debrief.', $event->lessons_learned);
        // Original attribution preserved; the editor is captured by updated_by only.
        $this->assertSame($firstReviewer->id, $event->reviewed_by);
        $this->assertEqualsWithDelta($reviewedAt->timestamp, $event->reviewed_at->timestamp, 2);
        $this->assertSame($secondReviewer->id, $event->updated_by);
    }

    public function test_review_requires_review_permission(): void
    {
        $event = RestraintEvent::factory()->create();

        $this->actingAs($this->supportWorker())
            ->put('/health-safety/restraints/events/'.$event->id, ['severity' => 'low'])
            ->assertForbidden();
    }

    /* ---- Post-hoc incident link (D3) ---- */

    public function test_link_incident_attaches_same_client_incident(): void
    {
        $client = $this->clientAt(Site::factory()->create());
        $event = RestraintEvent::factory()->create(['client_id' => $client->id, 'related_incident_id' => null]);
        $incident = $this->incidentFor($client);

        $this->actingAs($this->officer())
            ->from('/health-safety/restraints')
            ->post('/health-safety/restraints/events/'.$event->id.'/link-incident', [
                'related_incident_id' => $incident->id,
            ])->assertRedirect();

        $this->assertSame($incident->id, $event->refresh()->related_incident_id);
    }

    public function test_link_incident_rejects_cross_client_incident(): void
    {
        $site = Site::factory()->create();
        $eventClient = $this->clientAt($site);
        $otherClient = $this->clientAt($site);
        $event = $this->eventFor($eventClient, ['related_incident_id' => null]);
        // An incident belonging to a DIFFERENT client must not be linkable.
        $otherClientIncident = $this->incidentFor($otherClient);

        $this->actingAs($this->officer())
            ->from('/health-safety/restraints')
            ->post('/health-safety/restraints/events/'.$event->id.'/link-incident', [
                'related_incident_id' => $otherClientIncident->id,
            ])->assertSessionHasErrors('related_incident_id');

        $this->assertNull($event->refresh()->related_incident_id);
    }

    public function test_link_incident_can_be_removed(): void
    {
        $client = $this->clientAt(Site::factory()->create());
        $incident = $this->incidentFor($client);
        $event = RestraintEvent::factory()->create(['client_id' => $client->id, 'related_incident_id' => $incident->id]);

        $this->actingAs($this->officer())
            ->from('/health-safety/restraints')
            ->post('/health-safety/restraints/events/'.$event->id.'/link-incident', [
                'related_incident_id' => null,
            ])->assertRedirect();

        $this->assertNull($event->refresh()->related_incident_id);
    }

    public function test_link_incident_requires_review_permission(): void
    {
        $client = $this->clientAt(Site::factory()->create());
        $event = RestraintEvent::factory()->create(['client_id' => $client->id]);
        $incident = $this->incidentFor($client);

        $this->actingAs($this->supportWorker())
            ->post('/health-safety/restraints/events/'.$event->id.'/link-incident', [
                'related_incident_id' => $incident->id,
            ])->assertForbidden();
    }

    /* ---- Plan lifecycle + review history ---- */

    public function test_plan_lifecycle_transitions_are_attributed(): void
    {
        $plan = BehaviourSupportPlan::factory()->draft()->create();
        $officer = $this->officer();

        $this->actingAs($officer)->post('/health-safety/restraints/plans/'.$plan->id.'/activate')->assertRedirect();
        $plan->refresh();
        $this->assertSame('active', $plan->status);
        $this->assertNotNull($plan->status_changed_at);
        $this->assertSame($officer->id, $plan->status_changed_by);

        $this->actingAs($officer)->post('/health-safety/restraints/plans/'.$plan->id.'/submit-review')->assertRedirect();
        $this->assertSame('under_review', $plan->refresh()->status);

        $this->actingAs($officer)->post('/health-safety/restraints/plans/'.$plan->id.'/archive')->assertRedirect();
        $this->assertSame('archived', $plan->refresh()->status);
    }

    public function test_review_plan_records_history_and_updates_review_date(): void
    {
        $plan = BehaviourSupportPlan::factory()->create(['status' => 'active']);
        $next = now()->addMonths(6)->toDateString();

        $this->actingAs($this->officer())->post('/health-safety/restraints/plans/'.$plan->id.'/review', [
            'outcome' => 'reduced',
            'next_review_date' => $next,
            'resulting_status' => 'active',
            'notes' => 'Restrictive practice reduced; less-restrictive strategies working.',
        ])->assertRedirect();

        $this->assertSame(1, BehaviourSupportPlanReview::where('behaviour_support_plan_id', $plan->id)->count());
        $this->assertSame($next, $plan->refresh()->review_date->toDateString());
    }

    /* ---- Premium evidence upload ---- */

    public function test_event_attachment_upload_and_remove(): void
    {
        Storage::fake('private');
        $event = RestraintEvent::factory()->create();
        $officer = $this->officer();

        $this->actingAs($officer)->post('/health-safety/restraints/events/'.$event->id.'/attachments', [
            'file' => UploadedFile::fake()->create('body-map.pdf', 120, 'application/pdf'),
            'category' => 'body_map',
            'notes' => 'Post-incident body map',
        ])->assertRedirect();

        $att = RestraintEventAttachment::where('restraint_event_id', $event->id)->firstOrFail();
        $this->assertSame('body_map', $att->category);
        $this->assertSame('private', $att->disk);
        Storage::disk('private')->assertExists($att->path);

        $this->actingAs($officer)
            ->delete('/health-safety/restraints/events/'.$event->id.'/attachments/'.$att->id)
            ->assertRedirect();
        $this->assertDatabaseMissing('restraint_event_attachments', ['id' => $att->id]);
    }

    /* ---- Export ---- */

    public function test_export_streams_csv(): void
    {
        RestraintEvent::factory()->create();

        $response = $this->actingAs($this->officer())->get('/health-safety/restraints/export?lens=events');
        $response->assertOk();
        $this->assertStringContainsString('Reference', $response->streamedContent());
    }

    /* ---- Client-profile summary (cross-module panel) ---- */

    public function test_client_summary_returns_active_plan_and_recent_events(): void
    {
        $client = $this->clientAt(Site::factory()->create());
        $plan = BehaviourSupportPlan::factory()->create(['client_id' => $client->id, 'status' => 'active']);
        RestraintEvent::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->officer())
            ->getJson('/health-safety/restraints/clients/'.$client->id.'/summary')
            ->assertOk()
            ->assertJsonPath('active_plan.id', $plan->id)
            ->assertJsonPath('active_plan.reference', fn ($r) => str_starts_with($r, 'BSP-'))
            ->assertJsonCount(1, 'recent_events')
            ->assertJsonPath('total_events', 1);
    }

    public function test_client_summary_requires_restraints_permission(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->supportWorker())
            ->getJson('/health-safety/restraints/clients/'.$client->id.'/summary')
            ->assertForbidden();
    }
}
