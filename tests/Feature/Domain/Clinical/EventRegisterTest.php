<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Enums\AlertSeverity;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->site = Site::factory()->create();
    }

    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_clinical_lead_can_access_event_register(): void
    {
        $user = $this->createUserWithRole('clinical_lead');

        $this->actingAs($user)
            ->get('/health-clinical/events')
            ->assertOk();
    }

    public function test_coordinator_can_access_event_register(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->get('/health-clinical/events')
            ->assertOk();
    }

    public function test_support_worker_cannot_access_event_register(): void
    {
        $user = $this->createUserWithRole('support_worker');

        $this->actingAs($user)
            ->get('/health-clinical/events')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get('/health-clinical/events')
            ->assertRedirect('/login');
    }

    public function test_register_renders_with_correct_props(): void
    {
        $user = $this->createUserWithRole('clinical_lead');

        $this->actingAs($user)
            ->get('/health-clinical/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Events')
                ->has('events')
                ->has('stats')
                ->has('filters')
                ->has('filter_options')
                ->has('filter_options.clients')
                ->has('filter_options.sites')
                ->has('filter_options.event_types')
                ->has('filter_options.severities')
                ->has('filter_options.follow_up_statuses')
                ->has('filter_options.review_statuses')
                ->has('event_types')
            );
    }

    public function test_filter_by_client(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $clientA = Client::factory()->create(['site_id' => $this->site->id]);
        $clientB = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->create([
            'client_id' => $clientA->id,
            'reported_by' => $lead->id,
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $clientB->id,
            'reported_by' => $lead->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events?client_id='.$clientA->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
            );
    }

    public function test_filter_by_event_type(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->fall()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
        ]);
        ClinicalEvent::factory()->seizure()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events?event_type='.ClinicalEventType::Fall->value)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.event_type', ClinicalEventType::Fall->value)
            );
    }

    public function test_filter_by_severity(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
            'severity' => AlertSeverity::LOW,
        ]);
        ClinicalEvent::factory()->critical()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events?severity='.AlertSeverity::CRITICAL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.severity', AlertSeverity::CRITICAL)
            );
    }

    public function test_filter_by_date_range(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
            'occurred_at' => now()->subDays(10),
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
            'occurred_at' => now(),
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events?date_from='.now()->subDays(2)->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
            );
    }

    public function test_filter_by_site_uses_event_site_or_client_site_when_needed(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);
        $clientB = Client::factory()->create(['site_id' => $siteB->id]);

        ClinicalEvent::factory()->create([
            'client_id' => $clientA->id,
            'reported_by' => $lead->id,
            'site_id' => null,
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $clientB->id,
            'reported_by' => $lead->id,
            'site_id' => $siteB->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events?site_id='.$siteA->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.client_id', $clientA->id)
            );
    }

    public function test_filter_by_pending_follow_up_status(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->withFollowup()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
            'requires_followup' => true,
            'followup_completed_at' => now(),
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events?follow_up_status=pending')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.requires_followup', true)
                ->where('events.data.0.followup_completed_at', null)
            );
    }

    public function test_filter_by_review_status(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
        ]);
        ClinicalEvent::factory()->reviewed()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events?review_status=reviewed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.reviewed_at', fn ($value) => $value !== null)
            );
    }

    public function test_invalid_event_type_filter_is_rejected(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');

        $this->actingAs($lead)
            ->get('/health-clinical/events?event_type=not_real')
            ->assertSessionHasErrors('event_type');
    }

    public function test_register_paginates_at_25(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->count(30)->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 25)
                ->where('events.total', 30)
                ->where('events.last_page', 2)
            );
    }

    public function test_stats_include_counts_for_follow_up_and_review_backlog(): void
    {
        $lead = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalEvent::factory()->withFollowup()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
            'occurred_at' => now(),
        ]);
        ClinicalEvent::factory()->reviewed()->create([
            'client_id' => $client->id,
            'reported_by' => $lead->id,
            'occurred_at' => now(),
        ]);

        $this->actingAs($lead)
            ->get('/health-clinical/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_7d', 2)
                ->where('stats.total_30d', 2)
                ->where('stats.pending_follow_ups', 1)
                ->where('stats.unreviewed', 1)
            );
    }
}
