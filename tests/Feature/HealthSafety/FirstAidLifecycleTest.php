<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidAttachment;
use App\Models\FirstAidCommandReceipt;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\FirstAidSourceDelivery;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\FirstAidDeliveryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FirstAidSourceFixtures;
use Tests\TestCase;

class FirstAidLifecycleTest extends TestCase
{
    use FirstAidSourceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeFirstAidFixtures();
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected guarded validation.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected denied source authority.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_create_only_actor_receives_exact_minimal_confirmation_without_record_read_authority(): void
    {
        $actor = $this->sourceStaff(['hazards.create']);
        $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.view')->firstOrFail()->id => ['allowed' => false]]);
        $payload = $this->treatment();
        $row = $this->sourceService()->create($actor, $payload);
        $retry = $this->sourceService()->create($actor, [...$payload, 'return_to' => '/my-day', 'section' => 'history']);
        $expected = $row->getAttributes();
        $actual = $retry->getAttributes();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(1, FirstAidRecord::count());
        $this->assertSame(1, FirstAidCommandReceipt::count());
        $proof = $this->sourceService()->confirmationForViewer($actor, ['id' => (int) $row->id, 'lock_version' => 1, 'idempotency_key' => $payload['idempotency_key']]);
        $this->assertSame(['id', 'lock_version', 'idempotency_key', 'command', 'removed'], array_keys($proof));
        $this->assertSame('create', $proof['command']);
        $this->denied(fn () => app(FirstAidAccessService::class)->recordForViewer($row, $actor));
        $this->invalid(fn () => $this->sourceService()->create($actor, [...$payload, 'treatment_given' => 'Changed treatment']), 'idempotency_key');
        $actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertNull($this->sourceService()->confirmationForViewer($actor, ['id' => (int) $row->id, 'lock_version' => 1, 'idempotency_key' => $payload['idempotency_key']]));
    }

    public function test_create_permission_can_edit_without_implying_view_and_parent_stale_guard_remains_atomic(): void
    {
        $row = $this->source();
        $capture = $this->sourceStaff(['hazards.create']);
        $data = $this->reviewed($row, ['first_aider_notes' => 'Recorded correction']);
        $updated = $this->sourceService()->update($row, $capture, $data);
        $this->assertSame(2, (int) $updated->lock_version);
        $before = $row->fresh()->getAttributes();
        $this->invalid(fn () => $this->sourceService()->update($row, $capture, [...$data, 'idempotency_key' => fake()->uuid(), 'first_aider_notes' => 'Stale correction']), 'expected_version');
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->denied(fn () => app(FirstAidAccessService::class)->recordForViewer($row, $capture));
    }

    public function test_archive_exact_retry_retains_history_without_accepting_a_fresh_archived_write(): void
    {
        $row = $this->source();
        $guard = $this->reviewed($row);
        $archived = $this->sourceService()->archive($row, $this->manager, $guard);
        $retry = $this->sourceService()->archive($row, $this->manager, $guard);
        $expected = $archived->getAttributes();
        $actual = $retry->getAttributes();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertTrue($archived->trashed());
        $proof = $this->sourceService()->confirmationForViewer($this->manager, ['id' => (int) $row->id, 'lock_version' => 2, 'idempotency_key' => $guard['idempotency_key']]);
        $this->assertTrue($proof['removed']);
        $this->invalid(fn () => $this->sourceService()->update($row, $this->manager, ['expected_version' => 2, 'idempotency_key' => fake()->uuid(), 'treatment_given' => 'Fresh archived write']), 'record');
        $this->assertSame(2, FirstAidCommandReceipt::count());
    }

    public static function archiveFollowthroughOutcomes(): array
    {
        return [['recorded'], ['not_required']];
    }

