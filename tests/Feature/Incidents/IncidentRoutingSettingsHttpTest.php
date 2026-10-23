<?php

namespace Tests\Feature\Incidents;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Models\Site;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings;
use App\Services\Incidents\IncidentRoutingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Real mounted routes/middleware. No runtime acceptance; current dedicated settings guard is exercised without suppression. */
final class IncidentRoutingSettingsHttpTest extends IncidentRoutingTestCase
{
    private const URL = '/health-safety/settings/incident-routing';

    public function test_unauthenticated_real_settings_routes_do_not_admit_configuration_or_candidates(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson(self::URL.'/candidates?site_id='.$this->site->id)->assertUnauthorized();
        $this->putJson(self::URL, $this->payload())->assertUnauthorized();
        $this->get('/health-safety/settings')->assertRedirect(route('login'));
    }

    public function test_settings_only_actor_can_edit_configuration_without_private_incident_or_roster_grant(): void
    {
        $actor = $this->staff([IncidentRoutingSettings::PERMISSION]); $root = $this->incident();
        $this->actingAs($actor);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('routing_viewer_id', $actor->id)
                ->assertJsonPath('can_manage_routing', true)->assertJsonPath('routing_configuration.state', 'unconfigured')
                ->assertJsonPath('routing_configuration.configuration', null)->assertJsonCount(0, 'routing_history.data');
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        foreach ($queries as $query) {
            foreach (['client_incidents', 'incident_owner_changes', 'incident_routing_intents', 'clients', 'safeguarding_concerns', 'hr_cases'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($query['query']));
            }
        }
        $this->assertStringNotContainsString('Private synthetic incident narrative', $response->getContent());
        $this->assertFalse($actor->fresh()->canDo('incidents.viewAny')); $this->assertFalse($actor->fresh()->canDo('incidents.owner.assign'));
        $this->putJson(self::URL, $this->payload())->assertOk()->assertJsonPath('receipt.actor_id', $actor->id);
        $this->getJson('/incidents/'.$root->id)->assertForbidden();
    }

    public function test_other_source_or_general_settings_grants_do_not_replace_the_actual_settings_permission(): void
    {
        $actor = $this->staff(['hazards.manage', 'incidents.viewAny', 'incidents.owner.assign', 'restraints.manage', 'settings.manage']);
        $this->actingAs($actor)->getJson(self::URL)->assertForbidden();
        $this->getJson(self::URL.'/candidates?site_id='.$this->site->id)->assertForbidden();
        $this->putJson(self::URL, $this->payload())->assertForbidden();
        $this->getJson('/health-safety/settings')->assertForbidden();
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
    }

    public function test_current_internal_staff_eligibility_remains_required_despite_positive_route_grant(): void
    {
        $actor = $this->staff([IncidentRoutingSettings::PERMISSION]); $actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->actingAs($actor)->getJson(self::URL)->assertForbidden();
        $this->putJson(self::URL, $this->payload())->assertForbidden();
        $actor->hrEmployeeProfile()->update(['is_active' => true]);
        $this->getJson(self::URL)->assertOk();
        $external = $this->staff([IncidentRoutingSettings::PERMISSION]); $external->update(['external_clinical_account' => true]);
        $this->actingAs($external)->getJson(self::URL)->assertForbidden();
    }

    public function test_candidate_json_is_exact_current_reference_and_viewer_not_a_source_directory(): void
    {
        $response = $this->actingAs($this->manager)->getJson($this->candidateUrl($this->site->id, ['selected_id' => $this->lead->id]))
            ->assertOk()->assertJsonPath('viewer_id', $this->manager->id)->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.id', $this->lead->id)->assertJsonPath('candidates.0.name', $this->lead->name);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['id', 'name'], array_keys($response->json('candidates.0')));
        $this->assertSame([], $this->getJson($this->candidateUrl($this->site->id, ['selected_id' => 2147483647]))->assertOk()->json('candidates'));
    }

