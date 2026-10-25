<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\HsEvent;
use Illuminate\Support\Facades\DB;

/** Whole actual-route suite prepared against the separately UNAPPLIED controller/routes. No local route registration or privacy mock. */
final class ChemicalExposureDeliveryWorkspaceHttpTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    private function pending(): array
    {
        $root = $this->exposure(['symptoms' => 'PRIVATE-SYMPTOMS']);
        return [$root, ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->sole()];
    }
    private function url($root, $row = null): string
    {
        return '/health-safety/chemical-exposures/'.$root->id.'/deliveries'.($row ? '/'.$row->id : '');
    }
    private function headers($response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }
    public function test_actual_get_json_list_and_detail_are_private_read_only_projections(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->actingAs($this->manager);
        $list = $this->getJson($this->url($root))->assertOk()->assertJsonPath('page.total', 1);
        $detail = $this->getJson($this->url($root, $row))->assertOk()->assertJsonPath('inspection.destination.state', 'unconfirmed');
        $this->headers($list); $this->headers($detail);
        foreach (['PRIVATE-', 'membership', 'source_version_id', 'original_context', 'idempotency_key', 'recipient_id'] as $private) {
            $this->assertStringNotContainsString($private, $list->getContent()); $this->assertStringNotContainsString($private, $detail->getContent());
        }
        $this->assertSame(0, $row->fresh()->attempts); $this->assertSame('pending', $row->fresh()->state);
    }
    public function test_html_inertia_and_partial_requests_cannot_turn_recovery_api_into_private_page_props(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->actingAs($this->manager);
        foreach ([$this->url($root), $this->url($root, $row)] as $url) {
            $this->headers($this->get($url)->assertStatus(406));
            $this->headers($this->get($url, ['Accept' => 'application/json','X-Inertia' => 'true'])->assertStatus(406));
            $this->headers($this->get($url, ['Accept' => 'application/json','X-Inertia' => 'true','X-Inertia-Partial-Data' => 'inspection'])->assertStatus(406));
        }
        $this->assertSame(0, $row->fresh()->attempts);
    }
    public function test_actual_post_retry_returns_reproved_concrete_hs_confirmation_without_new_source_receipt(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending();
        $r = $this->actingAs($this->manager)->postJson($this->url($root, $row).'/retry')->assertOk()
            ->assertJsonPath('inspection.destination.state', 'hs_event_present')->assertJsonPath('retry_requested_by', $this->manager->id);
        $this->headers($r); $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(1, $row->fresh()->attempts);
        $this->assertStringNotContainsString('idempotency_key', $r->getContent());
    }
    public function test_retry_is_post_only_and_never_dispatched_by_an_inspection_get(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->actingAs($this->manager)->getJson($this->url($root, $row).'/retry')->assertStatus(405);
        $this->getJson($this->url($root, $row))->assertOk();
        $this->assertSame(0, $row->fresh()->attempts); $this->assertSame(0, HsEvent::where('source_type', $root::class)->where('source_id', $root->id)->count());
    }
    public function test_wrong_same_site_route_root_delivery_pair_is_concealed_for_get_and_post(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); [$other, $foreign] = $this->pending(); $this->actingAs($this->manager);
        $this->headers($this->getJson($this->url($root, $foreign))->assertNotFound());
        $this->headers($this->postJson($this->url($root, $foreign).'/retry')->assertNotFound());
        $this->assertSame(0, $foreign->fresh()->attempts);
    }
    public function test_current_internal_staff_and_site_withdrawal_precede_attempt_body(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->actingAs($this->manager);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->headers($this->getJson($this->url($root, $row))->assertForbidden());
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true,'primary_site_id' => $this->foreignSite->id]);
        $this->headers($this->getJson($this->url($root, $row))->assertNotFound());
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->getJson($this->url($root, $row))->assertOk();
    }
    public function test_fully_warmed_current_private_grant_revoke_and_restore_are_decisive_in_actual_http(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->manager->load(['roles.permissions','permissionOverrides']); $this->actingAs($this->manager);
        $this->allow($this->manager, 'hr.wellbeing.view', false); $this->getJson($this->url($root, $row))->assertForbidden();
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->getJson($this->url($root, $row))->assertOk();
        $this->allow($this->manager, 'hazards.manage', false); $this->postJson($this->url($root, $row).'/retry')->assertForbidden();
        $this->allow($this->manager, 'hazards.manage'); $this->postJson($this->url($root, $row).'/retry')->assertOk();
    }
    public function test_generic_safety_reader_has_no_self_grant_to_identifiable_saved_delivery(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->allow($this->worker, 'hazards.view'); $this->allow($this->worker, 'hr.wellbeing.view', false);
        $this->actingAs($this->worker)->getJson($this->url($root))->assertForbidden();
        $this->getJson($this->url($root, $row))->assertForbidden(); $this->assertSame(0, $row->fresh()->attempts);
    }
    public function test_retry_rejects_new_uuid_callback_prose_and_file_input_instead_of_ignoring_command_intent(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->actingAs($this->manager);
        foreach ([['idempotency_key' => 'unknown-new-identity'], ['callback' => 'automatic_delivery'], ['reason' => 'PRIVATE-PROSE']] as $input) {
            $this->headers($this->postJson($this->url($root, $row).'/retry', $input)->assertStatus(422));
        }
        $this->assertSame(0, $row->fresh()->attempts);
    }
    public function test_actual_uploaded_file_is_rejected_before_recovery_dispatch_or_staging(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->actingAs($this->manager);
        $this->headers($this->post($this->url($root, $row).'/retry',
            ['evidence' => \Illuminate\Http\UploadedFile::fake()->create('observed.txt', 1)], ['Accept' => 'application/json'])->assertStatus(422));
        $this->assertSame(0, $row->fresh()->attempts);
    }
    public function test_unknown_query_selectors_and_invalid_page_are_rejected_without_exposing_rows(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->actingAs($this->manager);
        $this->getJson($this->url($root).'?worker_id='.$this->worker->id)->assertStatus(400);
        $this->getJson($this->url($root).'?page=0')->assertUnprocessable();
        $this->getJson($this->url($root, $row).'?idempotency_key=unknown')->assertStatus(400);
    }
    public function test_actual_delivered_flag_does_not_fabricate_a_destination_href_in_http(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); DB::table('chemical_exposure_source_deliveries')->where('id', $row->id)->update(['state' => 'delivered']);
        $this->actingAs($this->manager)->getJson($this->url($root, $row))->assertOk()
            ->assertJsonPath('inspection.attempt_state', 'delivered')->assertJsonPath('inspection.destination.state', 'unconfirmed')
            ->assertJsonPath('inspection.destination.href', null);
    }
    public function test_genuine_superseded_source_is_reported_without_sending_the_old_attempt(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s delivery inspection and retry routes (/health-safety/chemical-exposures/{id}/deliveries, …/{delivery} and …/{delivery}/retry) are not in routes/health-safety.php, so every call is 404. Today the record page only lists the recorded delivery attempts (GET /{id}/delivery-attempts) and nobody can retry a delivery.', 'Job 3: route the delivery inspection and retry endpoints with the pipeline.'));
        [$root, $row] = $this->pending(); $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Actual source update', 'circumstances' => 'Later current fact']));
        $this->actingAs($this->manager)->getJson($this->url($root, $row))->assertOk()->assertJsonPath('inspection.currency', 'superseded');
        $this->postJson($this->url($root, $row).'/retry')->assertUnprocessable(); $this->assertSame(0, $row->fresh()->attempts);
    }
}
