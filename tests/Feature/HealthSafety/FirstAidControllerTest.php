<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidAttachment;
use App\Models\FirstAidRecord;
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
 * First Aid Register — H&S gold-standard controller coverage (the module had zero HTTP
 * tests before the rebuild). Modal-first register payload (hero/tabs/can + picker pools),
 * param-driven detail (?record=), CRUD with the client-link clearing rule, user-driven
 * incident escalation (create for a client / link existing / reject for a non-client),
 * the premium attachment library with its IDOR guard, CSV export and permission gating.
 *
 * Reuses the hazards.* scheme (no dedicated first_aid.* perms): admin holds them all,
 * support_worker holds none.
 */
class FirstAidControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        if ($r = Role::where('name', $role)->first()) {
            $user->roles()->attach($r);
        }

        return $user;
    }

    private function record(array $overrides = []): FirstAidRecord
    {
        return FirstAidRecord::factory()->create(array_merge([
            'site_id' => $this->site->id,
        ], $overrides));
    }

    /** A valid store() payload (every required field in StoreFirstAidRecordRequest). */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'site_id' => $this->site->id,
            'treated_person_name' => 'Jordan Smith',
            'treated_person_type' => 'staff',
            'treatment_date' => now()->toDateTimeString(),
            'injury_illness_type' => 'cut',
            'injury_illness_description' => 'Laceration to the left hand while preparing food.',
            'treatment_given' => 'Cleaned, dressed and bandaged the wound.',
            'treatment_outcome' => 'returned_to_activity',
            'first_aider_id' => $this->admin->id,
        ], $overrides);
    }

    /* ================================================================== */
    /*  Register page                                                      */
    /* ================================================================== */

    public function test_index_renders_hero_tabcounts_can_and_pickers(): void
    {
        // Pin treatment_date to now() so both fall inside the default 30d period window
        // and the tab counts are deterministic.
        $this->record(['treatment_date' => now()]); // a plain staff treatment
        FirstAidRecord::factory()->ambulance()->create([
            'site_id' => $this->site->id,
            'treatment_date' => now(),
        ]); // ambulance signal

        $this->actingAs($this->admin)
            ->get('/health-safety/first-aid')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/first-aid/index')
                ->where('tab', 'all')
                ->where('tabCounts.all', 2)
                ->where('tabCounts.ambulance', 1)
                ->where('can.manage', true)
                ->has('hero.live')
                ->has('hero.attention')
                ->has('hero.badges')
                ->has('firstAiders')
                ->has('clients')
                ->has('incidents')
                ->has('staff')
                ->where('detail', null));
    }

    public function test_detail_loads_only_with_record_param(): void
    {
        $record = $this->record();

        $this->actingAs($this->admin)
            ->get('/health-safety/first-aid?record='.$record->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('detail.id', $record->id)
                ->where('detail.reference', 'FA-'.str_pad((string) $record->id, 4, '0', STR_PAD_LEFT))
                ->has('detail.attachments')
                ->has('detail.followups')
                ->has('detail.history')
                ->where('detail.can.manage', true));
    }

    /* ================================================================== */
    /*  CRUD                                                               */
    /* ================================================================== */

    public function test_store_creates_record_and_stamps_creator(): void
    {
        $this->actingAs($this->admin)
            ->from('/health-safety/first-aid')
            ->post('/health-safety/first-aid', $this->validPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $record = FirstAidRecord::latest('id')->first();
        $this->assertNotNull($record);
        $this->assertSame('Jordan Smith', $record->treated_person_name);
        $this->assertSame($this->admin->id, $record->created_by);
        $this->assertEquals($record->id, session('created_first_aid_id'));
    }

    public function test_store_persists_linked_treated_staff(): void
    {
        // NOTE: the "treated staff" picker is being added to the wizard concurrently; the
        // field is already validated (exists:users) + fillable, so a posted id must persist.
        $staff = User::factory()->create();

        $this->actingAs($this->admin)
            ->post('/health-safety/first-aid', $this->validPayload([
                'treated_person_id' => $staff->id,
            ]))
            ->assertSessionHasNoErrors();

        $record = FirstAidRecord::latest('id')->first();
        $this->assertSame($staff->id, $record->treated_person_id);
    }

    public function test_update_edits_fields(): void
    {
        $record = $this->record(['body_part' => 'Left hand']);

        $this->actingAs($this->admin)
            ->put('/health-safety/first-aid/'.$record->id, [
                'body_part' => 'Right forearm',
                'treatment_outcome' => 'sent_home',
            ])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame('Right forearm', $record->body_part);
        $this->assertSame('sent_home', $record->treatment_outcome);
        $this->assertSame($this->admin->id, $record->updated_by);
    }

    public function test_update_clears_client_link_when_type_switches_away_from_client(): void
    {
        // A client treatment carries a client_id; switching the person type to staff must
        // null it, else the record keeps showing on the old client's First-aid panel.
        $client = Client::factory()->create();
        $record = $this->record([
            'treated_person_type' => 'client',
            'client_id' => $client->id,
        ]);

        $this->actingAs($this->admin)
            ->put('/health-safety/first-aid/'.$record->id, [
                'treated_person_type' => 'staff',
            ])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame('staff', $record->treated_person_type);
        $this->assertNull($record->client_id, 'Switching away from client must clear client_id');
    }

    public function test_destroy_soft_deletes_record(): void
    {
        $record = $this->record();

        $this->actingAs($this->admin)
            ->from('/health-safety/first-aid')
            ->delete('/health-safety/first-aid/'.$record->id)
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('first_aid_records', ['id' => $record->id]);
    }

    /* ================================================================== */
    /*  Incident escalation (linkIncident)                                 */
    /* ================================================================== */

    public function test_link_incident_creates_incident_for_a_client_treatment(): void
    {
        $client = Client::factory()->create();
        $record = $this->record([
            'treated_person_type' => 'client',
            'client_id' => $client->id,
            'injury_illness_type' => 'fall',
            'related_incident_id' => null,
        ]);

        $this->actingAs($this->admin)
            ->post('/health-safety/first-aid/'.$record->id.'/link-incident', [])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertNotNull($record->related_incident_id, 'A client treatment must auto-create + link an incident');
        $this->assertTrue((bool) $record->incident_reported);

        $incident = ClientIncident::find($record->related_incident_id);
        $this->assertNotNull($incident);
        $this->assertSame($client->id, $incident->client_id);
    }

    public function test_link_incident_links_an_existing_incident(): void
    {
        $record = $this->record(['related_incident_id' => null]);
        $incident = ClientIncident::factory()->create();

        $this->actingAs($this->admin)
            ->post('/health-safety/first-aid/'.$record->id.'/link-incident', [
                'related_incident_id' => $incident->id,
            ])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame($incident->id, $record->related_incident_id);
        $this->assertTrue((bool) $record->incident_reported);
    }

    public function test_link_incident_rejects_create_for_a_non_client_treatment(): void
    {
        // client_incidents.client_id is NOT-NULL → only a client treatment can auto-create one.
        $record = $this->record([
            'treated_person_type' => 'staff',
            'client_id' => null,
            'related_incident_id' => null,
        ]);

        $this->actingAs($this->admin)
            ->from('/health-safety/first-aid?record='.$record->id)
            ->post('/health-safety/first-aid/'.$record->id.'/link-incident', [])
            ->assertSessionHas('error');

        $this->assertNull($record->fresh()->related_incident_id);
    }

    /* ================================================================== */
    /*  Attachments (premium document upload + IDOR guard)                 */
    /* ================================================================== */

    public function test_upload_download_destroy_attachment_with_idor_guard(): void
    {
        Storage::fake('public');
        $record = $this->record();
        $other = $this->record();

        // Upload (real fake image so it passes the mimes allowlist).
        $this->actingAs($this->admin)
            ->post('/health-safety/first-aid/'.$record->id.'/attachments', [
                'file' => UploadedFile::fake()->image('acc45.jpg'),
                'kind' => 'acc45',
            ])
            ->assertSessionHasNoErrors();

        $att = FirstAidAttachment::where('first_aid_record_id', $record->id)->first();
        $this->assertNotNull($att);
        $this->assertSame('acc45', $att->kind);
        Storage::disk('public')->assertExists($att->path);

        // IDOR guard: the attachment belongs to $record, not $other → 404 under $other's id.
        $this->actingAs($this->admin)
            ->get('/health-safety/first-aid/'.$other->id.'/attachments/'.$att->id.'/download')
            ->assertNotFound();

        // Correct parent downloads fine.
        $this->actingAs($this->admin)
            ->get('/health-safety/first-aid/'.$record->id.'/attachments/'.$att->id.'/download')
            ->assertOk();

        // Destroy (FirstAidAttachment uses SoftDeletes).
        $this->actingAs($this->admin)
            ->delete('/health-safety/first-aid/'.$record->id.'/attachments/'.$att->id)
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted('first_aid_attachments', ['id' => $att->id]);
    }

    /* ================================================================== */
    /*  Export                                                             */
    /* ================================================================== */

    public function test_export_streams_csv_with_reference(): void
    {
        // NOTE: export() is being wired into the controller/routes concurrently — this
        // asserts the contract: text/csv stream containing the FA- reference.
        $record = $this->record(['treatment_date' => now()]);

        $res = $this->actingAs($this->admin)->get('/health-safety/first-aid/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('FA-'.str_pad((string) $record->id, 4, '0', STR_PAD_LEFT), $res->streamedContent());
    }

    /* ================================================================== */
    /*  Show redirect + permission gating                                  */
    /* ================================================================== */

    public function test_show_redirects_to_register_modal(): void
    {
        $record = $this->record();

        $this->actingAs($this->admin)
            ->get('/health-safety/first-aid/'.$record->id)
            ->assertRedirect('/health-safety/first-aid?record='.$record->id);
    }

    public function test_zero_permission_user_is_forbidden(): void
    {
        $this->actingAs($this->userWithRole('support_worker'))
            ->get('/health-safety/first-aid')
            ->assertForbidden();
    }
}
