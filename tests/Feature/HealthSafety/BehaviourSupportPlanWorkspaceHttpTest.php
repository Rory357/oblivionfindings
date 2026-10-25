<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AppSetting;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\BehaviourSupportPlanReview;
use App\Models\ClientDocument;
use App\Models\HealthSafetySettingChange;
use App\Models\Permission;
use App\Models\RestraintCommandReceipt;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\User;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;
use App\Services\HealthSafety\BehaviourSupportPlanWorkspaceService;
use App\Support\HealthSafety\RestraintReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

final class BehaviourSupportPlanWorkspaceHttpTest extends BehaviourSupportPlanTestCase
{
    public function test_html_and_json_permanent_reads_preserve_content_dates_and_bounded_navigation(): void
    {
        $plan = $this->makePlan();
        $this->grant($this->planManager, 'restraints.view');
        $origin = RestraintReturn::REGISTER.'?lens=plans&tab=draft&q=quiet&plans_page=2';
        $href = RestraintReturn::plan($plan->id, $origin, 'content');
        $this->actingAs($this->planManager)->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/restraints/plans/show')->where('detail.id', $plan->id)->where('section', 'content')->where('return_to', $origin));
        $json = $this->getJson($href)->assertOk()->assertJsonPath('workspace', 'view')->assertJsonPath('detail.content.notes', 'Private plan narrative')->assertJsonPath('detail.content.developed_at', '2026-10-01')->assertJsonPath('detail.candidate.content.review_date', '2026-11-30')->assertJsonPath('detail.commands.can.edit', true)->json();
        $this->assertSame($plan->candidateVersion->content_digest, $json['detail']['candidate']['content_digest']);
        $this->assertNull($json['detail']['published_version']);
        $this->assertTrue($json['detail']['legacy_approval_unknown']);
        $this->getJson($this->url($plan).'?return_to=https%3A%2F%2Fevil.test&section=unknown&action=unknown')->assertOk()->assertJsonPath('return_to', null)->assertJsonPath('section', 'overview')->assertJsonPath('action', null);
    }

    public function test_review_only_workspace_does_not_widen_ordinary_source_read(): void
    {
        $plan = $this->makePlan();
        $this->actingAs($this->planReviewer)->getJson($this->url($plan))->assertForbidden();
        $this->getJson($this->url($plan).'/review?include_history=0')->assertOk()->assertJsonPath('workspace', 'review')->assertJsonPath('detail.can.review', true)->assertJsonPath('detail.can.view', false)->assertJsonPath('detail.can.approve', false)->assertJsonPath('detail.versions', [])->assertJsonPath('detail.history', []);
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', Policy::KEY)->count());
        $this->assertSame(0, AppSetting::whereIn('key', [Policy::KEY, Policy::REVISION_KEY])->count());
    }

    public function test_explicit_specialist_workspace_has_exact_candidate_without_ordinary_or_review_grants(): void
    {
        $plan = $this->makePlan();
        $this->actingAs($this->planSpecialist)->getJson($this->url($plan))->assertForbidden();
        $this->getJson($this->url($plan).'/review')->assertForbidden();
        $this->getJson($this->url($plan).'/approval?include_history=0')->assertOk()->assertJsonPath('workspace', 'approval')->assertJsonPath('detail.can.approve', true)->assertJsonPath('detail.can.view', false)->assertJsonPath('detail.current_policy.mode', 'separate_specialist')->assertJsonPath('detail.current_policy.policy_revision', 0)->assertJsonPath('detail.candidate.id', $plan->current_candidate_version_id);
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', Policy::KEY)->count());
    }

    public function test_alternative_policy_authorises_reviewer_explicit_decision_without_specialist_grant(): void
    {
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $plan = $this->makePlan();
        $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval')->assertForbidden();
        $this->actingAs($this->planReviewer)->getJson($this->url($plan).'/approval?include_history=0')->assertOk()->assertJsonPath('detail.can.approve', true)->assertJsonPath('detail.current_policy.policy_revision', 1);
        $input = $this->approvalPayload($plan, ['origin' => 'authorised_reviewer', 'approver_role' => 'Actual organisation-authorised reviewer']);
        $this->postJson($this->url($plan).'/approvals', $input)->assertOk()->assertJsonPath('behaviour_support_plan_confirmation.accepted', true)->assertJsonPath('behaviour_support_plan_confirmation.command', 'approval')->assertJsonPath('behaviour_support_plan_confirmation.applied_policy.mode', 'authorised_reviewer')->assertJsonPath('behaviour_support_plan_confirmation.disposition', 'approved_not_published');
        $this->assertFalse($this->planReviewer->canDo('restraints.approve'));
        $this->assertNull($plan->fresh()->current_published_version_id);
    }

