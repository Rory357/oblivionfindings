<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Decision 18 (Stephan, 1 Nov 2026, H&S board G4-2): the incident report's "Straight away" part asks quick ticks,
 * each with the time it was done, and the incident page shows them under "Done straight away".
 */
class IncidentImmediateResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'Pacific/Auckland')->utc());

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now(), 'email_verified_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'email_verified_at' => now()]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->site = Site::factory()->create(['name' => 'Kauri House']);
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->client->supportWorkers()->attach($this->staff->id);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->staff->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $this->app->instance(NotificationService::class, \Mockery::mock(NotificationService::class)->shouldIgnoreMissing());
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'intent' => 'draft',
            'report_request_uuid' => (string) Str::uuid(),
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'type' => 'fall',
            'severity' => 'medium',
            'occurred_at' => '2026-10-26T19:40',
            'description' => 'Aroha slipped on the lounge rug.',
            'immediate_action_taken' => 'Moved the rug out of the lounge.',
            'stay' => true,
        ], $overrides);
    }

    public function test_the_report_stores_each_tick_at_the_time_it_was_done(): void
    {
        $this->actingAs($this->staff)->post('/incidents', $this->payload([
            'immediate_response' => [
                ['key' => 'first_aid', 'done' => true, 'time' => '19:47', 'detail' => 'By me'],
                ['key' => 'made_safe', 'done' => true, 'time' => '19:45', 'detail' => null],
                ['key' => 'medical_rung', 'done' => false, 'time' => null, 'detail' => null],
                ['key' => 'family_told', 'done' => false, 'time' => null, 'detail' => 'Hemi will ring tomorrow morning'],
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $incident = ClientIncident::query()->where('reported_by', $this->staff->id)->sole();
        // 7:45 pm NZDT on 26 Oct is 06:45 UTC; unticked rows without a note are not kept; form order wins.
        // (MySQL JSON reorders each row's keys, so the rows are compared by value.)
        $this->assertEquals([
            ['key' => 'made_safe', 'done' => true, 'at' => '2026-10-26T06:45:00+00:00', 'detail' => null],
            ['key' => 'first_aid', 'done' => true, 'at' => '2026-10-26T06:47:00+00:00', 'detail' => 'By me'],
            ['key' => 'family_told', 'done' => false, 'at' => null, 'detail' => 'Hemi will ring tomorrow morning'],
        ], $incident->immediate_response);
        $this->assertSame('Moved the rug out of the lounge.', $incident->immediate_action_taken);
    }

    public function test_a_time_earlier_than_the_incident_is_the_next_morning(): void
    {
        $this->actingAs($this->staff)->post('/incidents', $this->payload([
            'occurred_at' => '2026-10-26T23:50',
            'immediate_response' => [['key' => 'made_safe', 'done' => true, 'time' => '00:05', 'detail' => null]],
        ]))->assertSessionHasNoErrors();

        $incident = ClientIncident::query()->where('reported_by', $this->staff->id)->sole();
        $this->assertSame('2026-10-26T11:05:00+00:00', $incident->immediate_response[0]['at']);
    }

    public function test_a_tick_needs_a_real_time_and_a_known_question(): void
    {
        $this->actingAs($this->staff)->post('/incidents', $this->payload([
            'immediate_response' => [['key' => 'made_safe', 'done' => true, 'time' => null, 'detail' => null]],
        ]))->assertSessionHasErrors('immediate_response.0.time');

        $this->actingAs($this->staff)->post('/incidents', $this->payload([
            'immediate_response' => [['key' => 'called_police', 'done' => true, 'time' => '19:45', 'detail' => null]],
        ]))->assertSessionHasErrors('immediate_response.0.key');

        $this->actingAs($this->staff)->post('/incidents', $this->payload([
            'immediate_response' => [
                ['key' => 'made_safe', 'done' => true, 'time' => '19:45', 'detail' => null],
                ['key' => 'made_safe', 'done' => true, 'time' => '19:46', 'detail' => null],
            ],
        ]))->assertSessionHasErrors('immediate_response.1.key');

        $this->assertSame(0, ClientIncident::query()->count());
    }

    public function test_a_tick_cannot_be_later_than_now(): void
    {
        $this->actingAs($this->staff)->post('/incidents', $this->payload([
            'occurred_at' => '2026-11-01T09:30',
            'immediate_response' => [['key' => 'made_safe', 'done' => true, 'time' => '11:30', 'detail' => null]],
        ]))->assertSessionHasErrors('immediate_response.0.time');

        $this->assertSame(0, ClientIncident::query()->count());
    }

    public function test_a_serious_report_still_says_in_words_what_was_done(): void
    {
        // The ticks add to the written immediate action; they never replace it for a high or critical incident.
        $this->actingAs($this->staff)->post('/incidents', $this->payload([
            'intent' => 'submit',
            'severity' => 'high',
            'immediate_action_taken' => '',
            'immediate_response' => [['key' => 'first_aid', 'done' => true, 'time' => '19:47', 'detail' => null]],
        ]))->assertSessionHasErrors('immediate_action_taken');
        $this->assertSame(0, ClientIncident::query()->count());

        $this->actingAs($this->staff)->post('/incidents', $this->payload([
            'intent' => 'submit',
            'severity' => 'high',
            'immediate_response' => [['key' => 'first_aid', 'done' => true, 'time' => '19:47', 'detail' => null]],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $incident = ClientIncident::query()->where('reported_by', $this->staff->id)->sole();
        $this->assertSame('submitted', $incident->status);
        $this->assertSame('first_aid', $incident->immediate_response[0]['key']);
    }

    public function test_the_sent_result_names_the_site_it_went_to(): void
    {
        $this->actingAs($this->staff)
            ->post('/incidents', $this->payload())
            ->assertSessionHas('incident_report_result', fn (array $result) => $result['site_name'] === 'Kauri House'
                && $result['result'] === 'draft');
    }

    public function test_the_incident_page_carries_the_ticks(): void
    {
        $incident = ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->staff->id,
            'status' => 'submitted',
            'immediate_response' => [
                ['key' => 'made_safe', 'done' => true, 'at' => '2026-10-26T06:45:00+00:00', 'detail' => null],
            ],
        ]);

        $this->actingAs($this->admin)->get('/incidents/'.$incident->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.immediate_response.0.key', 'made_safe')
                ->where('detail.immediate_response.0.at', '2026-10-26T06:45:00+00:00'));
    }

    public function test_a_saved_recovery_copy_may_hold_a_tick_before_its_time_is_chosen(): void
    {
        $this->actingAs($this->staff)->putJson('/incidents/drafts/'.Str::uuid(), [
            'expected_revision' => 0,
            'mode' => 'incident',
            'entry_context' => 'incidents',
            'step_index' => 3,
            'form' => [
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'description' => 'Started.',
                'immediate_response' => [['key' => 'made_safe', 'done' => true, 'time' => '', 'detail' => '']],
            ],
        ])->assertOk();

        $this->actingAs($this->staff)->putJson('/incidents/drafts/'.Str::uuid(), [
            'expected_revision' => 0,
            'mode' => 'incident',
            'entry_context' => 'incidents',
            'step_index' => 3,
            'form' => [
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'immediate_response' => [['key' => 'not_a_tick', 'done' => true, 'time' => '', 'detail' => '']],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('form.immediate_response.0.key');
    }
}
