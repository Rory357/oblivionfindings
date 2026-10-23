<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidAttachment;
use App\Models\FirstAidCommandReceipt;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Support\HealthSafety\FirstAidReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FirstAidSourceFixtures;
use Tests\TestCase;

/** Retained register/capture/link/evidence/export coverage, now using real current source authority and reviewed commands. */
class FirstAidControllerTest extends TestCase
{
    use FirstAidSourceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeFirstAidFixtures();
    }

    public function test_index_renders_hero_tabcounts_can_and_pickers(): void
    {
        $this->source();
        $this->source(['ambulance_called' => true]);
        $this->get('/health-safety/first-aid')->assertOk()->assertInertia(fn (Assert $p) => $p->component('health-safety/first-aid/index')
            ->where('tab', 'all')->where('tabCounts.all', 2)->where('tabCounts.ambulance', 1)->where('can.manage', true)
            ->has('hero.live')->has('hero.attention')->has('hero.badges')->has('firstAiders')->has('clients')->has('incidents')->has('staff')->where('detail', null));
    }

    public function test_detail_loads_only_with_record_param(): void
    {
        $record = $this->source();
        $this->get('/health-safety/first-aid?record='.$record->id)->assertRedirect(FirstAidReturn::href($record->id, '/health-safety/first-aid'));
        $this->getJson('/health-safety/first-aid?record='.$record->id)->assertOk()->assertJsonPath('detail.id', $record->id)->assertJsonPath('detail.can.manage', true)->assertJsonStructure(['detail' => ['attachments', 'followups', 'history']]);
    }

    public function test_store_creates_record_and_stamps_creator(): void
    {
        $payload = $this->treatment(['treated_person_name' => 'Jordan Smith']);
        $response = $this->post('/health-safety/first-aid', $payload)->assertSessionHasNoErrors();
        $record = FirstAidRecord::sole();
        $response->assertRedirect('/health-safety/first-aid/'.$record->id)->assertSessionHas('first_aid_confirmation', fn ($proof) => $proof === ['id' => $record->id, 'lock_version' => 1, 'idempotency_key' => $payload['idempotency_key'], 'command' => 'create', 'removed' => false]);
        $this->assertSame('Jordan Smith', $record->treated_person_name);
        $this->assertSame($this->manager->id, $record->created_by);
        $this->post('/health-safety/first-aid', $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, FirstAidRecord::count());
    }

    public static function captureReturnOrigins(): array
    {
        return [
            'dashboard origin' => ['/dashboard?discarded=private', '/dashboard'],
            'health and safety origin' => ['/health-safety?discarded=private', '/health-safety'],
            'untrusted external origin' => ['https://untrusted.example/record', null],
        ];
    }

    #[DataProvider('captureReturnOrigins')]
    public function test_create_only_html_confirmation_preserves_only_sanitized_origin_without_granting_record_read(string $submittedOrigin, ?string $origin): void
    {
        $capturer = $this->sourceStaff(['hazards.create']);
        foreach (['hazards.view', 'hazards.manage'] as $key) {
            $capturer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => false]]);
        }
        $payload = $this->treatment(['return_to' => $submittedOrigin]);
        $target = '/health-safety/first-aid/report'.($origin !== null ? '?return_to='.rawurlencode($origin) : '');
        $response = $this->actingAs($capturer)->post('/health-safety/first-aid', $payload)->assertSessionHasNoErrors()->assertRedirect($target);
        $record = FirstAidRecord::sole();
        $proof = ['id' => $record->id, 'lock_version' => 1, 'idempotency_key' => $payload['idempotency_key'], 'command' => 'create', 'removed' => false];
        $response->assertSessionHas('first_aid_confirmation', $proof);
        $this->get($target)->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/first-aid/report')
            ->where('can.view', false)->where('record_href', null)->where('first_aid_confirmation', $proof));
        $this->getJson('/health-safety/first-aid/'.$record->id)->assertForbidden();
        $this->getJson('/health-safety/first-aid')->assertForbidden();
        $this->post('/health-safety/first-aid', $payload)->assertSessionHasNoErrors()->assertRedirect($target)->assertSessionHas('first_aid_confirmation', $proof);
        $this->assertSame(1, FirstAidRecord::count());
        $this->assertSame(1, FirstAidCommandReceipt::count());
    }

    public function test_store_persists_linked_treated_staff(): void
    {
        $staff = $this->sourceStaff();
        $this->post('/health-safety/first-aid', $this->treatment(['treated_person_id' => $staff->id]))->assertSessionHasNoErrors();
        $this->assertSame($staff->id, FirstAidRecord::sole()->treated_person_id);
    }

    public function test_update_edits_fields(): void
    {
        $record = $this->source(['body_part' => 'Left hand']);
        $this->put('/health-safety/first-aid/'.$record->id, $this->reviewed($record, ['body_part' => 'Right forearm', 'treatment_outcome' => 'sent_home']))->assertSessionHasNoErrors();
        $this->assertSame('Right forearm', $record->fresh()->body_part);
        $this->assertSame('sent_home', $record->fresh()->treatment_outcome);
        $this->assertSame($this->manager->id, $record->fresh()->updated_by);
    }

    public function test_update_clears_client_link_when_type_switches_away_from_client(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $record = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_id' => null]);
        $this->put('/health-safety/first-aid/'.$record->id, $this->reviewed($record, ['treated_person_type' => 'staff']))->assertSessionHasNoErrors();
        $this->assertSame('staff', $record->fresh()->treated_person_type);
        $this->assertNull($record->fresh()->client_id);
    }

    public function test_destroy_soft_deletes_record(): void
    {
        $record = $this->source();
        $payload = $this->reviewed($record);
        $this->delete('/health-safety/first-aid/'.$record->id, $payload)->assertSessionHasNoErrors()->assertSessionHas('first_aid_confirmation', fn ($proof) => $proof['removed'] === true && $proof['idempotency_key'] === $payload['idempotency_key']);
        $this->assertSoftDeleted('first_aid_records', ['id' => $record->id]);
        $this->delete('/health-safety/first-aid/'.$record->id, $payload)->assertSessionHasNoErrors();
        $this->getJson('/health-safety/first-aid/'.$record->id)->assertOk()->assertJsonPath('record.status', 'archived');
    }

    public function test_link_incident_creates_incident_for_a_client_treatment(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $record = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_id' => null, 'injury_illness_type' => 'fall']);
        $payload = $this->reviewed($record);
        $this->post('/health-safety/first-aid/'.$record->id.'/link-incident', $payload)->assertSessionHasNoErrors();
        $this->post('/health-safety/first-aid/'.$record->id.'/link-incident', $payload)->assertSessionHasNoErrors();
        $incident = ClientIncident::sole();
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame($this->sourceSite->id, $incident->site_id);
        $this->assertSame($incident->id, $record->fresh()->related_incident_id);
        $this->assertSame(HsEvent::sole()->id, $incident->hs_event_id);
    }

    public function test_serious_client_treatment_requires_immediate_action_before_incident_escalation(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $record = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_id' => null, 'ambulance_called' => true]);
        $payload = $this->reviewed($record);
        $this->post('/health-safety/first-aid/'.$record->id.'/link-incident', $payload)->assertSessionHasErrors('immediate_action_taken');
        $this->assertSame(0, ClientIncident::count());
        $this->assertSame(1, (int) $record->fresh()->lock_version);
        $this->post('/health-safety/first-aid/'.$record->id.'/link-incident', [...$payload, 'immediate_action_taken' => 'Ambulance called; actual handover recorded.'])->assertSessionHasNoErrors();
        $this->assertSame('Ambulance called; actual handover recorded.', ClientIncident::sole()->immediate_action_taken);
    }

    public function test_link_incident_links_an_existing_incident(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $record = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_id' => null]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->sourceSite->id, 'reported_by' => $this->manager->id]));
        $this->post('/health-safety/first-aid/'.$record->id.'/link-incident', $this->reviewed($record, ['related_incident_id' => $incident->id]))->assertSessionHasNoErrors();
        $this->assertSame($incident->id, $record->fresh()->related_incident_id);
        $this->assertTrue($record->fresh()->incident_reported);
    }

    public function test_link_incident_rejects_create_for_a_non_client_treatment(): void
    {
        $record = $this->source();
        $this->post('/health-safety/first-aid/'.$record->id.'/link-incident', $this->reviewed($record))->assertSessionHasErrors('related_incident_id');
        $this->assertNull($record->fresh()->related_incident_id);
        $this->assertSame(0, ClientIncident::count());
        $this->assertSame(1, FirstAidCommandReceipt::count());
    }

    public function test_upload_download_destroy_attachment_with_idor_guard(): void
    {
        $record = $this->source();
        $other = $this->source();
        $payload = $this->reviewed($record, ['kind' => 'acc45', 'file' => UploadedFile::fake()->image('acc45.jpg')]);
        $this->post('/health-safety/first-aid/'.$record->id.'/attachments', $payload)->assertSessionHasNoErrors();
        $this->post('/health-safety/first-aid/'.$record->id.'/attachments', $payload)->assertSessionHasNoErrors();
        $att = FirstAidAttachment::sole();
        $this->assertSame('acc45', $att->kind);
        Storage::disk('private')->assertExists($att->path);
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->get('/health-safety/first-aid/'.$other->id.'/attachments/'.$att->id.'/download')->assertNotFound();
        $this->get('/health-safety/first-aid/'.$record->id.'/attachments/'.$att->id.'/download')->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");
        $remove = $this->reviewed($record);
        $this->delete('/health-safety/first-aid/'.$record->id.'/attachments/'.$att->id, $remove)->assertSessionHasNoErrors()->assertSessionHas('first_aid_confirmation', fn ($proof) => $proof['removed'] === false);
        $this->delete('/health-safety/first-aid/'.$record->id.'/attachments/'.$att->id, $remove)->assertSessionHasNoErrors();
        $this->assertSoftDeleted('first_aid_attachments', ['id' => $att->id]);
        Storage::disk('private')->assertExists($att->path);
    }

    public function test_export_streams_csv_with_reference(): void
    {
        $record = $this->source(['treated_person_name' => '=SUM(1,2)']);
        $res = $this->get('/health-safety/first-aid/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString($record->reference_number, $res->streamedContent());
        $this->assertStringContainsString("'=SUM(1,2)", $res->streamedContent());
    }

    public function test_show_renders_permanent_record_with_legacy_redirect_retained(): void
    {
        $record = $this->source();
        $this->get('/health-safety/first-aid/'.$record->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('health-safety/first-aid/show')->where('detail.id', $record->id));
        $this->get('/health-safety/first-aid?record='.$record->id)->assertRedirect(FirstAidReturn::href($record->id, '/health-safety/first-aid'));
    }

    public function test_zero_permission_user_is_forbidden(): void
    {
        $this->actingAs($this->sourceStaff())->get('/health-safety/first-aid')->assertForbidden();
    }

    public function test_create_only_http_confirmation_stays_on_capture_without_granting_treatment_read(): void
    {
        $creator = $this->sourceStaff(['hazards.create']);
        $this->actingAs($creator)->get('/health-safety/first-aid/report')->assertOk()->assertInertia(fn (Assert $p) => $p->component('health-safety/first-aid/report')->where('can.view', false));
        $payload = $this->treatment();
        $this->post('/health-safety/first-aid', $payload)->assertRedirect('/health-safety/first-aid/report')->assertSessionHas('first_aid_confirmation');
        $record = FirstAidRecord::sole();
        $proof = ['id' => $record->id, 'lock_version' => 1, 'idempotency_key' => $payload['idempotency_key'], 'command' => 'create', 'removed' => false];
        $response = $this->postJson('/health-safety/first-aid', $payload)->assertOk()->assertJsonPath('first_aid_confirmation', $proof)->assertJsonPath('record_href', null);
        $this->assertStringNotContainsString('Recorded small cut', $response->getContent());
        $this->get('/health-safety/first-aid/'.$record->id)->assertForbidden();
        $this->get('/health-safety/first-aid')->assertForbidden();
        $this->assertSame(1, FirstAidRecord::count());
    }

    public function test_reader_first_aider_filter_retains_historical_attribution_only_from_readable_selected_site_sources(): void
    {
        $retired = $this->firstAider;
        $retired->forceFill(['name' => 'Recorded retired first aider'])->save();
        $record = $this->source(['treatment_date' => now()->subYears(2)->toISOString()]);
        $retired->hrEmployeeProfile()->update(['is_active' => false, 'is_first_aider' => false]);
        $reader = $this->sourceStaff(['hazards.view']);
        $privateAider = $this->sourceStaff([], true);
        $privateAider->forceFill(['name' => 'Private Client treatment attribution'])->save();
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $this->source(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id, 'first_aider_id' => $privateAider->id]);
        $unrecordedAider = $this->sourceStaff([], true);
        $unrecordedAider->forceFill(['name' => 'Unrecorded eligible HR first aider'])->save();
        $otherSite = Site::factory()->create(['is_active' => true]);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $reader->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $otherAider = $this->sourceStaff([], true);
        $otherAider->forceFill(['name' => 'Other approved Site recorded aider'])->save();
        $otherAider->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
        $this->source(['site_id' => $otherSite->id, 'treated_person_id' => null, 'first_aider_id' => $otherAider->id]);
        $url = '/health-safety/first-aid?site_id='.$this->sourceSite->id;
        $response = $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonPath('filterOptions.first_aiders', [['id' => $retired->id, 'name' => $retired->name]])->assertJsonPath('pickers.first_aiders', [])->assertJsonPath('pickers.staff', [])->assertJsonPath('can.create', false)->assertJsonPath('records.total', 0);
        $this->assertStringNotContainsString($privateAider->name, $response->getContent());
        $this->assertStringNotContainsString($unrecordedAider->name, $response->getContent());
        $this->assertStringNotContainsString($otherAider->name, $response->getContent());
        $this->getJson($url.'&period=all&first_aider_id='.$retired->id)->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.id', $record->id);
        $this->getJson('/health-safety/first-aid?site_id='.$otherSite->id)->assertOk()->assertJsonPath('filterOptions.first_aiders', [['id' => $otherAider->id, 'name' => $otherAider->name]]);
        $reader->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $this->getJson('/health-safety/first-aid?site_id='.$otherSite->id)->assertOk()->assertJsonPath('filterOptions.first_aiders', [])->assertJsonPath('records.total', 0);
        $this->assertFalse($retired->hrEmployeeProfile()->firstOrFail()->is_active);
    }

    public function test_new_capture_incident_choices_require_selected_site_and_current_independent_authority(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->sourceSite->id, 'reported_by' => $this->manager->id, 'status' => 'draft']));
        $captureManager = $this->sourceStaff(['hazards.manage', 'clients.viewAny', 'incidents.viewAny', 'incidents.update']);
        $url = '/health-safety/first-aid/options?site_id='.$this->sourceSite->id.'&client_id='.$client->id;
        $this->actingAs($captureManager)->getJson($url)->assertOk()->assertJsonCount(1, 'incidents')->assertJsonPath('incidents.0.id', $incident->id);
        $this->getJson('/health-safety/first-aid/options?client_id='.$client->id)->assertUnprocessable()->assertJsonValidationErrors('site_id');
        $record = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_id' => null]);
        $this->getJson('/health-safety/first-aid/options?record_id='.$record->id)->assertForbidden();
        $deniedClient = $this->sourceStaff(['hazards.manage', 'incidents.viewAny', 'incidents.update']);
        $this->actingAs($deniedClient)->getJson($url)->assertForbidden();
        $this->assertSame(1, ClientIncident::count());
    }

    public function test_named_executor_completion_is_source_owned_and_confirms_exact_reviewed_parent(): void
    {
        $record = $this->source();
        $this->sourceService()->addFollowup($record, $this->manager, $this->reviewed($record, ['notes' => 'Record actual follow-through', 'assigned_to_user_id' => $this->worker->id]));
        $child = FirstAidFollowup::sole();
        $payload = $this->reviewed($record, ['completion_notes' => 'Actual action recorded', 'section' => 'followups', 'action' => 'complete-followup', 'followup' => $child->id, 'return_to' => '/my-day']);
        $this->actingAs($this->worker)->patch('/health-safety/first-aid/'.$record->id.'/followups/'.$child->id.'/complete', $payload)->assertSessionHasNoErrors()->assertRedirect(FirstAidReturn::href($record->id, '/my-day', 'followups', $child->id).'&action=complete-followup');
        $this->assertSame($this->worker->id, $child->fresh()->completed_by_user_id);
        $this->get(FirstAidReturn::href($record->id, '/my-day', 'followups', $child->id))->assertOk()->assertInertia(fn (Assert $p) => $p->where('first_aid_confirmation.idempotency_key', $payload['idempotency_key'])->where('detail.lock_version', 3));
    }
}