    public function test_malformed_policy_remains_visible_as_unusable_without_a_less_restrictive_fallback(): void
    {
        $plan = $this->makePlan();
        AppSetting::create(['key' => Policy::KEY, 'value' => ['schema_version' => 1, 'mode' => 'unknown']]);
        $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval')->assertOk()->assertJsonPath('detail.current_policy.configuration_valid', false)->assertJsonPath('detail.current_policy.mode', null)->assertJsonPath('detail.can.approve', false);
        $input = $this->approvalPayload($plan);
        $input['expected_policy_revision'] = 0;
        $this->postJson($this->url($plan).'/approvals', $input)->assertUnprocessable()->assertJsonValidationErrors('expected_policy_revision');
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertSame(1, $plan->fresh()->lock_version);
    }

    public function test_capture_json_is_minimal_and_html_falls_back_to_report_without_read_grant(): void
    {
        $input = ['client_id' => $this->client->id, 'title' => 'Private new capture', 'notes' => 'Private capture narrative', 'idempotency_key' => (string) Str::uuid(), '_token' => 'http framing', 'section' => 'content', 'return_to' => RestraintReturn::REGISTER.'?lens=plans'];
        $this->actingAs($this->planCapturer);
        $json = $this->postJson(RestraintReturn::REGISTER.'/plans', $input)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('plan_href', null)->assertJsonPath('behaviour_support_plan_confirmation.command', 'create')->assertJsonPath('behaviour_support_plan_confirmation.version', 1)->json();
        $this->assertStringNotContainsString('Private capture narrative', json_encode($json));
        $plan = BehaviourSupportPlan::findOrFail($json['receipt']['plan_id']);
        $this->getJson($this->url($plan))->assertForbidden();
        $this->getJson($this->url($plan).'/review')->assertForbidden();
        $this->getJson($this->url($plan).'/approval')->assertForbidden();
        $input['idempotency_key'] = (string) Str::uuid();
        $response = $this->post(RestraintReturn::REGISTER.'/plans', $input)->assertRedirect()->assertSessionHas('behaviour_support_plan_confirmation.accepted', true);
        // redirect() makes the relative fallback absolute.
        $this->assertStringStartsWith(url(RestraintReturn::REGISTER.'/report?kind=plan&return_to='), $response->headers->get('Location'));
        $this->assertStringNotContainsString($input['idempotency_key'], $response->headers->get('Location'));
    }

    public function test_http_framing_is_removed_but_unknown_domain_fields_still_reject(): void
    {
        $plan = $this->makePlan();
        $input = $this->command($plan, ['notes' => 'Updated exact narrative', '_token' => 'token', '_method' => 'PUT', 'return_to' => RestraintReturn::REGISTER.'?lens=plans', 'section' => 'content', 'action' => 'edit', 'include_history' => 0, 'version' => $plan->current_candidate_version_id, 'approval' => null]);
        $this->actingAs($this->planManager)->putJson($this->url($plan), $input)->assertOk()->assertJsonPath('behaviour_support_plan_confirmation.version', 2);
        $bad = $this->command($plan, ['notes' => 'Not saved', 'restraints_manage' => true]);
        $this->putJson($this->url($plan), $bad)->assertUnprocessable()->assertJsonValidationErrors('command');
        $this->assertSame('Updated exact narrative', $plan->fresh()->notes);
        $this->assertSame(2, $plan->fresh()->lock_version);
    }

