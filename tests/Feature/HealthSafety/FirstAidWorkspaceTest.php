<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FirstAidAttachment;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidSourceProjectionService;
use App\Support\HealthSafety\FirstAidPresenter;
use App\Support\HealthSafety\FirstAidReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Pending integration: source authorization, controller negotiation and additive schema. */
class FirstAidWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->manager = $this->worker(true);
    }

    public function test_html_json_and_scoped_legacy_entry_preserve_actual_record_and_bounded_register(): void
    {
        $record = $this->record();
        $origin = '/health-safety/first-aid?tab=followup&site_id='.$this->site->id.'&period=all&q=wound';
        $href = FirstAidReturn::href($record->id, $origin, 'injury');
        $this->actingAs($this->manager)->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/first-aid/show')->where('detail.id', $record->id)->where('return_to', $origin));
        $this->getJson('/health-safety/first-aid/'.$record->id)->assertOk()->assertJsonPath('record.id', $record->id)->assertJsonPath('record.treatment_given', 'Cleaned and dressed');
        $this->get('/health-safety/first-aid?record='.$record->id.'&tab=followup&site_id='.$this->site->id.'&period=all&q=wound')->assertRedirect(FirstAidReturn::href($record->id, $origin));
        foreach (['0', 'x', '', 'record[]=1'] as $input) {
            $query = str_contains($input, '[]') ? $input : 'record='.$input;
            $this->get('/health-safety/first-aid?'.$query)->assertNotFound();
        }
    }

    public function test_direct_id_foreign_site_and_current_staff_revocation_are_denied_even_with_cached_viewer(): void
    {
        $record = $this->record(['site_id' => Site::factory()->create()->id]);
        $this->denied(fn () => $this->detail($record));
        $local = $this->record();
        $this->assertSame($local->id, $this->detail($local)['id']);
        $this->manager->load('hrEmployeeProfile');
        $this->manager->hrEmployeeProfile->forceFill(['is_active' => false])->save();
        $this->denied(fn () => $this->detail($local));
    }

    public function test_create_permission_does_not_grant_canonical_treatment_read(): void
    {
        $creator = $this->worker(false);
        $this->grant($creator, 'hazards.view', false);
        $this->grant($creator, 'hazards.create', true);
        $record = $this->record(['created_by' => $creator->id]);
        $this->actingAs($creator)->getJson('/health-safety/first-aid/'.$record->id)->assertForbidden();
        $this->assertDatabaseHas('first_aid_records', ['id' => $record->id, 'created_by' => $creator->id]);
    }

    public function test_legacy_unlinked_blank_person_remains_readable_with_display_only_unknown_label(): void
    {
        $record = $this->record(['treated_person_type' => 'staff', 'treated_person_id' => null, 'client_id' => null, 'treated_person_name' => '   ']);
        $child = $this->followup($record);
        $detail = $this->detail($record);
        $this->assertSame('Person not recorded', $detail['treated_person_name']);
        $this->assertNull($detail['treated_person_id']);
        $this->assertNull($detail['treated_person']);
        $this->assertNull($detail['client_id']);
        $this->assertSame('Person not recorded', app(FirstAidPresenter::class)->row($record, $this->manager)['treated_person_name']);
        $rows = app(FirstAidSourceProjectionService::class)->forMyDay($this->manager);
        $this->assertSame($child->id, $rows[0]['id']);
        $this->assertSame('Person not recorded', $rows[0]['treated_person_name']);
        $this->assertSame('Person not recorded', FirstAidPresenter::recordedPersonLabel(null));
        $this->assertSame('   ', $record->refresh()->treated_person_name);
        $this->assertNull($record->treated_person_id);
    }

    public function test_primary_client_treatment_requires_independent_current_client_authority(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Private', 'last_name' => 'Surname']);
        $record = $this->record(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_name' => 'Private Surname']);
        $this->grant($this->manager, 'clients.viewAny', false);
        $this->grant($this->manager, 'clients.viewAssigned', false);
        $this->denied(fn () => $this->detail($record));
        $this->grant($this->manager, 'clients.viewAssigned', true);
        $client->supportWorkers()->attach($this->manager->id);
        $this->assertSame($client->id, $this->detail($record)['client_id']);
        $client->supportWorkers()->detach($this->manager->id);
        $this->denied(fn () => $this->detail($record));
    }

    public function test_semantic_history_uses_immutable_allowlists_and_legacy_remains_incomplete(): void
    {
        $record = $this->record(['treatment_given' => 'Later amended treatment']);
        $audit = AuditLog::create(['user_id' => $this->manager->id, 'action' => 'first_aid.updated', 'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
            'meta' => ['snapshot' => [...$this->tuple($record), 'treatment_given' => 'Original full treatment', 'ambulance_called' => false, 'path' => 'secret/private.pdf', 'recipient_email' => 'hidden@example.test'], 'note' => 'Original correction', 'related_incident_id' => 991]]);
        $legacy = AuditLog::create(['user_id' => null, 'action' => 'firstaidrecord.update', 'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
            'meta' => ['after' => ['first_aider_notes' => 'Legacy truncated private text'], 'path' => 'secret/private.pdf']]);
        $detail = $this->detail($record);
        $modern = collect($detail['history'])->firstWhere('id', 'audit-'.$audit->id);
        $this->assertSame('Original full treatment', $modern['snapshot']['treatment_given']);
        $this->assertFalse($modern['snapshot']['ambulance_called']);
        $this->assertSame('Original correction', $modern['note']);
        $this->assertSame($this->manager->id, $modern['actor']['id']);
        $old = collect($detail['history'])->firstWhere('id', 'audit-'.$legacy->id);
        $this->assertTrue($old['legacy']);
        $this->assertNull($old['actor']);
        $this->assertNull($old['note']);
        $this->assertSame([], $old['snapshot']);
        $this->assertStringNotContainsString('secret/private', json_encode($detail));
        $this->assertStringNotContainsString('hidden@example', json_encode($detail));
        $this->assertStringNotContainsString('Legacy truncated private', json_encode($detail));
    }

    public function test_complete_source_history_is_not_silently_capped_and_modern_creation_does_not_duplicate_legacy(): void
    {
        $record = $this->record();
        AuditLog::create(['action' => 'first_aid.created', 'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id, 'meta' => ['snapshot' => [...$this->tuple($record), 'treatment_given' => 'Original']]]);
        for ($i = 0; $i < 55; $i++) {
            AuditLog::create(['action' => 'first_aid.updated', 'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id, 'meta' => ['snapshot' => [...$this->tuple($record), 'treatment_given' => 'Update '.$i]]]);
        }
        $history = $this->detail($record)['history'];
        $this->assertCount(56, $history);
        $this->assertCount(1, array_filter($history, fn ($row) => $row['action'] === 'first_aid.created'));
        $this->assertCount(0, array_filter($history, fn ($row) => $row['action'] === 'firstaidrecord.create'));
    }

    public function test_followup_due_is_an_instant_and_completion_is_attributed_without_clinical_assurance(): void
    {
        $record = $this->record();
        $followup = $this->followup($record, ['due_at' => '2026-10-09T07:30:00+13:00']);
        $followup->forceFill(['completed_at' => now(), 'completed_by_user_id' => $this->manager->id, 'completion_notes' => 'Contact attempt recorded',
            'completion_outcome' => ['kind' => 'contact', 'result' => 'attempted', 'occurred_at' => now()->toIso8601String(), 'note' => 'No response', 'recipient_email' => 'hidden@example.test']])->saveQuietly();
        $row = $this->detail($record)['followups'][0];
        $this->assertSame($followup->due_at->timestamp, Carbon::parse($row['due_at'])->timestamp);
        $this->assertSame($this->manager->id, $row['completed_by']['id']);
        $this->assertSame('attempted', $row['completion_outcome']['result']);
        $this->assertFalse($row['can']['complete']);
        $this->assertFalse($row['legacy']);
        $this->assertStringNotContainsString('hidden@example', json_encode($row));
        $this->assertArrayNotHasKey('clinically_cleared', $row);
    }

    public function test_legacy_followup_completion_does_not_infer_executor_as_completer_or_contact_outcome(): void
    {
        $record = $this->record();
        $this->followup($record, ['completed_at' => now()]);
        $row = $this->detail($record)['followups'][0];
        $this->assertSame($this->manager->id, $row['assigned_to']['id']);
        $this->assertNull($row['completed_by']);
        $this->assertNull($row['completion_outcome']);
        $this->assertTrue($row['legacy']);
    }

    public function test_private_evidence_has_real_availability_basename_and_no_storage_path(): void
    {
        $record = $this->record();
        $available = $this->attachment($record);
        $missing = $this->attachment($record, ['path' => 'private/missing-secret.pdf'], false);
        $detail = $this->detail($record);
        $this->assertSame('available', $detail['attachments'][0]['availability']);
        $this->assertSame('treatment.pdf', $detail['attachments'][0]['original_name']);
        $this->assertSame('/health-safety/first-aid/'.$record->id.'/attachments/'.$available->id.'/download', $detail['attachments'][0]['download_href']);
        $this->assertSame('missing', $detail['attachments'][1]['availability']);
        $this->assertNull($detail['attachments'][1]['download_href']);
        $this->assertStringNotContainsString('missing-secret', json_encode($detail));
        $this->assertArrayNotHasKey('disk', $detail['attachments'][0]);
        $this->assertArrayNotHasKey('path', $detail['attachments'][0]);
    }

    public function test_completion_evidence_remains_retained_and_foreign_attachment_ids_are_not_projected(): void
    {
        $record = $this->record();
        $file = $this->attachment($record);
        $foreign = $this->attachment($this->record(['site_id' => Site::factory()->create()->id]), ['original_name' => 'Hidden evidence.pdf']);
        $followup = $this->followup($record, ['completed_at' => now()]);
        $followup->forceFill(['completion_evidence_ids' => [$file->id, $foreign->id]])->saveQuietly();
        $detail = $this->detail($record);
        $this->assertFalse($detail['attachments'][0]['can']['remove']);
        $this->assertSame([$file->id], array_column($detail['followups'][0]['completion_evidence'], 'id'));
        $this->assertStringNotContainsString('Hidden evidence', json_encode($detail));
    }

    public function test_archived_canonical_record_retains_evidence_and_open_followups_with_no_source_writes(): void
    {
        $record = $this->record();
        $this->attachment($record);
        $this->followup($record);
        $record->delete();
        $detail = $this->detail($record);
        $this->assertSame('archived', $detail['status']);
        $this->assertNotNull($detail['archived_at']);
        $this->assertCount(1, $detail['attachments']);
        $this->assertNull($detail['followups'][0]['completed_at']);
        foreach (['edit', 'archive', 'link_incident', 'create_incident', 'add_followup', 'add_evidence'] as $flag) {
            $this->assertFalse($detail['can'][$flag]);
        }
        $this->assertFalse($detail['attachments'][0]['can']['remove']);
        $this->assertFalse($detail['followups'][0]['can']['complete']);
        $this->actingAs($this->manager)->getJson('/health-safety/first-aid/'.$record->id)->assertOk()->assertJsonPath('record.status', 'archived');
    }

    public function test_register_projection_retains_factual_attribution_without_owner_or_regulatory_inference(): void
    {
        $record = $this->record(['ambulance_called' => true, 'incident_reported' => true]);
        $row = app(FirstAidPresenter::class)->row($record, $this->manager, '/health-safety/first-aid?tab=ambulance');
        $this->assertTrue($row['ambulance_called']);
        $this->assertTrue($row['incident_reported']);
        $this->assertSame($this->manager->id, $row['first_aider_id']);
        $this->assertArrayNotHasKey('owner', $row);
        $this->assertArrayNotHasKey('is_notifiable', $row);
        $this->assertArrayNotHasKey('acc_accepted', $row);
        $this->assertArrayNotHasKey('first_aider_notes', $row);
    }

    public function test_register_child_counts_are_current_same_source_and_exclude_removed_evidence_and_completed_followups(): void
    {
        $record = $this->record(['treated_person_name' => 'Count case subject', 'treatment_date' => now()]);
        $record->loadCount(['attachments', 'followups as open_followups_count' => fn ($query) => $query->whereNull('completed_at')]);
        $this->assertSame(0, $record->attachments_count);
        $this->assertSame(0, $record->open_followups_count);
        $this->attachment($record);
        $this->attachment($record, ['path' => 'private/count-missing.pdf'], false);
        $removed = $this->attachment($record, ['path' => 'private/count-removed.pdf']);
        $removed->delete();
        $open = $this->followup($record);
        $this->followup($record, ['due_at' => now()->subDay()]);
        $this->followup($record, ['completed_at' => now()]);
        $other = $this->record(['treated_person_name' => 'Another subject']);
        $this->attachment($other, ['original_name' => 'Other record evidence.pdf']);
        $this->followup($other);
        $foreign = $this->record(['site_id' => Site::factory()->create()->id, 'treated_person_name' => 'Count case subject']);
        $this->attachment($foreign);
        $this->followup($foreign);
        $reader = $this->worker(false);
        $row = app(FirstAidPresenter::class)->row($record, $reader);
        $this->assertSame(2, $row['attachments_count']);
        $this->assertSame(2, $row['open_followups_count']);
        $this->assertSame($this->site->name, $row['site_name']);
        $this->assertFalse($row['can']['add_evidence']);
        $this->assertArrayNotHasKey('treatment_given', $row);
        $this->assertArrayNotHasKey('related_incident_id', $row);
        $this->assertStringNotContainsString('Other record evidence', json_encode($row));
        $this->actingAs($reader)->getJson('/health-safety/first-aid?period=all&q=Count%20case%20subject')
            ->assertOk()->assertJsonCount(1, 'records.data')
            ->assertJsonPath('records.data.0.id', $record->id)
            ->assertJsonPath('records.data.0.attachments_count', 2)
            ->assertJsonPath('records.data.0.open_followups_count', 2);
        $open->forceFill(['completed_at' => now()])->saveQuietly();
        $fresh = app(FirstAidPresenter::class)->row($record, $reader);
        $this->assertSame(2, $fresh['attachments_count']);
        $this->assertSame(1, $fresh['open_followups_count']);
    }

    private function tuple(FirstAidRecord $record): array
    {
        return ['site_id' => (int) $record->site_id, 'treated_person_type' => $record->treated_person_type,
            'client_id' => $record->client_id === null ? null : (int) $record->client_id,
            'treated_person_id' => $record->treated_person_id === null ? null : (int) $record->treated_person_id];
    }

    private function worker(bool $manage = false): User
    {
        $viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        foreach (['hazards.view' => true, 'hazards.manage' => $manage, 'hazards.create' => $manage, 'healthSafety.viewAllSites' => false, 'clients.viewAny' => false, 'clients.viewAssigned' => false] as $key => $allowed) {
            $this->grant($viewer, $key, $allowed);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null, 'is_first_aider' => $manage]);

        return $viewer;
    }

    private function grant(User $viewer, string $key, bool $allowed): void
    {
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }

    private function record(array $fields = []): FirstAidRecord
    {
        $row = FirstAidRecord::factory()->create(['site_id' => $this->site->id, 'first_aider_id' => $this->manager->id, 'created_by' => $this->manager->id, 'treated_person_type' => 'staff', 'treatment_given' => 'Cleaned and dressed', 'ambulance_called' => false, 'treatment_outcome' => 'returned_to_activity', ...$fields]);
        $row->forceFill(['lock_version' => 1])->saveQuietly();

        return $row;
    }

    private function followup(FirstAidRecord $record, array $fields = []): FirstAidFollowup
    {
        return FirstAidFollowup::create(['first_aid_record_id' => $record->id, 'notes' => 'Recheck dressing', 'assigned_to_user_id' => $this->manager->id, 'created_by' => $this->manager->id, ...$fields]);
    }

    private function attachment(FirstAidRecord $record, array $fields = [], bool $available = true): FirstAidAttachment
    {
        $row = FirstAidAttachment::create(['first_aid_record_id' => $record->id, 'uploaded_by' => $this->manager->id, 'disk' => 'private', 'path' => 'private/treatment-'.$record->id.'.pdf', 'original_name' => 'C:'.chr(92).'private'.chr(92).'treatment.pdf', 'mime' => 'application/pdf', 'size' => 20, 'kind' => 'document', ...$fields]);
        if ($available) {
            Storage::disk('private')->put($row->path, 'actual treatment evidence');
        }

        return $row;
    }

    private function detail(FirstAidRecord $record): array
    {
        return app(FirstAidPresenter::class)->detail($record, $this->manager, '/health-safety/first-aid');
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected fresh source denial.');
        } catch (HttpException $error) {
            $this->assertContains($error->getStatusCode(), [403, 404]);
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }
}
