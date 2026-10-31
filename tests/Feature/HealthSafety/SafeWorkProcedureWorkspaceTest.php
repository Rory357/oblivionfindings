<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\HsAttachment;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\ProcedureAcknowledgementReceipt;
use App\Models\ProcedureReviewDelivery;
use App\Models\ProcedureReviewRequest;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\SafeWorkProcedureService;
use App\Support\HealthSafety\SafeWorkProcedurePresenter;
use App\Support\HealthSafety\SafeWorkProcedureReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SafeWorkProcedureWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->manager = $this->worker(['procedures.view', 'procedures.create', 'procedures.manage', 'procedures.approve', 'hazards.view', 'hazards.manage']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_html_json_and_legacy_links_share_the_canonical_published_record(): void
    {
        $record = $this->procedure();
        $origin = '/health-safety/procedures?tab=approved&site_id='.$this->site->id.'&page=2';
        $this->actingAs($this->manager)->get(SafeWorkProcedureReturn::href($record->id, $origin))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/procedures/show')
            ->where('detail.id', $record->id)->where('detail.viewed_revision.kind', 'published')
            ->where('return_to', $origin)->has('pickers')->has('detail.review_requests')->has('detail.history'));
        $this->getJson('/health-safety/procedures/'.$record->id)->assertOk()
            ->assertJsonPath('detail.publication.version_id', $record->published_version_id);
        $this->get($origin.'&procedure='.$record->id.'&action=approve')
            ->assertRedirect(SafeWorkProcedureReturn::href($record->id, $origin));
        $this->get('/health-safety/procedures?procedure[]=1')->assertNotFound();
    }

    public function test_worker_reads_published_instructions_and_owner_while_successor_is_private(): void
    {
        $originalOwner = $this->worker([]);
        $record = $this->procedure(['title' => 'Published instructions', 'owner_id' => $originalOwner->id]);
        $publishedId = $record->published_version_id;
        $this->draft($record, ['title' => 'Private revision', 'owner_id' => $this->manager->id]);
        $reader = $this->worker(['procedures.view']);
        $detail = SafeWorkProcedurePresenter::detail($record, $reader, '/hr/my');
        $this->assertSame('Published instructions', $detail['title']);
        $this->assertSame($originalOwner->id, $detail['owner']['id']);
        $this->assertSame('approved', $detail['status']);
        $this->assertNull($detail['working_revision']);
        $this->assertNull($detail['form']);
        $this->assertTrue($detail['can']['acknowledge']);
        $this->assertSame($publishedId, $detail['acknowledgement']['version_id']);
        $this->assertSame(SafeWorkProcedureReturn::href($record->id, '/hr/my'), $detail['publication']['href']);
        $this->assertStringNotContainsString('Private revision', json_encode($detail));
        $this->actingAs($reader)->get('/health-safety/procedures/'.$record->id.'?revision=working')->assertNotFound();
    }

    public function test_historical_approved_snapshot_keeps_its_audience_and_owner_after_republication(): void
    {
        $owner = $this->worker([]);
        $record = $this->procedure(['title' => 'Original approved copy', 'owner_id' => $owner->id]);
        $old = $record->publishedVersion;
        $otherRole = Role::firstOrCreate(['name' => 'procedure_other_role'], ['label' => 'Other role']);
        $new = $this->draft($record, ['title' => 'New role copy', 'owner_id' => $this->manager->id, 'applicable_roles' => [$otherRole->name]]);
        $new->forceFill(['approved_by' => $this->manager->id, 'approved_at' => now()])->saveQuietly();
        $record->forceFill(['status' => 'approved', 'published_version_id' => $new->id])->saveQuietly();
        $reader = $this->worker(['procedures.view']);
        $detail = SafeWorkProcedurePresenter::detail($record, $reader, '/hr/my', $old->id);
        $this->assertSame('Original approved copy', $detail['title']);
        $this->assertSame($owner->id, $detail['owner']['id']);
        $this->assertSame('historical', $detail['viewed_revision']['kind']);
        $this->assertFalse($detail['can']['acknowledge']);
        $this->assertNull($detail['publication']);
        $this->actingAs($reader)->get('/health-safety/procedures/'.$record->id.'?version='.$old->id)->assertOk();
        $this->get('/health-safety/procedures/'.$record->id.'?version='.$new->id)->assertNotFound();
    }

    public function test_archived_canonical_link_reads_the_newest_authorized_prior_publication_without_restoring_it(): void
    {
        $record = $this->procedure(['title' => 'Last authorized publication']);
        $approvedId = $record->published_version_id;
        $foreignSite = Site::factory()->create();
        $later = $this->draft($record, ['title' => 'Other Site approved copy', 'applicable_sites' => [$foreignSite->id]]);
        $later->forceFill(['approved_by' => $this->manager->id, 'approved_at' => now()])->saveQuietly();
        $this->draft($record, ['title' => 'Unpublished final revision']);
        $record->forceFill(['status' => 'archived', 'published_version_id' => null, 'published_review_date' => null])->saveQuietly();
        $reader = $this->worker(['procedures.view']);
        $detail = SafeWorkProcedurePresenter::detail($record, $reader, '/hr/my');
        $this->assertSame('Last authorized publication', $detail['title']);
        $this->assertSame('archived', $detail['status']);
        $this->assertFalse($detail['is_current']);
        $this->assertSame('historical', $detail['viewed_revision']['kind']);
        $this->assertSame($approvedId, $detail['viewed_revision']['version_id']);
        $this->assertNull($detail['publication']);
        $this->assertNull($detail['form']);
        foreach (['edit', 'approve', 'request_changes', 'submit_review', 'record_review', 'archive', 'restore', 'add_evidence', 'remove_evidence', 'acknowledge', 'manage_review_requests'] as $command) {
            $this->assertFalse($detail['can'][$command], $command);
        }
        $this->actingAs($reader)->get('/health-safety/procedures/'.$record->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.status', 'archived')->where('detail.is_current', false)
                ->where('detail.viewed_revision.version_id', $approvedId)->where('detail.title', 'Last authorized publication'));
        $this->getJson('/health-safety/procedures/'.$record->id.'?version='.$approvedId)->assertOk()
            ->assertJsonPath('detail.viewed_revision.version_id', $approvedId);
        $this->assertNull($record->refresh()->published_version_id);
        $this->assertSame('archived', $record->status);
    }

    public function test_current_site_and_workforce_removal_denies_direct_record_and_profile_projection(): void
    {
        $reader = $this->worker(['procedures.view']);
        $record = $this->procedure();
        $foreign = $this->procedure(['applicable_sites' => [Site::factory()->create()->id]]);
        $this->actingAs($reader)->getJson('/health-safety/procedures/'.$foreign->id)->assertForbidden();
        $reader->hrEmployeeProfile()->update(['is_active' => false]);
        $this->getJson('/health-safety/procedures/'.$record->id)->assertForbidden();
        $this->assertSame([], SafeWorkProcedurePresenter::applicableRows($reader, '/hr/my')->all());
    }

    public function test_legacy_capture_labels_unverified_approval_and_unknown_audience_without_mutable_fallback(): void
    {
        $record = $this->procedure();
        $v = $record->publishedVersion;
        $v->forceFill(['provenance' => 'legacy_current_capture', 'captured_at' => now(),
            'approved_by' => null, 'approved_at' => null, 'legacy_approved_by' => $this->manager->id,
            'legacy_approved_at' => now()->subYear(), 'document_manifest' => null,
            'content_snapshot' => ['title' => 'Captured instructions', 'applicable_roles' => null,
                'applicable_sites' => null, '_publication_provenance' => 'legacy_current_capture']])->saveQuietly();
        $record->forceFill(['purpose' => 'Later mutable purpose'])->saveQuietly();
        $detail = SafeWorkProcedurePresenter::detail($record, $this->worker(['procedures.view']));
        $this->assertNull($detail['approved_by']);
        $this->assertNull($detail['approved_at']);
        $this->assertSame($this->manager->id, $detail['publication']['legacy_approved_by']['id']);
        $this->assertSame('unknown', $detail['applies']['roles_scope']);
        $this->assertSame('unknown', $detail['applies']['sites_scope']);
        $this->assertSame('unrecorded', $detail['documents_state']);
        $this->assertNull($detail['purpose']);
        $this->assertTrue($detail['viewed_revision']['legacy']);
        $this->assertTrue($detail['can']['acknowledge']);
    }

    public function test_document_manifest_exposes_only_exact_copy_and_never_storage_paths(): void
    {
        $record = $this->procedure();
        $included = $this->attachment($record, 'Published.pdf');
        $excluded = $this->attachment($record, 'Working.pdf');
        $record->publishedVersion->forceFill(['document_manifest' => [$included->id]])->saveQuietly();
        $detail = SafeWorkProcedurePresenter::detail($record, $this->worker(['procedures.view']));
        $this->assertSame([$included->id], array_column($detail['attachments'], 'id'));
        $this->assertStringEndsWith('?version='.$record->published_version_id, $detail['attachments'][0]['url']);
        $this->assertStringNotContainsString('private/secret', json_encode($detail));
        $this->actingAs($this->manager)->get('/health-safety/procedures/'.$record->id.'/attachments/'.$excluded->id.'/download?version='.$record->published_version_id)->assertNotFound();
    }

    public function test_history_keeps_attribution_and_explicit_scalar_snapshots_without_private_metadata(): void
    {
        $record = $this->procedure();
        $writer = $this->worker([]);
        $audit = AuditLog::create(['auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
            'action' => 'procedure.evidence_added', 'user_id' => $writer->id,
            'meta' => ['version_id' => $record->published_version_id, 'note' => 'Recorded note',
                'names' => ['private/secret/evidence.pdf', 'C:\\fakepath\\photo.png'],
                'snapshot' => ['title' => ['path' => 'private/nested'], 'purpose' => 'Recorded purpose',
                    'applicable_sites' => [$this->site->id, Site::factory()->create()->id], 'path' => 'private/secret'],
                'request' => ['ip' => '192.0.2.1']]]);
        $legacy = AuditLog::create(['auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
            'action' => 'safeworkprocedure.update', 'user_id' => null, 'meta' => ['note' => 'Truncated legacy note', 'after' => ['purpose' => 'Old purpose']]]);
        $detail = SafeWorkProcedurePresenter::detail($record, $this->manager);
        $entry = collect($detail['history'])->firstWhere('id', 'audit-'.$audit->id);
        $this->assertSame($writer->id, $entry['actor']['id']);
        $this->assertSame(['evidence.pdf', 'photo.png'], $entry['snapshot']['names']);
        $this->assertSame([$this->site->id], $entry['snapshot']['applicable_sites']);
        $this->assertArrayNotHasKey('title', $entry['snapshot']);
        $this->assertStringNotContainsString('private/', json_encode($entry));
        $legacyEntry = collect($detail['history'])->firstWhere('id', 'audit-'.$legacy->id);
        $this->assertTrue($legacyEntry['legacy']);
        $this->assertNull($legacyEntry['note']);
    }

    public function test_source_rows_are_complete_and_published_even_when_more_than_twenty_five_are_applicable(): void
    {
        $records = SafeWorkProcedure::factory()->approved()->count(26)->create(['applicable_sites' => [$this->site->id],
            'applicable_roles' => [], 'created_by' => $this->manager->id, 'approved_by' => $this->manager->id]);
        $first = $records->first();
        $originalTitle = $first->title;
        $this->draft($first, ['title' => 'Hidden working title']);
        $this->procedure(['applicable_sites' => [Site::factory()->create()->id]]);
        $reader = $this->worker(['procedures.view']);
        $rows = SafeWorkProcedurePresenter::applicableRows($reader, '/sites/'.$this->site->id.'?tab=hazards', $this->site->id);
        $this->assertCount(26, $rows);
        $row = $rows->firstWhere('id', $first->id);
        $this->assertSame($originalTitle, $row['title']);
        $this->assertSame('approved', $row['status']);
        $this->assertSame(SafeWorkProcedureReturn::href($first->id, '/sites/'.$this->site->id.'?tab=hazards'), $row['href']);
    }

    public function test_employee_audience_and_exact_receipt_are_separate_from_manager_audience(): void
    {
        $record = $this->procedure();
        $subject = $this->worker(['procedures.view']);
        $other = $this->worker(['procedures.view']);
        ProcedureAcknowledgementReceipt::create(['safe_work_procedure_id' => $record->id,
            'version_id' => $record->published_version_id, 'user_id' => $subject->id,
            'provenance' => 'controlled', 'acknowledged_at' => now()]);
        $role = Role::create(['name' => 'procedure_profile_audience', 'label' => 'Procedure profile audience']);
        $this->manager->roles()->attach($role);
        $excludedRole = $this->procedure(['applicable_roles' => [$role->name]]);
        $rows = SafeWorkProcedurePresenter::applicableRows($this->manager, '/hr/people/'.$subject->hrEmployeeProfile->id.'?tab=training', null, $subject);
        $this->assertNull($rows->firstWhere('id', $excludedRole->id));
        $this->assertTrue($rows->firstWhere('id', $record->id)['acknowledged']);
        $this->assertFalse(SafeWorkProcedurePresenter::applicableRows($this->manager, '/hr/my', null, $other)->firstWhere('id', $record->id)['acknowledged']);
        $subject->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $this->assertCount(0, SafeWorkProcedurePresenter::applicableRows($this->manager, '/hr/my', null, $subject));
        $subject->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertCount(0, SafeWorkProcedurePresenter::applicableRows($this->manager, '/hr/my', null, $subject));
    }

    public function test_source_requests_reauthorize_exact_event_tuple_and_hide_their_history_together(): void
    {
        $record = $this->procedure();
        $event = HsEvent::factory()->create(['site_id' => $this->site->id]);
        $visible = $this->reviewRequest($record, $event);
        $foreignEvent = HsEvent::factory()->create(['site_id' => Site::factory()->create()->id]);
        $hidden = $this->reviewRequest($record, $foreignEvent);
        $mismatched = $this->reviewRequest($record, $event, ['source_site_id' => $foreignEvent->site_id]);
        foreach ([$visible, $hidden, $mismatched] as $request) {
            AuditLog::create(['auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
                'action' => 'procedure.review_requested', 'user_id' => $this->manager->id,
                'meta' => ['request_id' => $request->id, 'version_id' => $record->published_version_id, 'note' => $request->note]]);
        }
        $detail = SafeWorkProcedurePresenter::detail($record, $this->manager);
        $this->assertSame([$visible->id], array_column($detail['review_requests'], 'id'));
        $history = collect($detail['history'])->where('action', 'procedure.review_requested');
        $this->assertCount(1, $history);
        $privateVersion = $this->draft($record, ['title' => 'Private successor']);
        AuditLog::create(['auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
            'action' => 'procedure.review_request_start', 'user_id' => $this->manager->id,
            'meta' => ['request_id' => $visible->id, 'version_id' => $privateVersion->id,
                'version' => $privateVersion->version_number, 'from' => 'requested', 'to' => 'in_review']]);
        $sourceReader = $this->worker(['procedures.view', 'hazards.view']);
        $entry = collect(SafeWorkProcedurePresenter::detail($record, $sourceReader)['history'])->firstWhere('action', 'procedure.review_request_start');
        $this->assertSame($visible->id, $entry['snapshot']['request_id']);
        $this->assertArrayNotHasKey('version_id', $entry['snapshot']);
        $this->assertArrayNotHasKey('version', $entry['snapshot']);
        $reader = $this->worker(['procedures.view']);
        $this->assertSame([], SafeWorkProcedurePresenter::detail($record, $reader)['review_requests']);
        $this->assertCount(0, collect(SafeWorkProcedurePresenter::detail($record, $reader)['history'])->where('action', 'procedure.review_requested'));
    }

    public function test_linked_action_requires_actual_task_authority_and_keeps_procedure_connections_context(): void
    {
        $record = $this->procedure();
        $event = HsEvent::factory()->create(['site_id' => $this->site->id]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'title' => 'Independent H&S action', 'status' => 'closed']);
        $request = $this->reviewRequest($record, $event, ['hs_corrective_action_id' => $action->id, 'status' => 'resolved', 'outcome' => 'no_change', 'result_version_id' => $record->published_version_id]);
        $origin = '/health-safety/procedures?tab=review_due&site_id='.$this->site->id;
        $row = SafeWorkProcedurePresenter::detail($record, $this->manager, $origin)['review_requests'][0];
        $this->assertSame($request->id, $row['id']);
        $this->assertSame($action->id, $row['corrective_action']['id']);
        $this->assertSame('closed', $row['corrective_action']['status']);
        foreach ([$row['source']['href'], $row['corrective_action']['href']] as $href) {
            parse_str(parse_url($href, PHP_URL_QUERY), $query);
            $this->assertSame('/health-safety/procedures/'.$record->id, parse_url($query['return_to'], PHP_URL_PATH));
            parse_str(parse_url($query['return_to'], PHP_URL_QUERY), $backQuery);
            $this->assertEquals(['section' => 'connections', 'request' => (string) $request->id, 'return_to' => $origin], $backQuery);
            $response = $this->actingAs($this->manager)->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page->has(str_contains(parse_url($href, PHP_URL_PATH), '/corrective-actions/') ? 'workspace' : 'detail'));
            $actualUrl = $response->viewData('page')['url'];
            $this->assertSame(parse_url($href, PHP_URL_PATH), parse_url($actualUrl, PHP_URL_PATH));
            parse_str(parse_url($actualUrl, PHP_URL_QUERY), $actualQuery);
            $this->assertEquals($query, $actualQuery);
        }
        foreach (['requested_version', 'result_version'] as $key) {
            $href = $row[$key]['href'];
            parse_str(parse_url($href, PHP_URL_QUERY), $query);
            $this->assertSame('connections', $query['section']);
            $this->assertSame((string) $request->id, $query['request']);
            $this->assertSame((string) $record->published_version_id, $query['version']);
            $this->assertSame($origin, $query['return_to']);
            $response = $this->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.viewed_revision.version_id', $record->published_version_id));
            $actualUrl = $response->viewData('page')['url'];
            $this->assertSame(parse_url($href, PHP_URL_PATH), parse_url($actualUrl, PHP_URL_PATH));
            parse_str(parse_url($actualUrl, PHP_URL_QUERY), $actualQuery);
            $this->assertEquals($query, $actualQuery);
        }
    }

    public function test_review_calendar_uses_the_new_zealand_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:30:00', 'UTC'));
        $record = $this->procedure(['review_date' => '2026-10-08']);
        $this->assertSame('today', SafeWorkProcedurePresenter::detail($record, $this->manager)['review_state']);
    }

    public function test_exact_acknowledgement_receipt_keeps_eligibility_but_removes_the_completed_command(): void
    {
        $record = $this->procedure();
        $reader = $this->worker(['procedures.view']);
        $before = SafeWorkProcedurePresenter::detail($record, $reader);
        $this->assertTrue($before['acknowledgement']['eligible']);
        $this->assertFalse($before['acknowledgement']['acknowledged']);
        $this->assertTrue($before['can']['acknowledge']);
        $service = app(SafeWorkProcedureService::class);
        $receipt = $service->acknowledge($record, $reader, ['published_version_id' => $record->published_version_id]);
        $after = SafeWorkProcedurePresenter::detail($record, $reader);
        $this->assertTrue($after['acknowledgement']['eligible']);
        $this->assertTrue($after['acknowledgement']['acknowledged']);
        $this->assertFalse($after['can']['acknowledge']);
        $replayed = $service->acknowledge($record, $reader, ['published_version_id' => $record->published_version_id]);
        $this->assertSame($receipt->id, $replayed->id);
        $this->assertDatabaseCount('procedure_acknowledgement_receipts', 1);
    }

    public function test_request_delivery_summary_uses_latest_assignment_and_is_private_to_executor_or_manager(): void
    {
        $record = $this->procedure();
        $event = HsEvent::factory()->create(['site_id' => $this->site->id]);
        $executor = $this->worker(['procedures.view', 'hazards.view']);
        $request = $this->reviewRequest($record, $event, ['assigned_to_user_id' => $executor->id, 'lock_version' => 3]);
        $this->delivery($request, 1, $this->manager->id, ['status' => 'delivered', 'delivered_at' => now()->subHour()]);
        $latest = $this->delivery($request, 2, $executor->id, ['status' => 'failed', 'failure_code' => 'queue_unavailable', 'attempts' => 2, 'next_attempt_at' => now()->addMinute()]);
        $managerRow = SafeWorkProcedurePresenter::detail($record, $this->manager)['review_requests'][0];
        $executorRow = SafeWorkProcedurePresenter::detail($record, $executor)['review_requests'][0];
        $this->assertSame($managerRow['delivery'], $executorRow['delivery']);
        $this->assertSame(['status' => 'failed', 'failure_code' => 'queue_unavailable', 'attempts' => 2, 'delivered_at' => null, 'next_attempt_at' => $latest->next_attempt_at->toIso8601String()], $executorRow['delivery']);
        $ordinary = $this->worker(['procedures.view', 'hazards.view']);
        $this->assertNull(SafeWorkProcedurePresenter::detail($record, $ordinary)['review_requests'][0]['delivery']);
        $this->assertArrayNotHasKey('recipient_user_id', $executorRow['delivery']);
        $this->assertArrayNotHasKey('initiated_by_user_id', $executorRow['delivery']);
        $latest->forceFill(['status' => 'skipped', 'failure_code' => 'private arbitrary detail', 'next_attempt_at' => null])->saveQuietly();
        $safe = SafeWorkProcedurePresenter::detail($record, $executor)['review_requests'][0]['delivery'];
        $this->assertSame('skipped', $safe['status']);
        $this->assertNull($safe['failure_code']);
        $request->forceFill(['assigned_to_user_id' => $ordinary->id])->saveQuietly();
        $this->assertNull(SafeWorkProcedurePresenter::detail($record, $executor)['review_requests'][0]['delivery']);
        $this->assertNull(SafeWorkProcedurePresenter::detail($record, $this->manager)['review_requests'][0]['delivery']);
    }

    private function delivery(ProcedureReviewRequest $request, int $revision, int $recipient, array $fields): ProcedureReviewDelivery
    {
        return ProcedureReviewDelivery::create([...['id' => (string) Str::uuid(), 'procedure_review_request_id' => $request->id,
            'recipient_user_id' => $recipient, 'initiated_by_user_id' => $this->manager->id, 'assignment_revision' => $revision,
            'status' => 'pending', 'attempts' => 0], ...$fields]);
    }

    private function worker(array $permissions): User
    {
        $worker = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        $ids = Permission::whereIn('key', $permissions)->pluck('id');
        $worker->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $worker;
    }

    private function procedure(array $attributes = []): SafeWorkProcedure
    {
        return SafeWorkProcedure::factory()->approved()->create([...['applicable_roles' => [],
            'applicable_sites' => [$this->site->id], 'created_by' => $this->manager->id,
            'approved_by' => $this->manager->id], ...$attributes])->refresh();
    }

    private function draft(SafeWorkProcedure $record, array $changes = []): SafeWorkProcedureVersion
    {
        $record->forceFill([...$changes, 'status' => 'under_review', 'current_version' => $record->current_version + 1,
            'lock_version' => $record->lock_version + 1])->saveQuietly();
        $snapshot = $record->only(SafeWorkProcedureService::CONTENT_FIELDS);
        $snapshot['review_date'] = $record->review_date?->toDateString();

        return SafeWorkProcedureVersion::create(['safe_work_procedure_id' => $record->id,
            'version_number' => $record->current_version, 'content_snapshot' => $snapshot,
            'document_manifest' => [], 'changed_by' => $this->manager->id, 'provenance' => 'controlled']);
    }

    private function attachment(SafeWorkProcedure $record, string $name): HsAttachment
    {
        return HsAttachment::create(['attachable_type' => $record->getMorphClass(), 'attachable_id' => $record->id,
            'uploaded_by' => $this->manager->id, 'original_name' => $name, 'path' => 'private/secret/'.$name,
            'disk' => 'private', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'version_at_upload' => $record->current_version]);
    }

    private function reviewRequest(SafeWorkProcedure $record, HsEvent $event, array $attributes = []): ProcedureReviewRequest
    {
        return ProcedureReviewRequest::create([...['safe_work_procedure_id' => $record->id, 'hs_event_id' => $event->id,
            'source_site_id' => $event->site_id, 'requested_version_id' => $record->published_version_id,
            'requested_by_user_id' => $this->manager->id, 'assigned_to_user_id' => $this->manager->id,
            'note' => 'Review source instructions '.$event->id, 'idempotency_key' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', (string) $event->id), 'requested_at' => now()], ...$attributes]);
    }
}
