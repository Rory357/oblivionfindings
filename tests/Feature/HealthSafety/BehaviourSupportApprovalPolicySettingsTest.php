<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AppSetting;
use App\Models\HealthSafetySettingChange;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

final class BehaviourSupportApprovalPolicySettingsTest extends BehaviourSupportPolicyTestCase
{
    public function test_absent_default_is_truthful_and_a_deliberate_keep_is_revision_one(): void
    {
        $before = $this->policy()->snapshot();
        $this->assertSame('separate_specialist', $before['mode']);
        $this->assertSame(0, $before['policy_revision']);
        $this->assertSame('application_default', $before['origin']);
        $this->assertNull($before['reviewed_at']);
        $this->assertNull($before['setting_change_id']);
        $this->assertSame(0, HealthSafetySettingChange::count());
        $receipt = $this->policy()->save($this->manager, $this->payload());
        $this->assertSame('kept', $receipt['action']);
        $this->assertSame(1, $receipt['policy_revision']);
        $default = HealthSafetySettingChange::where('revision', 0)->sole();
        $this->assertNull($default->actor_id);
        $this->assertNull($default->audit_id);
        $this->assertNull($default->command_key);
        $this->assertNull($default->accepted_result);
        $this->assertSame('application_default', $default->origin);
        $this->assertNotNull($this->policy()->snapshot()['reviewed_at']);
    }

    public function test_http_editor_and_save_have_typed_current_policy_history_and_minimal_receipt(): void
    {
        $this->actingAs($this->manager)->get('/health-safety/settings')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/settings')->where('policy.mode', Policy::DEFAULT_MODE)->where('policy.reviewed_at', null)->where('can_manage', true));
        $payload = $this->payload('authorised_reviewer');
        $this->putJson('/health-safety/settings/restraint-plan-approval', $payload)
            ->assertOk()->assertJsonPath('receipt.policy_revision', 1)->assertJsonPath('receipt.mode', 'authorised_reviewer')->assertJsonPath('receipt.replayed', false)
            ->assertJsonMissing(['reason' => $payload['reason']]);
        $this->getJson('/health-safety/settings')->assertOk()->assertJsonPath('policy.policy_revision', 1)->assertJsonPath('history.total', 2);
    }

    public function test_html_save_redirects_to_settings_with_the_minimal_accepted_receipt(): void
    {
        $this->actingAs($this->manager)->from('/health-safety/settings')
            ->put('/health-safety/settings/restraint-plan-approval', $this->payload())
            ->assertRedirect('/health-safety/settings')
            ->assertSessionHas('health_safety_policy_receipt', fn ($receipt) => $receipt['accepted'] === true && $receipt['policy_revision'] === 1 && ! array_key_exists('reason', $receipt));
    }

    public function test_same_service_refreshes_after_each_governed_save(): void
    {
        $service = $this->policy();
        $this->assertSame(0, $service->snapshot()['policy_revision']);
        $service->save($this->manager, $this->payload('authorised_reviewer'));
        $this->assertSame('authorised_reviewer', $service->snapshot()['mode']);
        $service->save($this->manager, $this->payload(Policy::DEFAULT_MODE, 1));
        $this->assertSame(2, $service->snapshot()['policy_revision']);
        $this->assertSame(Policy::DEFAULT_MODE, $service->snapshot()['mode']);
    }

    public function test_changed_mode_requires_reason_but_kept_mode_does_not(): void
    {
        $changed = $this->payload('authorised_reviewer');
        unset($changed['reason']);
        $this->validation(fn () => $this->policy()->save($this->manager, $changed), 'reason');
        $kept = $this->payload();
        unset($kept['reason']);
        $this->assertSame('kept', $this->policy()->save($this->manager, $kept)['action']);
    }

    #[DataProvider('invalidConfigurations')]
    public function test_malformed_config_has_no_usable_mode_or_false_reviewed_provenance(mixed $stored): void
    {
        AppSetting::create(['key' => Policy::KEY, 'value' => $stored]);
        $snapshot = $this->policy()->snapshot();
        $this->assertFalse($snapshot['configuration_valid']);
        $this->assertSame('configuration_invalid', $snapshot['origin']);
        $this->assertNull($snapshot['mode']);
        $this->assertNull($snapshot['policy_digest']);
        $this->assertNull($snapshot['reviewed_at']);
        $this->validation(fn () => $this->policy()->assertUsable($snapshot, 0), 'expected_policy_revision');
        $this->assertSame(0, HealthSafetySettingChange::count());
    }

    public static function invalidConfigurations(): array
    {
        return [
            'null' => [null], 'scalar' => ['authorised_reviewer'], 'bad mode' => [['schema_version' => 1, 'mode' => 'automatic']],
            'string schema' => [['schema_version' => '1', 'mode' => 'separate_specialist']],
            'missing schema' => [['mode' => 'separate_specialist']],
            'unknown extra' => [['schema_version' => 1, 'mode' => 'separate_specialist', 'approve_all' => true]],
        ];
    }

    public function test_invalid_config_can_be_explicitly_repaired_without_relabeling_old_values(): void
    {
        $raw = ['mode' => 'unsafe legacy value'];
        AppSetting::create(['key' => Policy::KEY, 'value' => $raw]);
        $receipt = $this->policy()->save($this->manager, $this->payload());
        $this->assertSame('changed', $receipt['action']);
        $row = HealthSafetySettingChange::where('revision', 1)->sole();
        $this->assertSame($raw, $row->before_value);
        $this->assertTrue($this->policy()->snapshot()['configuration_valid']);
        $this->assertSame(1, HealthSafetySettingChange::count());
    }

    public function test_bad_revision_is_unusable_and_not_silently_reset(): void
    {
        AppSetting::create(['key' => Policy::REVISION_KEY, 'value' => '2']);
        $this->assertNull($this->policy()->snapshot()['policy_revision']);
        $this->validation(fn () => $this->policy()->save($this->manager, $this->payload()), 'expected_policy_revision');
        $this->assertSame('2', AppSetting::where('key', Policy::REVISION_KEY)->sole()->value);
    }

    public function test_strict_envelope_rejects_unknown_fields_arrays_and_boolean_revision(): void
    {
        $this->actingAs($this->manager);
        foreach ([
            ['mode' => ['authorised_reviewer']], ['mode' => 'automatic'], ['expected_policy_revision' => true],
            ['expected_policy_revision' => -1], ['idempotency_key' => 'not-a-uuid'], ['reason' => ['hidden']],
            ['site_id' => $this->site->id],
        ] as $mutation) {
            $this->putJson('/health-safety/settings/restraint-plan-approval', array_replace($this->payload(), $mutation))->assertUnprocessable();
        }
        $this->assertSame(0, HealthSafetySettingChange::count());
    }
}
