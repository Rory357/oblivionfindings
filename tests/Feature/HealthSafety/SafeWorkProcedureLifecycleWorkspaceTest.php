<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\HsAttachment;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\ProcedureAcknowledgement;
use App\Models\ProcedureAcknowledgementReceipt;
use App\Models\ProcedureReviewCommandReceipt;
use App\Models\ProcedureReviewDelivery;
use App\Models\ProcedureReviewRequest;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\HealthSafety\SafeWorkProcedureService;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\SafeWorkProcedurePresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SafeWorkProcedureLifecycleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $writer;

    private Site $site;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->writer = $this->worker($this->site);
        foreach (['procedures.view', 'procedures.create', 'procedures.manage', 'procedures.approve', 'hazards.view', 'hazards.manage'] as $key) {
            $this->grant($this->writer, $key);
        }
        $this->actingAs($this->writer);
    }

    private function worker(Site $site): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        $this->grant($user, 'procedures.view');
        $this->grant($user, 'hazards.view');

        return $user;
    }

    private function grant(User $user, string $key): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
    }

    private function service(): SafeWorkProcedureService
    {
        return app(SafeWorkProcedureService::class);
    }

    private function draft(array $data = []): SafeWorkProcedure
    {
        return $this->service()->create($this->writer, [...$this->content(), ...$data]);
    }

    private function content(): array
    {
        return ['title' => 'Current controlled instructions', 'reference_number' => 'SWP-LIFECYCLE-'.(++$this->sequence), 'category' => 'manual_handling', 'steps' => [['description' => 'Use the agreed handling technique.', 'safety_notes' => 'Two workers for this transfer.']], 'applicable_roles' => ['support_worker'], 'applicable_sites' => [$this->site->id], 'related_training' => [], 'owner_id' => $this->writer->id, 'review_date' => null, 'review_frequency_months' => null];
    }

    private function guards(SafeWorkProcedure $record): array
    {
        $fresh = $record->fresh();

        return ['expected_version' => $fresh->current_version, 'expected_lock_version' => $fresh->lock_version];
    }

    private function publish(array $data = []): SafeWorkProcedure
    {
        $record = $this->draft($data);
        $this->service()->lifecycle($record, $this->writer, 'submit-for-review', $this->guards($record));
        $this->service()->lifecycle($record, $this->writer, 'approve', [...$this->guards($record), 'approval_note' => 'Approved current instructions.']);

        return $record->fresh();
    }

    private function audit(SafeWorkProcedure $record, string $action): AuditLog
    {
        return AuditLog::where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->where('action', $action)->latest('id')->firstOrFail();
    }

    private function event(): HsEvent
    {
        return HsEvent::factory()->create(['site_id' => $this->site->id]);
    }

    private function requestReview(SafeWorkProcedure $record, HsEvent $event, array $extra = []): ProcedureReviewRequest
    {
        return $this->service()->requestReview($record, $this->writer, ['hs_event_id' => $event->id, 'requested_version_id' => $record->published_version_id, 'assigned_to_user_id' => $this->writer->id, 'note' => 'Review these instructions against the safety findings.', 'idempotency_key' => (string) Str::uuid(), ...$extra]);
    }

    public function test_edit_preserves_published_content_dates_and_document_manifest(): void
    {
        Storage::fake('private');
        $record = $this->draft();
        $this->service()->addEvidence($record, $this->writer, $this->guards($record), UploadedFile::fake()->create('original.pdf', 2, 'application/pdf'));
        $this->service()->lifecycle($record, $this->writer, 'submit-for-review', $this->guards($record));
        $this->service()->lifecycle($record, $this->writer, 'approve', [...$this->guards($record), 'review_date' => '2027-01-01']);
        $record = $record->fresh();
        $published = $record->publishedVersion;
        $before = $published->getAttributes();
        $this->service()->update($record, $this->writer, [...$record->only(SafeWorkProcedureService::CONTENT_FIELDS), 'title' => 'Unapproved new title', 'review_date' => '2028-02-02', 'change_summary' => 'Proposed revision', ...$this->guards($record)]);
        $this->assertSame($published->id, $record->fresh()->published_version_id);
        $this->assertSame('2027-01-01', $record->fresh()->published_review_date->toDateString());
        $this->assertSame($before, $published->fresh()->getAttributes());
        $worker = $this->worker($this->site);
        $this->actingAs($worker)->getJson('/health-safety/procedures/'.$record->id)->assertOk()->assertJsonPath('detail.title', 'Current controlled instructions')->assertJsonPath('detail.form', null)->assertJsonCount(1, 'detail.attachments');
    }

    public function test_file_changes_bump_only_working_manifest_and_old_download_remains_readable(): void
    {
        Storage::fake('private');
        $record = $this->draft();
        $this->service()->addEvidence($record, $this->writer, $this->guards($record), UploadedFile::fake()->create('old.pdf', 2, 'application/pdf'));
        $this->service()->lifecycle($record, $this->writer, 'submit-for-review', $this->guards($record));
        $this->service()->lifecycle($record, $this->writer, 'approve', $this->guards($record));
        $record = $record->fresh();
        $published = $record->publishedVersion;
        $attachment = $record->attachments()->firstOrFail();
        $this->service()->removeEvidence($record, $this->writer, $this->guards($record), $attachment);
        $this->assertSame([(int) $attachment->id], $published->fresh()->document_manifest);
        $this->assertSame([], $record->fresh()->versions()->where('version_number', $record->fresh()->current_version)->first()->document_manifest);
        Storage::disk('private')->assertExists($attachment->path);
        $this->assertFalse($attachment->fresh()->trashed());
        $this->get('/health-safety/procedures/'.$record->id.'/attachments/'.$attachment->id.'/download?version='.$published->id)->assertOk();
        $working = $record->fresh()->versions()->where('version_number', $record->fresh()->current_version)->first();
        $this->get('/health-safety/procedures/'.$record->id.'/attachments/'.$attachment->id.'/download?version='.$working->id)->assertNotFound();
    }

    public function test_approval_requires_review_and_both_current_guards_and_writes_new_attributable_snapshot(): void
    {
        $record = $this->draft();
        $this->post('/health-safety/procedures/'.$record->id.'/approve', $this->guards($record))->assertSessionHasErrors('status');
        $stale = $this->guards($record);
        $this->service()->lifecycle($record, $this->writer, 'submit-for-review', $stale);
        $this->post('/health-safety/procedures/'.$record->id.'/approve', $stale)->assertSessionHasErrors('expected_version');
        $submitted = $record->fresh()->current_version;
        $this->post('/health-safety/procedures/'.$record->id.'/approve', [...$this->guards($record), 'approval_note' => 'Checked with the document owner.', 'review_date' => null])->assertSessionDoesntHaveErrors();
        $saved = $record->fresh();
        $this->assertSame($submitted + 1, $saved->current_version);
        $this->assertSame($this->writer->id, $saved->publishedVersion->approved_by);
        $this->assertNull($saved->published_review_date);
        $audit = $this->audit($record, 'procedure.approved');
        $this->assertSame($saved->published_version_id, $audit->meta['version_id']);
        $this->assertSame('Checked with the document owner.', $audit->meta['note']);
        $this->assertSame($this->writer->id, $audit->user_id);
    }

    public function test_review_archive_and_restore_do_not_republish_an_older_copy(): void
    {
        $record = $this->publish(['review_date' => '2026-01-01']);
        $id = $record->published_version_id;
        $this->service()->lifecycle($record, $this->writer, 'record-review', [...$this->guards($record), 'review_note' => 'Reviewed with staff; approval pending.', 'review_date' => '2027-01-01']);
        $this->assertSame('under_review', $record->fresh()->status);
        $this->assertSame($id, $record->fresh()->published_version_id);
        $this->assertSame('2026-01-01', $record->fresh()->published_review_date->toDateString());
        $this->service()->lifecycle($record, $this->writer, 'archive', $this->guards($record));
        $this->assertNull($record->fresh()->published_version_id);
        $this->service()->lifecycle($record, $this->writer, 'restore', $this->guards($record));
        $this->assertSame('draft', $record->fresh()->status);
        $this->assertNull($record->fresh()->published_version_id);
        $worker = $this->worker($this->site);
        $this->actingAs($worker)->getJson('/health-safety/procedures/'.$record->id)->assertOk()->assertJsonPath('detail.viewed_revision.kind', 'historical')->assertJsonPath('detail.publication', null)->assertJsonPath('detail.can.acknowledge', false);
        $this->get('/health-safety/procedures/'.$record->id.'?version='.$id)->assertOk();
    }

    public function test_omitted_audience_preserves_existing_scope_and_unknown_requires_resolution_before_approval(): void
    {
        $record = $this->draft();
        $this->service()->update($record, $this->writer, ['title' => 'Edited', 'reference_number' => $record->reference_number, 'category' => $record->category, 'change_summary' => 'Keep audience', ...$this->guards($record)]);
        $this->assertSame([$this->site->id], $record->fresh()->applicable_sites);
        $this->assertSame(['support_worker'], $record->fresh()->applicable_roles);
        $record->forceFill(['applicable_roles' => null, 'applicable_sites' => null])->saveQuietly();
        $this->grant($this->writer, 'healthSafety.viewAllSites');
        $this->service()->update($record, $this->writer, ['title' => 'Edited again', 'reference_number' => $record->reference_number, 'category' => $record->category, 'change_summary' => 'Unknown audience retained', ...$this->guards($record)]);
        $this->assertNull($record->fresh()->applicable_roles);
        $this->assertNull($record->fresh()->applicable_sites);
        $this->service()->lifecycle($record, $this->writer, 'submit-for-review', $this->guards($record));
        $this->post('/health-safety/procedures/'.$record->id.'/approve', $this->guards($record))->assertSessionHasErrors('applicable_roles');
    }

    public function test_null_training_links_survive_ordinary_edit_and_explicit_publication(): void
    {
        $record = $this->draft(['related_training' => null]);
        $this->put('/health-safety/procedures/'.$record->id, ['title' => 'Edited with unknown training links', 'reference_number' => $record->reference_number, 'category' => $record->category, 'change_summary' => 'Preserve omitted optional links', ...$this->guards($record)])->assertSessionDoesntHaveErrors();
        $this->assertNull($record->fresh()->related_training);
        $this->service()->lifecycle($record, $this->writer, 'submit-for-review', $this->guards($record));
        $this->post('/health-safety/procedures/'.$record->id.'/approve', $this->guards($record))->assertSessionDoesntHaveErrors();
        $this->assertNull($record->fresh()->publishedVersion->content_snapshot['related_training']);
    }

    public function test_manage_does_not_grant_foreign_site_or_beyond_role_working_oversight(): void
    {
        $foreign = Site::factory()->create();
        $record = SafeWorkProcedure::factory()->create(['applicable_sites' => [$foreign->id], 'applicable_roles' => ['support_worker']]);
        $this->get('/health-safety/procedures/'.$record->id.'?revision=working')->assertForbidden();
        $local = SafeWorkProcedure::factory()->create(['applicable_sites' => [$this->site->id], 'applicable_roles' => ['team_lead']]);
        $this->get('/health-safety/procedures/'.$local->id.'?revision=working')->assertForbidden();
        $this->grant($this->writer, 'healthSafety.viewAllSites');
        $this->get('/health-safety/procedures/'.$local->id.'?revision=working')->assertOk();
        $this->writer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'procedures.approve')->firstOrFail()->id => ['allowed' => false]]);
        $this->get('/health-safety/procedures/'.$local->id.'?revision=working')->assertForbidden();
    }

    public function test_commands_refresh_cached_actor_permission_employment_and_site_evidence(): void
    {
        $record = $this->draft();
        $this->writer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        app(UserSiteAccessService::class)->accessibleSiteIds($this->writer);
        $this->writer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->post('/health-safety/procedures/'.$record->id.'/submit-for-review', $this->guards($record))->assertForbidden();
        $this->writer->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => Site::factory()->create()->id]);
        $this->post('/health-safety/procedures/'.$record->id.'/submit-for-review', $this->guards($record))->assertForbidden();
        $this->writer->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->writer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'procedures.manage')->firstOrFail()->id => ['allowed' => false]]);
        $this->post('/health-safety/procedures/'.$record->id.'/submit-for-review', $this->guards($record))->assertForbidden();
        $this->assertSame('draft', $record->fresh()->status);
    }

    public function test_owner_must_be_named_current_document_manager_without_hs_owner_inference(): void
    {
        $other = $this->worker($this->site);
        $data = $this->content();
        $data['owner_id'] = $other->id;
        $this->post('/health-safety/procedures', $data)->assertSessionHasErrors('owner_id');
        $data['owner_id'] = null;
        $this->post('/health-safety/procedures', $data)->assertSessionDoesntHaveErrors();
        $this->assertNull(SafeWorkProcedure::where('reference_number', $data['reference_number'])->firstOrFail()->owner_id);
    }

    public function test_exact_reading_receipts_preserve_legacy_stamp_and_replays_do_not_duplicate_audit(): void
    {
        $record = $this->publish();
        ProcedureAcknowledgement::create(['safe_work_procedure_id' => $record->id, 'user_id' => $this->writer->id, 'version_acknowledged' => 1, 'acknowledged_at' => now()->subYear()]);
        $old = ProcedureAcknowledgement::first()->getAttributes();
        $data = ['published_version_id' => $record->published_version_id];
        $this->post('/health-safety/procedures/'.$record->id.'/acknowledge', $data)->assertSessionDoesntHaveErrors();
        $this->post('/health-safety/procedures/'.$record->id.'/acknowledge', $data)->assertSessionDoesntHaveErrors();
        $this->assertSame(1, ProcedureAcknowledgementReceipt::count());
        $this->assertSame($old, ProcedureAcknowledgement::first()->getAttributes());
        $this->assertSame(1, AuditLog::where('action', 'procedure.acknowledged')->count());
        $this->service()->lifecycle($record, $this->writer, 'record-review', [...$this->guards($record), 'review_note' => 'Reviewed unchanged content.', 'review_date' => null]);
        $this->service()->lifecycle($record, $this->writer, 'approve', $this->guards($record));
        $this->post('/health-safety/procedures/'.$record->id.'/acknowledge', $data)->assertSessionHasErrors('published_version_id');
        $this->assertSame(1, ProcedureAcknowledgementReceipt::count());
    }

    public function test_acknowledgement_rejects_a_pointer_to_unapproved_working_instructions(): void
    {
        $record = $this->draft();
        $version = $record->versions()->firstOrFail();
        $record->forceFill(['published_version_id' => $version->id])->saveQuietly();
        $this->post('/health-safety/procedures/'.$record->id.'/acknowledge', ['published_version_id' => $version->id])->assertForbidden();
        $this->assertSame(0, ProcedureAcknowledgementReceipt::count());
        $this->assertSame(0, AuditLog::where('action', 'procedure.acknowledged')->count());
    }

    public function test_strict_audit_failure_rolls_back_publication_and_clean_upload(): void
    {
        Storage::fake('private');
        $record = $this->draft();
        $this->service()->lifecycle($record, $this->writer, 'submit-for-review', $this->guards($record));
        $before = $record->fresh()->getAttributes();
        AuditLog::creating(function ($log) {
            if (in_array($log->action, ['procedure.approved', 'procedure.evidence_added'], true)) {
                throw new \RuntimeException('forced audit outage');
            }
        });
        try {
            $this->service()->lifecycle($record, $this->writer, 'approve', $this->guards($record));
            $this->fail('Expected strict audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('forced audit outage', $error->getMessage());
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
        try {
            $this->service()->addEvidence($record, $this->writer, $this->guards($record), UploadedFile::fake()->create('rollback.pdf', 2, 'application/pdf'));
            $this->fail('Expected upload audit failure');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, HsAttachment::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    public function test_failed_file_storage_persists_no_version_attachment_or_success(): void
    {
        $record = $this->draft();
        $before = $record->current_version;
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);
        $this->post('/health-safety/procedures/'.$record->id.'/attachments', [...$this->guards($record), 'file' => UploadedFile::fake()->create('fail.pdf', 2, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertSame($before, $record->fresh()->current_version);
        $this->assertSame(0, HsAttachment::count());
    }

    public function test_request_creation_is_idempotent_and_captures_canonical_source_with_named_executor(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $key = (string) Str::uuid();
        $request = $this->requestReview($record, $event, ['idempotency_key' => $key]);
        $again = $this->requestReview($record, $event, ['idempotency_key' => $key]);
        $this->assertSame($request->id, $again->id);
        $this->assertSame($this->site->id, $request->source_site_id);
        $this->assertSame($this->writer->id, $request->assigned_to_user_id);
        $this->assertSame(1, AuditLog::where('action', 'procedure.review_requested')->count());
        $this->expectException(ValidationException::class);
        $this->requestReview($record, $event, ['idempotency_key' => $key, 'note' => 'Different request content']);
    }

    public function test_source_http_receipt_links_the_exact_persisted_request_and_readable_options(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $response = $this->post('/health-safety/events/'.$event->id.'/procedure-review-requests', ['procedure_id' => $record->id, 'requested_version_id' => $record->published_version_id, 'assigned_to_user_id' => $this->writer->id, 'note' => 'Request from the canonical safety review.', 'idempotency_key' => (string) Str::uuid()]);
        $response->assertSessionDoesntHaveErrors();
        $review = ProcedureReviewRequest::firstOrFail();
        $response->assertSessionHas('created_procedure_review_request_id', $review->id);
        $href = session('created_procedure_review_request_href');
        $this->assertStringContainsString('section=connections', $href);
        $this->assertStringContainsString('&request='.$review->id, $href);
        $this->get($href)->assertOk();
        $this->getJson('/health-safety/events/'.$event->id.'/procedure-review-options')->assertOk()->assertJsonPath('requests.0.id', $review->id)->assertJsonPath('requests.0.procedure.title', $record->title);
        $summary = $this->getJson('/health-safety/events/'.$event->id.'/procedure-review-options')->json('requests.0.procedure.href');
        $this->assertStringContainsString('&request='.$review->id, $summary);
    }

    public function test_request_commands_are_stale_guarded_and_do_not_close_hs_source_or_action(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);
        $request = $this->requestReview($record, $event, ['hs_corrective_action_id' => $action->id]);
        $eventState = $event->status;
        $actionState = $action->status;
        $path = '/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id;
        $this->post($path.'/start', ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionDoesntHaveErrors();
        $this->post($path.'/resolve', ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'outcome' => 'no_change', 'result_version_id' => $record->published_version_id, 'decision_note' => 'No change required.'])->assertSessionHasErrors('expected_version');
        $this->post($path.'/resolve', ['expected_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'outcome' => 'no_change', 'result_version_id' => $record->published_version_id, 'decision_note' => 'Existing instructions cover this finding.'])->assertSessionDoesntHaveErrors();
        $this->assertSame('resolved', $request->fresh()->status);
        $this->assertSame('no_change', $request->fresh()->outcome);
        $this->assertSame($this->writer->id, $request->fresh()->decided_by_user_id);
        $this->assertSame($eventState, $event->fresh()->status);
        $this->assertSame($actionState, $action->fresh()->status);
    }

    public function test_request_reloads_source_site_and_rejects_foreign_action_tuple(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $request = $this->requestReview($record, $event);
        $event->update(['site_id' => Site::factory()->create()->id]);
        $this->grant($this->writer, 'healthSafety.viewAllSites');
        $this->post('/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id.'/start', ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame('requested', $request->fresh()->status);
        $local = $this->event();
        $foreignAction = HsCorrectiveAction::factory()->create(['hs_event_id' => $this->event()->id]);
        $this->post('/health-safety/events/'.$local->id.'/procedure-review-requests', ['procedure_id' => $record->id, 'requested_version_id' => $record->published_version_id, 'assigned_to_user_id' => $this->writer->id, 'hs_corrective_action_id' => $foreignAction->id, 'note' => 'Forged unrelated action', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_read_only_source_connections_require_both_authorities_and_do_not_expose_executor_pickers(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $this->requestReview($record, $event);
        $worker = $this->worker($this->site);
        $this->actingAs($worker)->getJson('/health-safety/events/'.$event->id.'/procedure-review-options')->assertOk()->assertJsonPath('can.create_request', false)->assertJsonCount(1, 'requests')->assertJsonCount(0, 'executors')->assertJsonCount(0, 'procedures');
        $worker->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'procedures.view')->firstOrFail()->id => ['allowed' => false]]);
        $this->getJson('/health-safety/events/'.$event->id.'/procedure-review-options')->assertForbidden();
    }

    public function test_revised_result_requires_new_controlled_publication_not_just_edited_working_content(): void
    {
        $record = $this->publish();
        $request = $this->requestReview($record, $this->event());
        $this->service()->reviewRequestCommand($record, $request, $this->writer, 'start', ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->service()->update($record, $this->writer, [...$record->only(SafeWorkProcedureService::CONTENT_FIELDS), 'title' => 'Revised instruction', 'change_summary' => 'Addressed finding', ...$this->guards($record)]);
        $working = $record->fresh()->versions()->where('version_number', $record->fresh()->current_version)->first();
        $path = '/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id.'/resolve';
        $this->post($path, ['expected_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'outcome' => 'revised', 'result_version_id' => $working->id, 'decision_note' => 'Working only'])->assertSessionHasErrors('result_version_id');
        $this->service()->lifecycle($record, $this->writer, 'approve', $this->guards($record));
        $result = $record->fresh()->published_version_id;
        $this->post($path, ['expected_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'outcome' => 'revised', 'result_version_id' => $result, 'decision_note' => 'New instructions explicitly approved.'])->assertSessionDoesntHaveErrors();
        $this->assertSame($result, $request->fresh()->result_version_id);
    }

    public function test_legacy_current_capture_backfill_preserves_old_snapshots_stamps_and_archive_boundary(): void
    {
        $record = SafeWorkProcedure::factory()->approved()->create(['applicable_roles' => null, 'applicable_sites' => null]);
        $original = $record->versions()->first()->getAttributes();
        $record->forceFill(['published_version_id' => null, 'published_review_date' => null])->saveQuietly();
        $archived = SafeWorkProcedure::factory()->archived()->create();
        $migration = require database_path('migrations/2026_10_08_180000_add_controlled_procedure_publications.php');
        $migration->captureLegacyPublications();
        $migration->captureLegacyPublications();
        $saved = $record->fresh();
        $this->assertSame($original, SafeWorkProcedureVersion::find($original['id'])->getAttributes());
        $this->assertSame('legacy_current_capture', $saved->publishedVersion->provenance);
        $this->assertNull($saved->publishedVersion->approved_at);
        $this->assertSame($record->approved_by, $saved->publishedVersion->legacy_approved_by);
        $this->assertNull($saved->publishedVersion->content_snapshot['applicable_roles']);
        $this->assertSame(2, $saved->versions()->count());
        $this->assertNull($archived->fresh()->published_version_id);
        $worker = $this->worker($this->site);
        $this->actingAs($worker)->get('/health-safety/procedures/'.$record->id)->assertOk();
        $this->post('/health-safety/procedures/'.$record->id.'/acknowledge', ['published_version_id' => $saved->published_version_id])->assertSessionDoesntHaveErrors();
        $this->assertSame('legacy_current_capture', ProcedureAcknowledgementReceipt::first()->provenance);
    }

    public function test_org_wide_mutation_requires_explicit_all_site_authority_even_for_manager_approver(): void
    {
        $data = $this->content();
        $data['applicable_sites'] = [];
        $this->post('/health-safety/procedures', $data)->assertSessionHasErrors('applicable_sites');
        $record = SafeWorkProcedure::factory()->underReview()->create(['applicable_sites' => [], 'applicable_roles' => ['support_worker']]);
        $this->post('/health-safety/procedures/'.$record->id.'/approve', $this->guards($record))->assertForbidden();
        $this->grant($this->writer, 'healthSafety.viewAllSites');
        $this->post('/health-safety/procedures/'.$record->id.'/approve', $this->guards($record))->assertSessionDoesntHaveErrors();
    }

    public function test_completed_source_action_remains_readable_and_command_retry_uses_confirmed_receipt(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => 'closed']);
        $request = $this->requestReview($record, $event, ['hs_corrective_action_id' => $action->id]);
        $payload = ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $path = '/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id.'/start';
        $this->post($path, $payload)->assertSessionDoesntHaveErrors();
        $this->post($path, $payload)->assertSessionDoesntHaveErrors();
        $this->assertSame(2, $request->fresh()->lock_version);
        $this->assertSame(1, AuditLog::where('action', 'procedure.review_request_start')->count());
        $this->post($path, [...$payload, 'expected_version' => 2])->assertSessionHasErrors('idempotency_key');
        $this->getJson('/health-safety/events/'.$event->id.'/procedure-review-options')->assertOk()->assertJsonCount(1, 'requests');
        $this->writer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->post($path, $payload)->assertForbidden();
    }

    public function test_direct_service_history_uses_authorized_actor_instead_of_unrelated_authenticated_user(): void
    {
        $other = $this->worker($this->site);
        $this->actingAs($other);
        $record = $this->draft();
        $this->assertSame($this->writer->id, $this->audit($record, 'procedure.created')->user_id);
        $this->service()->lifecycle($record, $this->writer, 'submit-for-review', $this->guards($record));
        $this->service()->lifecycle($record, $this->writer, 'approve', $this->guards($record));
        $this->assertSame($this->writer->id, $record->fresh()->publishedVersion->approved_by);
        $this->assertSame($this->writer->id, $this->audit($record, 'procedure.approved')->user_id);
    }

    public function test_strict_source_command_receipt_failure_rolls_back_request_and_does_not_consume_retry_key(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $request = $this->requestReview($record, $event);
        $key = (string) Str::uuid();
        AuditLog::creating(function ($log) {
            if ($log->action === 'procedure.review_request_start') {
                throw new \RuntimeException('request receipt outage');
            }
        });
        try {
            $this->service()->reviewRequestCommand($record, $request, $this->writer, 'start', ['expected_version' => 1, 'idempotency_key' => $key]);
            $this->fail('Expected strict request receipt failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('request receipt outage', $error->getMessage());
        }
        $this->assertSame('requested', $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->lock_version);
        $this->assertSame(0, AuditLog::where('meta->idempotency_key', $key)->count());
    }

    public function test_reassign_picker_and_command_require_current_request_authority_without_requiring_source_management(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $request = $this->requestReview($record, $event);
        $other = $this->worker($this->site);
        $this->grant($other, 'procedures.manage');
        $this->writer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->firstOrFail()->id => ['allowed' => false]]);
        $path = '/health-safety/events/'.$event->id.'/procedure-review-options?procedure_id='.$record->id;
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'executors')->assertJsonPath('can.create_request', false);
        $this->getJson($path.'&request_id='.$request->id)->assertOk()->assertJsonCount(2, 'executors');
        $this->post('/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id.'/reassign', ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'assigned_to_user_id' => $other->id])->assertSessionDoesntHaveErrors();
        $this->assertSame($other->id, $request->fresh()->assigned_to_user_id);
        $this->writer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'procedures.view')->firstOrFail()->id => ['allowed' => false]]);
        $this->post('/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id.'/cancel', ['expected_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'decision_note' => 'No longer needed'])->assertForbidden();
    }

    public function test_legacy_scalar_audience_capture_is_unknown_and_readable_without_claiming_controlled_approval(): void
    {
        $record = SafeWorkProcedure::factory()->approved()->create(['applicable_roles' => 'old scalar role', 'applicable_sites' => 'old scalar Site']);
        $record->forceFill(['published_version_id' => null])->saveQuietly();
        $migration = require database_path('migrations/2026_10_08_180000_add_controlled_procedure_publications.php');
        $migration->captureLegacyPublications();
        $copy = $record->fresh()->publishedVersion;
        $this->assertNull($copy->content_snapshot['applicable_roles']);
        $this->assertNull($copy->content_snapshot['applicable_sites']);
        $this->assertNull($copy->approved_at);
        $this->actingAs($this->worker($this->site))->getJson('/health-safety/procedures/'.$record->id)->assertOk()->assertJsonPath('detail.title', $record->title)->assertJsonPath('detail.approved_at', null);
    }

    public function test_register_metrics_and_export_use_visible_publication_facets_with_independent_working_status(): void
    {
        $record = $this->publish();
        $other = $this->publish(['category' => 'fire_safety']);
        $this->service()->update($record, $this->writer, [...$record->only(SafeWorkProcedureService::CONTENT_FIELDS), 'title' => 'Unpublished title', 'change_summary' => 'Proposed edit', ...$this->guards($record)]);
        $this->get('/health-safety/procedures?tab=approved&category=manual_handling')->assertOk()->assertInertia(fn (Assert $page) => $page->has('procedures.data', 1)->where('procedures.data.0.title', 'Current controlled instructions')->where('procedures.data.0.working_status', 'under_review')->where('tabCounts.approved', 1)->where('tabCounts.under_review', 1));
        $body = $this->get('/health-safety/procedures/export?tab=approved&category=manual_handling')->assertOk()->streamedContent();
        $this->assertStringContainsString($record->reference_number, $body);
        $this->assertStringNotContainsString($other->reference_number, $body);
        $this->assertStringNotContainsString('Unpublished title', $body);
        $foreign = Site::factory()->create();
        $this->get('/health-safety/procedures/export?site_id='.$foreign->id)->assertForbidden();
    }

    public function test_retry_returns_immutable_confirmed_result_after_request_has_later_progressed(): void
    {
        $record = $this->publish();
        $request = $this->requestReview($record, $this->event());
        $start = ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $this->service()->reviewRequestCommand($record, $request, $this->writer, 'start', $start);
        $this->service()->reviewRequestCommand($record, $request, $this->writer, 'resolve', ['expected_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'outcome' => 'no_change', 'result_version_id' => $record->published_version_id, 'decision_note' => 'Confirmed unchanged instructions.']);
        $confirmed = $this->service()->reviewRequestCommand($record, $request, $this->writer, 'start', $start);
        $this->assertSame('in_review', $confirmed->status);
        $this->assertSame(2, $confirmed->lock_version);
        $this->assertSame('resolved', $request->fresh()->status);
        $this->assertSame(3, $request->fresh()->lock_version);
        $this->assertSame(1, AuditLog::where('action', 'procedure.review_request_start')->count());
    }

    public function test_malformed_emergency_instructions_are_validation_errors_in_http_and_direct_service(): void
    {
        $data = $this->content();
        $data['emergency_procedures'] = [['unexpected' => 'nested value']];
        $this->post('/health-safety/procedures', $data)->assertSessionHasErrors('emergency_procedures');
        $this->assertSame(0, SafeWorkProcedure::count());
        $this->expectException(ValidationException::class);
        $this->service()->create($this->writer, $data);
    }

    public function test_native_source_request_unique_key_collision_becomes_a_safe_conflict_with_atomic_rollback(): void
    {
        $record = $this->publish();
        $event = $this->event();
        ProcedureReviewRequest::creating(function ($row) {
            DB::table('procedure_review_requests')->insert($row->getAttributes());
        });
        try {
            $this->requestReview($record, $event);
            $this->fail('Expected unique key conflict');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('idempotency_key', $error->errors());
        }
        $this->assertSame(0, ProcedureReviewRequest::count());
        $this->assertSame(0, AuditLog::where('action', 'procedure.review_requested')->count());
    }

    public function test_current_staff_boundary_rejects_legacy_and_rbac_portal_identities_with_lingering_employment(): void
    {
        $record = $this->publish();
        foreach (['client', 'next_of_kin'] as $portalRole) {
            foreach (['legacy', 'rbac'] as $identitySource) {
                $user = $this->worker($this->site);
                $this->grant($user, 'procedures.manage');
                if ($identitySource === 'legacy') {
                    $user->forceFill(['role' => $portalRole])->saveQuietly();
                } else {
                    $role = Role::firstOrCreate(['name' => $portalRole], ['label' => ucwords(str_replace('_', ' ', $portalRole)), 'type' => 'system']);
                    $user->roles()->attach($role);
                }
                $this->actingAs($user)->getJson('/health-safety/procedures/'.$record->id)->assertForbidden();
                $this->post('/health-safety/procedures/'.$record->id.'/acknowledge', ['published_version_id' => $record->published_version_id])->assertForbidden();
                $this->post('/health-safety/procedures/'.$record->id.'/archive', $this->guards($record))->assertForbidden();
            }
        }
        $this->assertSame(0, ProcedureAcknowledgementReceipt::count());
        $this->assertSame('approved', $record->fresh()->status);
    }

    public function test_review_projection_rechecks_warmed_hazards_grant_and_restores_without_stale_permissions(): void
    {
        $record = $this->publish();
        $request = $this->requestReview($record, $this->event());
        $this->assertSame([$request->id], array_column(SafeWorkProcedurePresenter::detail($record, $this->writer)['review_requests'], 'id'));
        $this->writer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $permission = Permission::where('key', 'hazards.view')->sole();
        $this->writer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $detail = SafeWorkProcedurePresenter::detail($record, $this->writer);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame([], $detail['review_requests']);
        $this->assertSame('Current controlled instructions', $detail['title']);
        $this->assertDoesNotMatchRegularExpression('/\bfrom\s+[`"]?(?:hs_events|first_aid_records|restraint_events|workplace_injuries)[`"]?\b/i', implode(' ', array_column($queries, 'query')));
        $this->grant($this->writer, 'hazards.view');
        $this->assertSame([$request->id], array_column(SafeWorkProcedurePresenter::detail($record, $this->writer)['review_requests'], 'id'));
    }

    public function test_foreign_requested_version_is_excluded_before_event_discovery_without_losing_healthy_request(): void
    {
        $record = $this->publish();
        $healthy = $this->requestReview($record, $this->event());
        $other = $this->publish();
        $damaged = $this->requestReview($record, $this->event());
        DB::table('procedure_review_requests')->where('id', $damaged->id)->update(['requested_version_id' => $other->published_version_id]);
        $detail = SafeWorkProcedurePresenter::detail($record, $this->writer);
        $this->assertSame([$healthy->id], array_column($detail['review_requests'], 'id'));
        $this->assertSame($other->published_version_id, $damaged->fresh()->requested_version_id);
        $this->assertSame('requested', $damaged->fresh()->status);
        $this->assertTrue($detail['review_requests'][0]['can']['start']);
        $this->assertSame($record->published_version_id, $detail['review_requests'][0]['requested_version']['id']);
    }

    public function test_review_sources_follow_current_sites_while_the_permitted_publication_remains_readable(): void
    {
        $otherSite = Site::factory()->create(['type' => 'house']);
        $this->writer->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $record = $this->publish(['applicable_sites' => [$this->site->id, $otherSite->id]]);
        $former = $this->requestReview($record, $this->event());
        $otherEvent = HsEvent::factory()->createQuietly(['site_id' => $otherSite->id]);
        $current = $this->requestReview($record, $otherEvent);
        $reader = $this->worker($this->site);
        $this->assertSame([$former->id], array_column(SafeWorkProcedurePresenter::detail($record, $reader)['review_requests'], 'id'));
        $reader->load('hrEmployeeProfile');
        $reader->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        $detail = SafeWorkProcedurePresenter::detail($record, $reader);
        $this->assertSame('Current controlled instructions', $detail['title']);
        $this->assertSame([$current->id], array_column($detail['review_requests'], 'id'));
        $this->assertFalse($detail['review_requests'][0]['can']['start']);
        $this->assertNull($detail['review_requests'][0]['delivery']);
    }
    public function test_rollback_refuses_to_discard_new_controlled_evidence(): void
    {
        $this->publish();
        $migration = require database_path('migrations/2026_10_08_180000_add_controlled_procedure_publications.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Export and preserve');
        $migration->down();
    }

    public function test_request_reassign_picker_uses_its_authorized_working_record_after_publication_audience_changes(): void
    {
        $otherSite = Site::factory()->create(['type' => 'house']);
        $this->writer->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $record = $this->publish();
        $event = $this->event();
        $request = $this->requestReview($record, $event);
        $requestedVersionId = $request->requested_version_id;
        $this->service()->update($record, $this->writer, [...$record->fresh()->only(SafeWorkProcedureService::CONTENT_FIELDS), 'applicable_sites' => [$otherSite->id], 'change_summary' => 'Publish instructions for the other Site.', ...$this->guards($record)]);
        $this->service()->lifecycle($record, $this->writer, 'approve', $this->guards($record));
        $this->service()->update($record, $this->writer, [...$record->fresh()->only(SafeWorkProcedureService::CONTENT_FIELDS), 'applicable_sites' => [$this->site->id], 'change_summary' => 'Prepare instructions for the original Site.', ...$this->guards($record)]);
        $this->writer->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $other = $this->worker($this->site);
        $this->grant($other, 'procedures.manage');
        $path = '/health-safety/events/'.$event->id.'/procedure-review-options?procedure_id='.$record->id;
        $this->getJson($path)->assertForbidden();
        $this->getJson($path.'&request_id='.$request->id)->assertOk()->assertJsonPath('can.create_request', true)->assertJsonCount(0, 'procedures')->assertJsonCount(2, 'executors')->assertJsonCount(1, 'requests');
        $this->post('/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id.'/reassign', ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'assigned_to_user_id' => $other->id])->assertSessionDoesntHaveErrors();
        $this->assertSame($other->id, (int) $request->fresh()->assigned_to_user_id);
        $this->assertSame($requestedVersionId, $request->fresh()->requested_version_id);
        $this->actingAs($this->worker($this->site))->getJson($path.'&request_id='.$request->id)->assertForbidden();
        $this->actingAs($this->writer)->getJson('/health-safety/events/'.$this->event()->id.'/procedure-review-options?procedure_id='.$record->id.'&request_id='.$request->id)->assertNotFound();
    }

    public function test_archived_request_has_no_commands_but_exact_receipt_recovers_without_mutation_authority_or_new_writes(): void
    {
        $record = $this->publish();
        $event = $this->event();
        $request = $this->requestReview($record, $event);
        $start = ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $this->service()->reviewRequestCommand($record, $request, $this->writer, 'start', $start);
        $resolve = ['expected_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'outcome' => 'no_change', 'result_version_id' => $record->published_version_id, 'decision_note' => 'Current instructions cover this source.'];
        $this->service()->reviewRequestCommand($record, $request, $this->writer, 'resolve', $resolve);
        $unstarted = $this->requestReview($record, $event);
        $started = $this->requestReview($record, $event);
        $this->service()->reviewRequestCommand($record, $started, $this->writer, 'start', ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->service()->lifecycle($record, $this->writer, 'archive', $this->guards($record));
        $audits = AuditLog::where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->count();
        $receipts = ProcedureReviewCommandReceipt::count();
        $deliveries = ProcedureReviewDelivery::count();
        $before = $request->fresh()->getAttributes();
        $recordBefore = $record->fresh()->getAttributes();
        $access = app(SafeWorkProcedureAccessService::class);
        foreach ([$unstarted->fresh(), $started->fresh()] as $candidate) {
            $this->assertSame(array_fill_keys(['start', 'resolve', 'decline', 'cancel', 'reassign'], false), $access->reviewRequestCan($record->fresh(), $candidate, $this->writer));
        }
        $this->writer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'procedures.manage')->firstOrFail()->id => ['allowed' => false]]);
        $confirmed = $this->service()->reviewRequestCommand($record, $request, $this->writer, 'start', $start);
        $this->assertSame('in_review', $confirmed->status);
        $this->assertSame(2, $confirmed->lock_version);
        $confirmed = $this->service()->reviewRequestCommand($record, $request, $this->writer, 'resolve', $resolve);
        $this->assertSame('resolved', $confirmed->status);
        $this->assertSame((int) $resolve['result_version_id'], (int) $confirmed->result_version_id);
        $path = '/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id.'/start';
        $this->post($path, ['expected_version' => 3, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('status');
        $this->post($path, [...$start, 'expected_version' => 3])->assertSessionHasErrors('idempotency_key');
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame($recordBefore, $record->fresh()->getAttributes());
        $this->assertSame($audits, AuditLog::where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->count());
        $this->assertSame($receipts, ProcedureReviewCommandReceipt::count());
        $this->assertSame($deliveries, ProcedureReviewDelivery::count());
        $this->assertNull($record->fresh()->published_version_id);
        $this->assertSame('open', $event->fresh()->status);
    }

    public function test_archived_confirmed_retry_still_requires_fresh_actor_source_and_requested_version_read(): void
    {
        $record = $this->publish();
        $request = $this->requestReview($record, $this->event());
        $payload = ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $this->service()->reviewRequestCommand($record, $request, $this->writer, 'start', $payload);
        $this->service()->lifecycle($record, $this->writer, 'archive', $this->guards($record));
        $before = $request->fresh()->getAttributes();
        $path = '/health-safety/procedures/'.$record->id.'/review-requests/'.$request->id.'/start';
        foreach (['procedures.view', 'hazards.view'] as $permission) {
            $id = Permission::where('key', $permission)->firstOrFail()->id;
            $this->writer->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => false]]);
            $this->post($path, $payload)->assertForbidden();
            $this->writer->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => true]]);
        }
        $this->writer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->post($path, $payload)->assertForbidden();
        $this->writer->hrEmployeeProfile()->update(['is_active' => true]);
        $this->writer->update(['role' => 'client']);
        $this->post($path, $payload)->assertForbidden();
        $this->writer->update(['role' => 'nurse']);
        $this->post($path, $payload)->assertForbidden();
        $this->writer->update(['role' => 'support_worker']);
        $foreign = Site::factory()->create(['type' => 'house']);
        $this->writer->hrEmployeeProfile()->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->post($path, $payload)->assertForbidden();
        $this->writer->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->actingAs($this->worker($this->site))->post($path, $payload)->assertSessionHasErrors('idempotency_key');
        $this->actingAs($this->writer)->post($path, $payload)->assertSessionDoesntHaveErrors();
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame(1, ProcedureReviewCommandReceipt::count());
        $this->assertSame(1, AuditLog::where('action', 'procedure.review_request_start')->count());
        $this->assertSame(1, ProcedureReviewDelivery::count());
    }
}
