<?php

namespace Tests\Feature\Incidents;

use App\Models\{HealthSafetySettingChange, Site};
use App\Services\Incidents\IncidentEvidenceWindowPolicySettings as Policy;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Native HTTP/owner integration; no controller, current-actor or policy mocks. UNRUN. */
final class IncidentEvidenceWindowSettingsHttpTest extends IncidentRoutingTestCase
{
    private const URL = '/health-safety/settings/evidence-windows';

    private function command(): array
    {
        return ['expected_actor_id' => (int) $this->manager->id, 'expected_policy_revision' => 0,
            'idempotency_key' => (string) Str::uuid(), 'reason' => 'Explicit camera evidence policy decision.',
            'policy' => ['schema_version' => 1, 'enabled' => true, 'rules' => [
                ['identifier' => 'fall-camera', 'trigger' => 'fall', 'site_id' => (int) $this->site->id,
                    'before_seconds' => 60, 'after_seconds' => 60, 'max_before_seconds' => 120,
                    'max_after_seconds' => 120, 'source_types' => ['camera']],
            ]]];
    }

    public function test_json_editor_has_current_viewer_without_materializing_a_default(): void
    {
        $this->actingAs($this->manager)->getJson(self::URL)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('evidence_window_viewer_id', $this->manager->id)
            ->assertJsonPath('policy.configuration_state', 'unconfigured')
            ->assertJsonPath('can_manage', true)->assertJsonCount(0, 'history.data');
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', Policy::KEY)->count());
    }

    public function test_real_save_replay_and_actor_private_recovery_share_one_receipt(): void
    {
        $command = $this->command();
        $first = $this->actingAs($this->manager)->putJson(self::URL, $command)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('receipt.replayed', false)
            ->assertJsonPath('evidence_window_viewer_id', $this->manager->id)->json('receipt');
        $this->putJson(self::URL, $command)->assertOk()->assertJsonPath('receipt.replayed', true)
            ->assertJsonPath('receipt.setting_change_id', $first['setting_change_id']);
        $this->getJson(self::URL.'/requests/'.$command['idempotency_key'].'?expected_actor_id='.$this->manager->id)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('receipt.setting_change_id', $first['setting_change_id'])
            ->assertJsonPath('receipt.policy_snapshot', $first['policy_snapshot']);
        $this->assertSame(1, HealthSafetySettingChange::where('setting_key', Policy::KEY)->count());
    }

    public function test_page_and_unrelated_partial_refresh_always_replace_scoped_evidence_props(): void
    {
        $this->actingAs($this->manager)->putJson(self::URL, $this->command())->assertOk();
        $this->get('/health-safety/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/settings')->where('evidence_window_viewer_id', $this->manager->id)
            ->where('evidence_window_policy.configuration_state', 'configured')->has('evidence_window_history.data', 1));
        $other = Site::factory()->create(['is_active' => true]);
        $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $other->id]);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/settings', 'X-Inertia-Partial-Data' => 'policy'];
        $this->get('/health-safety/settings', $headers)->assertOk()
            ->assertJsonPath('props.evidence_window_viewer_id', $this->manager->id)
            ->assertJsonPath('props.evidence_window_policy.policy_snapshot.rules', [])
            ->assertJsonPath('props.evidence_window_history.data.0.after_value.rules', [])
            ->assertJsonPath('props.can_manage_evidence_windows', false)
            ->assertJsonPath('props.evidence_window_options.sites.0.id', $other->id);
    }

    public function test_warmed_permission_revocation_denies_editor_write_recovery_and_partial_page(): void
    {
        $command = $this->command();
        $this->actingAs($this->manager)->putJson(self::URL, $command)->assertOk();
        $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, Policy::PERMISSION, false);
        $this->getJson(self::URL)->assertForbidden();
        $this->putJson(self::URL, $command)->assertForbidden();
        $this->getJson(self::URL.'/requests/'.$command['idempotency_key'].'?expected_actor_id='.$this->manager->id)->assertForbidden();
        $this->get('/health-safety/settings', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/settings',
            'X-Inertia-Partial-Data' => 'policy'])->assertForbidden();
    }

    public function test_recovery_cannot_rebind_actor_or_reveal_another_actors_receipt(): void
    {
        $command = $this->command();
        $this->actingAs($this->manager)->putJson(self::URL, $command)->assertOk();
        $other = $this->staff([Policy::PERMISSION]);
        $this->actingAs($other)->getJson(self::URL.'/requests/'.$command['idempotency_key'].'?expected_actor_id='.$this->manager->id)->assertForbidden();
        $this->getJson(self::URL.'/requests/'.$command['idempotency_key'].'?expected_actor_id='.$other->id)->assertNotFound();
        $this->getJson(self::URL.'/requests/'.Str::uuid().'?expected_actor_id='.$other->id)->assertNotFound();
    }

    public function test_editor_and_recovery_queries_are_closed_and_canonical(): void
    {
        $this->actingAs($this->manager);
        foreach (['window_policy_page=0', 'window_policy_page=01', 'window_policy_page=1e2', 'window_policy_page[]=1',
            'window_policy_page=1000001', 'unexpected=1'] as $query) {
            $this->getJson(self::URL.'?'.$query)->assertUnprocessable();
        }
        $key = (string) Str::uuid();
        foreach (['', '?expected_actor_id=0', '?expected_actor_id=01', '?expected_actor_id[]=1',
            '?expected_actor_id=999999999999999999999999999', '?expected_actor_id='.$this->manager->id.'&unexpected=1'] as $query) {
            $this->getJson(self::URL.'/requests/'.$key.$query)->assertUnprocessable();
        }
        $this->getJson(self::URL.'/requests/'.strtoupper($key).'?expected_actor_id='.$this->manager->id)->assertUnprocessable();
        $this->putJson(self::URL.'?unexpected=1', $this->command())->assertUnprocessable();
        $this->putJson(self::URL, [...$this->command(), 'extra' => true])->assertUnprocessable();
        $this->put(self::URL, $this->command(), ['Accept' => 'application/json'])->assertStatus(415);
        $this->getJson('/health-safety/settings?window_policy_page=01')->assertUnprocessable();
    }
}