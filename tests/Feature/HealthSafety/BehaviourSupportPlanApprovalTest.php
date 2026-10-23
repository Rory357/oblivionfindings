<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\BehaviourSupportPlanReview;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Permission;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\User;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

final class BehaviourSupportPlanApprovalTest extends BehaviourSupportPlanTestCase
{
    public function test_default_requires_explicit_designation_but_never_invents_author_inequality(): void
    {
        $plan = $this->makePlan();
        foreach ([$this->planManager, $this->planReviewer] as $actor) {
            $this->denied(fn () => $this->approvals()->decide($plan, $actor, $this->approvalPayload($plan)));
        }
        $grant = Permission::where('key', 'restraints.approve')->sole();
        $this->planManager->permissionOverrides()->syncWithoutDetaching([$grant->id => ['allowed' => true]]);
        $receipt = $this->approvals()->decide($plan, $this->planManager, $this->approvalPayload($plan));
        $decision = BehaviourSupportPlanApproval::findOrFail($receipt['approval_id']);
        $this->assertSame($plan->created_by, $decision->approver_id);
        $this->assertSame('separate_specialist', $decision->policy_snapshot['mode']);
        $this->assertFalse($decision->approver_snapshot['qualification_verified']);
        $this->assertSame($decision->recorded_by, $decision->approver_id);
    }

