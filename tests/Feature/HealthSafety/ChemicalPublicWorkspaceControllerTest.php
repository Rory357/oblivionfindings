<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalEvidenceStaging;
use App\Models\SafetyDataSheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Whole public HTTP contract for the isolated source composition; private HTTP remains unmounted.
 * No substitute test routes or mocked source authority. Runtime/integration UNRUN.
 * Physical SDS commands require committed fixtures, not an ambient transaction.
 */
final class ChemicalPublicWorkspaceControllerTest extends ChemicalCommandTestCase
{
    protected bool $physicalCommits = true;
    private const BASE = '/health-safety/substances';
    private const RECEIPT = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id',
        'source_type', 'source_id', 'parent_id', 'expected_version', 'parent_expected_version', 'version', 'source_version_id', 'changed'];

    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }

    private function receipt($response): array
    {
        $response->assertOk()->assertJsonStructure(['chemical_confirmation', 'chemical_href']);
        $receipt = $response->json('chemical_confirmation');
        $this->assertTrue($receipt['accepted']);
        $keys = array_keys($receipt); $expected = self::RECEIPT; sort($keys); sort($expected);
        $this->assertSame($expected, $keys);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        return $receipt;
    }
    private function file(): UploadedFile { return UploadedFile::fake()->create('recorded-sds.pdf', 4, 'application/pdf'); }
    private function privateHeaders($response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }
    private function upload($root, array $facts = []): array
    {
        return $this->service()->uploadSds($root, $this->manager, $this->file(),
            $this->command($root, ['version' => 'Provided SDS', 'issue_date' => '2026-10-01', 'review_date' => '2027-10-01', ...$facts]));
    }
    private function noPrivateSql(): void
    {
        foreach (DB::getQueryLog() as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringNotContainsString('chemical_exposure', $sql);
            $this->assertStringNotContainsString('substance_exposure_records', $sql);
            $this->assertStringNotContainsString('workplace_injury', $sql);
        }
    }

    public function test_canonical_register_preserves_filters_truthful_counts_and_never_discovers_private_exposures(): void
    {
        $root = $this->catalogue(['name' => 'Recorded public reference']);
        $this->storage($root);
        DB::enableQueryLog(); DB::flushQueryLog();
        $response = $this->actingAs($this->manager)->getJson(self::BASE.'?tab=active&q=Recorded&site_id='.$this->site->id.'&period=30');
        $response->assertOk()->assertJsonPath('filters.tab', 'active')->assertJsonPath('filters.period', 30)
            ->assertJsonPath('rows.data.0.id', $root->id)->assertJsonPath('rows.data.0.source_integrity', 'valid')
            ->assertJsonPath('rows.data.0.storage_count', 1)->assertJsonPath('detail', null)
            ->assertJsonPath('hero.attention.exposures', null)->assertJsonPath('exposure_access', 'restricted');
        $this->noPrivateSql();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_actual_inertia_index_and_record_components_receive_canonical_protected_shapes(): void
    {
        $root = $this->catalogue();
        $this->actingAs($this->manager)->get(self::BASE)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/substances/index')->where('detail', null)->where('exposure_access', 'restricted'));
        $this->get(self::BASE.'/'.$root->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/substances/show')->where('detail.id', $root->id)
            ->where('detail.name', 'Recorded disinfectant')->where('detail.exposure_records', null)->where('detail.counts.exposures', null));
    }

    public function test_x_inertia_takes_precedence_over_json_accept_on_all_public_page_reads(): void
    {
        $root = $this->catalogue(); $this->actingAs($this->manager);
        foreach ([self::BASE => 'index', self::BASE.'/'.$root->id => 'show', self::BASE.'/create' => 'create'] as $url => $leaf) {
            $initial = $this->get($url)->assertOk();
            $version = $initial->viewData('page')['version'] ?? '';
            $response = $this->get($url, ['X-Inertia' => 'true', 'Accept' => 'application/json', 'X-Inertia-Version' => $version]);
            $response->assertOk()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'health-safety/substances/'.$leaf)
                ->assertJsonStructure(['component', 'props', 'url', 'version']);
            $this->privateHeaders($response);
            if ($leaf === 'show') { $response->assertJsonPath('props.detail.id', $root->id)->assertJsonMissingPath('detail'); }
            if ($leaf === 'create') { $response->assertJsonPath('props.capture.can.create', true)->assertJsonMissingPath('capture'); }
            $json = $this->getJson($url)->assertOk()->assertJsonMissingPath('component')->assertJsonMissingPath('props');
            $this->privateHeaders($json);
        }
    }

    public function test_permission_subgroup_denial_retains_actual_403_and_receives_route_private_headers(): void
    {
        $root = $this->catalogue(); $denied = $this->staff([]);
        $response = $this->actingAs($denied)->getJson(self::BASE.'/'.$root->id);
        $response->assertForbidden()->assertJsonMissingPath('chemical_confirmation')->assertJsonMissingPath('detail');
        $this->privateHeaders($response);
    }

    public function test_canonical_site_tuple_and_missing_source_denials_retain_404_and_private_headers(): void
    {
        $root = $this->catalogue(); $location = $this->storage($root);
        $foreign = $this->staff(['hazards.view'], $this->foreignSite);
        $response = $this->actingAs($foreign)->getJson(self::BASE.'/'.$root->id.'/history?source_type=storage&source_id='.$location->id);
        $response->assertNotFound()->assertJsonMissingPath('history'); $this->privateHeaders($response);
        $missing = $this->getJson(self::BASE.'/'.((int) \App\Models\HazardousSubstance::max('id') + 1000));
        $missing->assertNotFound()->assertJsonMissingPath('detail'); $this->privateHeaders($missing);
    }

    public function test_actual_domain_validation_keeps_422_field_errors_with_route_private_headers(): void
    {
        $root = $this->catalogue();
        $response = $this->actingAs($this->manager)->patchJson(self::BASE.'/'.$root->id.'/status', $this->command($root, ['status' => 'inactive']));
        $response->assertUnprocessable()->assertJsonValidationErrors('reason')->assertJsonMissingPath('chemical_confirmation');
        $this->privateHeaders($response);
        $this->assertSame('active', $root->fresh()->status);
    }

    public function test_legacy_modal_bookmark_converges_with_exact_safe_filtered_register_back(): void
    {
        $root = $this->catalogue();
        $back = self::BASE.'?tab=active&q=Recorded&site_id='.$this->site->id.'&period=30&page=2';
        $response = $this->actingAs($this->manager)->get($back.'&substance='.$root->id.'&action=edit&receipt_key=private');
        $response->assertRedirect(self::BASE.'/'.$root->id.'?return_to='.rawurlencode($back));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_capture_only_page_and_options_have_no_existing_source_fields_counts_or_private_sql(): void
    {
        $this->catalogue(['handling_precautions' => 'Existing public record must not be discovered by capture picker']);
        $capturer = $this->staff(['hazards.create']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->actingAs($capturer)->get(self::BASE.'/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/substances/create')->where('capture.can.create', true)->where('chemical_confirmation', null)
            ->where('chemical_href', null)->missing('detail')->missing('capture.rows')->missing('capture.exposure_records'));
        $this->getJson(self::BASE.'/capture-options')->assertOk()->assertJsonPath('can.create', true)
            ->assertJsonPath('sites.0.id', $this->site->id)->assertJsonPath('options.sds_max_kb', 10240)->assertJsonMissingPath('detail');
        foreach (DB::getQueryLog() as $query) { $this->assertStringNotContainsString('hazardous_substances', strtolower($query['query'])); }
        $this->noPrivateSql();
    }

    public function test_create_only_acceptance_exact_replay_and_recovery_do_not_grant_enduring_read_or_echo_input(): void
    {
        $capturer = $this->staff(['hazards.create']); $key = (string) Str::uuid();
        $input = ['idempotency_key' => $key, 'name' => 'New reported reference', 'physical_form' => 'liquid',
            'first_aid_measures' => 'Recorded supplied reference text'];
        $this->actingAs($capturer);
        $first = $this->postJson(self::BASE, $input); $receipt = $this->receipt($first);
        $first->assertJsonPath('chemical_href', null)->assertJsonMissingPath('detail');
        $this->assertStringNotContainsString('Recorded supplied reference text', $first->getContent());
        $this->assertSame($receipt, $this->receipt($this->postJson(self::BASE, $input)));
        $recovered = $this->getJson(self::BASE.'/'.$receipt['root_id'].'/confirmations/'.strtoupper($key));
        $this->assertSame($receipt, $this->receipt($recovered)); $recovered->assertJsonPath('chemical_href', null);
        $this->getJson(self::BASE.'/'.$receipt['root_id'])->assertForbidden();
        $this->getJson(self::BASE)->assertForbidden();
        $this->assertSame(1, ChemicalCommandReceipt::where('idempotency_key', $key)->count());
    }

    public function test_html_acceptance_flashes_only_identity_hint_then_reconstructs_current_actor_receipt(): void
    {
        $capturer = $this->staff(['hazards.create']); $key = (string) Str::uuid();
        $response = $this->actingAs($capturer)->post(self::BASE, ['name' => 'New reference', 'physical_form' => 'solid', 'idempotency_key' => $key]);
        $id = ChemicalCommandReceipt::where('idempotency_key', $key)->sole()->source_id;
        $response->assertRedirect(self::BASE.'/create')->assertSessionHas('chemical_confirmation_hint',
            ['source_type' => 'catalogue', 'source_id' => $id, 'idempotency_key' => $key])->assertSessionMissing('chemical_confirmation');
        $this->get(self::BASE.'/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('chemical_confirmation.actor_id', $capturer->id)->where('chemical_confirmation.idempotency_key', $key)->where('chemical_href', null));
    }

    public function test_foreign_actor_or_private_family_session_hint_cannot_echo_saved_acceptance(): void
    {
        $root = $this->catalogue(); $receipt = ChemicalCommandReceipt::where('source_type', 'catalogue')->where('source_id', $root->id)->sole();
        $other = $this->staff(['hazards.create']);
        $this->actingAs($other)->withSession(['chemical_confirmation_hint' => ['source_type' => 'catalogue',
            'source_id' => $root->id, 'idempotency_key' => $receipt->idempotency_key, 'accepted' => true, 'name' => 'Forged private copy']])
            ->getJson(self::BASE.'/create')->assertOk()->assertJsonPath('chemical_confirmation', null)->assertJsonPath('chemical_href', null);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->withSession(['chemical_confirmation_hint' => ['source_type' => 'exposure', 'source_id' => 999, 'idempotency_key' => $receipt->idempotency_key]])
            ->getJson(self::BASE.'/create')->assertOk()->assertJsonPath('chemical_confirmation', null);
        $this->noPrivateSql();
    }

    public function test_current_capture_permission_revocation_hides_confirmation_and_restore_recovers_exact_original(): void
    {
        $capturer = $this->staff(['hazards.create']); $input = ['name' => 'New reference', 'physical_form' => 'solid', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($capturer); $receipt = $this->receipt($this->postJson(self::BASE, $input));
        $capturer->canDo('hazards.create'); $this->allow($capturer, 'hazards.create', false);
        $url = self::BASE.'/'.$receipt['root_id'].'/confirmations/'.$input['idempotency_key'];
        $this->getJson($url)->assertForbidden();
        $this->allow($capturer, 'hazards.create', true);
        $this->assertSame($receipt, $this->receipt($this->getJson($url)));
    }

    public function test_view_revoke_restore_is_fresh_and_catalogue_remains_independent_of_storage_site(): void
    {
        $root = $this->catalogue(); $this->storage($root);
        $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id)->assertOk()->assertJsonCount(1, 'detail.storage_locations');
        $this->manager->canDo('hazards.view'); $this->allow($this->manager, 'hazards.view', false);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->getJson(self::BASE.'/'.$root->id)->assertForbidden();
        foreach (DB::getQueryLog() as $query) { $this->assertStringNotContainsString('hazardous_substances', strtolower($query['query'])); }
        $this->allow($this->manager, 'hazards.view', true);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->getJson(self::BASE.'/'.$root->id)->assertOk()->assertJsonCount(0, 'detail.storage_locations');
        $this->getJson(self::BASE.'?site_id='.$this->site->id)->assertNotFound();
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->getJson(self::BASE.'/'.$root->id)->assertOk()->assertJsonCount(1, 'detail.storage_locations');
    }

    public function test_raw_malformed_route_ids_never_implicitly_hydrate_catalogue_or_sds(): void
    {
        $this->actingAs($this->manager); DB::enableQueryLog(); DB::flushQueryLog();
        foreach (['0', '-1', '01', '1e2', '9007199254740992', 'abc'] as $id) {
            $this->getJson(self::BASE.'/'.$id)->assertNotFound();
            $this->getJson(self::BASE.'/'.$id.'/sds/1/download')->assertNotFound();
        }
        foreach (DB::getQueryLog() as $query) {
            $sql = strtolower($query['query']); $this->assertStringNotContainsString('hazardous_substances', $sql);
            $this->assertStringNotContainsString('safety_data_sheets', $sql);
        }
    }

    public function test_unknown_domain_fields_are_not_silently_discarded_but_known_navigation_framing_is_stripped(): void
    {
        $input = ['name' => 'New reference', 'physical_form' => 'solid', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($this->manager)->postJson(self::BASE, [...$input, 'private_exposure_override' => true])->assertUnprocessable()
            ->assertJsonValidationErrors('command');
        $this->receipt($this->postJson(self::BASE, [...$input, '_token' => 'framing', '_method' => 'POST',
            'return_to' => self::BASE.'?tab=active&action=edit&receipt_key=secret']));
    }

    public function test_stale_update_returns_field_error_and_retains_original_uuid_without_mutation(): void
    {
        $root = $this->catalogue(); $draft = $this->command($root, ['name' => 'User draft']);
        $this->service()->update($root, $this->manager, $this->command($root, ['name' => 'Already updated']));
        $this->actingAs($this->manager)->putJson(self::BASE.'/'.$root->id, $draft)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->assertSame('Already updated', $root->fresh()->name);
        $this->assertFalse(ChemicalCommandReceipt::where('idempotency_key', $draft['idempotency_key'])->exists());
    }

    public function test_html_validation_preserves_reviewed_uuid_revision_and_user_draft_for_correction(): void
    {
        $root = $this->catalogue(); $draft = $this->command($root, ['name' => 'Retained user draft']);
        $this->service()->update($root, $this->manager, $this->command($root, ['name' => 'Current accepted name']));
        $url = self::BASE.'/'.$root->id;
        $response = $this->actingAs($this->manager)->from($url)->put($url, $draft);
        $response->assertRedirect($url)
            ->assertSessionHasErrors('expected_version')->assertSessionHasInput('name', 'Retained user draft')
            ->assertSessionHasInput('expected_version', 1)->assertSessionHasInput('idempotency_key', $draft['idempotency_key']);
        $this->privateHeaders($response);
        $this->assertSame('Current accepted name', $root->fresh()->name);
    }

    public function test_recovery_rechecks_original_manage_operation_even_when_capture_permission_remains(): void
    {
        $root = $this->catalogue(); $input = $this->command($root, ['name' => 'Recorded correction']);
        $receipt = $this->service()->update($root, $this->manager, $input);
        $this->allow($this->manager, 'hazards.create'); $this->allow($this->manager, 'hazards.manage', false);
        $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id.'/confirmations/'.$input['idempotency_key'])->assertForbidden();
        $this->getJson(self::BASE.'/'.$root->id)->assertOk();
        $this->allow($this->manager, 'hazards.manage');
        $this->assertSame($receipt, $this->receipt($this->getJson(self::BASE.'/'.$root->id.'/confirmations/'.$input['idempotency_key'])));
    }

    public function test_status_requires_reason_and_preserves_canonical_lifecycle_with_exact_retry(): void
    {
        $root = $this->catalogue(); $this->actingAs($this->manager);
        $this->patchJson(self::BASE.'/'.$root->id.'/status', $this->command($root, ['status' => 'inactive']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $input = $this->command($root, ['status' => 'inactive', 'reason' => 'No longer held']);
        $receipt = $this->receipt($this->patchJson(self::BASE.'/'.$root->id.'/status', $input));
        $this->assertSame($receipt, $this->receipt($this->patchJson(self::BASE.'/'.$root->id.'/status', $input)));
        $this->getJson(self::BASE.'/'.$root->id)->assertOk()->assertJsonPath('detail.status', 'inactive')->assertJsonPath('detail.status_reason', 'No longer held');
    }

    public function test_storage_create_binds_parent_revision_and_distinct_own_version_without_advancing_catalogue(): void
    {
        $root = $this->catalogue(); $input = ['idempotency_key' => (string) Str::uuid(), 'parent_expected_version' => 1,
            'site_id' => $this->site->id, 'location_description' => 'Recorded cabinet', 'properly_labelled' => false, 'segregation_compliant' => false];
        $receipt = $this->receipt($this->actingAs($this->manager)->postJson(self::BASE.'/'.$root->id.'/storage-locations', $input));
        $this->assertSame('storage', $receipt['root_type']); $this->assertSame($root->id, $receipt['parent_id']);
        $this->assertNull($receipt['expected_version']); $this->assertSame(1, $receipt['parent_expected_version']); $this->assertSame(1, $receipt['version']);
        $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame($receipt, $this->receipt($this->postJson(self::BASE.'/'.$root->id.'/storage-locations', $input)));
    }

    public function test_storage_foreign_site_and_wrong_parent_are_concealed_before_source_fields_or_receipt_are_returned(): void
    {
        $root = $this->catalogue(); $other = $this->catalogue(['name' => 'Independent reference']);
        $storage = $this->storage($root); $this->actingAs($this->manager);
        $this->putJson(self::BASE.'/'.$other->id.'/storage-locations/'.$storage->id,
            $this->storageCommand($other, $storage, ['location_description' => 'Wrong association']))->assertNotFound();
        $foreign = $this->staff(['hazards.view', 'hazards.manage'], $this->foreignSite);
        $this->actingAs($foreign)->getJson(self::BASE.'/'.$root->id.'/history?source_type=storage&source_id='.$storage->id)->assertNotFound();
        $this->putJson(self::BASE.'/'.$root->id.'/storage-locations/'.$storage->id,
            $this->storageCommand($root, $storage, ['location_description' => 'Not authorised']))->assertNotFound();
        $this->assertSame('Recorded cabinet', $storage->fresh()->location_description);
    }

    public function test_storage_and_its_confirmation_follow_current_site_revoke_restore_not_saved_actor_scope(): void
    {
        $root = $this->catalogue(); $storage = $this->storage($root);
        $receipt = ChemicalCommandReceipt::where('source_type', 'storage')->where('source_id', $storage->id)->sole();
        $url = self::BASE.'/'.$root->id.'/confirmations/'.$receipt->idempotency_key.'?source_type=storage&source_id='.$storage->id;
        $this->actingAs($this->manager)->getJson($url)->assertOk()->assertJsonPath('chemical_confirmation.source_id', $storage->id);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->getJson($url)->assertNotFound();
        $this->site->update(['is_active' => false]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->getJson($url)->assertForbidden();
        $this->site->update(['is_active' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('chemical_confirmation.source_id', $storage->id);
    }

    public function test_storage_update_inventory_audit_archive_restore_keep_whole_command_and_receipt_parity(): void
    {
        $root = $this->catalogue(); $storage = $this->storage($root); $url = self::BASE.'/'.$root->id.'/storage-locations/'.$storage->id;
        $this->actingAs($this->manager);
        $this->receipt($this->putJson($url, $this->storageCommand($root, $storage, ['location_description' => 'Confirmed cabinet', 'quantity_unit' => 'L'])));
        $this->receipt($this->postJson($url.'/inventory-audit', $this->storageCommand($root, $storage,
            ['current_quantity' => '3.25', 'properly_labelled' => true, 'segregation_compliant' => true, 'last_audit_date' => '2026-10-12'])));
        $this->assertSame('3.25', $storage->fresh()->getRawOriginal('current_quantity'));
        $archive = $this->storageCommand($root, $storage, ['reason' => 'Location retired']);
        $accepted = $this->receipt($this->deleteJson($url, $archive));
        $this->assertSame($accepted, $this->receipt($this->deleteJson($url, $archive)));
        $this->getJson(self::BASE.'/'.$root->id)->assertJsonCount(0, 'detail.storage_locations');
        $this->receipt($this->postJson($url.'/restore', ['idempotency_key' => (string) Str::uuid(), 'expected_version' => $accepted['version'],
            'parent_expected_version' => $root->fresh()->source_revision, 'reason' => 'Location available again']));
        $this->getJson(self::BASE.'/'.$root->id)->assertJsonCount(1, 'detail.storage_locations');
    }

    public function test_foreign_uuid_or_wrong_parent_confirmation_returns_no_accepted_receipt_or_working_link(): void
    {
        $root = $this->catalogue(); $other = $this->catalogue(); $storage = $this->storage($root);
        $receipt = ChemicalCommandReceipt::where('source_type', 'storage')->where('source_id', $storage->id)->sole();
        $this->actingAs($this->manager)->getJson(self::BASE.'/'.$other->id.'/confirmations/'.$receipt->idempotency_key
            .'?source_type=storage&source_id='.$storage->id)->assertOk()->assertJsonPath('chemical_confirmation', null)->assertJsonPath('chemical_href', null);
        $another = $this->staff(['hazards.create']);
        $this->actingAs($another)->getJson(self::BASE.'/'.$root->id.'/confirmations/'.$receipt->idempotency_key
            .'?source_type=storage&source_id='.$storage->id)->assertOk()->assertJsonPath('chemical_confirmation', null);
    }

    public function test_uuid_reuse_for_changed_payload_or_another_source_does_not_report_success(): void
    {
        $root = $this->catalogue(); $input = $this->command($root, ['name' => 'Reviewed reference']); $this->actingAs($this->manager);
        $this->receipt($this->putJson(self::BASE.'/'.$root->id, $input));
        $this->putJson(self::BASE.'/'.$root->id, [...$input, 'name' => 'Changed intent'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $other = $this->catalogue();
        $this->putJson(self::BASE.'/'.$other->id, [...$input, 'expected_version' => $other->source_revision])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame('Reviewed reference', $root->fresh()->name);
    }

    public function test_sds_http_upload_download_and_recovery_use_actual_verified_bytes_and_minimal_receipt(): void
    {
        $root = $this->catalogue(); $key = (string) Str::uuid(); $this->actingAs($this->manager);
        $response = $this->postJson(self::BASE.'/'.$root->id.'/sds', $this->command($root,
            ['file' => $this->file(), 'version' => 'Provided SDS', 'issue_date' => '2026-10-01', 'review_date' => '2027-10-01'], $key));
        $receipt = $this->receipt($response); $sheet = SafetyDataSheet::findOrFail($receipt['source_id']);
        $this->assertStringNotContainsString($sheet->document_path, $response->getContent());
        $this->get(self::BASE.'/'.$root->id.'/sds/'.$sheet->id.'/download')->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");
        $this->assertSame($receipt, $this->receipt($this->getJson(self::BASE.'/'.$root->id.'/sds/recover/'.strtoupper($key))));
        $this->assertSame($sheet->id, $root->fresh()->current_sds_id);
    }

    public function test_sds_wrong_parent_missing_bytes_and_current_view_revocation_all_refuse_download(): void
    {
        $root = $this->catalogue(); $other = $this->catalogue(); $r = $this->upload($root); $sheet = SafetyDataSheet::findOrFail($r['source_id']);
        $this->actingAs($this->manager)->get(self::BASE.'/'.$other->id.'/sds/'.$sheet->id.'/download')->assertNotFound();
        $this->allow($this->manager, 'hazards.view', false);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->get(self::BASE.'/'.$root->id.'/sds/'.$sheet->id.'/download')->assertForbidden();
        foreach (DB::getQueryLog() as $query) { $this->assertStringNotContainsString('safety_data_sheets', strtolower($query['query'])); }
        $this->allow($this->manager, 'hazards.view', true); Storage::disk('private')->delete($sheet->document_path);
        $this->get(self::BASE.'/'.$root->id.'/sds/'.$sheet->id.'/download')->assertNotFound();
        $this->getJson(self::BASE.'/'.$root->id)->assertJsonPath('detail.current_sds.available', false);
    }

    public function test_sds_future_printed_metadata_is_recorded_but_cannot_be_selected_as_current(): void
    {
        config(['app.worker_timezone' => 'America/Los_Angeles']);
        $root = $this->catalogue(); $initial = $this->upload($root);
        $future = $this->upload($root, ['issue_date' => '2026-10-12']);
        $this->actingAs($this->manager)->postJson(self::BASE.'/'.$root->id.'/sds/'.$future['source_id'].'/current',
            $this->command($root, ['reason' => 'Select recorded document']))->assertUnprocessable()->assertJsonValidationErrors('sds_id');
        $this->assertSame($initial['source_id'], $root->fresh()->current_sds_id);
        $this->getJson(self::BASE.'/'.$root->id)->assertJsonPath('detail.sds_records.0.issue_date', '2026-10-12')
            ->assertJsonPath('detail.sds_records.0.state', 'not_yet_effective');
    }

    public function test_sds_metadata_correction_and_explicit_selection_require_catalogue_revision_and_reason(): void
    {
        $root = $this->catalogue(); $r = $this->upload($root); $this->actingAs($this->manager);
        $url = self::BASE.'/'.$root->id.'/sds/'.$r['source_id'];
        $this->putJson($url, $this->command($root, ['supplier_name' => 'Provided supplier']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->receipt($this->putJson($url, $this->command($root, ['supplier_name' => 'Provided supplier', 'reason' => 'Correct recorded supplier'])));
        $this->receipt($this->postJson($url.'/current', $this->command($root, ['reason' => 'Confirm effective document'])));
        $this->assertSame('Provided supplier', SafetyDataSheet::findOrFail($r['source_id'])->supplier_name);
    }

    public function test_failed_sds_acceptance_keeps_original_current_document_and_no_false_recovery_receipt(): void
    {
        $root = $this->catalogue(); $first = $this->upload($root); $key = (string) Str::uuid();
        AuditLog::creating(fn ($row) => $row->action === 'chemical.upload_sds' ? false : null);
        try {
            $response = $this->actingAs($this->manager)->postJson(self::BASE.'/'.$root->id.'/sds', $this->command($root,
                ['file' => $this->file(), 'version' => 'Unconfirmed SDS', 'issue_date' => '2026-10-01'], $key));
            $response->assertStatus(500)->assertJsonMissingPath('chemical_confirmation');
            $this->privateHeaders($response);
        } finally { AuditLog::flushEventListeners(); }
        $stage = ChemicalEvidenceStaging::where('idempotency_key', $key)->sole();
        $this->assertNull($stage->receipt_id); Storage::disk('private')->assertExists($stage->path);
        $this->assertSame($first['source_id'], $root->fresh()->current_sds_id);
        $this->getJson(self::BASE.'/'.$root->id.'/sds/recover/'.$key)->assertOk()->assertJsonPath('chemical_confirmation', null)->assertJsonPath('chemical_href', null);
    }

    public function test_history_is_current_authorised_minimal_provenance_not_raw_version_content_or_private_counts(): void
    {
        $root = $this->catalogue(); $storage = $this->storage($root); DB::enableQueryLog(); DB::flushQueryLog();
        $r = $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id.'/history');
        $r->assertOk()->assertJsonPath('history.source_integrity', 'valid')->assertJsonCount(1, 'history.items')
            ->assertJsonMissingPath('history.items.0.content')->assertJsonMissingPath('history.items.0.fingerprint');
        $this->getJson(self::BASE.'/'.$root->id.'/history?source_type=storage&source_id='.$storage->id)
            ->assertOk()->assertJsonPath('history.items.0.site_id', $this->site->id);
        $this->noPrivateSql();
    }

    public function test_public_recovery_and_history_reject_private_family_selectors_without_private_discovery(): void
    {
        $root = $this->catalogue(); $this->actingAs($this->manager); DB::enableQueryLog(); DB::flushQueryLog();
        $this->getJson(self::BASE.'/'.$root->id.'/history?source_type=exposure&source_id=123')->assertUnprocessable()->assertJsonValidationErrors('source_type');
        $this->getJson(self::BASE.'/'.$root->id.'/confirmations/'.Str::uuid().'?source_type=exposure&source_id=123')
            ->assertUnprocessable()->assertJsonValidationErrors('source_type');
        $this->noPrivateSql();
    }

    public function test_damaged_typed_source_retains_repair_identity_without_stale_name_dates_or_working_commands(): void
    {
        $root = $this->catalogue();
        DB::table('chemical_source_versions')->where('source_type', 'catalogue')->where('source_id', $root->id)->update(['content_digest' => str_repeat('0', 64)]);
        $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id)->assertOk()
            ->assertJsonPath('detail.id', $root->id)->assertJsonPath('detail.source_integrity', 'repair_required')
            ->assertJsonPath('detail.name', null)->assertJsonPath('detail.sds_records', null)->assertJsonPath('detail.counts.storage', null)
            ->assertJsonPath('detail.can.manage', false);
        $this->getJson(self::BASE.'?q=nonmatching&tab=active')->assertOk()->assertJsonPath('rows.data.0.id', $root->id)->assertJsonPath('rows.data.0.name', null);
    }

    public function test_return_navigation_rejects_external_cycles_commands_and_preserves_existing_source_leaf(): void
    {
        $root = $this->catalogue(); $this->actingAs($this->manager);
        foreach (['https://example.test/private', '//example.test/private', self::BASE.'/'.$root->id.'?return_to='.rawurlencode(self::BASE.'/'.$root->id)] as $back) {
            $this->getJson(self::BASE.'/'.$root->id.'?return_to='.rawurlencode($back))->assertOk()->assertJsonPath('return_to', self::BASE);
        }
        $this->getJson(self::BASE.'/'.$root->id.'?return_to='.rawurlencode(self::BASE.'?tab=active&page=2&action=edit&receipt_key=secret'))
            ->assertOk()->assertJsonPath('return_to', self::BASE.'?tab=active&page=2');
        $this->getJson(self::BASE.'/'.$root->id.'?return_to='.rawurlencode('/tasks?overdue=1'))
            ->assertOk()->assertJsonPath('return_to', '/tasks?overdue=1');
    }

    public function test_private_exposure_read_return_survives_public_reference_without_any_private_discovery_or_command(): void
    {
        $root = $this->catalogue(); $this->actingAs($this->manager); DB::enableQueryLog(); DB::flushQueryLog();
        $origin = '/health-safety/chemical-exposures/22?section=connections&history_page=2&action=correct&receipt_key=private&symptoms=private&return_to=%2Ftasks';
        $this->getJson(self::BASE.'/'.$root->id.'?return_to='.rawurlencode($origin))->assertOk()
            ->assertJsonPath('return_to', '/health-safety/chemical-exposures/22?section=connections&history_page=2');
        $origin = '/health-safety/chemical-exposures?site_id=3&worker_id=7&substance_id=9&page=2&command=complete_followup';
        $this->getJson(self::BASE.'/'.$root->id.'?return_to='.rawurlencode($origin))->assertOk()
            ->assertJsonPath('return_to', '/health-safety/chemical-exposures?site_id=3&worker_id=7&substance_id=9&page=2');
        foreach (['/health-safety/chemical-exposures/2147483648', '/health-safety/chemical-exposures/22?worker_id[]=7',
            '/health-safety/chemical-exposures/capture?substance_id=9', '/health-safety/chemical-exposures/22?section=connections&notes=%0Asecret'] as $origin) {
            $this->getJson(self::BASE.'/'.$root->id.'?return_to='.rawurlencode($origin))->assertOk()->assertJsonPath('return_to', self::BASE);
        }
        $this->noPrivateSql();
    }

    public function test_inactive_or_external_current_staff_cannot_use_capture_or_public_detail(): void
    {
        $root = $this->catalogue(); $this->actingAs($this->manager);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->getJson(self::BASE.'/capture-options')->assertForbidden();
        $this->getJson(self::BASE.'/'.$root->id)->assertForbidden();
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->manager->forceFill(['external_clinical_account' => true])->save();
        $this->getJson(self::BASE.'/capture-options')->assertForbidden();
    }
}