    public function test_selected_reference_beyond_first_thirty_has_real_http_lookup_without_losing_scope(): void
    {
        $this->lead->update(['name' => 'ZZZ selected lead']);
        for ($index = 0; $index < 31; $index++) {
            $this->staff(['incidents.viewAny', 'incidents.owner.hold'])->update(['name' => 'AAA reference '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
        }
        $normal = $this->actingAs($this->manager)->getJson($this->candidateUrl($this->site->id))->assertOk()->json('candidates');
        $this->assertCount(30, $normal); $this->assertNotContains($this->lead->id, array_column($normal, 'id'));
        $this->getJson($this->candidateUrl($this->site->id, ['selected_id' => $this->lead->id]))->assertOk()
            ->assertJsonPath('candidates', [['id' => $this->lead->id, 'name' => 'ZZZ selected lead']]);
    }

    public function test_unapproved_site_and_foreign_selected_reference_are_denied_before_name_hydration(): void
    {
        $other = Site::factory()->create(); $foreign = $this->staff(['incidents.viewAny', 'incidents.owner.hold'], $other);
        $foreign->update(['name' => 'Outside private roster recipient']); $this->actingAs($this->manager);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->getJson($this->candidateUrl($other->id, ['selected_id' => $foreign->id]))->assertNotFound();
            $response = $this->getJson($this->candidateUrl($this->site->id, ['selected_id' => $foreign->id]))->assertOk()->assertJsonPath('candidates', []);
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        $this->assertStringNotContainsString($foreign->name, $response->getContent());
        foreach ($queries as $query) {
            if (preg_match('/select\s+(?:`users`\.|"users"\.)?\*\s+from\s+[`"]?users/i', $query['query'])) {
                $this->assertNotContains($foreign->id, $query['bindings']);
            }
        }
    }

    public function test_candidate_warmed_permission_and_site_withdrawal_then_restore_use_current_named_reference(): void
    {
        $url = $this->candidateUrl($this->site->id, ['selected_id' => $this->lead->id]);
        $this->lead->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->actingAs($this->manager)->getJson($url)->assertOk()->assertJsonCount(1, 'candidates');
        $this->allow($this->lead, 'incidents.owner.hold', false); $this->getJson($url)->assertOk()->assertJsonPath('candidates', []);
        $this->allow($this->lead, 'incidents.owner.hold'); $this->getJson($url)->assertOk()->assertJsonCount(1, 'candidates');
        $this->lead->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('candidates', []);
        $this->lead->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'candidates');
    }

    public function test_candidate_and_history_query_parameters_reject_arrays_unknown_scope_and_unrepresentable_ids(): void
    {
        $this->actingAs($this->manager);
        foreach ([['site_id' => [1]], ['site_id' => 0], ['site_id' => 2147483648], ['site_id' => $this->site->id, 'scope' => 'unsupported'],
            ['site_id' => $this->site->id, 'selected_id' => [1]], ['site_id' => $this->site->id, 'selected_id' => 0],
            ['site_id' => $this->site->id, 'selected_id' => 2147483648], ['site_id' => $this->site->id, 'search' => str_repeat('x', 101)]] as $query) {
            $this->getJson(self::URL.'/candidates?'.http_build_query($query))->assertUnprocessable();
        }
        foreach (['routing_page=0', 'routing_page[]=1', 'routing_page=1000001'] as $query) {
            $this->getJson(self::URL.'?'.$query)->assertUnprocessable();
        }
    }

    public function test_json_save_has_exact_actor_bound_audited_receipt_and_preserves_command_values(): void
    {
        $input = $this->payload(); $response = $this->actingAs($this->manager)->putJson(self::URL, $input)->assertOk();
        $receipt = $response->json('receipt');
        $expectedKeys = ['accepted', 'request_uuid', 'setting_key', 'revision', 'configuration_digest', 'action', 'actor_id']; $actualKeys = array_keys($receipt); sort($expectedKeys); sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
        $this->assertTrue($receipt['accepted']); $this->assertSame($input['request_uuid'], $receipt['request_uuid']); $this->assertSame($this->manager->id, $receipt['actor_id']);
        $this->assertSame(1, $receipt['revision']); $this->assertSame('changed', $receipt['action']);
        $saved = HealthSafetySettingChange::where('command_key', $input['request_uuid'])->sole();
        $this->assertNotNull($saved->audit_id); $this->assertSame($this->lead->id, $saved->after_value['routes'][0]['lead_user_id']);
        $this->assertSame('2026-10-12T00:00:00.000000Z', $saved->after_value['routes'][0]['effective_from']);
        $this->assertSame(self::typedMap($receipt), self::typedMap($saved->accepted_result)); $this->assertSame(1, AuditLog::where('meta->request_uuid', $input['request_uuid'])->count());
        $this->assertArrayNotHasKey('configuration', $receipt); $this->assertArrayNotHasKey('reason', $receipt);
        $this->assertSame(3, count($saved->after_value['triage']));
        $this->assertSame(self::typedMap($input['configuration']['triage']), self::typedMap($saved->after_value['triage']));
    }

    public function test_uppercase_exact_uuid_replay_after_later_revision_returns_original_acceptance_once(): void
    {
        $input = $this->payload(); $input['request_uuid'] = strtoupper($input['request_uuid']);
        $first = $this->actingAs($this->manager)->putJson(self::URL, $input)->assertOk()->json('receipt');
        $next = $this->payload(revision: 1); $next['configuration']['routes'][0]['lead_user_id'] = $this->cover->id;
        $this->putJson(self::URL, $next)->assertOk()->assertJsonPath('receipt.revision', 2);
        $input['request_uuid'] = strtolower($input['request_uuid']);
        $this->putJson(self::URL, $input)->assertOk()->assertJsonPath('receipt', fn ($receipt) => self::typedMap($receipt) === self::typedMap($first));
        $this->assertSame(2, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
        $this->assertSame(1, AuditLog::where('meta->request_uuid', $first['request_uuid'])->count());
    }

    public function test_reused_uuid_other_actor_or_changed_semantic_payload_never_returns_original_acceptance(): void
    {
        $input = $this->payload(); $this->actingAs($this->manager)->putJson(self::URL, $input)->assertOk();
        $other = $this->staff([IncidentRoutingSettings::PERMISSION]);
        $this->actingAs($other)->putJson(self::URL, $input)->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $changed = $input; $changed['reason'] = 'Different explicit operational review';
        $this->actingAs($this->manager)->putJson(self::URL, $changed)->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $this->assertSame(1, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
    }

    public function test_stale_revision_preserves_new_intent_and_stored_configuration_without_false_receipt(): void
    {
        $this->actingAs($this->manager)->putJson(self::URL, $this->payload())->assertOk();
        $stale = $this->payload(); $stale['configuration']['routes'][0]['lead_user_id'] = $this->cover->id;
        $response = $this->putJson(self::URL, $stale)->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->assertArrayNotHasKey('receipt', $response->json());
        $this->assertSame($this->lead->id, AppSetting::where('key', IncidentRoutingSettings::KEY)->value('value')['routes'][0]['lead_user_id']);
        $this->assertFalse(HealthSafetySettingChange::where('command_key', $stale['request_uuid'])->exists());
    }

    public function test_html_save_stores_only_uuid_then_shared_page_reconstructs_exact_current_actor_receipt(): void
    {
        $input = $this->payload();
        $this->actingAs($this->manager)->put(self::URL, $input)->assertRedirect(route('health-safety.settings.index'))
            ->assertSessionHas('health_safety_routing_request_uuid', $input['request_uuid']);
        $receipt = HealthSafetySettingChange::where('command_key', $input['request_uuid'])->sole()->accepted_result;
        $this->getJson('/health-safety/settings')->assertOk()->assertJsonPath('health_safety_routing_receipt', $receipt)
            ->assertJsonPath('routing_viewer_id', $this->manager->id);
        $this->get('/health-safety/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/settings')
            ->where('health_safety_routing_receipt', $receipt)->where('routing_viewer_id', $this->manager->id));
    }

    public function test_another_actor_or_forged_session_receipt_cannot_recover_saved_acceptance(): void
    {
        $input = $this->payload(); $this->actingAs($this->manager)->putJson(self::URL, $input)->assertOk();
        $other = $this->staff([IncidentRoutingSettings::PERMISSION]);
        $this->actingAs($other)->withSession(['health_safety_routing_request_uuid' => $input['request_uuid']])
            ->getJson('/health-safety/settings')->assertOk()->assertJsonPath('health_safety_routing_receipt', null)->assertJsonPath('routing_viewer_id', $other->id);
        $this->actingAs($this->manager)->withSession(['health_safety_routing_request_uuid' => (string) Str::uuid(),
            'health_safety_routing_receipt' => ['accepted' => true, 'actor_id' => $this->manager->id, 'revision' => 999]])
            ->getJson('/health-safety/settings')->assertOk()->assertJsonPath('health_safety_routing_receipt', null);
    }

    public function test_damaged_acceptance_audit_cannot_be_presented_as_a_session_confirmation(): void
    {
        $input = $this->payload(); $this->actingAs($this->manager)->putJson(self::URL, $input)->assertOk();
        $saved = HealthSafetySettingChange::where('command_key', $input['request_uuid'])->sole();
        DB::table('audit_logs')->where('id', $saved->audit_id)->update(['meta' => json_encode(['forged' => true])]);
        $response = $this->withSession(['health_safety_routing_request_uuid' => $input['request_uuid']])->getJson('/health-safety/settings')->assertUnprocessable();
        $this->assertArrayNotHasKey('health_safety_routing_receipt', $response->json());
        $this->getJson(self::URL)->assertOk()->assertJsonPath('routing_configuration.state', 'repair_required');
    }

    public function test_closed_command_contract_refuses_editor_wrappers_unknown_values_and_empty_reason(): void
    {
        $this->actingAs($this->manager); $base = $this->payload();
        $wrapped = $base; $wrapped['configuration']['triage'] = ['visibility' => 'readable', 'state' => 'configured', 'values' => $base['configuration']['triage']];
        $unknown = $base; $unknown['configuration']['grant_owner'] = true;
        $display = $base; $display['configuration']['triage']['display_names'] = ['user' => 'Invented recipient'];
        foreach ([$wrapped, $unknown, $display, [...$base, 'reason' => '   '], [...$base, 'configuration_valid' => true]] as $input) {
            $this->putJson(self::URL, $input)->assertUnprocessable();
        }
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
    }

    public function test_scoped_http_edit_preserves_unseen_site_rules_and_whole_organisation_revision(): void
    {
        $otherSite = Site::factory()->create(); $otherLead = $this->staff(['incidents.viewAny', 'incidents.owner.hold'], $otherSite);
        $this->allow($this->manager, 'healthSafety.viewAllSites'); $config = $this->config();
        $config['routes'][] = [...$config['routes'][0], 'site_id' => $otherSite->id, 'lead_user_id' => $otherLead->id]; $this->settings($config);
        $retainedRoute = AppSetting::where('key', IncidentRoutingSettings::KEY)->value('value')['routes'][1];
        $this->allow($this->manager, 'healthSafety.viewAllSites', false); $this->actingAs($this->manager);
        $visible = $this->getJson(self::URL)->assertOk()->json('routing_configuration.configuration');
        $this->assertSame([$this->site->id], array_column($visible['routes'], 'site_id'));
        $local = ['schema_version' => 1, 'routes' => $visible['routes'], 'triage' => $visible['triage']['values']];
        $local['routes'][0]['lead_user_id'] = $this->cover->id;
        $this->putJson(self::URL, $this->payload($local, 1))->assertOk()->assertJsonPath('receipt.revision', 2);
        $stored = AppSetting::where('key', IncidentRoutingSettings::KEY)->value('value');
        $this->assertSame($retainedRoute, $stored['routes'][1]);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'routing_history.data.0.configuration.routes');
        $this->putJson(self::URL, $this->payload($config, 2))->assertNotFound();
    }

    public function test_restricted_monitored_recipient_names_and_ids_are_withheld_and_omission_preserves_them(): void
    {
        $otherSite = Site::factory()->create(); $hidden = $this->staff(['incidents.viewAny', 'incidents.owner.hold', 'incidents.owner.assign'], $otherSite);
        $hidden->update(['name' => 'Hidden monitored recipient']); $this->allow($this->manager, 'healthSafety.viewAllSites');
        $config = $this->config(); $config['triage']['user_id'] = $hidden->id; $config['triage']['escalation_user_id'] = $hidden->id; $this->settings($config);
        $this->allow($this->manager, 'healthSafety.viewAllSites', false); $this->actingAs($this->manager);
        $response = $this->getJson(self::URL)->assertOk();
        $restricted = ['visibility' => 'restricted', 'state' => 'configured'];
        $this->assertSame($restricted, $response->json('routing_configuration.configuration.triage'));
        $this->assertSame($restricted, $response->json('routing_history.data.0.configuration.triage'));
        $this->assertStringNotContainsString($hidden->name, $response->getContent());
        $local = ['schema_version' => 1, 'routes' => $response->json('routing_configuration.configuration.routes')];
        $local['routes'][0]['lead_user_id'] = $this->cover->id;
        $this->putJson(self::URL, $this->payload($local, 1))->assertOk();
        $this->assertSame($config['triage'], AppSetting::where('key', IncidentRoutingSettings::KEY)->value('value')['triage']);
        $this->putJson(self::URL, $this->payload([...$local, 'triage' => null], 2))->assertUnprocessable()->assertJsonValidationErrors('configuration.triage');
        $this->putJson(self::URL, $this->payload([...$local, 'triage' => $config['triage']], 2))->assertUnprocessable()->assertJsonValidationErrors('configuration.triage');
    }

    public function test_readable_recipient_names_are_current_scoped_metadata_and_withheld_on_fresh_withdrawal(): void
    {
        $escalation = $this->staff(['incidents.viewAny', 'incidents.owner.hold', 'incidents.owner.assign']); $escalation->update(['name' => 'Scoped escalation recipient']);
        $config = $this->config(); $config['triage']['escalation_user_id'] = $escalation->id; $this->settings($config); $this->actingAs($this->manager);
        $expected = ['user' => $this->triage->name, 'escalation' => $escalation->name];
        $this->getJson(self::URL)->assertOk()->assertJsonPath('routing_configuration.configuration.triage.display_names', $expected)
            ->assertJsonPath('routing_history.data.0.configuration.triage.display_names', $expected);
        $escalation->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->allow($escalation, 'incidents.owner.assign', false);
        $restricted = ['visibility' => 'restricted', 'state' => 'configured'];
        $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('routing_configuration.configuration.triage', $restricted);
        $this->assertStringNotContainsString($escalation->name, $response->getContent());
        $this->allow($escalation, 'incidents.owner.assign');
        $this->getJson(self::URL)->assertOk()->assertJsonPath('routing_configuration.configuration.triage.display_names', $expected);
        $escalation->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('routing_configuration.configuration.triage', $restricted);
        $this->assertSame($config['triage'], AppSetting::where('key', IncidentRoutingSettings::KEY)->value('value')['triage']);
    }

    public function test_inertia_partial_visits_replace_routing_props_on_site_withdrawal_and_restore(): void
    {
        $this->settings(); $this->manager->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']); $this->actingAs($this->manager);
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html',
            'X-Inertia-Partial-Component' => 'health-safety/settings', 'X-Inertia-Partial-Data' => 'policy'];
        $this->get('/health-safety/settings', $headers)->assertOk()->assertJsonPath('props.routing_configuration.configuration.routes.0.site_id', $this->site->id);
        $otherSite = Site::factory()->create(); $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
        $withdrawn = $this->get('/health-safety/settings', $headers)->assertOk()->assertHeader('X-Inertia', 'true');
        foreach (['routing_configuration', 'routing_history', 'routing_sites', 'can_manage_routing', 'routing_viewer_id', 'health_safety_routing_receipt'] as $key) {
            $this->assertArrayHasKey($key, $withdrawn->json('props'));
        }
        $withdrawn->assertJsonPath('props.routing_configuration.configuration.routes', [])->assertJsonPath('props.routing_history.data.0.configuration.routes', [])
            ->assertJsonPath('props.routing_configuration.configuration.triage', ['visibility' => 'restricted', 'state' => 'configured'])
            ->assertJsonPath('props.routing_sites', [['id' => $otherSite->id, 'name' => $otherSite->name]])->assertJsonPath('props.routing_viewer_id', $this->manager->id);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->get('/health-safety/settings', $headers)->assertOk()->assertJsonPath('props.routing_configuration.configuration.routes.0.site_id', $this->site->id)
            ->assertJsonPath('props.routing_configuration.configuration.triage.visibility', 'readable');
    }