    #[DataProvider('outcomes')]
    public function test_all_retained_review_outcomes_and_resulting_statuses_have_real_http_parity(string $outcome, string $status): void
    {
        $plan = $this->makePlan();
        $input = $this->command($plan, ['outcome' => $outcome, 'reviewed_at' => '2026-10-10T00:00:00Z', 'resulting_status' => $status, 'next_review_date' => '2026-12-01', 'notes' => 'Actual review narrative']);
        $json = $this->actingAs($this->planReviewer)->postJson($this->url($plan).'/review', $input)->assertOk()->assertJsonPath('behaviour_support_plan_confirmation.command', 'review')->assertJsonPath('behaviour_support_plan_confirmation.status', $status)->json();
        $review = BehaviourSupportPlanReview::findOrFail($json['receipt']['review_id']);
        $this->assertSame($outcome, $review->outcome);
        $this->assertSame($this->planReviewer->id, $review->reviewed_by);
        $this->assertSame('Actual review narrative', $review->notes);
        $this->assertStringContainsString('/review?section=review', $json['plan_href']);
    }

    public static function outcomes(): array
    {
        return [['continued', 'draft'], ['modified', 'under_review'], ['reduced', 'draft'], ['discontinued', 'archived'], ['escalated', 'under_review']];
    }

    public function test_requested_active_review_is_explicitly_pending_and_manager_activation_is_not_implicit_approval(): void
    {
        $plan = $this->makePlan();
        $input = $this->command($plan, ['outcome' => 'continued', 'reviewed_at' => '2026-10-10T00:00:00Z', 'resulting_status' => 'active', ...$this->targetPayload($plan)]);
        $this->actingAs($this->planReviewer)->postJson($this->url($plan).'/review', $input)->assertOk()->assertJsonPath('receipt.disposition', 'approval_pending')->assertJsonPath('receipt.requested_status', 'active')->assertJsonPath('receipt.status', 'draft');
        $this->actingAs($this->planManager)->postJson($this->url($plan).'/activate', $this->command($plan, $this->targetPayload($plan)))->assertOk()->assertJsonPath('receipt.disposition', 'approval_pending');
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertNull($plan->fresh()->current_published_version_id);
    }

    public function test_submit_approve_activate_archive_http_commands_preserve_immutable_history(): void
    {
        $plan = $this->makePlan();
        $this->actingAs($this->planManager)->postJson($this->url($plan).'/submit-review', $this->command($plan))->assertOk()->assertJsonPath('receipt.status', 'under_review');
        $decision = $this->actingAs($this->planSpecialist)->postJson($this->url($plan).'/approvals', $this->approvalPayload($plan))->assertOk()->json('receipt');
        $published = $this->actingAs($this->planManager)->postJson($this->url($plan).'/activate', $this->command($plan, $this->targetPayload($plan)))->assertOk()->assertJsonPath('receipt.status', 'active')->json('receipt');
        $this->assertSame($decision['approval_id'], $published['approval_id']);
        $this->assertNotSame($published['candidate_version_id'], $published['published_version_id']);
        $this->postJson($this->url($plan).'/archive', $this->command($plan))->assertOk()->assertJsonPath('receipt.status', 'archived');
        $this->assertDatabaseHas('behaviour_support_plan_versions', ['id' => $published['published_version_id'], 'approval_id' => $decision['approval_id']]);
        $this->assertSame(4, RestraintCommandReceipt::where('source_id', $plan->id)->where('command', '!=', 'create')->count());
    }

    public function test_stale_parent_and_policy_conflicts_do_not_change_rows_or_accept_old_draft(): void
    {
        $plan = $this->makePlan();
        $stale = $this->command($plan, ['notes' => 'Preserved stale draft']);
        $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Saved newer content']));
        $this->actingAs($this->planManager)->putJson($this->url($plan), $stale)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $decision = $this->approvalPayload($plan);
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $this->actingAs($this->planSpecialist)->postJson($this->url($plan).'/approvals', $decision)->assertUnprocessable()->assertJsonValidationErrors('expected_policy_revision');
        $this->assertSame('Saved newer content', $plan->fresh()->notes);
        $this->assertSame(2, $plan->fresh()->lock_version);
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertDatabaseMissing('restraint_command_receipts', ['idempotency_key' => $stale['idempotency_key']]);
        $this->assertDatabaseMissing('restraint_command_receipts', ['idempotency_key' => $decision['idempotency_key']]);
    }

