<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentSafeguardingContent;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Readiness audit P4 (Stephan, 6 Nov 2026, by pop-up via MASTER: "Safeguarding access only"). A safeguarding-type
 * incident, or one a concern links to, shows its words, witnesses and files only to people with safeguarding access to
 * it. Everyone else, including a Finance CSV, sees "Restricted". Each surface is proved both ways.
 */
class SafeguardingIncidentContentTest extends TestCase
{
    use RefreshDatabase;

    private const WORDS = 'SG-PRIVATE-ACCOUNT';

    private const WITNESS = 'SG-PRIVATE-WITNESS';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
        Storage::fake('private');
    }

    private function viewer(array $permissions, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function incident(array $attributes = []): ClientIncident
    {
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id,
            'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'status' => 'submitted',
            'type' => 'behaviour',
            'description' => self::WORDS,
            'witnesses' => self::WITNESS,
            'immediate_action_taken' => 'SG-PRIVATE-ACTION',
            ...$attributes,
        ]));
    }

    private function linkedConcern(ClientIncident $incident, array $facts = []): SafeguardingConcern
    {
        return SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
            'site_id' => $this->site->id,
            'related_incident_id' => $incident->id,
            'reference_number' => 'SG-TEST-'.$incident->id.'-'.random_int(1000, 9999),
            'is_sensitive' => false,
            ...$facts,
        ]));
    }

    private function attachment(ClientIncident $incident): ClientIncidentAttachment
    {
        Storage::disk('private')->put('incidents/sg.txt', 'file');

        return ClientIncidentAttachment::create([
            'incident_id' => $incident->id, 'uploaded_by' => $incident->reported_by, 'disk' => 'private',
            'path' => 'incidents/sg.txt', 'original_name' => 'sg.txt', 'mime' => 'text/plain', 'size' => 4,
        ]);
    }

    public function test_the_incident_record_shows_restricted_without_safeguarding_access_and_the_words_with_it(): void
    {
        $incident = $this->incident();
        $this->linkedConcern($incident);
        $this->attachment($incident);

        $reader = $this->viewer(['incidents.viewAny']);
        $this->actingAs($reader)->get("/incidents/{$incident->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.description', IncidentSafeguardingContent::RESTRICTED)
            ->where('detail.witnesses', IncidentSafeguardingContent::RESTRICTED)
            ->where('detail.immediate_action_taken', IncidentSafeguardingContent::RESTRICTED)
            ->where('detail.content_restricted', true)
            ->where('detail.attachments', [])
            ->where('detail.notes', [])
            ->where('detail.can.add_note', false));

        $lead = $this->viewer(['incidents.viewAny', 'safeguarding.viewAny']);
        $this->actingAs($lead)->get("/incidents/{$incident->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.description', self::WORDS)
            ->where('detail.witnesses', self::WITNESS)
            ->where('detail.content_restricted', false)
            ->has('detail.attachments', 1));
    }

    public function test_an_ordinary_incident_is_unchanged(): void
    {
        $incident = $this->incident();
        $reader = $this->viewer(['incidents.viewAny']);

        $this->actingAs($reader)->get("/incidents/{$incident->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.description', self::WORDS)->where('detail.content_restricted', false));
    }

    public function test_a_safeguarding_type_incident_needs_safeguarding_access_at_its_site(): void
    {
        $incident = $this->incident(['type' => 'safeguarding']);
        $content = app(IncidentSafeguardingContent::class);

        $this->assertTrue($content->restricted($this->viewer(['incidents.viewAny']), $incident));
        $this->assertFalse($content->restricted($this->viewer(['incidents.viewAny', 'safeguarding.viewAny']), $incident));

        // Safeguarding access at another Site doesn't reach it; "view reports" doesn't widen it (P6).
        $elsewhere = $this->viewer(['incidents.viewAny', 'safeguarding.viewAny', 'reports.viewAny'], Site::factory()->create());
        $this->assertTrue(app(IncidentSafeguardingContent::class)->restricted($elsewhere, $incident));
        $everySite = $this->viewer(['incidents.viewAny', 'safeguarding.viewAny', 'safeguarding.viewAllSites'], Site::factory()->create());
        $this->assertFalse(app(IncidentSafeguardingContent::class)->restricted($everySite, $incident));
    }

    public function test_the_reporter_keeps_their_own_words_unless_the_concern_names_them(): void
    {
        $reporter = $this->viewer(['incidents.viewAny']);
        $incident = $this->incident(['reported_by' => $reporter->id]);
        $concern = $this->linkedConcern($incident);

        $this->assertFalse(app(IncidentSafeguardingContent::class)->restricted($reporter, $incident));

        $concern->update(['alleged_perpetrator_type' => User::class, 'alleged_perpetrator_id' => $reporter->id]);
        $this->assertTrue(app(IncidentSafeguardingContent::class)->restricted($reporter, $incident));
    }

    public function test_a_need_to_know_concern_keeps_the_words_from_safeguarding_staff_without_clearance(): void
    {
        $incident = $this->incident();
        $this->linkedConcern($incident, ['is_sensitive' => true]);

        $this->assertTrue(app(IncidentSafeguardingContent::class)
            ->restricted($this->viewer(['incidents.viewAny', 'safeguarding.viewAny']), $incident));
        $this->assertFalse(app(IncidentSafeguardingContent::class)
            ->restricted($this->viewer(['incidents.viewAny', 'safeguarding.viewAny', 'safeguarding.viewSensitive']), $incident));
    }

    public function test_the_csv_export_says_restricted_for_finance_and_keeps_other_incidents(): void
    {
        $safeguarding = $this->incident();
        $this->linkedConcern($safeguarding);
        $this->incident(['description' => 'ORDINARY-ACCOUNT', 'witnesses' => 'ORDINARY-WITNESS']);

        $finance = $this->viewer(['incidents.viewAny', 'incidents.export']);
        $csv = $this->actingAs($finance)->get('/reports/incidents/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString(self::WORDS, $csv);
        $this->assertStringNotContainsString(self::WITNESS, $csv);
        $this->assertStringContainsString(IncidentSafeguardingContent::RESTRICTED, $csv);
        $this->assertStringContainsString('ORDINARY-ACCOUNT', $csv);

        $lead = $this->viewer(['incidents.viewAny', 'incidents.export', 'safeguarding.viewAny']);
        $csv = $this->actingAs($lead)->get('/reports/incidents/export')->assertOk()->streamedContent();
        $this->assertStringContainsString(self::WORDS, $csv);
    }

    public function test_the_register_row_and_search_hide_the_words(): void
    {
        $incident = $this->incident();
        $this->linkedConcern($incident);
        $reader = $this->viewer(['incidents.viewAny']);

        $this->actingAs($reader)->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)->where('rows.data.0.description', IncidentSafeguardingContent::RESTRICTED));
        $this->actingAs($reader)->get('/incidents?q='.self::WORDS)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));

        $lead = $this->viewer(['incidents.viewAny', 'safeguarding.viewAny']);
        $this->actingAs($lead)->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.data.0.description', self::WORDS));
    }

    public function test_files_and_edits_are_refused_without_safeguarding_access(): void
    {
        $incident = $this->incident();
        $this->linkedConcern($incident);
        $file = $this->attachment($incident);

        $reader = $this->viewer(['incidents.viewAny', 'incidents.update']);
        $this->actingAs($reader)->get("/incidents/{$incident->id}/attachments/{$file->id}/download")->assertForbidden();
        $this->actingAs($reader)->put("/incidents/{$incident->id}", ['description' => 'Overwritten'])->assertForbidden();
        $this->assertSame(self::WORDS, $incident->fresh()->description);

        $lead = $this->viewer(['incidents.viewAny', 'safeguarding.viewAny']);
        $this->actingAs($lead)->get("/incidents/{$incident->id}/attachments/{$file->id}/download")->assertOk();
    }

    public function test_the_task_card_names_the_kind_only(): void
    {
        $incident = $this->incident(['title' => 'SG-PRIVATE-TITLE', 'status' => 'submitted']);
        $this->linkedConcern($incident);
        $reader = $this->viewer(['incidents.viewAny']);

        $task = collect(app(ClientIncidentProvider::class)->authorizedTasks($reader))->firstWhere('id', 'incident-'.$incident->id);
        $this->assertNotNull($task);
        $this->assertNull($task->description);
        $this->assertStringNotContainsString('SG-PRIVATE', $task->title);
    }
}