    public function test_warmed_positive_route_grant_withdrawal_denies_saved_receipt_and_write_without_undoing_history(): void
    {
        $input = $this->payload(); $this->actingAs($this->manager)->putJson(self::URL, $input)->assertOk();
        $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, IncidentRoutingSettings::PERMISSION, false);
        $this->withSession(['health_safety_routing_request_uuid' => $input['request_uuid']])->getJson('/health-safety/settings')->assertForbidden();
        $this->putJson(self::URL, $input)->assertForbidden();
        $this->assertSame(1, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
        $this->allow($this->manager, IncidentRoutingSettings::PERMISSION);
        $this->actingAs($this->manager->fresh())->getJson('/health-safety/settings')->assertOk()->assertJsonPath('health_safety_routing_receipt.request_uuid', $input['request_uuid']);
    }

    public function test_warmed_negative_middleware_grant_restoration_must_not_block_fresh_settings_authority(): void
    {
        // Real dedicated route guard must re-read current authority despite a loaded negative override.
        $this->allow($this->manager, IncidentRoutingSettings::PERMISSION, false);
        $this->manager->load(['roles.permissions', 'permissionOverrides']); $this->actingAs($this->manager);
        $this->getJson(self::URL)->assertForbidden();
        $this->allow($this->manager, IncidentRoutingSettings::PERMISSION);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('routing_viewer_id', $this->manager->id);
    }

