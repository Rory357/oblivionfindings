<?php

namespace Tests\Feature\Incidents;

use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Services\Incidents\IncidentIndependencePolicySettings;
use App\Services\Incidents\IncidentRoutingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Whole mounted HTTP contract; actual middleware stays enabled. Native execution is pending the shared slot. */
final class IncidentIndependenceSettingsHttpTest extends IncidentRoutingTestCase
{
    private const URL = '/health-safety/settings/incident-independence';

    public function test_unauthenticated_read_and_write_cannot_reach_policy(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->putJson(self::URL, $this->command())->assertUnauthorized();
    }

    public function test_settings_only_actor_sees_unreviewed_default_without_incident_or_declaration_hydration(): void
    {
        $actor = $this->staff([IncidentIndependencePolicySettings::PERMISSION]);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $response = $this->actingAs($actor)->getJson(self::URL)->assertOk()
                ->assertJsonPath('independence_viewer_id', $actor->id)
                ->assertJsonPath('independence_policy.mode', 'block_involved_access')
                ->assertJsonPath('independence_policy.policy_revision', 0)
                ->assertJsonPath('independence_policy.origin', 'application_default_unreviewed')
                ->assertJsonPath('independence_policy.reviewed_by', null)
                ->assertJsonPath('independence_policy.reviewed_at', null)
                ->assertJsonPath('independence_policy.configured', false)
                ->assertJsonCount(0, 'independence_history.data');
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        foreach ($queries as $query) {
            foreach (['client_incidents', 'incident_independence_declarations', 'incident_owner_exclusions', 'safeguarding_concerns', 'hr_cases'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($query['query']));
            }
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($actor->fresh()->canDo('incidents.viewAny'));
    }

    public function test_other_settings_or_incident_authority_does_not_replace_this_setting_permission(): void
    {
        $actor = $this->staff(['settings.manage', 'hazards.manage', 'incidents.viewAny', 'incidents.owner.assign']);
        $this->actingAs($actor)->getJson(self::URL)->assertForbidden();
        $this->putJson(self::URL, $this->command())->assertForbidden();
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', IncidentIndependencePolicySettings::KEY)->count());
    }

    public function test_actual_route_rechecks_warmed_permission_and_current_staff_withdrawal_and_restore(): void
    {
        $actor = $this->staff([IncidentIndependencePolicySettings::PERMISSION]);
        $actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->actingAs($actor)->getJson(self::URL)->assertOk();
        $this->allow($actor, IncidentIndependencePolicySettings::PERMISSION, false);
        $this->getJson(self::URL)->assertForbidden();
        $this->putJson(self::URL, $this->command())->assertForbidden();
        $this->allow($actor, IncidentIndependencePolicySettings::PERMISSION);
        $this->getJson(self::URL)->assertOk();
        $actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->getJson(self::URL)->assertForbidden();
        $actor->hrEmployeeProfile()->update(['is_active' => true]);
        $this->getJson(self::URL)->assertOk();
    }

    public function test_save_records_exact_actor_policy_receipt_audit_and_history_without_granting_incident_access(): void
    {
        $actor = $this->staff([IncidentIndependencePolicySettings::PERMISSION]); $root = $this->incident();
        $command = $this->command();
        $response = $this->actingAs($actor)->putJson(self::URL, $command)->assertOk()
            ->assertJsonPath('receipt.accepted', true)->assertJsonPath('receipt.actor_id', $actor->id)
            ->assertJsonPath('receipt.mode', 'assignment_only')->assertJsonPath('receipt.policy_revision', 1);
        $receipt = $response->json('receipt');
        $expected = ['accepted', 'request_uuid', 'setting_key', 'policy_revision', 'mode', 'policy_digest', 'action', 'actor_id'];
        $actual = array_keys($receipt); sort($expected); sort($actual); $this->assertSame($expected, $actual);
        $saved = HealthSafetySettingChange::where('command_key', $command['request_uuid'])->sole();
        $this->assertNotNull($saved->audit_id);
        $savedReceipt = $saved->accepted_result;
        ksort($receipt); ksort($savedReceipt);
        $this->assertSame($receipt, $savedReceipt);
        $this->assertSame(1, AuditLog::where('meta->request_uuid', $command['request_uuid'])->count());
        $this->getJson(self::URL)->assertOk()->assertJsonPath('independence_policy.origin', 'organisation_setting')
            ->assertJsonPath('independence_policy.reviewed_by', $actor->id)->assertJsonPath('independence_history.data.0.reason', $command['reason']);
        $this->getJson('/incidents/'.$root->id)->assertForbidden();
        $this->assertFalse($actor->fresh()->canDo('incidents.owner.assign'));
    }

    public function test_same_command_replay_after_new_policy_returns_original_receipt_without_another_revision(): void
    {
        $first = $this->command(); $this->actingAs($this->manager);
        $receipt = $this->putJson(self::URL, $first)->assertOk()->json('receipt');
        $this->putJson(self::URL, $this->command('block_involved_access', 1))->assertOk()->assertJsonPath('receipt.policy_revision', 2);
        $this->putJson(self::URL, $first)->assertOk()->assertJsonPath('receipt', $receipt);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('independence_policy.mode', 'block_involved_access')
            ->assertJsonPath('independence_policy.policy_revision', 2);
        $this->assertSame(2, HealthSafetySettingChange::where('setting_key', IncidentIndependencePolicySettings::KEY)->count());
    }

    public function test_changed_intent_foreign_actor_stale_revision_and_cross_setting_uuid_do_not_return_acceptance(): void
    {
        $command = $this->command(); $this->actingAs($this->manager)->putJson(self::URL, $command)->assertOk();
        $changed = $command; $changed['reason'] = 'A different operational decision';
        $this->putJson(self::URL, $changed)->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $other = $this->staff([IncidentIndependencePolicySettings::PERMISSION]);
        $this->actingAs($other)->putJson(self::URL, $command)->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $stale = $this->command();
        $this->actingAs($this->manager)->putJson(self::URL, $stale)->assertUnprocessable()->assertJsonValidationErrors('expected_policy_revision');
        $this->assertFalse(HealthSafetySettingChange::where('command_key', $stale['request_uuid'])->exists());
        $this->settings(uuid: $routingUuid = (string) Str::uuid());
        $cross = $this->command(revision: 1); $cross['request_uuid'] = $routingUuid;
        $this->putJson(self::URL, $cross)->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $this->assertSame(1, HealthSafetySettingChange::where('setting_key', IncidentIndependencePolicySettings::KEY)->count());
        $this->assertSame(1, HealthSafetySettingChange::where('setting_key', IncidentRoutingSettings::KEY)->count());
    }

    public function test_html_redirect_stores_only_command_identity_and_current_page_reconstructs_the_receipt(): void
    {
        $command = $this->command();
        $this->actingAs($this->manager)->put(self::URL, $command)->assertRedirect(route('health-safety.settings.index'))
            ->assertSessionHas('health_safety_independence_request_uuid', $command['request_uuid']);
        $this->get('/health-safety/settings')->assertInertia(fn (Assert $page) => $page->component('health-safety/settings')
            ->where('independence_viewer_id', $this->manager->id)
            ->where('health_safety_independence_receipt.request_uuid', $command['request_uuid'])
            ->where('health_safety_independence_receipt.actor_id', $this->manager->id));
    }

    public function test_unrelated_inertia_partial_refresh_always_replaces_current_policy_history_and_viewer(): void
    {
        $this->actingAs($this->manager)->putJson(self::URL, $this->command())->assertOk();
        $this->get('/health-safety/settings', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/settings', 'X-Inertia-Partial-Data' => 'policy'])
            ->assertOk()->assertJsonPath('props.independence_policy.mode', 'assignment_only')
            ->assertJsonPath('props.independence_viewer_id', $this->manager->id)
            ->assertJsonCount(1, 'props.independence_history.data')->assertJsonPath('props.can_manage_independence', true);
    }

    public function test_closed_command_fields_and_bounded_history_page_do_not_accept_extra_or_malformed_values(): void
    {
        $this->actingAs($this->manager);
        foreach ([['mode' => 'unknown'], ['mode' => ['assignment_only']], ['reason' => ''], ['expected_policy_revision' => -1], ['request_uuid' => 'invalid'], ['unexpected_field' => 1]] as $change) {
            $this->putJson(self::URL, array_replace($this->command(), $change))->assertUnprocessable();
        }
        foreach (['independence_page[]=1', 'independence_page=0', 'independence_page=1000001'] as $query) {
            $this->getJson(self::URL.'?'.$query)->assertUnprocessable();
        }
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', IncidentIndependencePolicySettings::KEY)->count());
    }

    private function command(string $mode = 'assignment_only', int $revision = 0): array
    {
        return ['mode' => $mode, 'expected_policy_revision' => $revision, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit organisation independent-review decision.'];
    }
}
