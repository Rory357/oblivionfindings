<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentNote;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

/**
 * Answer 20 (Stephan, 1 Nov 2026): a submitted incident report is never edited; a manager adds a dated note beside the
 * reporter's words and the reporter is told.
 */
class IncidentNoteTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_a_manager_adds_a_dated_note_beside_the_reporters_words(): void
    {
        Notification::fake();
        $manager = $this->roleUser('provider_manager');
        $incident = $this->incident('submitted');

        $this->actingAs($manager)
            ->post("/incidents/{$incident->id}/notes", ['field' => 'what_happened', 'note' => 'Aroha told us later she was reaching for her cardigan.'])
            ->assertSessionHas('success');

        $note = ClientIncidentNote::query()->sole();
        $this->assertSame([$incident->id, 'what_happened', $manager->id], [$note->client_incident_id, $note->field, $note->author_id]);
        $this->assertSame('Aroha stood up without her walker.', $incident->fresh()->description, 'The report itself never changes.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'incident.noteAdded', 'auditable_id' => $incident->id, 'user_id' => $manager->id]);
        Notification::assertSentTo($incident->reporter, AppEventNotification::class);

        $this->expectException(LogicException::class);
        $note->update(['note' => 'Rewritten.']);
    }

    public function test_drafts_take_no_notes(): void
    {
        $manager = $this->roleUser('provider_manager');
        $incident = $this->incident('draft');

        $this->actingAs($manager)
            ->post("/incidents/{$incident->id}/notes", ['field' => 'what_happened', 'note' => 'A note on a draft.'])
            ->assertSessionHasErrors('note');
        $this->assertSame(0, ClientIncidentNote::query()->count());
    }

    public function test_people_who_dont_review_incidents_cant_add_notes(): void
    {
        $lead = $this->roleUser('team_lead');
        $incident = $this->incident('submitted');

        $this->actingAs($lead)
            ->post("/incidents/{$incident->id}/notes", ['field' => 'what_happened', 'note' => 'A team lead trying to add a note.'])
            ->assertForbidden();
        $this->assertSame(0, ClientIncidentNote::query()->count());
    }

    private function incident(string $status): ClientIncident
    {
        return ClientIncident::factory()->atSite($this->site)->create([
            'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'status' => $status,
            'type' => 'fall',
            'description' => 'Aroha stood up without her walker.',
        ]);
    }

    private function roleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'position_role' => $roleName, 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }
}
