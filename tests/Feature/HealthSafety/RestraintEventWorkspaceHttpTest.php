<?php

namespace Tests\Feature\HealthSafety;

use App\Events\Respite\RespiteEvent;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintCommandReceipt;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\Site;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Full HTTP source/receipt boundary; production command, projection and route middleware run together. */
class RestraintEventWorkspaceHttpTest extends RestraintEventCommandTestCase
{
    public function test_normal_json_and_inertia_source_page_exposes_only_authorized_materialized_dto(): void
    {
        $row = $this->event();
        $this->actingAs($this->actor)->getJson(route('health-safety.restraints.events.show', $row))
            ->assertOk()->assertJsonPath('detail.event.id', $row->id)->assertJsonPath('workspace', 'view')
            ->assertJsonMissingPath('detail.event.creation_key')->assertJsonMissingPath('detail.event.creation_fingerprint')
            ->assertJsonMissingPath('detail.event.plan_authorization_snapshot')->assertJsonMissingPath('detail.event.client.support_workers');
        $this->get(route('health-safety.restraints.events.show', $row))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/restraints/events/show')->where('detail.event.id', $row->id)->where('workspace', 'view'));
    }

    public function test_review_only_workspace_and_capture_only_report_preserve_separate_operation_grants(): void
    {
        $row = $this->event();
        $reviewer = $this->staff(['restraints.review', 'clients.viewAny']);
        $this->actingAs($reviewer)->getJson(route('health-safety.restraints.events.show', $row))->assertForbidden();
        $this->getJson(route('health-safety.restraints.events.review-workspace', $row))->assertOk()
            ->assertJsonPath('workspace', 'review')->assertJsonPath('detail.event.id', $row->id);
        $capturer = $this->staff(['restraints.create', 'clients.viewAny']);
        $response = $this->actingAs($capturer)->postJson(route('health-safety.restraints.events.store'), $this->capture());
        $response->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('event_href', null)
            ->assertJsonMissingPath('restraint_confirmation.restraint_description');
        $confirmation = $response->json('restraint_confirmation');
        $this->withSession(['restraint_confirmation' => $confirmation])->getJson(route('health-safety.restraints.report'))->assertOk()
            ->assertJsonPath('restraint_confirmation.event_id', $confirmation['event_id'])->assertJsonMissingPath('detail');
        $this->getJson(route('health-safety.restraints.events.show', $confirmation['event_id']))->assertForbidden();
        $this->getJson(route('health-safety.restraints.events.review-workspace', $confirmation['event_id']))->assertForbidden();
    }

    public function test_get_flash_reconstructs_exact_actor_uuid_and_ignores_forged_acceptance_or_narrative(): void
    {
        $receipt = $this->service()->create($this->actor, $this->capture());
        $row = RestraintEvent::findOrFail($receipt['event_id']);
        $this->actingAs($this->actor)->withSession(['restraint_confirmation' => [...$receipt, 'version' => 999, 'restraint_description' => 'Forged narrative', 'accepted' => false]])
            ->getJson(route('health-safety.restraints.events.show', $row))->assertOk()
            ->assertJsonPath('restraint_confirmation.accepted', true)->assertJsonPath('restraint_confirmation.version', 1)
            ->assertJsonMissingPath('restraint_confirmation.restraint_description');
        $other = $this->staff(['restraints.view', 'restraints.create', 'clients.viewAny']);
        $this->actingAs($other)->withSession(['restraint_confirmation' => $receipt])->getJson(route('health-safety.restraints.events.show', $row))
            ->assertOk()->assertJsonPath('restraint_confirmation', null);
        $this->actingAs($this->actor)->withSession(['restraint_confirmation' => [...$receipt, 'idempotency_key' => 'urn:uuid:'.$receipt['idempotency_key']]])
            ->getJson(route('health-safety.restraints.events.show', $row))->assertOk()->assertJsonPath('restraint_confirmation', null);
        $this->withSession(['restraint_confirmation' => $receipt]);
        $this->revoke($this->actor, 'restraints.create');
        $this->revoke($this->actor, 'restraints.manage');
        $this->getJson(route('health-safety.restraints.events.show', $row))->assertOk()->assertJsonPath('restraint_confirmation', null);
    }

