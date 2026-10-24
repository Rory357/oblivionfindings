<?php

namespace Tests\Feature\HealthSafety;

use App\Events\Respite\RespiteEvent;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintCommandReceipt;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\BehaviourSupportPlanService;
use App\Support\HealthSafety\RestraintReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real commands followed by report recovery; a receipt never grants source read or intervention authority. */
final class RestraintReportRecoveryHttpTest extends RestraintEventCommandTestCase
{
    public function test_lost_first_event_response_is_recovered_without_source_id_and_retry_does_not_duplicate(): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $input = $this->capture(['restraint_description' => 'Private lost-response narrative']);
        $saved = $this->service()->create($actor, $input);
        $this->actingAs($actor)->getJson($this->report($input['idempotency_key']))->assertOk()
            ->assertJsonPath('restraint_confirmation.event_id', $saved['event_id'])
            ->assertJsonPath('restraint_confirmation.command', 'create')->assertJsonPath('restraint_confirmation.version', 1)
            ->assertJsonPath('restraint_confirmation.idempotency_key', $input['idempotency_key'])
            ->assertJsonPath('behaviour_support_plan_confirmation', null)->assertJsonPath('restraint_href', null)->assertJsonPath('plan_href', null)
            ->assertJsonPath('can.capture', true)->assertJsonPath('can.create_plan', true)
            ->assertJsonMissingPath('restraint_confirmation.restraint_description');
        $this->postJson(route('health-safety.restraints.events.store'), $input)->assertOk()->assertJsonPath('restraint_confirmation.replayed', true);
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertSame(1, RestraintCommandReceipt::where('source_type', 'event')->count());
        $row = RestraintEvent::sole();
        $this->assertNull($row->behaviour_support_plan_version_id);
        // This Client has no plan at all, so the recorded state is 'missing' (never an approval).
        $this->assertSame('missing', $row->plan_authorization_state);
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
    }

    public function test_lost_first_plan_response_has_exact_minimal_receipt_without_plan_read_or_approval(): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $input = $this->planInput();
        $saved = app(BehaviourSupportPlanService::class)->create($actor, $input);
        $response = $this->actingAs($actor)->getJson($this->report($input['idempotency_key']))->assertOk()
            ->assertJsonPath('behaviour_support_plan_confirmation.plan_id', $saved['plan_id'])
            ->assertJsonPath('behaviour_support_plan_confirmation.command', 'create')
            ->assertJsonPath('behaviour_support_plan_confirmation.version', 1)
            ->assertJsonPath('restraint_confirmation', null)->assertJsonPath('plan_href', null)->assertJsonPath('restraint_href', null);
        $this->assertStringNotContainsString('Private new-plan narrative', $response->getContent());
        $this->postJson(route('health-safety.restraints.plans.store'), $input)->assertOk()->assertJsonPath('receipt.replayed', true);
        $this->assertSame(1, BehaviourSupportPlan::count());
        $this->assertSame(1, BehaviourSupportPlanVersion::count());
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertNull(BehaviourSupportPlan::sole()->current_published_version_id);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    #[DataProvider('sources')]
    public function test_key_lookup_is_actor_bound_even_when_the_other_actor_can_read_the_source(string $kind): void
    {
        $owner = $this->staff(['restraints.create', 'clients.viewAny']);
        $saved = $this->firstCreate($kind, $owner);
        $other = $this->staff(['restraints.create', 'restraints.view', 'clients.viewAny']);
        $this->actingAs($other)->getJson($this->report($saved['idempotency_key']))->assertOk()
            ->assertJsonPath('restraint_confirmation', null)->assertJsonPath('behaviour_support_plan_confirmation', null)
            ->assertJsonPath('restraint_href', null)->assertJsonPath('plan_href', null);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    #[DataProvider('sources')]
    public function test_uppercase_uuid_recovery_uses_the_canonical_key_and_never_exposes_it_in_links(string $kind): void
    {
        $actor = $this->staff(['restraints.create', 'restraints.view', 'clients.viewAny']);
        $saved = $this->firstCreate($kind, $actor);
        $prop = $kind === 'event' ? 'restraint_confirmation' : 'behaviour_support_plan_confirmation';
        $link = $kind === 'event' ? 'restraint_href' : 'plan_href';
        $json = $this->actingAs($actor)->getJson($this->report(strtoupper($saved['idempotency_key'])))->assertOk()
            ->assertJsonPath($prop.'.idempotency_key', strtolower($saved['idempotency_key']))->json();
        $this->assertNotNull($json[$link]);
        $this->assertStringNotContainsString($saved['idempotency_key'], $json[$link]);
        $this->assertStringNotContainsString('receipt_key', $json[$link]);
        $this->getJson($json[$link])->assertOk();
    }

    public function test_unknown_or_non_create_key_does_not_fall_back_to_a_session_claim(): void
    {
        $input = $this->capture();
        $created = $this->service()->create($this->actor, $input);
        $event = RestraintEvent::findOrFail($created['event_id']);
        $corrected = $this->service()->correct($event, $this->actor, $this->command($event, ['person_response' => 'Later recorded response', 'reason' => 'Actual later fact']));
        $this->actingAs($this->actor)->withSession(['restraint_confirmation' => $created]);
        foreach ([(string) Str::uuid(), $corrected['idempotency_key']] as $key) {
            $this->getJson($this->report($key))->assertOk()->assertJsonPath('restraint_confirmation', null)->assertJsonPath('behaviour_support_plan_confirmation', null)
                ->assertJsonPath('restraint_href', null)->assertJsonPath('plan_href', null);
        }
        $this->assertSame(2, $event->fresh()->lock_version);
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 2);
    }

    public function test_readable_recovery_links_require_independent_view_or_bounded_workspace_grants(): void
    {
        $actor = $this->staff(['restraints.create', 'restraints.review', 'clients.viewAny']);
        $event = $this->firstCreate('event', $actor);
        $plan = $this->firstCreate('plan', $actor);
        $this->actingAs($actor);
        $eventHref = $this->getJson($this->report($event['idempotency_key']))->assertOk()->json('restraint_href');
        $planHref = $this->getJson($this->report($plan['idempotency_key']))->assertOk()->json('plan_href');
        $this->assertStringContainsString('/events/'.$event['event_id'].'/review', $eventHref);
        $this->assertStringContainsString('/plans/'.$plan['plan_id'].'/review', $planHref);
        $this->getJson($eventHref)->assertOk();
        $this->getJson($planHref)->assertOk();
        $this->getJson(RestraintReturn::event($event['event_id']))->assertForbidden();
        $this->getJson(RestraintReturn::plan($plan['plan_id']))->assertForbidden();
        $this->revoke($actor, 'restraints.review');
        $this->getJson($this->report($event['idempotency_key']))->assertOk()->assertJsonPath('restraint_confirmation.accepted', true)->assertJsonPath('restraint_href', null);
        $this->getJson($this->report($plan['idempotency_key']))->assertOk()->assertJsonPath('behaviour_support_plan_confirmation.accepted', true)->assertJsonPath('plan_href', null);
    }

    #[DataProvider('sources')]
    public function test_fresh_client_read_withdrawal_retains_saved_rows_but_hides_recovery(string $kind): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $saved = $this->firstCreate($kind, $actor);
        $actor->load('roles.permissions', 'permissionOverrides');
        $this->revoke($actor, 'clients.viewAny');
        $this->actingAs($actor)->getJson($this->report($saved['idempotency_key']))->assertOk()
            ->assertJsonPath('restraint_confirmation', null)->assertJsonPath('behaviour_support_plan_confirmation', null)
            ->assertJsonPath('pickers.clients', []);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    #[DataProvider('sources')]
    public function test_source_site_withdrawal_cannot_recover_through_a_warm_actor(string $kind): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $saved = $this->firstCreate($kind, $actor);
        $actor->load('hrEmployeeProfile');
        $actor->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id, 'secondary_site_ids' => []]);
        $this->actingAs($actor)->getJson($this->report($saved['idempotency_key']))->assertOk()
            ->assertJsonPath('restraint_confirmation', null)->assertJsonPath('behaviour_support_plan_confirmation', null)
            ->assertJsonPath('pickers.clients', []);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    public function test_current_internal_hr_withdrawal_denies_report_before_receipt_disclosure(): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $saved = $this->firstCreate('event', $actor);
        $actor->load('hrEmployeeProfile', 'permissionOverrides');
        $actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->actingAs($actor)->getJson($this->report($saved['idempotency_key']))->assertForbidden();
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    #[DataProvider('sources')]
    public function test_exact_semantic_audit_is_required_to_reconstruct_saved_acceptance(string $kind): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $saved = $this->firstCreate($kind, $actor);
        $ledger = RestraintCommandReceipt::where('idempotency_key', $saved['idempotency_key'])->sole();
        DB::table('audit_logs')->where('id', $ledger->audit_log_id)->update(['action' => 'unrelated.source.command']);
        $this->actingAs($actor)->getJson($this->report($saved['idempotency_key']))->assertOk()
            ->assertJsonPath('restraint_confirmation', null)->assertJsonPath('behaviour_support_plan_confirmation', null)
            ->assertJsonPath('restraint_href', null)->assertJsonPath('plan_href', null);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    public function test_polluted_event_receipt_cannot_publish_foreign_identity_or_private_narrative(): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $saved = $this->firstCreate('event', $actor);
        $ledger = RestraintCommandReceipt::where('idempotency_key', $saved['idempotency_key'])->sole();
        DB::table('restraint_command_receipts')->where('id', $ledger->id)->update(['accepted_result' => json_encode([...$ledger->accepted_result, 'restraint_description' => 'Polluted private narrative', 'actor_snapshot' => ['email' => 'hidden@example.test']])]);
        $response = $this->actingAs($actor)->getJson($this->report($saved['idempotency_key']))->assertOk()->assertJsonPath('restraint_confirmation.event_id', $saved['event_id']);
        $this->assertStringNotContainsString('Polluted private narrative', $response->getContent());
        $this->assertStringNotContainsString('hidden@example.test', $response->getContent());
        DB::table('restraint_command_receipts')->where('id', $ledger->id)->update(['accepted_result' => json_encode([...$ledger->accepted_result, 'event_id' => $saved['event_id'] + 100000])]);
        $this->getJson($this->report($saved['idempotency_key']))->assertOk()->assertJsonPath('restraint_confirmation', null);
    }

    public function test_report_recovery_does_not_turn_saved_receipt_into_any_new_grant(): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $event = $this->firstCreate('event', $actor);
        $plan = $this->firstCreate('plan', $actor);
        $this->actingAs($actor)->getJson($this->report($event['idempotency_key']))->assertOk();
        $this->getJson($this->report($plan['idempotency_key']))->assertOk();
        foreach ([route('health-safety.restraints.index'), RestraintReturn::event($event['event_id']), RestraintReturn::event($event['event_id']).'/review', RestraintReturn::plan($plan['plan_id']), RestraintReturn::plan($plan['plan_id']).'/review', RestraintReturn::plan($plan['plan_id']).'/approval'] as $href) {
            $this->getJson($href)->assertForbidden();
        }
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertNull(BehaviourSupportPlan::sole()->current_published_version_id);
        $this->assertNull(RestraintEvent::sole()->behaviour_support_plan_version_id);
        $this->assertFalse($actor->fresh()->canDo('restraints.approve'));
        $this->assertFalse($actor->fresh()->canDo('healthSafety.settings.manage'));
    }

    public function test_html_and_inertia_recovery_queries_are_rejected_without_serialising_an_inertia_page(): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $saved = $this->firstCreate('event', $actor);
        $href = $this->report($saved['idempotency_key']);
        $this->actingAs($actor)->get($href)->assertNotFound();
        $this->withHeaders(['X-Inertia' => 'true'])->get($href)->assertNotFound();
        $this->flushHeaders();
        $this->get(route('health-safety.restraints.report'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/restraints/events/report')->where('restraint_confirmation', null));
    }

    /*
     * Decision 7 (Stephan, 25 Oct): a respite stay records through main's respite form for this merge,
     * and Codex's stay report page (`/respite/stays/{stay}/restraints/report`) is unrouted until that
     * follow-up, because its receipt recovery cannot find a submission of main's form.
     */
    public function test_respite_only_actor_records_through_main_form_and_has_no_shared_capture_or_stay_report(): void
    {
        Event::fake([RespiteEvent::class]);
        $stay = $this->stay();
        $actor = $this->staff(['respite.viewAny', 'respite.stays.manage', 'clients.viewAny']);
        $this->actingAs($actor)->getJson('/respite/stays/'.$stay->id.'/restraints/report')->assertNotFound();
        $input = $this->capture();
        $this->postJson(route('health-safety.restraints.events.store'), $input)->assertForbidden();
        $this->postJson(route('health-safety.restraints.plans.store'), $this->planInput())->assertForbidden();
        $this->getJson($this->report($input['idempotency_key']))->assertForbidden();
        $this->post(route('respite.stays.restraints.store', $stay), $input)->assertRedirect()->assertSessionHasNoErrors();
        $row = RestraintEvent::sole();
        $this->assertSame($stay->id, $row->stay_id);
        $this->assertSame($this->client->id, $row->client_id);
        $this->assertSame($this->site->id, $row->site_id);
        $this->assertDatabaseCount('restraint_command_receipts', 0);
        $this->getJson(route('health-safety.restraints.index'))->assertForbidden();
    }

    public function test_respite_permission_cannot_recover_a_former_shared_create_after_original_grant_withdrawal(): void
    {
        $stay = $this->stay();
        $actor = $this->staff(['restraints.create', 'respite.viewAny', 'respite.stays.manage', 'clients.viewAny']);
        $input = $this->capture(['stay_id' => $stay->id, 'site_id' => $this->site->id]);
        $saved = $this->service()->create($actor, $input);
        $this->revoke($actor, 'restraints.create');
        // The generic report is the only recovery entry now; the respite grant does not reopen it.
        $this->actingAs($actor->fresh())->getJson($this->report($saved['idempotency_key']))->assertForbidden();
        $this->postJson(route('health-safety.restraints.events.store'), $input)->assertForbidden();
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    #[DataProvider('sources')]
    public function test_missing_retained_audit_identity_cannot_be_recovered_as_acceptance(string $kind): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $saved = $this->firstCreate($kind, $actor);
        $ledger = RestraintCommandReceipt::where('idempotency_key', $saved['idempotency_key'])->sole();
        // audit_log_id is NOT NULL with a restrict-on-delete key (Codex's schema too), so a lost
        // audit identity is simulated by a receipt that points at an unrelated audit row.
        $unrelated = DB::table('audit_logs')->insertGetId(['action' => 'unrelated.audit', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('restraint_command_receipts')->where('id', $ledger->id)->update(['audit_log_id' => $unrelated]);
        $this->actingAs($actor)->getJson($this->report($saved['idempotency_key']))->assertOk()
            ->assertJsonPath('restraint_confirmation', null)->assertJsonPath('behaviour_support_plan_confirmation', null);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    public function test_stay_bound_plan_create_records_its_exact_canonical_stay_without_approval(): void
    {
        $stay = $this->stay();
        $actor = $this->staff(['restraints.create', 'respite.viewAny', 'respite.stays.manage', 'clients.viewAny']);
        $input = [...$this->planInput(), 'site_id' => $this->site->id, 'stay_id' => $stay->id];
        app(BehaviourSupportPlanService::class)->create($actor, $input);
        $plan = BehaviourSupportPlan::sole();
        $this->assertSame($stay->id, $plan->source_stay_id);
        $this->assertSame($this->client->id, $plan->client_id);
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
        $this->assertNull($plan->current_published_version_id);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }

    public function test_malformed_receipt_keys_are_rejected_before_lookup_or_saved_source_acceptance(): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $this->actingAs($actor);
        foreach (['unknown', 'urn:uuid:'.Str::uuid(), ['nested' => (string) Str::uuid()], 42] as $key) {
            $this->getJson(route('health-safety.restraints.report', ['receipt_key' => $key]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('restraint_events', 0);
        $this->assertSame(0, BehaviourSupportPlan::count());
        $this->assertDatabaseCount('restraint_command_receipts', 0);
    }

    public function test_generic_report_rejects_stay_context_instead_of_silently_dropping_provenance(): void
    {
        $stay = $this->stay();
        $actor = $this->staff(['restraints.create', 'respite.viewAny', 'respite.stays.manage', 'clients.viewAny']);
        $this->actingAs($actor);
        foreach (['stay_id', 'respite_stay_id'] as $key) {
            $this->getJson(route('health-safety.restraints.report', [$key => $stay->id]))->assertUnprocessable();
        }
        // Codex's stay report page is unrouted for this merge (decision 7 follow-up).
        $this->getJson('/respite/stays/'.$stay->id.'/restraints/report?respite_stay_id='.$stay->id)->assertNotFound();
        $this->assertDatabaseCount('restraint_events', 0);
        $this->assertDatabaseCount('restraint_command_receipts', 0);
    }

    public static function sources(): array
    {
        return [['event'], ['plan']];
    }

    private function firstCreate(string $kind, User $actor): array
    {
        return $kind === 'event' ? $this->service()->create($actor, $this->capture()) : app(BehaviourSupportPlanService::class)->create($actor, $this->planInput());
    }

    private function planInput(): array
    {
        return ['client_id' => $this->client->id, 'title' => 'Recorded plan', 'notes' => 'Private new-plan narrative', 'idempotency_key' => (string) Str::uuid()];
    }

    private function report(string $key, array $query = []): string
    {
        return route('health-safety.restraints.report', [...$query, 'receipt_key' => $key]);
    }

    private function stay(): RespiteStay
    {
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);

        return RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $this->client->id, 'status' => 'active', 'actual_start' => now()->subDay(), 'created_by' => $this->actor->id]);
    }
}
