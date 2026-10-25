<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Models\WorkplaceInjuryCommandReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** All production routes and domain adapters run together; no temporary permissive route stands in for the workspace. */
class InjuryWorkspaceControllerTest extends WorkplaceInjurySourceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
    }

    /** Real PDF bytes: fake()->create() reports a size with no content, so its stored size never matches its bytes. */
    private function certificate(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('certificate.pdf', "%PDF-1.4\n% Actual certificate bytes\n");
    }

    public function test_permanent_index_show_and_create_inertia_pages_retain_their_canonical_shapes(): void
    {
        $injury = $this->injury();
        $this->actingAs($this->manager)->get(route('health-safety.injuries.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/injuries/workspace-index')->where('detail', null)->has('injuries.data', 1));
        $this->get(route('health-safety.injuries.show', $injury))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/injuries/show')->where('detail.id', $injury->id)
                ->where('detail.notes', 'Private enduring narrative')->missing('pickers'));
        $this->get(route('health-safety.injuries.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/injuries/create')->has('capture.workers')->missing('detail'));
    }

    public function test_factual_json_detail_has_no_medical_collection_identity_count_or_private_sql(): void
    {
        $injury = $this->injury();
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $response = $this->actingAs($this->factual)->getJson(route('health-safety.injuries.show', $injury));
            $response->assertOk()->assertJsonPath('detail.medical_access', 'restricted')->assertJsonPath('detail.notes', null)
                ->assertJsonPath('detail.rtw_plans', null)->assertJsonPath('detail.capacity_assessments', null)
                ->assertJsonPath('detail.attachments', null)->assertJsonPath('detail.history', null)->assertJsonPath('detail.audits', null)
                ->assertJsonMissingPath('detail.source_creation_key');
            $this->assertStringNotContainsString('Private enduring narrative', $response->getContent());
            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])(?:content|content_digest)(?![a-z_])/i', $query['query']);
                if (str_contains($query['query'], 'workplace_injury_source_versions')) {
                    $this->assertDoesNotMatchRegularExpression('/^\s*select\s+(?:[`"]?[a-z_]+[`"]?\.)?\*/i', $query['query']);
                }
                foreach (['return_to_work_plans', 'work_capacity_assessments', 'workplace_injury_attachments'] as $table) {
                    $this->assertStringNotContainsString($table, strtolower($query['query']));
                }
            }
        } finally { DB::disableQueryLog(); }
    }

    public function test_readonly_medical_reader_gets_full_detail_but_no_write_or_create_authority(): void
    {
        $injury = $this->injury();
        $this->actingAs($this->medicalReader)->getJson(route('health-safety.injuries.show', $injury))->assertOk()
            ->assertJsonPath('detail.notes', 'Private enduring narrative')->assertJsonPath('detail.can.manage', false)
            ->assertJsonPath('detail.can.recovery', false);
        $this->getJson(route('health-safety.injuries.create'))->assertForbidden();
        $this->putJson(route('health-safety.injuries.update', $injury), $this->command($injury, ['description' => 'Denied change']))->assertForbidden();
    }

    public function test_capture_only_post_and_create_recovery_never_confer_enduring_record_read(): void
    {
        $payload = $this->createPayload();
        $response = $this->actingAs($this->capturer)->postJson(route('health-safety.injuries.store'), $payload)->assertOk()
            ->assertJsonPath('capture.can.create', true)->assertJsonPath('capture.can.record_reported_details', true)
            ->assertJsonPath('capture.medical_access', 'restricted')->assertJsonPath('injury_confirmation.accepted', true)
            ->assertJsonPath('injury_href', null)->assertJsonMissingPath('detail')
            ->assertJsonMissingPath('injury_confirmation.notes');
        $id = $response->json('injury_confirmation.injury_id');
        $this->getJson(route('health-safety.injuries.show', $id))->assertForbidden();
        $this->getJson(route('health-safety.injuries.index'))->assertForbidden();
        $this->getJson(route('health-safety.injuries.create', ['receipt_key' => strtoupper($payload['idempotency_key'])]))->assertOk()
            ->assertJsonPath('injury_confirmation.injury_id', $id)->assertJsonPath('injury_href', null);
        $this->get(route('health-safety.injuries.create', ['receipt_key' => $payload['idempotency_key']]))->assertNotFound();
    }

    public function test_create_uuid_retry_preserves_one_source_version_audit_and_accepted_injury(): void
    {
        $payload = $this->createPayload();
        $first = $this->actingAs($this->manager)->postJson(route('health-safety.injuries.store'), $payload)->assertOk();
        $second = $this->postJson(route('health-safety.injuries.store'), $payload)->assertOk()->assertJsonPath('injury_confirmation.replayed', true);
        $this->assertSame($first->json('injury_confirmation.injury_id'), $second->json('injury_confirmation.injury_id'));
        $this->assertDatabaseCount('workplace_injuries', 1);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 1);
        $this->assertDatabaseCount('workplace_injury_source_versions', 1);
        $this->assertSame(1, AuditLog::where('action', 'health_safety.injury.create')->count());
    }

    public function test_unknown_domain_fields_bad_uuid_and_naive_actual_datetime_cannot_create_an_injury(): void
    {
        foreach ([['unknown_field' => 'must reject'], ['idempotency_key' => 'urn:uuid:'.Str::uuid()],
            ['injury_date' => '2026-10-10T10:00:00']] as $change) {
            $this->actingAs($this->manager)->postJson(route('health-safety.injuries.store'), $this->createPayload($change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('workplace_injuries', 0);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 0);
    }

    public function test_foreign_site_and_withdrawn_worker_are_denied_before_capture_acceptance(): void
    {
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->actingAs($this->manager)->postJson(route('health-safety.injuries.store'), $this->createPayload(['site_id' => $foreign->id]))->assertNotFound();
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->postJson(route('health-safety.injuries.store'), $this->createPayload())->assertForbidden();
        $this->assertDatabaseCount('workplace_injury_command_receipts', 0);
    }

    public function test_noncreate_write_requires_version_and_uuid_and_retains_unknown_domain_rejection(): void
    {
        $injury = $this->injury();
        foreach ([['description' => 'Legacy bypass attempt'], ['expected_version' => 1, 'description' => 'Missing key'],
            $this->command($injury, ['status' => 'closed']), $this->command($injury, ['unknown_field' => true])] as $input) {
            $this->actingAs($this->manager)->putJson(route('health-safety.injuries.update', $injury), $input)->assertUnprocessable();
        }
        $this->assertSame('Recorded lifting injury.', $injury->fresh()->description);
        $this->assertSame('reported', $injury->fresh()->status);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 1);
    }

    public function test_factual_update_exact_retry_and_stale_draft_validation_are_truthful(): void
    {
        $injury = $this->injury();
        $input = $this->command($injury, ['description' => 'Accepted factual correction', '_method' => 'PUT', 'return_to' => 'https://external.invalid']);
        $first = $this->actingAs($this->factual)->putJson(route('health-safety.injuries.update', $injury), $input)->assertOk()
            ->assertJsonPath('detail.medical_access', 'restricted')->assertJsonPath('injury_confirmation.version', 2);
        $this->putJson(route('health-safety.injuries.update', $injury), $input)->assertOk()->assertJsonPath('injury_confirmation.replayed', true);
        $this->putJson(route('health-safety.injuries.update', $injury), [...$input, 'idempotency_key' => (string) Str::uuid(), 'description' => 'Retained stale draft'])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->assertSame('Accepted factual correction', $injury->fresh()->description);
        $this->assertSame(2, $injury->fresh()->source_revision);
        $this->assertStringNotContainsString('external.invalid', (string) $first->json('injury_href'));
    }

    public function test_same_uuid_different_actor_payload_or_command_is_not_a_successful_replay(): void
    {
        $injury = $this->injury();
        $input = $this->command($injury, ['description' => 'Accepted factual correction']);
        $this->actingAs($this->manager)->putJson(route('health-safety.injuries.update', $injury), $input)->assertOk();
        $this->actingAs($this->factual)->putJson(route('health-safety.injuries.update', $injury), $input)->assertUnprocessable();
        $this->actingAs($this->manager)->putJson(route('health-safety.injuries.update', $injury), [...$input, 'description' => 'Changed retry'])->assertUnprocessable();
        $this->postJson(route('health-safety.injuries.status', $injury), [...$input, 'status' => 'closed', 'reason' => 'Wrong command'])->assertUnprocessable();
        $this->assertSame('Accepted factual correction', $injury->fresh()->description);
        $this->assertSame(2, $injury->fresh()->source_revision);
    }

    public function test_reused_actor_management_revoke_blocks_uuid_replay_and_removes_detail_flags(): void
    {
        $injury = $this->injury();
        $input = $this->command($injury, ['description' => 'Previously accepted']);
        $this->actingAs($this->manager)->putJson(route('health-safety.injuries.update', $injury), $input)->assertOk();
        $this->revoke($this->manager, 'hazards.manage');
        $this->putJson(route('health-safety.injuries.update', $injury), $input)->assertForbidden();
        $this->getJson(route('health-safety.injuries.show', $injury))->assertOk()->assertJsonPath('detail.can.manage', false)
            ->assertJsonPath('detail.notes', 'Private enduring narrative');
        $this->assertSame(2, $injury->fresh()->source_revision);
    }

    public function test_lifecycle_command_preserves_actual_return_uncertainty_and_does_not_close_other_domains(): void
    {
        $injury = $this->injury();
        $this->actingAs($this->manager)->postJson(route('health-safety.injuries.status', $injury), $this->command($injury,
            ['status' => 'under_treatment', 'reason' => 'Treatment actually started']))->assertOk();
        $this->postJson(route('health-safety.injuries.status', $injury), $this->command($injury,
            ['status' => 'recovered', 'reason' => 'Recovery status actually recorded']))->assertOk()
            ->assertJsonPath('detail.status', 'recovered')->assertJsonPath('detail.actual_return_date', null)
            ->assertJsonPath('detail.can.declare_fit', false);
        $this->assertNull($injury->fresh()->actual_return_date);
    }

    public function test_existing_plan_update_and_duty_routes_use_exact_parent_worker_and_global_revision_receipts(): void
    {
        $injury = $this->injury();
        $created = $this->actingAs($this->manager)->postJson(route('health-safety.injuries.rtw-plans.store', $injury), $this->planPayload($injury))
            ->assertOk()->assertJsonPath('injury_confirmation.command', 'create_rtw_plan');
        $plan = ReturnToWorkPlan::findOrFail($created->json('injury_confirmation.source_id'));
        $this->putJson(route('health-safety.injuries.rtw-plans.update', $plan), $this->command($injury, ['goals' => ['Accepted revised goal']]))
            ->assertOk()->assertJsonPath('injury_confirmation.command', 'update_rtw_plan');
        $duty = $this->postJson(route('health-safety.injuries.modified-duties.store', $plan), $this->command($injury,
            ['start_date' => '2026-10-12', 'end_date' => '2026-10-20', 'modified_duties_description' => 'Recorded proposed arrangement', 'hours_per_day' => 4]))
            ->assertOk()->assertJsonPath('injury_confirmation.command', 'add_modified_duty');
        $this->assertSame(4, $injury->fresh()->source_revision);
        $duty->assertJsonPath('detail.rtw_plans.0.modified_duties.0.approval_state', 'unknown');
        $this->assertSame($this->worker->id, $plan->fresh()->worker_id);
    }

    public function test_foreign_or_conflicting_plan_child_cannot_be_rebound_by_legacy_parentless_endpoint(): void
    {
        $injury = $this->injury();
        $other = $this->staff();
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create(['workplace_injury_id' => $injury->id, 'worker_id' => $other->id]));
        $this->actingAs($this->manager)->putJson(route('health-safety.injuries.rtw-plans.update', $plan), $this->command($injury, ['review_notes' => 'Forbidden rewrite']))->assertNotFound();
        $this->postJson(route('health-safety.injuries.modified-duties.store', $plan), $this->command($injury,
            ['start_date' => '2026-10-12', 'modified_duties_description' => 'Foreign worker duty', 'hours_per_day' => 4]))->assertNotFound();
        $this->assertNotSame('Forbidden rewrite', $plan->fresh()->review_notes);
        $this->assertDatabaseCount('modified_duties', 0);
    }

    public function test_capacity_endpoint_records_actual_assessor_separately_and_cannot_default_to_worker(): void
    {
        $injury = $this->injury();
        $this->actingAs($this->manager)->postJson(route('health-safety.injuries.capacity-assessments.store', $injury), $this->capacityPayload($injury))
            ->assertOk()->assertJsonPath('detail.capacity_assessments.0.worker.id', $this->worker->id)
            ->assertJsonPath('detail.capacity_assessments.0.assessor_identity_kind', 'reported_external')
            ->assertJsonPath('detail.capacity_assessments.0.recorder.id', $this->manager->id)
            ->assertJsonPath('detail.capacity_assessments.0.medical_certification_state', 'unverified');
        $this->postJson(route('health-safety.injuries.capacity-assessments.store', $injury), $this->capacityPayload($injury, ['user_id' => $this->worker->id]))->assertUnprocessable();
        $this->actingAs($this->factual)->postJson(route('health-safety.injuries.capacity-assessments.store', $injury), $this->capacityPayload($injury))->assertForbidden();
    }

    public function test_current_coordinator_assignment_and_review_endpoints_preserve_named_ownership_and_private_gate(): void
    {
        $injury = $this->injury();
        $coordinator = $this->staff(['hazards.view']);
        $input = $this->command($injury, ['coordinator_user_id' => $coordinator->id, 'next_review_date' => '2026-10-20', 'reason' => 'Named current owner']);
        $this->actingAs($this->factual)->postJson(route('health-safety.injuries.coordinator', $injury), $input)->assertOk()
            ->assertJsonPath('detail.coordination.coordinator.id', $coordinator->id)->assertJsonPath('injury_confirmation.command', 'assign');
        $this->revoke($coordinator, 'hazards.view');
        $this->postJson(route('health-safety.injuries.coordinator', $injury), $input)->assertForbidden();
        $review = $this->command($injury, ['reviewed_at' => '2026-10-12T12:00:00+13:00', 'next_review_date' => '2026-10-22', 'notes' => 'Private actual review']);
        $this->postJson(route('health-safety.injuries.reviews', $injury), $review)->assertForbidden();
        $this->actingAs($this->manager)->postJson(route('health-safety.injuries.reviews', $injury), $review)->assertOk()
            ->assertJsonPath('injury_confirmation.command', 'review')->assertJsonPath('detail.next_review_date', '2026-10-22');
    }

    public function test_private_upload_download_removal_and_exact_remove_retry_retain_bytes_and_history(): void
    {
        $injury = $this->injury();
        $upload = $this->actingAs($this->manager)->postJson(route('health-safety.injuries.attachments.store', $injury),
            [...$this->command($injury, ['kind' => 'medical_cert']), 'file' => $this->certificate()])
            ->assertOk()->assertJsonPath('injury_confirmation.command', 'upload_evidence');
        $attachment = WorkplaceInjuryAttachment::findOrFail($upload->json('injury_confirmation.source_id'));
        $this->get(route('health-safety.injuries.attachments.download', [$injury, $attachment]))->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");
        $remove = $this->command($injury, ['reason' => 'Retained superseded evidence']);
        $this->deleteJson(route('health-safety.injuries.attachments.destroy', [$injury, $attachment]), $remove)->assertOk()
            ->assertJsonPath('injury_confirmation.command', 'remove_evidence');
        $this->deleteJson(route('health-safety.injuries.attachments.destroy', [$injury, $attachment]), $remove)->assertOk()
            ->assertJsonPath('injury_confirmation.replayed', true);
        $this->assertSoftDeleted($attachment);
        Storage::disk('private')->assertExists($attachment->path);
        $this->get(route('health-safety.injuries.attachments.download', [$injury, $attachment]))->assertNotFound();
    }

    public function test_download_refuses_factual_readers_foreign_children_changed_bytes_and_revoked_medical_read(): void
    {
        $injury = $this->injury();
        $upload = $this->recovery()->uploadEvidence($injury, $this->manager, $this->certificate(), $this->command($injury));
        $attachment = WorkplaceInjuryAttachment::findOrFail($upload['source_id']);
        $url = route('health-safety.injuries.attachments.download', [$injury, $attachment]);
        $this->actingAs($this->factual)->get($url)->assertForbidden();
        $other = $this->injury();
        $this->actingAs($this->manager)->get(route('health-safety.injuries.attachments.download', [$other, $attachment]))->assertNotFound();
        $bytes = Storage::disk('private')->get($attachment->path);
        Storage::disk('private')->put($attachment->path, 'externally changed bytes');
        $this->get($url)->assertNotFound();
        Storage::disk('private')->put($attachment->path, $bytes);
        $this->revoke($this->manager, 'hr.wellbeing.view');
        $this->get($url)->assertForbidden();
    }

    public function test_get_confirmation_reconstructs_saved_actor_source_uuid_and_discards_forged_flash_fields(): void
    {
        $accepted = $this->source()->create($this->manager, $this->createPayload());
        $this->actingAs($this->manager)->withSession(['injury_confirmation' => [...$accepted, 'version' => 999, 'accepted' => false, 'notes' => 'Forged private flash']])
            ->getJson(route('health-safety.injuries.show', $accepted['injury_id']))->assertOk()
            ->assertJsonPath('injury_confirmation.accepted', true)->assertJsonPath('injury_confirmation.version', 1)
            ->assertJsonMissingPath('injury_confirmation.notes');
        $this->actingAs($this->medicalReader)->withSession(['injury_confirmation' => $accepted])
            ->getJson(route('health-safety.injuries.show', $accepted['injury_id']))->assertOk()->assertJsonPath('injury_confirmation', null);
        $this->actingAs($this->manager)->getJson(route('health-safety.injuries.show', ['injury' => $accepted['injury_id'], 'receipt_key' => strtoupper($accepted['idempotency_key'])]))
            ->assertOk()->assertJsonPath('injury_confirmation.injury_id', $accepted['injury_id']);
    }

    public function test_corrupt_audit_never_echoes_saved_confirmation_and_command_replay_is_not_success(): void
    {
        $payload = $this->createPayload();
        $accepted = $this->source()->create($this->manager, $payload);
        $receipt = WorkplaceInjuryCommandReceipt::where('idempotency_key', $accepted['idempotency_key'])->sole();
        DB::table('audit_logs')->where('id', $receipt->audit_log_id)->update(['action' => 'wrong-source-action']);
        $this->actingAs($this->manager)->withSession(['injury_confirmation' => $accepted])
            ->getJson(route('health-safety.injuries.show', $accepted['injury_id']))->assertOk()->assertJsonPath('injury_confirmation', null);
        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);
        $this->postJson(route('health-safety.injuries.store'), $payload);
    }

    public function test_current_site_hr_and_medical_withdrawal_recheck_every_get_without_stale_actor_fallback(): void
    {
        $injury = $this->injury();
        $this->actingAs($this->manager)->getJson(route('health-safety.injuries.show', $injury))->assertOk();
        $this->revoke($this->manager, 'hr.wellbeing.view');
        $this->getJson(route('health-safety.injuries.show', $injury))->assertOk()->assertJsonPath('detail.notes', null);
        $alternative = Site::factory()->create(['is_active' => true]);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$alternative->id]]);
        $this->site->update(['is_active' => false]);
        $this->getJson(route('health-safety.injuries.show', $injury))->assertNotFound();
        $this->site->update(['is_active' => true]);
        $this->manager->hrEmployeeProfile()->update(['is_active' => false]);
        $this->getJson(route('health-safety.injuries.show', $injury))->assertForbidden();
    }

    public function test_legacy_detail_bookmark_redirects_to_permanent_id_with_safe_filters_and_command_hint(): void
    {
        $injury = $this->injury();
        $response = $this->actingAs($this->manager)->get(route('health-safety.injuries.index',
            ['injury' => $injury->id, 'tab' => 'reported', 'q' => 'lifting', 'page' => 2, 'section' => 'rtw', 'plan' => 5, 'action' => 'edit', 'receipt_key' => 'private']));
        $response->assertRedirect('/health-safety/injuries/'.$injury->id.'?section=recovery&action=edit&plan=5&return_to='
            .rawurlencode('/health-safety/injuries?tab=reported&q=lifting&page=2'));
        $this->actingAs($this->capturer)->get(route('health-safety.injuries.index', ['injury' => $injury->id]))->assertForbidden();
    }

    public function test_picker_endpoints_are_fresh_operation_specific_and_search_keeps_large_directories_reachable(): void
    {
        for ($index = 0; $index < 101; $index++) {
            $this->staff()->update(['name' => sprintf('000 current directory worker %03d', $index)]);
        }
        $target = $this->staff(['hazards.view']);
        $target->update(['name' => 'ZZZ Exact searchable coordinator']);
        $injury = $this->injury();
        $first = $this->actingAs($this->capturer)->getJson(route('health-safety.injuries.capture-options'))->assertOk()
            ->assertJsonPath('worker_picker.has_more', true)->assertJsonPath('worker_picker.next_page', 2);
        $this->assertNotContains($target->id, array_column($first->json('workers'), 'id'));
        $second = $this->getJson(route('health-safety.injuries.capture-options', ['page' => 2]))->assertOk();
        $this->assertContains($target->id, array_column($second->json('workers'), 'id'));
        $this->getJson(route('health-safety.injuries.capture-options', ['search' => 'Exact searchable coordinator']))
            ->assertOk()->assertJsonPath('workers.0.id', $target->id);
        $this->getJson(route('health-safety.injuries.command-options', $injury))->assertForbidden();
        $this->actingAs($this->factual)->getJson(route('health-safety.injuries.command-options', ['injury' => $injury->id, 'search' => 'Exact searchable coordinator']))
            ->assertOk()->assertJsonPath('coordinators.0.id', $target->id)->assertJsonPath('expected_version', 1);
        $this->getJson(route('health-safety.injuries.recovery-options', $injury))->assertForbidden();
        $this->actingAs($this->manager)->getJson(route('health-safety.injuries.recovery-options', $injury))->assertOk();
        $this->revoke($target, 'hazards.view');
        $this->actingAs($this->factual)->getJson(route('health-safety.injuries.command-options', ['injury' => $injury->id, 'search' => 'Exact searchable coordinator']))
            ->assertOk()->assertJsonPath('coordinators', []);
    }

    public function test_export_uses_current_factual_scope_medical_redaction_nz_dates_and_excel_injection_guard(): void
    {
        $injury = $this->injury(['body_part_affected' => '=UNTRUSTED_FORMULA', 'injury_date' => '2026-10-10T00:30:00+13:00']);
        $foreignSite = Site::factory()->create();
        WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create(['site_id' => $foreignSite->id, 'description' => 'Foreign export source', 'reference_number' => 'WI-FOREIGN']));
        $response = $this->actingAs($this->factual)->get(route('health-safety.injuries.export', ['q' => $injury->reference_number]));
        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=UNTRUSTED_FORMULA", $csv);
        $this->assertStringContainsString('2026-10-10', $csv);
        $this->assertStringNotContainsString('PRIVATE-CLAIM', $csv);
        $this->assertStringNotContainsString('WI-FOREIGN', $csv);
        $this->get(route('health-safety.injuries.export', ['treatment' => 'gp_visit']))->assertForbidden();
    }

    public function test_closed_source_retains_read_and_coordinator_authority_without_promising_new_factual_or_recovery_commands(): void
    {
        $injury = $this->injury();
        $this->actingAs($this->manager)->postJson(route('health-safety.injuries.status', $injury), $this->command($injury,
            ['status' => 'closed', 'reason' => 'Case closure actually recorded']))->assertOk();
        $this->getJson(route('health-safety.injuries.show', $injury))->assertOk()
            ->assertJsonPath('detail.status', 'closed')->assertJsonPath('detail.can.manage', true)
            ->assertJsonPath('detail.can.assign', true)->assertJsonPath('detail.can.read_evidence', true)
            ->assertJsonPath('detail.can.update', false)->assertJsonPath('detail.can.transition', false)
            ->assertJsonPath('detail.can.review', false)->assertJsonPath('detail.can.recovery', false)
            ->assertJsonPath('detail.can.upload_evidence', false)->assertJsonPath('detail.can.remove_evidence', false)
            ->assertJsonPath('detail.can.declare_fit', false)->assertJsonPath('detail.commands.transitions', []);
        $this->putJson(route('health-safety.injuries.update', $injury), $this->command($injury,
            ['description' => 'Must remain a retained rejected draft']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson(route('health-safety.injuries.rtw-plans.store', $injury), $this->planPayload($injury))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson(route('health-safety.injuries.status', $injury), $this->command($injury,
            ['status' => 'reported', 'reason' => 'Forbidden reopening']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('Recorded lifting injury.', $injury->fresh()->description);
        $this->assertSame(2, $injury->fresh()->source_revision);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 2);
        $this->assertDatabaseCount('workplace_injury_source_versions', 2);
    }
    public function test_html_acceptance_redirect_has_only_safe_origin_and_actor_bound_minimal_flash(): void
    {
        $response = $this->actingAs($this->capturer)->post(route('health-safety.injuries.store'),
            [...$this->createPayload(), '_token' => 'test', 'return_to' => '//external.invalid/tasks']);
        $response->assertRedirect('/health-safety/injuries/create')->assertSessionHas('injury_confirmation.accepted', true);
        $this->assertArrayNotHasKey('notes', session('injury_confirmation'));
        $this->assertArrayNotHasKey('source_creation_key', session('injury_confirmation'));
        $this->getJson(route('health-safety.injuries.create'))->assertOk()->assertJsonPath('injury_confirmation.accepted', true)
            ->assertJsonPath('injury_href', null);
    }
    public function test_actual_command_options_route_forwards_target_site_with_canonical_identity_and_current_denial(): void
    {
        $injury = $this->injury();
        $target = Site::factory()->create(['is_active' => true]);
        $worker = $this->staff([], $target);
        $coordinator = $this->staff(['hazards.view'], $target);
        $this->factual->hrEmployeeProfile()->update(['secondary_site_ids' => [$target->id]]);
        $this->assertFalse($this->factual->canDo('hazards.create'));
        $url = route('health-safety.injuries.command-options', ['injury' => $injury->id, 'site_id' => $target->id]);
        $response = $this->actingAs($this->factual)->getJson($url)->assertOk()
            ->assertJsonPath('injury_id', $injury->id)->assertJsonPath('canonical_site_id', $this->site->id)
            ->assertJsonPath('site_id', $target->id)->assertJsonPath('worker_id', $this->worker->id)
            ->assertJsonPath('expected_version', 1);
        $this->assertContains($worker->id, array_column($response->json('workers'), 'id'));
        $this->assertNotContains($this->worker->id, array_column($response->json('workers'), 'id'));
        $this->assertContains($coordinator->id, array_column($response->json('coordinators'), 'id'));
        $this->assertNotContains($worker->id, array_column($response->json('coordinators'), 'id'));
        $this->assertEqualsCanonicalizing([$this->site->id, $target->id], array_column($response->json('sites'), 'id'));
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->getJson(route('health-safety.injuries.command-options', ['injury' => $injury->id, 'site_id' => $foreign->id]))->assertNotFound();
        $this->factual->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->factual->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $this->getJson($url)->assertNotFound();
        $this->assertSame($this->site->id, $injury->fresh()->site_id);
        $this->assertSame($this->worker->id, $injury->fresh()->user_id);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 1);
    }
    public function test_capture_only_page_capability_never_reads_or_prefills_existing_private_source(): void
    {
        $existing = $this->injury();
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $response = $this->actingAs($this->capturer)->getJson(route('health-safety.injuries.create'))->assertOk()
                ->assertJsonPath('capture.can.create', true)->assertJsonPath('capture.can.record_reported_details', true)
                ->assertJsonPath('capture.medical_access', 'restricted')->assertJsonPath('injury_confirmation', null)
                ->assertJsonPath('injury_href', null)->assertJsonMissingPath('detail');
            $this->assertStringNotContainsString('Private enduring narrative', $response->getContent());
            $this->assertStringNotContainsString('PRIVATE-CLAIM', $response->getContent());
            foreach (DB::getQueryLog() as $entry) {
                foreach (['workplace_injuries', 'workplace_injury_source_versions', 'return_to_work_plans', 'work_capacity_assessments', 'workplace_injury_attachments'] as $table) {
                    $this->assertStringNotContainsString($table, strtolower($entry['query']));
                }
            }
        } finally { DB::disableQueryLog(); }
        $this->getJson(route('health-safety.injuries.show', $existing))->assertForbidden();
        $this->putJson(route('health-safety.injuries.update', $existing), $this->command($existing, ['notes' => 'Unauthorised medical replacement']))->assertForbidden();
    }

    public function test_actual_capture_capability_endpoint_rechecks_create_grant_and_selected_site_on_warmed_actor(): void
    {
        $url = route('health-safety.injuries.capture-options', ['site_id' => $this->site->id]);
        $this->actingAs($this->capturer)->getJson($url)->assertOk()->assertJsonPath('can.record_reported_details', true)
            ->assertJsonPath('medical_access', 'restricted');
        $this->revoke($this->capturer, 'hazards.create');
        $this->getJson($url)->assertForbidden();
        $this->capturer->permissionOverrides()->syncWithoutDetaching([
            \App\Models\Permission::where('key', 'hazards.create')->sole()->id => ['allowed' => true],
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('can.record_reported_details', true);
        $other = Site::factory()->create(['is_active' => true]);
        $this->capturer->hrEmployeeProfile()->update(['primary_site_id' => $other->id, 'secondary_site_ids' => []]);
        $this->getJson($url)->assertNotFound();
    }
}