    public function test_receipt_reconstruction_requires_exact_audit_identity_and_source_version(): void
    {
        $accepted = $this->service()->create($this->actor, $this->capture());
        $row = RestraintEvent::findOrFail($accepted['event_id']);
        $receipt = RestraintCommandReceipt::where('idempotency_key', $accepted['idempotency_key'])->sole();
        $audit = AuditLog::findOrFail($receipt->audit_log_id);
        $original = $audit->getRawOriginal();
        $other = $this->staff(['restraints.view', 'clients.viewAny']);
        foreach ([['user_id' => $other->id], ['auditable_type' => Client::class], ['auditable_id' => $row->id + 1000],
            ['action' => 'restraints.event.review'], ['meta' => json_encode([...$audit->meta, 'idempotency_key' => (string) Str::uuid()])],
            ['meta' => json_encode([...$audit->meta, 'snapshot' => [...$audit->meta['snapshot'], 'lock_version' => 99]])]] as $mutation) {
            DB::table('audit_logs')->where('id', $audit->id)->update($mutation);
            $this->assertNull($this->service()->confirmationForViewer($this->actor, $accepted));
            DB::table('audit_logs')->where('id', $audit->id)->update($original);
        }
        DB::table('restraint_command_receipts')->where('id', $receipt->id)->update(['audit_log_id' => null]);
        $this->assertNull($this->service()->confirmationForViewer($this->actor, $accepted));
        DB::table('restraint_command_receipts')->where('id', $receipt->id)->update(['audit_log_id' => $audit->id]);
        $this->assertSame($accepted['event_id'], $this->service()->confirmationForViewer($this->actor, $accepted)['event_id']);
        $this->actingAs($this->actor)->withSession(['restraint_confirmation' => $accepted])->getJson(route('health-safety.restraints.events.show', $row))
            ->assertOk()->assertJsonPath('restraint_confirmation.accepted', true);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    public function test_saved_receipt_identity_is_validated_and_recovered_dto_is_a_whitelist(): void
    {
        $accepted = $this->service()->create($this->actor, $this->capture());
        $receipt = RestraintCommandReceipt::where('idempotency_key', $accepted['idempotency_key'])->sole();
        foreach ([['event_id' => $accepted['event_id'] + 1], ['command' => 'review'], ['idempotency_key' => (string) Str::uuid()],
            ['accepted' => false], ['version' => '1'], ['source_revision' => 99], ['recorded_at' => '2026-99-99T00:00:00+00:00']] as $mutation) {
            DB::table('restraint_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode([...$receipt->accepted_result, ...$mutation])]);
            $this->assertNull($this->service()->confirmationForViewer($this->actor, $accepted));
        }
        DB::table('restraint_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode([...$receipt->accepted_result, 'restraint_description' => 'Injected private narrative', 'replayed' => true, 'unknown_actor' => 999])]);
        $recovered = $this->service()->confirmationForViewer($this->actor, [...$accepted, 'idempotency_key' => strtoupper($accepted['idempotency_key'])]);
        $this->assertTrue($recovered['accepted']);
        $this->assertSame($accepted['idempotency_key'], $recovered['idempotency_key']);
        $this->assertFalse($recovered['replayed']);
        $this->assertArrayNotHasKey('restraint_description', $recovered);
        $this->assertArrayNotHasKey('unknown_actor', $recovered);
        DB::table('restraint_command_receipts')->where('id', $receipt->id)->update(['expected_version' => 0]);
        $this->assertNull($this->service()->confirmationForViewer($this->actor, $accepted));
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    public function test_command_replay_stale_validation_and_current_return_context_preserve_saved_truth(): void
    {
        $row = $this->event();
        $input = $this->command($row, ['person_response' => 'Actual later response', 'reason' => 'Later factual discovery']);
        $this->actingAs($this->actor)->postJson(route('health-safety.restraints.events.correct', $row), [...$input, 'return_to' => '/tasks?domain=health_safety', 'section' => 'review', 'action' => 'correct'])
            ->assertOk()->assertJsonPath('restraint_confirmation.version', 2)->assertJsonPath('restraint_confirmation.replayed', false);
        $this->postJson(route('health-safety.restraints.events.correct', $row), $input)->assertOk()
            ->assertJsonPath('restraint_confirmation.replayed', true)->assertJsonPath('restraint_confirmation.version', 2);
        $stale = [...$input, 'idempotency_key' => (string) Str::uuid(), 'person_response' => 'Retained unsaved entry'];
        $this->postJson(route('health-safety.restraints.events.correct', $row), $stale)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->from(route('health-safety.restraints.events.show', $row))->post(route('health-safety.restraints.events.correct', $row), $stale)
            ->assertRedirect()->assertSessionHasErrors('expected_version')->assertSessionHasInput('person_response', 'Retained unsaved entry');
        $this->assertSame('Actual later response', $row->fresh()->person_response);
        $this->assertSame(2, RestraintCommandReceipt::where('source_type', 'event')->count());
    }

    public function test_valid_uppercase_uuid_capture_and_exact_retry_have_one_canonical_receipt_and_no_narrative_flash(): void
    {
        $capturer = $this->staff(['restraints.create', 'clients.viewAny']);
        $input = $this->capture(['idempotency_key' => strtoupper((string) Str::uuid())]);
        $first = $this->actingAs($capturer)->postJson(route('health-safety.restraints.events.store'), $input);
        $first->assertOk()->assertJsonPath('restraint_confirmation.idempotency_key', strtolower($input['idempotency_key']));
        $this->postJson(route('health-safety.restraints.events.store'), $input)->assertOk()->assertJsonPath('restraint_confirmation.replayed', true);
        $this->assertSame(1, RestraintCommandReceipt::where('source_type', 'event')->count());
        $this->post(route('health-safety.restraints.events.store'), [...$input, 'return_to' => '/tasks?domain=health_safety'])
            ->assertRedirect()->assertSessionHas('restraint_confirmation', fn ($receipt) => $receipt['replayed'] === true && ! array_key_exists('restraint_description', $receipt));
    }

    public function test_foreign_child_query_correction_and_private_download_deny_before_any_child_disclosure_or_mutation(): void
    {
        $row = $this->event();
        $other = $this->event();
        $observation = $this->service()->addObservation($other, $this->actor, $this->command($other, ['condition' => 'Other private condition', 'observed_at' => now()->subMinute()->toIso8601String()]));
        $attachment = RestraintEventAttachment::create(['restraint_event_id' => $other->id, 'disk' => 'private', 'path' => 'restraint_attachments/missing', 'original_name' => 'Other private proof']);
        $this->actingAs($this->actor)->getJson(route('health-safety.restraints.events.show', $row).'?observation='.$observation['observation_id'])->assertNotFound();
        $this->postJson(route('health-safety.restraints.events.observations.correct', [$row, $observation['observation_id']]), $this->command($row, ['condition' => 'Forged correction', 'observed_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Wrong source']))->assertNotFound();
        $this->get(route('health-safety.restraints.events.attachments.download', [$row, $attachment]))->assertNotFound();
        $this->assertSame(1, $row->fresh()->lock_version);
        $this->assertDatabaseCount('restraint_monitoring_observations', 1);
    }

    public function test_reused_loaded_http_actor_and_current_client_hr_site_revocation_cannot_return_source_json(): void
    {
        $row = $this->event();
        $viewer = $this->staff(['restraints.view', 'clients.viewAssigned']);
        $this->client->supportWorkers()->attach($viewer->id);
        $viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->actingAs($viewer)->getJson(route('health-safety.restraints.events.show', $row))->assertOk();
        $this->client->supportWorkers()->detach($viewer->id);
        $this->getJson(route('health-safety.restraints.events.show', $row))->assertForbidden();
        $this->client->supportWorkers()->attach($viewer->id);
        $viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->getJson(route('health-safety.restraints.events.show', $row))->assertForbidden();
        $viewer->hrEmployeeProfile()->update(['is_active' => true]);
        $this->revoke($viewer, 'restraints.view');
        $this->getJson(route('health-safety.restraints.events.show', $row))->assertForbidden();
        $this->actingAs($this->actor);
        $this->site->update(['is_active' => false]);
        $this->getJson(route('health-safety.restraints.events.show', $row))->assertForbidden();
        $this->assertSame(1, RestraintCommandReceipt::where('source_type', 'event')->count());
    }

    public function test_legacy_null_site_global_source_reader_retains_safe_uncertainty_instead_of_false_missing_record(): void
    {
        $legacy = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'created_by' => $this->actor->id]));
        $legacy->updateQuietly(['site_id' => null]);
        $global = $this->staff(['restraints.view', 'clients.viewAny', 'healthSafety.viewAllSites']);
        $this->actingAs($global)->getJson(route('health-safety.restraints.events.show', $legacy))->assertOk()
            ->assertJsonPath('detail.event.site', null)->assertJsonPath('detail.event.source_provenance', 'legacy_uncertainty');
    }

    private function stay(): RespiteStay
    {
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);

        return RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $this->client->id, 'status' => 'active', 'actual_start' => now()->subDay()]);
    }

    public function test_respite_capture_uses_uuid_offset_commands_and_emits_existing_downstream_event_once_not_on_replay(): void
    {
        Event::fake([RespiteEvent::class]);
        $stay = $this->stay();
        $coordinator = $this->staff(['respite.stays.manage', 'respite.viewAny', 'clients.viewAny']);
        $input = Arr::except($this->capture(), 'client_id');
        $this->actingAs($coordinator)->from('/respite/stays/'.$stay->id)->post(route('respite.stays.restraints.store', $stay), $input)
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('restraint_confirmation', fn ($receipt) => $receipt['capture_entry'] === 'respite' && $receipt['accepted'] === true && ! array_key_exists('restraint_description', $receipt));
        $row = RestraintEvent::sole();
        $this->assertSame($stay->id, $row->stay_id);
        $this->assertSame($this->client->id, $row->recorded_client_id);
        $this->assertSame($this->site->id, $row->recorded_site_id);
        $this->assertSame($stay->id, $row->recorded_stay_id);
        $this->post(route('respite.stays.restraints.store', $stay), $input)->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('restraint_confirmation', fn ($receipt) => $receipt['replayed'] === true);
        Event::assertDispatchedTimes(RespiteEvent::class, 1);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
        $this->post(route('respite.stays.restraints.store', $stay), [...$input, 'idempotency_key' => (string) Str::uuid(), 'started_at' => '2026-10-10T12:00:00'])
            ->assertSessionHasErrors('started_at');
        $this->revoke($coordinator, 'respite.stays.manage');
        $this->post(route('respite.stays.restraints.store', $stay), $input)->assertForbidden();
        Event::assertDispatchedTimes(RespiteEvent::class, 1);
    }

    public function test_respite_capture_cannot_silently_override_submitted_client_stay_or_foreign_approved_source_context(): void
    {
        Event::fake([RespiteEvent::class]);
        $stay = $this->stay();
        $coordinator = $this->staff(['respite.stays.manage', 'respite.viewAny', 'clients.viewAny']);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $this->actingAs($coordinator)->postJson(route('respite.stays.restraints.store', $stay), $this->capture(['client_id' => $otherClient->id]))->assertNotFound();
        $this->postJson(route('respite.stays.restraints.store', $stay), $this->capture(['stay_id' => $stay->id + 1000]))->assertNotFound();
        $foreign = $this->staff(['respite.stays.manage', 'respite.viewAny', 'clients.viewAny'], Site::factory()->create(['is_active' => true]));
        $this->actingAs($foreign)->postJson(route('respite.stays.restraints.store', $stay), $this->capture())->assertNotFound();
        $this->assertDatabaseCount('restraint_events', 0);
        $this->assertDatabaseCount('restraint_command_receipts', 0);
        Event::assertNotDispatched(RespiteEvent::class);
    }
}