    #[DataProvider('archiveFollowthroughOutcomes')]
    public function test_archive_requires_factual_followthrough_before_removing_source_work_and_never_closes_other_domains(string $result): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $row = $this->source(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id, 'ambulance_called' => true]);
        $intent = FirstAidSourceDelivery::where('purpose', 'source_review')->sole();
        app(FirstAidDeliveryService::class)->deliver($intent->id);
        $event = HsEvent::sole();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['site_id' => $this->sourceSite->id, 'client_id' => $client->id, 'reported_by' => $this->manager->id, 'status' => 'draft', 'is_hr_confidential' => false]));
        $this->sourceService()->linkIncident($row, $this->manager, $this->reviewed($row, ['related_incident_id' => $incident->id]));
        $this->sourceService()->addFollowup($row, $this->manager, $this->reviewed($row, ['notes' => 'Record the actual follow-through', 'assigned_to_user_id' => $this->manager->id]));
        $child = $row->followups()->sole();
        $guard = $this->reviewed($row);
        $before = $row->fresh()->getAttributes();
        $receipts = FirstAidCommandReceipt::count();
        $audits = AuditLog::count();
        $eventBefore = $event->fresh()->getAttributes();
        $incidentBefore = $incident->fresh()->getAttributes();
        $this->invalid(fn () => $this->sourceService()->archive($row, $this->manager, $guard), 'followups');
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame($receipts, FirstAidCommandReceipt::count());
        $this->assertSame($audits, AuditLog::count());
        $this->assertFalse($row->fresh()->trashed());
        $this->assertNull($child->fresh()->completed_at);
        $this->assertSame($eventBefore, $event->fresh()->getAttributes());
        $this->assertSame($incidentBefore, $incident->fresh()->getAttributes());

        $this->sourceService()->completeFollowup($row, $child, $this->manager, $this->reviewed($row, ['completion_notes' => 'Actual reviewed follow-through: '.$result, 'completion_outcome' => ['kind' => 'action', 'result' => $result, 'occurred_at' => now()->subMinute()->toISOString()]]));
        $completed = $child->fresh()->getAttributes();
        $guard = $this->reviewed($row);
        $archived = $this->sourceService()->archive($row, $this->manager, $guard);
        $confirmedReceipts = FirstAidCommandReceipt::count();
        $confirmedAudits = AuditLog::count();
        $this->travel(1)->days();
        $retry = $this->sourceService()->archive($row, $this->manager, $guard);
        $this->assertTrue($retry->trashed());
        $this->assertSame((int) $archived->lock_version, (int) $retry->lock_version);
        $this->assertSame($confirmedReceipts, FirstAidCommandReceipt::count());
        $this->assertSame($confirmedAudits, AuditLog::count());
        $this->assertSame($completed, $child->fresh()->getAttributes());
        $this->assertSame($eventBefore, $event->fresh()->getAttributes());
        $this->assertSame($incidentBefore, $incident->fresh()->getAttributes());
        $this->assertTrue($this->sourceService()->confirmationForViewer($this->manager, ['id' => (int) $row->id, 'lock_version' => (int) $retry->lock_version, 'idempotency_key' => $guard['idempotency_key']])['removed']);
    }

    public function test_unchanged_historical_aider_survives_loss_of_current_eligibility_but_new_choice_is_denied(): void
    {
        $row = $this->source();
        $this->firstAider->hrEmployeeProfile()->update(['is_active' => false, 'is_first_aider' => false]);
        $this->sourceService()->update($row, $this->manager, $this->reviewed($row, ['first_aider_notes' => 'Historical attribution retained']));
        $unqualified = $this->sourceStaff();
        $this->denied(fn () => $this->sourceService()->update($row, $this->manager, $this->reviewed($row, ['first_aider_id' => $unqualified->id])));
        $this->assertSame($this->firstAider->id, $row->fresh()->first_aider_id);
    }

    public static function invalidTreatmentTimes(): array
    {
        return [['2026-10-08T13:00'], ['2026-02-31T00:00:00Z'], ['2026-10-09T00:00:00+00:00']];
    }

    #[DataProvider('invalidTreatmentTimes')]
    public function test_actual_treatment_rejects_naive_invalid_and_future_instants(string $time): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T00:00:00Z'));
        $this->invalid(fn () => $this->sourceService()->create($this->manager, $this->treatment(['treatment_date' => $time])), 'treatment_date');
        $this->assertSame(0, FirstAidRecord::count());
        $this->assertSame(0, FirstAidCommandReceipt::count());
    }

    public function test_named_completion_records_actual_actor_outcome_and_selected_evidence_once(): void
    {
        $row = $this->source();
        $this->sourceService()->addFollowup($row, $this->manager, $this->reviewed($row, ['notes' => 'Notify family if authorized', 'assigned_to_user_id' => $this->worker->id, 'due_at' => now()->subMinute()->toISOString()]));
        $child = FirstAidFollowup::sole();
        Storage::disk('private')->put('synthetic/first-aid-proof.txt', 'Synthetic source evidence');
        $file = FirstAidAttachment::create(['first_aid_record_id' => $row->id, 'disk' => 'private', 'path' => 'synthetic/first-aid-proof.txt', 'original_name' => 'source-evidence.txt', 'uploaded_by' => $this->manager->id]);
        $payload = $this->reviewed($row, ['completion_notes' => 'Recorded attempted contact; no response confirmed', 'completion_outcome' => ['kind' => 'contact', 'result' => 'attempted', 'occurred_at' => now()->subMinute()->toISOString()], 'completion_evidence_ids' => [$file->id]]);
        $this->sourceService()->completeFollowup($row, $child, $this->worker, $payload);
        $first = $child->fresh()->getAttributes();
        $this->travel(1)->days();
        $this->sourceService()->completeFollowup($row, $child, $this->worker, $payload);
        $this->assertSame($first, $child->fresh()->getAttributes());
        $this->assertSame($this->worker->id, $child->fresh()->completed_by_user_id);
        $this->assertSame('attempted', $child->fresh()->completion_outcome['result']);
        $this->assertSame([$file->id], $child->fresh()->completion_evidence_ids);
        $this->invalid(fn () => $this->sourceService()->removeEvidence($row, $file, $this->manager, $this->reviewed($row)), 'attachment');
        $this->assertTrue(Storage::disk('private')->exists($file->path));
    }

    public function test_followup_title_does_not_infer_a_contact_or_claim_outcome_and_foreign_executor_is_denied(): void
    {
        $row = $this->source();
        $this->sourceService()->addFollowup($row, $this->manager, $this->reviewed($row, ['notes' => 'Lodge ACC45', 'assigned_to_user_id' => $this->worker->id]));
        $child = FirstAidFollowup::sole();
        $other = $this->sourceStaff(['hazards.view']);
        $data = $this->reviewed($row, ['completion_notes' => 'Factual action recorded without claim acceptance']);
        $this->denied(fn () => $this->sourceService()->completeFollowup($row, $child, $other, $data));
        $this->sourceService()->completeFollowup($row, $child, $this->worker, $data);
        $this->assertNull($child->fresh()->completion_outcome);
        $this->assertSame(0, FirstAidSourceDelivery::where('purpose', 'source_review')->count());
    }

    public function test_assignment_revisions_change_only_for_actual_owner_change_and_do_not_duplicate_on_retry(): void
    {
        $row = $this->source();
        $this->sourceService()->addFollowup($row, $this->manager, $this->reviewed($row, ['notes' => 'Review recorded treatment', 'assigned_to_user_id' => $this->worker->id]));
        $child = FirstAidFollowup::sole();
        $sameOwner = $this->reviewed($row, ['notes' => 'Updated requested work', 'assigned_to_user_id' => $this->worker->id]);
        $this->sourceService()->updateFollowup($row, $child, $this->manager, $sameOwner);
        $this->assertSame(1, (int) $child->fresh()->assignment_revision);
        $other = $this->sourceStaff(['hazards.view']);
        $transfer = $this->reviewed($row, ['notes' => 'Transferred work', 'assigned_to_user_id' => $other->id]);
        $this->sourceService()->updateFollowup($row, $child, $this->manager, $transfer);
        $this->sourceService()->updateFollowup($row, $child, $this->manager, $transfer);
        $this->assertSame(2, (int) $child->fresh()->assignment_revision);
        $this->assertSame(2, FirstAidSourceDelivery::where('purpose', 'followup_assignment')->count());
    }

    public function test_client_subject_assignment_requires_the_actual_recipient_client_policy(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $row = $this->source(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id]);
        $this->denied(fn () => $this->sourceService()->addFollowup($row, $this->manager, $this->reviewed($row, ['notes' => 'Client treatment follow-through', 'assigned_to_user_id' => $this->worker->id])));
        $this->assertSame(0, FirstAidFollowup::count());
        $this->assertSame(1, (int) $row->fresh()->lock_version);
    }

    public function test_evidence_exact_bytes_retry_is_one_private_file_and_changed_bytes_are_rejected(): void
    {
        $row = $this->source();
        $data = $this->reviewed($row, ['kind' => 'photo', 'alt_text' => 'Synthetic source image']);
        $file = UploadedFile::fake()->image('source.png');
        $this->sourceService()->addEvidence($row, $this->manager, $data, $file);
        $this->sourceService()->addEvidence($row, $this->manager, [...$data, 'section' => 'history'], $file);
        $this->assertSame(1, FirstAidAttachment::count());
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->invalid(fn () => $this->sourceService()->addEvidence($row, $this->manager, $data, UploadedFile::fake()->image('source.png', 20, 20)), 'idempotency_key');
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $attachment = FirstAidAttachment::sole();
        $this->sourceService()->removeEvidence($row, $attachment, $this->manager, $this->reviewed($row));
        $this->assertSame(0, FirstAidAttachment::count());
        $this->assertTrue(Storage::disk('private')->exists($attachment->path));
    }

    public function test_strict_audit_failure_rolls_back_evidence_metadata_parent_receipt_and_blob(): void
    {
        $row = $this->source();
        $before = $row->fresh()->getAttributes();
        $dispatcher = Model::getEventDispatcher();
        $replacement = clone $dispatcher;
        Model::setEventDispatcher($replacement);
        $replacement->listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('Synthetic strict audit unavailable'));
        try {
            $this->sourceService()->addEvidence($row, $this->manager, $this->reviewed($row), UploadedFile::fake()->image('source.png'));
            $this->fail('Strict audit failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic strict audit unavailable', $error->getMessage());
        } finally {
            Model::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(0, FirstAidAttachment::count());
        $this->assertSame(1, FirstAidCommandReceipt::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_subject_correction_cannot_transfer_retained_child_medical_history(): void
    {
        $row = $this->source();
        $this->sourceService()->addFollowup($row, $this->manager, $this->reviewed($row, ['notes' => 'Original subject follow-through', 'assigned_to_user_id' => $this->worker->id]));
        $other = $this->sourceStaff();
        $before = $row->fresh()->getAttributes();
        $this->invalid(fn () => $this->sourceService()->update($row, $this->manager, $this->reviewed($row, ['treated_person_id' => $other->id])), 'treated_person_id');
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(1, FirstAidFollowup::count());
        $plain = $this->source();
        $this->sourceService()->update($plain, $this->manager, $this->reviewed($plain, ['treated_person_id' => $other->id, 'treated_person_name' => 'Corrected recorded subject']));
        $this->assertSame($other->id, $plain->fresh()->treated_person_id);
    }

    public function test_command_keys_cannot_replay_for_another_actor_or_treatment(): void
    {
        $row = $this->source();
        $other = $this->source();
        $payload = $this->reviewed($row, ['body_part' => 'Left hand']);
        $confirmed = $this->sourceService()->update($row, $this->manager, $payload);
        $peer = $this->sourceStaff(['hazards.manage']);
        $this->invalid(fn () => $this->sourceService()->update($row, $peer, $payload), 'idempotency_key');
        $this->invalid(fn () => $this->sourceService()->update($other, $this->manager, $payload), 'idempotency_key');
        $this->assertSame(2, (int) $row->fresh()->lock_version);
        $this->assertSame(1, (int) $other->fresh()->lock_version);
        $proof = ['id' => (int) $confirmed->id, 'lock_version' => (int) $confirmed->lock_version, 'idempotency_key' => $payload['idempotency_key']];
        $this->assertNull($this->sourceService()->confirmationForViewer($peer, $proof));
        $this->assertSame(3, FirstAidCommandReceipt::count());
    }

    public function test_future_recorded_outcome_does_not_complete_or_advance_source(): void
    {
        $row = $this->source();
        $this->sourceService()->addFollowup($row, $this->manager, $this->reviewed($row, ['notes' => 'Factual follow-through', 'assigned_to_user_id' => $this->worker->id]));
        $child = FirstAidFollowup::sole();
        $before = $row->fresh()->getAttributes();
        $this->invalid(fn () => $this->sourceService()->completeFollowup($row, $child, $this->worker, $this->reviewed($row, ['completion_notes' => 'A future assertion', 'completion_outcome' => ['kind' => 'contact', 'result' => 'contacted', 'occurred_at' => now()->addHour()->toISOString()]])), 'completion_outcome.occurred_at');
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertNull($child->fresh()->completed_at);
        $this->assertNull($child->fresh()->completed_by_user_id);
        $this->assertSame(2, FirstAidCommandReceipt::count());
    }
}