    public function test_exact_receipt_recovery_is_actor_plan_uuid_bound_and_handles_uppercase_keys(): void
    {
        $plan = $this->makePlan();
        $input = $this->approvalPayload($plan);
        $input['idempotency_key'] = strtoupper($input['idempotency_key']);
        $json = $this->actingAs($this->planSpecialist)->postJson($this->url($plan).'/approvals', $input)->assertOk()->json('receipt');
        $this->assertSame(strtolower($input['idempotency_key']), $json['idempotency_key']);
        $this->getJson($this->url($plan).'/approval?include_history=0&receipt_key='.$input['idempotency_key'])->assertOk()->assertJsonPath('behaviour_support_plan_confirmation.receipt_id', $json['receipt_id'])->assertJsonPath('behaviour_support_plan_confirmation.version', 2);
        $this->postJson($this->url($plan).'/approvals', $input)->assertOk()->assertJsonPath('receipt.replayed', true)->assertJsonPath('receipt.receipt_id', $json['receipt_id']);
        $other = $this->staff(['restraints.approve', 'clients.viewAny']);
        $this->actingAs($other)->getJson($this->url($plan).'/approval?include_history=0&receipt_key='.$input['idempotency_key'])->assertOk()->assertJsonPath('behaviour_support_plan_confirmation', null);
        $foreign = $this->makePlan();
        $this->actingAs($this->planSpecialist)->getJson($this->url($foreign).'/approval?include_history=0&receipt_key='.$input['idempotency_key'])->assertOk()->assertJsonPath('behaviour_support_plan_confirmation', null);
        $this->assertSame(1, BehaviourSupportPlanApproval::count());
    }

    public function test_current_revocation_denies_replay_and_does_not_show_retained_page_receipt(): void
    {
        $plan = $this->makePlan();
        $input = $this->approvalPayload($plan);
        $this->actingAs($this->planSpecialist)->postJson($this->url($plan).'/approvals', $input)->assertOk();
        $this->grant($this->planSpecialist, 'restraints.view');
        $this->planSpecialist->load('roles.permissions', 'permissionOverrides');
        $this->grant($this->planSpecialist, 'restraints.approve', false);
        $this->postJson($this->url($plan).'/approvals', $input)->assertForbidden();
        $this->getJson($this->url($plan).'?receipt_key='.$input['idempotency_key'])->assertOk()->assertJsonPath('behaviour_support_plan_confirmation', null);
        $this->assertSame(1, BehaviourSupportPlanApproval::count());
    }

