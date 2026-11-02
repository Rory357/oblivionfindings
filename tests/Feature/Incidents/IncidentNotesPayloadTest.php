<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Board M14 (Stephan, 1 Nov 2026): the incident page shows the dated notes beside the reporter's words to everyone who
 * can open the incident, and offers "Add a note" only under the note endpoint's own rule (incidents.approve + the
 * incident view policy + not a draft).
 */
class IncidentNotesPayloadTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE_ONE = 'Aroha told us later she was reaching for her cardigan.';

    private const NOTE_TWO = 'The rug was taken out of the lounge the same evening.';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_a_manager_sees_the_notes_in_order_and_may_add_one(): void
    {
        $manager = $this->roleUser('provider_manager', $this->site);
        $incident = $this->incident('submitted');
        $this->notes($incident, $manager);

        $this->actingAs($manager)->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/show')
                ->where('detail.can.add_note', true)
                ->has('detail.notes', 2)
                ->where('detail.notes.0.field', 'what_happened')
                ->where('detail.notes.0.note', self::NOTE_ONE)
                ->where('detail.notes.0.author_name', $manager->name)
                ->where('detail.notes.1.field', 'done_straight_away')
                ->where('detail.notes.1.note', self::NOTE_TWO)
                ->has('detail.notes.0', fn (Assert $note) => $note
                    ->has('id')->has('field')->has('note')->has('author_name')->has('created_at'))
            );
    }

    public function test_a_viewer_who_doesnt_review_incidents_sees_the_notes_but_cant_add_one(): void
    {
        $manager = $this->roleUser('provider_manager', $this->site);
        $lead = $this->roleUser('team_lead', $this->site);
        $this->allow($lead, ['incidents.viewAny']);
        $incident = $this->incident('submitted');
        $this->notes($incident, $manager);

        $this->actingAs($lead->fresh())->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.can.add_note', false)
                ->has('detail.notes', 2)
                ->where('detail.notes.0.note', self::NOTE_ONE)
            );
    }

    public function test_a_draft_takes_no_notes(): void
    {
        $manager = $this->roleUser('provider_manager', $this->site);
        $incident = $this->incident('draft', $manager);

        $this->actingAs($manager)->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.can.add_note', false)
                ->where('detail.notes', [])
            );
    }

    public function test_a_closed_incident_still_shows_its_notes_and_a_manager_may_add_one(): void
    {
        $manager = $this->roleUser('provider_manager', $this->site);
        $incident = $this->incident('closed');
        $this->notes($incident, $manager);

        $this->actingAs($manager)->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.can.add_note', true)
                ->has('detail.notes', 2)
            );
    }

    public function test_a_manager_at_another_site_gets_neither_the_notes_nor_the_flag(): void
    {
        $manager = $this->roleUser('provider_manager', $this->site);
        $elsewhere = $this->roleUser('provider_manager', Site::factory()->create(['is_active' => true]));
        $incident = $this->incident('submitted');
        $this->notes($incident, $manager);

        $response = $this->actingAs($elsewhere)->get('/incidents/'.$incident->id);
        $this->assertContains($response->status(), [200, 403, 404]);
        $body = (string) $response->getContent();
        foreach ([self::NOTE_ONE, self::NOTE_TWO, '"add_note":true'] as $private) {
            $this->assertStringNotContainsString($private, $body, 'Leaked to a viewer who cannot open the incident: '.$private);
        }
    }

    private function notes(ClientIncident $incident, User $author): void
    {
        ClientIncidentNote::create([
            'client_incident_id' => $incident->id, 'field' => 'what_happened', 'note' => self::NOTE_ONE,
            'author_id' => $author->id, 'author_name' => $author->name,
        ]);
        $this->travel(5)->minutes();
        ClientIncidentNote::create([
            'client_incident_id' => $incident->id, 'field' => 'done_straight_away', 'note' => self::NOTE_TWO,
            'author_id' => $author->id, 'author_name' => $author->name,
        ]);
    }

    private function incident(string $status, ?User $reporter = null): ClientIncident
    {
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->atSite($this->site)->create([
            'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'status' => $status,
            'type' => 'fall',
            'description' => 'Aroha stood up without her walker.',
            'submitted_at' => $status === 'draft' ? null : now()->subHour(),
            ...($reporter ? ['reported_by' => $reporter->id] : []),
        ]));
    }

    private function roleUser(string $roleName, Site $site): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'position_role' => $roleName, 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }

    /** @param  array<int, string>  $keys */
    private function allow(User $user, array $keys): void
    {
        $ids = collect($keys)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key], ['description' => $key])->id);
        $user->permissionOverrides()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
    }
}