    public function test_review_requested_active_is_retained_pending_and_separate_approval_completes_exact_request(): void
    {
        $plan = $this->makePlan();
        $review = $this->plans()->review($plan, $this->planReviewer, $this->command($plan, [...$this->targetPayload($plan), 'outcome' => 'continued', 'reviewed_at' => '2026-10-10T01:00:00Z', 'resulting_status' => 'active']));
        $this->assertSame('approval_pending', $review['disposition']);
        $row = BehaviourSupportPlanReview::findOrFail($review['review_id']);
        $this->assertSame('active', $row->requested_resulting_status);
        $this->assertSame('draft', $row->resulting_status);
        $this->assertNull($plan->fresh()->current_published_version_id);
        $approved = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['activate_requested' => true]));
        $this->assertSame('activated', $approved['disposition']);
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertSame('draft', $row->fresh()->resulting_status);
        $this->assertSame($this->planReviewer->id, $row->fresh()->reviewed_by);
        $this->assertSame($this->planSpecialist->id, $plan->fresh()->publishedVersion->published_by);
    }

    public function test_reviewer_mode_requires_explicit_decision_and_can_activate_without_manage_or_specialist_grant(): void
    {
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $plan = $this->makePlan();
        $routine = $this->plans()->review($plan, $this->planReviewer, $this->command($plan, [...$this->targetPayload($plan), 'outcome' => 'continued', 'reviewed_at' => '2026-10-10T01:00:00Z', 'resulting_status' => 'active']));
        $this->assertSame('approval_pending', $routine['disposition']);
        $payload = $this->approvalPayload($plan, ['origin' => 'authorised_reviewer']);
        $payload['outcome'] = 'continued';
        $payload['reviewed_at'] = '2026-10-10T01:00:00Z';
        $payload['resulting_status'] = 'active';
        $receipt = $this->plans()->review($plan, $this->planReviewer, $payload);
        $this->assertSame('activated', $receipt['disposition']);
        $this->assertSame('active', $plan->fresh()->status);
        $approval = BehaviourSupportPlanApproval::findOrFail($receipt['approval_id']);
        $this->assertSame('authorised_reviewer', $approval->origin);
        $this->assertSame($this->planReviewer->id, $approval->approver_id);
        $this->assertFalse($this->planReviewer->canDo('restraints.approve'));
        $this->assertFalse($this->planReviewer->canDo('restraints.manage'));
    }

    public function test_specialist_approval_alone_does_not_claim_publication_or_unrequested_activation(): void
    {
        $plan = $this->makePlan();
        $receipt = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan));
        $this->assertSame('approved_not_published', $receipt['disposition']);
        $this->assertNull($receipt['published_version_id']);
        $this->assertSame('draft', $plan->fresh()->status);
        $this->assertSame('approval_unknown', $this->resolve($plan)['authorization_state']);
        $this->validation(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['activate_requested' => true])), 'activate_requested');
        $this->assertSame(1, BehaviourSupportPlanApproval::count());
    }

    public function test_setting_switch_keeps_existing_approved_history_but_invalidates_new_publication_draft(): void
    {
        $plan = $this->makePlan();
        $payload = $this->approvalPayload($plan);
        $accepted = $this->approvals()->decide($plan, $this->planSpecialist, $payload);
        $this->plans()->activate($plan, $this->planManager, $this->command($plan, $this->targetPayload($plan)));
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $replay = $this->approvals()->decide($plan, $this->planSpecialist, $payload);
        $this->assertSame($accepted['receipt_id'], $replay['receipt_id']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(0, $replay['applied_policy']['policy_revision']);
        $this->assertSame('verified', $this->resolve($plan)['authorization_state']);
        $this->assertSame('separate_specialist', $plan->fresh()->publishedVersion->policy_snapshot['mode']);
        $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'New candidate']));
        $stale = $this->approvalPayload($plan, ['origin' => 'authorised_reviewer', 'expected_policy_revision' => 0]);
        $this->validation(fn () => $this->approvals()->decide($plan, $this->planReviewer, $stale), 'expected_policy_revision');
    }

    public function test_documented_external_specialist_keeps_actual_identity_separate_from_recorder(): void
    {
        Storage::fake('private');
        $this->documentGrant($this->planSpecialist);
        Storage::disk('private')->put('clients/approval.pdf', 'Actual retained document bytes');
        $document = ClientDocument::create(['client_id' => $this->client->id, 'title' => 'External approval decision', 'storage_disk' => 'private', 'storage_path' => 'clients/approval.pdf']);
        $plan = $this->makePlan();
        $payload = $this->approvalPayload($plan, ['origin' => 'external_specialist', 'external_name' => 'Named external decision maker', 'external_role' => 'Recorded professional role', 'external_organisation' => 'Recorded provider', 'external_reference' => 'Decision ABC-42', 'external_decision_date' => '2026-10-10', 'evidence_references' => [['type' => 'client_document', 'id' => $document->id]]]);
        $receipt = $this->approvals()->decide($plan, $this->planSpecialist, $payload);
        $approval = BehaviourSupportPlanApproval::findOrFail($receipt['approval_id']);
        $this->assertNull($approval->approver_id);
        $this->assertSame($this->planSpecialist->id, $approval->recorded_by);
        $this->assertSame('Named external decision maker', $approval->approver_snapshot['name']);
        $this->assertFalse($approval->approver_snapshot['professional_registration_verified']);
        $this->assertSame(hash('sha256', 'Actual retained document bytes'), $approval->evidence_references[0]['sha256']);
        $this->assertFalse($approval->evidence_references[0]['preservation_claim']);
    }

    public function test_external_private_evidence_must_be_available_exact_client_and_is_not_public_disk_fallback(): void
    {
        Storage::fake('private');
        $plan = $this->makePlan();
        $this->documentGrant($this->planSpecialist);
        $document = ClientDocument::create(['client_id' => $this->client->id, 'title' => 'Unavailable document', 'storage_disk' => 'public', 'storage_path' => 'legacy.pdf']);
        $payload = $this->approvalPayload($plan, ['origin' => 'external_specialist', 'external_name' => 'Actual specialist', 'external_role' => 'Recorded role', 'external_organisation' => 'Provider', 'external_reference' => 'D-1', 'external_decision_date' => '2026-10-10', 'evidence_references' => [['type' => 'client_document', 'id' => $document->id]]]);
        $this->validation(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $payload), 'evidence_references');
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertSame(1, $plan->fresh()->lock_version);
    }

    public function test_known_credential_expiry_denies_new_decision_but_does_not_rewrite_old_identity(): void
    {
        $plan = $this->makePlan();
        $credential = StaffCredential::create(['user_id' => $this->planSpecialist->id, 'type' => 'Actual recorded credential', 'issuer' => 'Actual issuer', 'reference' => 'C-42', 'issued_at' => '2026-01-01', 'expires_at' => '2026-10-11']);
        $payload = $this->approvalPayload($plan, ['credential_id' => $credential->id]);
        $receipt = $this->approvals()->decide($plan, $this->planSpecialist, $payload);
        $credential->update(['expires_at' => '2026-10-09']);
        $this->assertTrue($this->approvals()->decide($plan, $this->planSpecialist, $payload)['replayed']);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['credential_id' => $credential->id])));
        $old = BehaviourSupportPlanApproval::findOrFail($receipt['approval_id']);
        $this->assertSame('2026-10-11', $old->approver_snapshot['recorded_credential']['expires_at']);
        $this->assertSame($this->planSpecialist->id, $old->approver_id);
    }

    public function test_withdrawal_is_new_immutable_evidence_and_old_publication_never_revives(): void
    {
        $plan = $this->makePlan();
        $approval = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan));
        $this->plans()->activate($plan, $this->planManager, $this->command($plan, $this->targetPayload($plan)));
        $published = $plan->fresh()->current_published_version_id;
        $withdraw = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['approval_decision' => 'revoke', 'supersedes_approval_id' => $approval['approval_id']]));
        $this->assertSame('withdrawn', $this->resolve($plan, version: $published)['authorization_state']);
        $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan));
        $this->assertSame('withdrawn', $this->resolve($plan, version: $published)['authorization_state']);
        $this->assertSame('approve', BehaviourSupportPlanApproval::findOrFail($approval['approval_id'])->decision);
        $this->assertSame('revoke', BehaviourSupportPlanApproval::findOrFail($withdraw['approval_id'])->decision);
    }

    public function test_changed_candidate_digest_or_context_cannot_receive_old_approval(): void
    {
        $plan = $this->makePlan();
        $old = $this->approvalPayload($plan);
        $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Changed candidate']));
        $old['expected_version'] = $plan->fresh()->lock_version;
        $this->validation(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $old), 'candidate_version_id');
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
    }

    public function test_invalid_policy_or_strict_audit_failure_has_no_partial_approval_publication_or_receipt(): void
    {
        $plan = $this->makePlan();
        AppSetting::create(['key' => Policy::KEY, 'value' => ['schema_version' => 1, 'mode' => 'malformed']]);
        $payload = $this->approvalPayload($plan);
        $this->validation(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $payload), 'expected_policy_revision');
        AppSetting::where('key', Policy::KEY)->delete();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function ($audit): void {
            if ($audit->action === 'behaviour_support_plan.approval') {
                throw new RuntimeException('Injected plan audit failure');
            }
        });
        try {
            $this->approvals()->decide($plan, $this->planSpecialist, $payload);
            $this->fail('Strict audit must roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected plan audit failure', $error->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertSame(1, BehaviourSupportPlanVersion::count());
        $this->assertSame(1, $plan->fresh()->lock_version);
        $this->assertDatabaseMissing('restraint_command_receipts', ['idempotency_key' => $payload['idempotency_key']]);
    }

    public function test_suppressed_approval_insert_cannot_claim_an_accepted_decision(): void
    {
        $plan = $this->makePlan();
        $dispatcher = BehaviourSupportPlanApproval::getEventDispatcher();
        BehaviourSupportPlanApproval::setEventDispatcher(clone $dispatcher);
        BehaviourSupportPlanApproval::creating(fn () => false);
        try {
            $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan));
            $this->fail('Suppressed insert cannot be accepted.');
        } catch (LogicException) {
            $this->assertSame(1, $plan->fresh()->lock_version);
        } finally {
            BehaviourSupportPlanApproval::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertSame(0, AuditLog::where('action', 'behaviour_support_plan.approval')->count());
    }

    public function test_actual_approval_microseconds_and_attribution_remain_immutable_after_actor_changes(): void
    {
        $plan = $this->makePlan();
        $receipt = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan));
        $row = BehaviourSupportPlanApproval::findOrFail($receipt['approval_id']);
        $recordedName = $row->approver_snapshot['name'];
        $this->assertSame('2026-10-10T00:00:00.123456+00:00', $row->approved_at->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-10-10T00:00:00.123456+00:00', $row->effective_from->format('Y-m-d\TH:i:s.uP'));
        $this->planSpecialist->update(['name' => 'Current changed staff name']);
        $this->assertSame($recordedName, $row->fresh()->approver_snapshot['name']);
        try {
            $row->update(['basis' => 'Overwrite old decision']);
            $this->fail('Recorded approval must remain immutable.');
        } catch (LogicException) {
            $this->assertSame('Actual plan-specific decision and authority basis.', $row->fresh()->basis);
        }
        try {
            $row->delete();
            $this->fail('Recorded approval must not be erased.');
        } catch (LogicException) {
            $this->assertDatabaseHas('behaviour_support_plan_approvals', ['id' => $row->id]);
        }
    }

    public function test_strict_semantic_audit_observes_executed_children_and_incremented_parent_before_acceptance(): void
    {
        $plan = $this->makePlan();
        $payload = $this->approvalPayload($plan);
        $observed = [];
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function ($audit) use ($plan, &$observed): void {
            if ($audit->action === 'behaviour_support_plan.approval') {
                $observed = ['version' => $plan->fresh()->lock_version, 'decisions' => BehaviourSupportPlanApproval::where('behaviour_support_plan_id', $plan->id)->count()];
            }
        });
        try {
            $receipt = $this->approvals()->decide($plan, $this->planSpecialist, $payload);
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame(['version' => 2, 'decisions' => 1], $observed);
        $decision = BehaviourSupportPlanApproval::findOrFail($receipt['approval_id']);
        $audit = AuditLog::findOrFail($decision->audit_id);
        $this->assertSame('behaviour_support_plan.approval', $audit->action);
        $this->assertSame($payload['idempotency_key'], $audit->meta['idempotency_key']);
        $this->assertSame($this->planSpecialist->id, $audit->user_id);
        $this->assertDatabaseHas('restraint_command_receipts', ['id' => $receipt['receipt_id'], 'audit_log_id' => $audit->id]);
    }

    public function test_document_evidence_requires_fresh_document_section_authority_and_exact_client(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('clients/read-boundary.pdf', 'Actual private bytes');
        $document = ClientDocument::create(['client_id' => $this->client->id, 'title' => 'Read boundary evidence', 'storage_disk' => 'private', 'storage_path' => 'clients/read-boundary.pdf']);
        $plan = $this->makePlan();
        $payload = $this->approvalPayload($plan, ['evidence_references' => [['type' => 'client_document', 'id' => $document->id]]]);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $payload));
        $this->assertSame(1, $plan->fresh()->lock_version);
        $this->documentGrant($this->planSpecialist);
        $accepted = $this->approvals()->decide($plan, $this->planSpecialist, $payload);
        $this->assertSame(2, $accepted['version']);
        $this->planSpecialist->load('permissionOverrides');
        $this->documentGrant($this->planSpecialist, false);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['evidence_references' => [['type' => 'client_document', 'id' => $document->id]]])));
        $foreign = Client::factory()->for($this->site)->create();
        $this->documentGrant($this->planSpecialist);
        $document->update(['client_id' => $foreign->id]);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['evidence_references' => [['type' => 'client_document', 'id' => $document->id]]])));
        $this->assertSame(1, BehaviourSupportPlanApproval::count());
        $this->assertSame(2, $plan->fresh()->lock_version);
    }

    public function test_document_read_never_bypasses_current_internal_hr_eligibility(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('clients/hr-boundary.pdf', 'Actual private bytes');
        $document = ClientDocument::create(['client_id' => $this->client->id, 'title' => 'Read evidence', 'storage_disk' => 'private', 'storage_path' => 'clients/hr-boundary.pdf']);
        $plan = $this->makePlan();
        $this->documentGrant($this->planSpecialist);
        $this->planSpecialist->load('hrEmployeeProfile');
        $this->planSpecialist->hrEmployeeProfile()->update(['is_active' => false]);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['evidence_references' => [['type' => 'client_document', 'id' => $document->id]]])));
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertSame(1, $plan->fresh()->lock_version);
    }

    public function test_attributed_internal_approver_needs_own_original_and_current_client_site(): void
    {
        $plan = $this->makePlan();
        $newSite = Site::factory()->create();
        $other = $this->staff(['restraints.approve', 'clients.viewAny', 'clinical.accessAllSites']);
        $this->client->update(['site_id' => $newSite->id]);
        $this->planSpecialist->hrEmployeeProfile()->update(['secondary_site_ids' => [$newSite->id]]);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['approver_id' => $other->id])));
        $other->hrEmployeeProfile()->update(['secondary_site_ids' => [$newSite->id]]);
        $accepted = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['approver_id' => $other->id]));
        $this->assertSame($other->id, BehaviourSupportPlanApproval::findOrFail($accepted['approval_id'])->approver_id);
        $this->assertSame(1, BehaviourSupportPlanApproval::count());
    }

    public function test_another_approvers_credential_requires_fresh_actual_credential_read_authority(): void
    {
        $plan = $this->makePlan();
        $other = $this->staff(['restraints.approve', 'clients.viewAny']);
        $credential = StaffCredential::create(['user_id' => $other->id, 'type' => 'Recorded credential', 'expires_at' => '2026-12-01']);
        $input = $this->approvalPayload($plan, ['approver_id' => $other->id, 'credential_id' => $credential->id]);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $input));
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $permission = Permission::firstOrCreate(['key' => 'staff.credentials.viewAny'], ['description' => 'Read staff credentials', 'group' => 'HR source', 'module' => 'staff']);
        $this->planSpecialist->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $accepted = $this->approvals()->decide($plan, $this->planSpecialist, $input);
        $this->assertFalse(BehaviourSupportPlanApproval::findOrFail($accepted['approval_id'])->approver_snapshot['recorded_credential']['professional_eligibility_verified']);
        $this->planSpecialist->load('permissionOverrides');
        $this->planSpecialist->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['approver_id' => $other->id, 'credential_id' => $credential->id])));
        $this->assertSame(1, BehaviourSupportPlanApproval::count());
        $this->assertSame(2, $plan->fresh()->lock_version);
    }

    private function documentGrant(User $actor, bool $allowed = true): void
    {
        $permission = Permission::firstOrCreate(['key' => 'clients.update'], ['description' => 'Client document source editor', 'group' => 'Source fixtures', 'module' => 'clients']);
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }
}