    public function test_false_session_hints_and_missing_exact_audit_cannot_fabricate_acceptance(): void
    {
        $plan = $this->makePlan();
        $receipt = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan));
        $bad = array_replace($receipt, ['version' => 99]);
        $this->actingAs($this->planSpecialist)->withSession(['behaviour_support_plan_confirmation' => $bad])->getJson($this->url($plan).'/approval')->assertOk()->assertJsonPath('behaviour_support_plan_confirmation', null);
        $this->assertNotNull(app(BehaviourSupportPlanWorkspaceService::class)->confirmationForViewer($this->planSpecialist, $receipt));
        $ledger = RestraintCommandReceipt::findOrFail($receipt['receipt_id']);
        DB::table('audit_logs')->where('id', $ledger->audit_log_id)->update(['action' => 'wrong.semantic.action']);
        $this->getJson($this->url($plan).'/approval?receipt_key='.$receipt['idempotency_key'])->assertOk()->assertJsonPath('behaviour_support_plan_confirmation', null);
    }

    public function test_foreign_children_and_malformed_selectors_do_not_cross_the_authorised_plan(): void
    {
        $plan = $this->makePlan();
        $other = $this->makePlan();
        $approval = $this->approvals()->decide($other, $this->planSpecialist, $this->approvalPayload($other));
        $this->actingAs($this->planReviewer)->getJson($this->url($plan).'/review?version='.$other->current_candidate_version_id)->assertNotFound();
        $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval?approval='.$approval['approval_id'])->assertNotFound();
        foreach (['version=0', 'version[]=1', 'version=9007199254740992', 'approval=foreign'] as $query) {
            $this->getJson($this->url($plan).'/approval?'.$query)->assertNotFound();
        }
    }

    public function test_current_client_original_site_and_internal_staff_checks_apply_to_all_workspace_modes(): void
    {
        $plan = $this->makePlan();
        $this->grant($this->planManager, 'restraints.view');
        $otherSite = Site::factory()->create();
        $this->client->update(['site_id' => $otherSite->id]);
        $this->actingAs($this->planManager)->getJson($this->url($plan))->assertForbidden();
        $this->actingAs($this->planReviewer)->getJson($this->url($plan).'/review')->assertForbidden();
        $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval')->assertForbidden();
        $this->planManager->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $this->actingAs($this->planManager)->getJson($this->url($plan))->assertOk()->assertJsonPath('detail.site.id', $this->site->id)->assertJsonPath('detail.candidate.content.notes', 'Private plan narrative');
        $this->planManager->hrEmployeeProfile()->update(['is_active' => false]);
        $this->getJson($this->url($plan))->assertForbidden();
        $settingsOnly = $this->staff(['healthSafety.settings.manage', 'restraints.approve', 'healthSafety.viewAllSites']);
        $this->actingAs($settingsOnly)->getJson($this->url($plan).'/approval')->assertForbidden();
    }

    public function test_private_decision_projection_whitelists_identity_evidence_and_current_availability(): void
    {
        Storage::fake('private');
        $plan = $this->makePlan();
        $this->grant($this->planSpecialist, 'clients.update');
        Storage::disk('private')->put('client_documents/decision.pdf', 'Actual private evidence');
        $document = ClientDocument::create(['client_id' => $this->client->id, 'title' => 'Current evidence label', 'storage_disk' => 'private', 'storage_path' => 'client_documents/decision.pdf', 'original_name' => 'decision.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 23]);
        $receipt = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['evidence_references' => [['type' => 'client_document', 'id' => $document->id]]]));
        $row = BehaviourSupportPlanApproval::findOrFail($receipt['approval_id']);
        DB::table('behaviour_support_plan_approvals')->where('id', $row->id)->update(['approver_snapshot' => json_encode([...$row->approver_snapshot, 'recipient_email' => 'hidden@example.test', 'credential_private' => 'secret-credential']), 'evidence_references' => json_encode([['type' => 'client_document', 'id' => $document->id, 'label' => 'Do not trust retained label', 'path' => 'secret/path.pdf', 'sha256' => 'secret-byte-hash']])]);
        $json = $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval?approval='.$row->id)->assertOk()->assertJsonPath('detail.selected_approval.evidence.0.label', 'Current evidence label')->assertJsonPath('detail.selected_approval.evidence.0.availability', 'available')->assertJsonPath('detail.selected_approval.evidence.0.preservation_claim', false)->json();
        foreach (['hidden@example.test', 'secret-credential', 'secret/path.pdf', 'secret-byte-hash', 'Do not trust retained label', 'permissions', 'actor_snapshot'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($json));
        }
        $this->assertSame('/clients/'.$this->client->id.'/documents/'.$document->id.'/download', $json['detail']['selected_approval']['evidence'][0]['href']);
        Storage::disk('private')->delete('client_documents/decision.pdf');
        $this->getJson($this->url($plan).'/approval?approval='.$row->id)->assertOk()->assertJsonPath('detail.selected_approval.evidence.0.availability', 'unavailable')->assertJsonPath('detail.selected_approval.evidence.0.href', null);
        $this->grant($this->planSpecialist, 'restraints.view');
        // A real request loads the user fresh; the test's reused actor still holds its first request's grants.
        $this->actingAs($this->planSpecialist->fresh())->getJson($this->url($plan))->assertOk()->assertJsonPath('detail.approvals.0.basis', null)->assertJsonPath('detail.approvals.0.evidence', []);
    }

    public function test_event_evidence_requires_its_current_independent_event_read(): void
    {
        Storage::fake('private');
        $plan = $this->makePlan();
        $event = RestraintEvent::factory()->for($this->client)->create(['site_id' => $this->site->id]);
        Storage::disk('private')->put('restraint_attachments/decision.pdf', 'Actual attachment');
        $attachment = RestraintEventAttachment::create(['restraint_event_id' => $event->id, 'disk' => 'private', 'path' => 'restraint_attachments/decision.pdf', 'original_name' => 'Event-specific decision', 'mime' => 'application/pdf', 'size' => 17, 'category' => 'authorisation']);
        $this->grant($this->planSpecialist, 'restraints.view');
        $receipt = $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['evidence_references' => [['type' => 'event_attachment', 'id' => $attachment->id]]]));
        $this->grant($this->planSpecialist, 'restraints.view', false);
        $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval?approval='.$receipt['approval_id'])->assertOk()->assertJsonPath('detail.selected_approval.evidence.0.availability', 'inaccessible')->assertJsonPath('detail.selected_approval.evidence.0.id', null)->assertJsonPath('detail.selected_approval.evidence.0.label', null)->assertJsonPath('detail.connections.events', []);
    }

    public function test_compact_current_check_returns_exact_candidate_and_policy_without_history_or_navigation_uuid(): void
    {
        $plan = $this->makePlan();
        $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Later current content']));
        $json = $this->actingAs($this->planReviewer)->getJson($this->url($plan).'/review?include_history=0&action=review&section=review')->assertOk()->assertJsonPath('detail.lock_version', 2)->assertJsonPath('detail.candidate.content.notes', 'Later current content')->assertJsonPath('detail.versions', [])->assertJsonPath('detail.reviews', [])->assertJsonPath('detail.approvals', [])->assertJsonPath('detail.history', [])->json();
        $this->assertTrue($json['detail']['candidate']['integrity_valid']);
        $this->assertSame(0, $json['detail']['current_policy']['policy_revision']);
        $this->assertStringNotContainsString('receipt_key', json_encode($json['detail']['readable_hrefs']));
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', Policy::KEY)->count());
    }

    public function test_html_command_retains_requested_workflow_and_exact_local_receipt(): void
    {
        $plan = $this->makePlan();
        $input = $this->command($plan, ['outcome' => 'continued', 'reviewed_at' => '2026-10-10T00:00:00Z', 'action' => 'review', 'section' => 'review', 'return_to' => RestraintReturn::REGISTER.'?lens=plans&tab=under_review']);
        $response = $this->actingAs($this->planReviewer)->post($this->url($plan).'/review', $input)->assertRedirect()->assertSessionHas('behaviour_support_plan_confirmation.command', 'review');
        $target = $response->headers->get('Location');
        $this->assertStringContainsString('/review?section=review&return_to=', $target);
        $this->assertStringContainsString('&action=review', $target);
        $this->assertStringNotContainsString($input['idempotency_key'], $target);
        $this->getJson($target)->assertOk()->assertJsonPath('behaviour_support_plan_confirmation.idempotency_key', $input['idempotency_key']);
    }

    public function test_approval_choices_use_current_mode_and_exact_source_authorised_people(): void
    {
        $plan = $this->makePlan();
        $eligible = $this->staff(['restraints.approve', 'clients.viewAny'], ['name' => 'Eligible approver']);
        $noClient = $this->staff(['restraints.approve'], ['name' => 'Denied Client approver']);
        $inactive = $this->staff(['restraints.approve', 'clients.viewAny'], ['name' => 'Inactive approver']);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $wrongSite = $this->staff(['restraints.approve', 'clients.viewAny'], ['name' => 'Foreign Site approver']);
        $wrongSite->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $json = $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval')->assertOk()->json('detail.approval_options');
        $this->assertEqualsCanonicalizing([$this->planSpecialist->id, $eligible->id], array_column($json['internal_approvers'], 'id'));
        foreach ([$noClient->id, $inactive->id, $wrongSite->id] as $hidden) {
            $this->assertNotContains($hidden, array_column($json['internal_approvers'], 'id'));
        }
        $this->grant($this->planSpecialist, 'restraints.view');
        $this->actingAs($this->planSpecialist->fresh())->getJson($this->url($plan))->assertOk()->assertJsonPath('detail.approval_options', null);
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $this->actingAs($this->planReviewer)->getJson($this->url($plan).'/approval')->assertOk()->assertJsonPath('detail.approval_options.internal_approvers', [['id' => $this->planReviewer->id, 'name' => $this->planReviewer->name]])->assertJsonPath('detail.approval_options.credentials', []);
    }

    public function test_evidence_choices_follow_document_section_read_and_current_bytes_without_paths(): void
    {
        Storage::fake('private');
        $plan = $this->makePlan();
        Storage::disk('private')->put('client_documents/choice.pdf', 'Actual bytes');
        $document = ClientDocument::create(['client_id' => $this->client->id, 'title' => 'Authorised evidence choice', 'storage_disk' => 'private', 'storage_path' => 'client_documents/choice.pdf', 'original_name' => 'choice.pdf']);
        $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval')->assertOk()->assertJsonPath('detail.approval_options.evidence', []);
        $this->grant($this->planSpecialist, 'clients.update');
        $json = $this->getJson($this->url($plan).'/approval')->assertOk()->assertJsonPath('detail.approval_options.evidence.0.reference', ['type' => 'client_document', 'id' => $document->id])->assertJsonPath('detail.approval_options.evidence.0.selectable', true)->json('detail.approval_options');
        $this->assertStringNotContainsString('client_documents/choice.pdf', json_encode($json));
        Storage::disk('private')->delete('client_documents/choice.pdf');
        $this->getJson($this->url($plan).'/approval')->assertOk()->assertJsonPath('detail.approval_options.evidence.0.availability', 'unavailable')->assertJsonPath('detail.approval_options.evidence.0.selectable', false)->assertJsonPath('detail.approval_options.evidence.0.href', null);
        $this->planSpecialist->load('permissionOverrides');
        $this->grant($this->planSpecialist, 'clients.update', false);
        $this->getJson($this->url($plan).'/approval')->assertOk()->assertJsonPath('detail.approval_options.evidence', []);
    }

    public function test_optional_credential_choices_preserve_actual_self_or_manager_read_boundary(): void
    {
        $plan = $this->makePlan();
        $other = $this->staff(['restraints.approve', 'clients.viewAny']);
        $own = StaffCredential::create(['user_id' => $this->planSpecialist->id, 'type' => 'Own factual record', 'expires_at' => '2026-12-01', 'notes' => 'Hidden HR narrative']);
        $their = StaffCredential::create(['user_id' => $other->id, 'type' => 'Other factual record', 'expires_at' => '2026-12-01']);
        $expired = StaffCredential::create(['user_id' => $this->planSpecialist->id, 'type' => 'Expired record', 'expires_at' => '2026-10-01']);
        $json = $this->actingAs($this->planSpecialist)->getJson($this->url($plan).'/approval')->assertOk()->assertJsonPath('detail.approval_options.credentials.0.professional_eligibility_verified', false)->json('detail.approval_options');
        $this->assertSame([$own->id], array_column($json['credentials'], 'id'));
        $this->assertStringNotContainsString('Hidden HR narrative', json_encode($json));
        $this->grant($this->planSpecialist, 'staff.credentials.viewAny');
        $json = $this->getJson($this->url($plan).'/approval')->assertOk()->json('detail.approval_options');
        $this->assertEqualsCanonicalizing([$own->id, $their->id], array_column($json['credentials'], 'id'));
        $this->assertNotContains($expired->id, array_column($json['credentials'], 'id'));
        $this->grant($this->planSpecialist, 'staff.credentials.viewAny', false);
        $this->getJson($this->url($plan).'/approval')->assertOk()->assertJsonCount(1, 'detail.approval_options.credentials');
    }

    public function test_review_only_version_links_stay_inside_bounded_review_workspace(): void
    {
        $plan = $this->makePlan();
        $json = $this->actingAs($this->planReviewer)->getJson($this->url($plan).'/review')->assertOk()->json('detail');
        $this->assertStringContainsString('/review?section=versions&version=', $json['candidate']['href']);
        $this->getJson($json['candidate']['href'])->assertOk()->assertJsonPath('detail.selected_version.id', $plan->current_candidate_version_id);
        $this->getJson($this->url($plan))->assertForbidden();
    }

    public function test_plan_editor_receives_current_source_developers_without_directory_or_history_grant(): void
    {
        $plan = $this->makePlan();
        $eligible = $this->staff([], ['name' => 'Current source developer']);
        $inactive = $this->staff([], ['name' => 'Inactive source developer']);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $foreign = $this->staff([], ['name' => 'Foreign Site developer']);
        $foreign->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $json = $this->actingAs($this->planManager)->getJson($this->url($plan).'/review')->assertOk()->json('pickers.staff');
        $this->assertContains($eligible->id, array_column($json, 'id'));
        $this->assertNotContains($inactive->id, array_column($json, 'id'));
        $this->assertNotContains($foreign->id, array_column($json, 'id'));
        $this->assertSame(['id', 'name'], array_keys($json[0]));
        $this->actingAs($this->planReviewer)->getJson($this->url($plan).'/review')->assertOk()->assertJsonPath('pickers.staff', []);
    }

    private function grant(User $actor, string $key, bool $allowed = true): void
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'Source HTTP fixtures', 'module' => 'healthSafety']);
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }

    private function url(BehaviourSupportPlan $plan): string
    {
        return RestraintReturn::REGISTER.'/plans/'.$plan->id;
    }
}
