<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\BehaviourFunction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Client\BehaviourPatternsService;
use App\Support\WorkerClock;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BehaviourAbcControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
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
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'occurred_at' => '2026-06-12T09:00',
            'setting' => 'Dining room at dinner',
            'others_present' => 'Two support workers',
            'antecedent' => 'Busy, noisy dining room at dinner time.',
            'behaviour' => 'Left the table and paced the hallway.',
            'consequence' => 'Offered a quiet space; settled in 10 minutes.',
            'behaviour_tags' => ['Pacing', 'Withdrawal'],
            'behaviour_function' => 'escape_avoidance',
            'intensity' => 'medium',
            'duration_seconds' => 360,
            'strategies_used' => 'Quiet space and low-demand approach.',
            'harm_occurred' => false,
            'escalated' => false,
            'requires_followup' => false,
        ], $overrides);
    }

    // ── Store ─────────────────────────────────────────────────────────────

    public function test_can_record_a_structured_abc_entry(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/behaviour/abc", $this->validPayload());

        $response->assertCreated();
        $this->assertDatabaseHas('behaviour_abc_entries', [
            'client_id' => $this->client->id,
            'recorded_by' => $user->id,
            'behaviour_function' => 'escape_avoidance',
            'intensity' => 'medium',
            'duration_seconds' => 360,
        ]);
    }

    public function test_records_a_timeline_event(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/behaviour/abc", $this->validPayload());

        $this->assertDatabaseHas('timeline_events', [
            'type' => 'behaviour_abc_entry',
            'client_id' => $this->client->id,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_occurred_at_is_stored_as_utc(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/behaviour/abc", $this->validPayload([
                'occurred_at' => '2026-06-12T09:00',
            ]));

        // 09:00 worker-local must be stored as its UTC equivalent.
        $expectedUtc = WorkerClock::toUtc('2026-06-12T09:00')->format('Y-m-d H:i:s');
        $this->assertDatabaseHas('behaviour_abc_entries', [
            'client_id' => $this->client->id,
            'occurred_at' => $expectedUtc,
        ]);
        $this->assertNotSame('2026-06-12 09:00:00', $expectedUtc, 'worker tz should differ from UTC');
    }

    public function test_validation_rejects_missing_abc_fields(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/behaviour/abc", $this->validPayload([
                'antecedent' => '',
                'behaviour' => '',
            ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['antecedent', 'behaviour']);
    }

    public function test_requires_clinical_events_record_permission(): void
    {
        // Coordinator can view the client, but an explicit deny override blocks
        // the events.record gate the controller enforces for writes.
        $user = $this->createUserWithRole('coordinator');
        $perm = Permission::where('key', 'clinical.events.record')->first();
        $user->permissionOverrides()->attach($perm->id, ['allowed' => false]);

        $response = $this->actingAs($user)
            ->postJson("/clients/{$this->client->id}/behaviour/abc", $this->validPayload());

        $response->assertForbidden();
    }

    // ── Index / Show ──────────────────────────────────────────────────────

    public function test_index_lists_entries_for_the_client_only(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $other = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        BehaviourAbcEntry::factory()->count(2)->create(['client_id' => $this->client->id]);
        BehaviourAbcEntry::factory()->create(['client_id' => $other->id]);

        $response = $this->actingAs($user)
            ->getJson("/clients/{$this->client->id}/behaviour/abc");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_show_returns_full_detail(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $entry = BehaviourAbcEntry::factory()->create([
            'client_id' => $this->client->id,
            'others_present' => 'Peers',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/clients/{$this->client->id}/behaviour/abc/{$entry->id}");

        $response->assertOk();
        $response->assertJsonPath('id', $entry->id);
        $response->assertJsonStructure(['id', 'occurred_at', 'occurred_at_local', 'others_present', 'antecedent', 'behaviour', 'consequence']);
    }

    public function test_cannot_access_entry_from_another_client(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $other = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $entry = BehaviourAbcEntry::factory()->create(['client_id' => $other->id]);

        $response = $this->actingAs($user)
            ->getJson("/clients/{$this->client->id}/behaviour/abc/{$entry->id}");

        $response->assertNotFound();
    }

    // ── Update / Destroy ──────────────────────────────────────────────────

    public function test_can_update_an_entry(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $entry = BehaviourAbcEntry::factory()->create(['client_id' => $this->client->id]);

        $response = $this->actingAs($user)
            ->putJson("/clients/{$this->client->id}/behaviour/abc/{$entry->id}", $this->validPayload([
                'behaviour' => 'Updated behaviour description.',
                'intensity' => 'high',
            ]));

        $response->assertOk();
        $this->assertDatabaseHas('behaviour_abc_entries', [
            'id' => $entry->id,
            'behaviour' => 'Updated behaviour description.',
            'intensity' => 'high',
        ]);
    }

    public function test_update_can_close_out_a_followup(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $entry = BehaviourAbcEntry::factory()->create([
            'client_id' => $this->client->id,
            'requires_followup' => true,
        ]);

        $this->actingAs($user)
            ->putJson("/clients/{$this->client->id}/behaviour/abc/{$entry->id}", $this->validPayload([
                'requires_followup' => true,
                'followup_completed' => true,
            ]));

        $entry->refresh();
        $this->assertNotNull($entry->followup_completed_at);
        $this->assertSame($user->id, $entry->followup_completed_by);
    }

    public function test_can_delete_an_entry(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $entry = BehaviourAbcEntry::factory()->create(['client_id' => $this->client->id]);

        $response = $this->actingAs($user)
            ->deleteJson("/clients/{$this->client->id}/behaviour/abc/{$entry->id}");

        $response->assertOk();
        $this->assertSoftDeleted('behaviour_abc_entries', ['id' => $entry->id]);
    }

    // ── Analytics (BehaviourPatternsService) ──────────────────────────────

    public function test_pattern_service_aggregates_function_and_intensity(): void
    {
        BehaviourAbcEntry::factory()->count(3)->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(2),
            'behaviour_function' => BehaviourFunction::EscapeAvoidance->value,
            'intensity' => 'high',
        ]);
        BehaviourAbcEntry::factory()->count(2)->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(1),
            'behaviour_function' => BehaviourFunction::AttentionSocial->value,
            'intensity' => 'low',
        ]);

        $payload = app(BehaviourPatternsService::class)->forClient($this->client->fresh());

        $this->assertSame(5, $payload['entry_count']);
        $this->assertSame(3, $payload['intensity_mix']['high']);
        $this->assertSame(2, $payload['intensity_mix']['low']);

        $functions = collect($payload['function_breakdown'])->keyBy('key');
        $this->assertSame(3, $functions['escape_avoidance']['count']);
        $this->assertSame(2, $functions['attention_social']['count']);
    }

    public function test_pattern_service_builds_the_headline_summary(): void
    {
        // 3 entries this quarter, 2 in the prior quarter → trend should be negative.
        BehaviourAbcEntry::factory()->count(3)->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(10),
            'duration_seconds' => 240,
            'antecedent' => 'Loud dining room',
        ]);
        BehaviourAbcEntry::factory()->count(5)->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(120),
        ]);

        $summary = app(BehaviourPatternsService::class)->forClient($this->client->fresh())['summary'];

        $this->assertSame(3, $summary['entries_90d']);
        $this->assertSame(240, $summary['avg_duration_seconds']);
        $this->assertSame(-40, $summary['trend_pct']); // (3-5)/5 = -40%
        $this->assertSame('Loud dining room', $summary['top_antecedent']);
        $this->assertCount(6, $summary['entries_by_month']);
    }
}