    public function test_routing_history_pagination_and_kept_commands_do_not_mix_or_change_bsp_policy_history(): void
    {
        $policyReceipt = app(BehaviourSupportApprovalPolicySettings::class)->save($this->manager, [
            'mode' => 'separate_specialist', 'expected_policy_revision' => 0, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Independent retained policy review',
        ]);
        for ($revision = 0; $revision < 26; $revision++) { $this->settings(revision: $revision); }
        $this->actingAs($this->manager)->getJson(self::URL.'?routing_page=2')->assertOk()
            ->assertJsonPath('routing_history.current_page', 2)->assertJsonPath('routing_history.total', 26)->assertJsonCount(1, 'routing_history.data')
            ->assertJsonPath('routing_history.data.0.revision', 1);
        $this->getJson('/health-safety/settings?routing_page=2')->assertOk()->assertJsonCount(1, 'routing_history.data')
            ->assertJsonPath('policy.policy_revision', $policyReceipt['policy_revision']);
        $this->assertSame(1, HealthSafetySettingChange::where('setting_key', BehaviourSupportApprovalPolicySettings::KEY)->count());
    }

    public function test_inertia_save_redirect_and_partial_receipt_use_actual_actor_uuid_without_command_body(): void
    {
        $input = $this->payload();
        $this->actingAs($this->manager)->putJson(self::URL, $input, ['X-Inertia' => 'true'])
            ->assertRedirect(route('health-safety.settings.index'))->assertSessionHas('health_safety_routing_request_uuid', $input['request_uuid']);
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html',
            'X-Inertia-Partial-Component' => 'health-safety/settings', 'X-Inertia-Partial-Data' => 'auth'];
        $response = $this->get('/health-safety/settings', $headers)->assertOk()->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('props.health_safety_routing_receipt.accepted', true)
            ->assertJsonPath('props.health_safety_routing_receipt.actor_id', $this->manager->id)
            ->assertJsonPath('props.health_safety_routing_receipt.request_uuid', $input['request_uuid'])
            ->assertJsonPath('props.health_safety_routing_receipt.revision', 1)
            ->assertJsonMissingPath('props.health_safety_routing_receipt.configuration')->assertJsonMissingPath('props.health_safety_routing_receipt.reason');
        $this->assertArrayHasKey('routing_configuration', $response->json('props'));
        $this->assertArrayHasKey('routing_sites', $response->json('props'));
    }
    /** JSON object key order is not evidence; scalar types and list order remain strict. */
    private static function typedMap(array $value): array
    {
        foreach ($value as $key => $child) { if (is_array($child)) { $value[$key] = self::typedMap($child); } }
        if (! array_is_list($value)) { ksort($value); }
        return $value;
    }
    private function payload(?array $configuration = null, int $revision = 0, ?string $uuid = null): array
    {
        return ['configuration' => $configuration ?? $this->config(), 'expected_revision' => $revision,
            'request_uuid' => $uuid ?? (string) Str::uuid(), 'reason' => 'Actual HTTP routing review.'];
    }

    private function candidateUrl(int $siteId, array $query = []): string
    {
        return self::URL.'/candidates?'.http_build_query(['site_id' => $siteId, ...$query]);
    }
}
