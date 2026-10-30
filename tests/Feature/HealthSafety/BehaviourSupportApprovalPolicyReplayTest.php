<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;

final class BehaviourSupportApprovalPolicyReplayTest extends BehaviourSupportPolicyTestCase
{
    public function test_exact_replay_after_policy_switch_returns_original_receipt_before_stale_revision(): void
    {
        $payload = $this->payload('authorised_reviewer');
        $accepted = $this->policy()->save($this->manager, $payload);
        $this->policy()->save($this->manager, $this->payload(Policy::DEFAULT_MODE, 1));
        $replay = $this->policy()->save($this->manager, $payload);
        $this->assertTrue($replay['replayed']);
        unset($accepted['replayed'], $replay['replayed']);
        $this->assertSame($accepted, $replay);
        $this->assertSame(2, $this->policy()->snapshot()['policy_revision']);
        $this->assertSame(3, HealthSafetySettingChange::count());
        $this->assertSame(2, AuditLog::where('action', 'like', 'health_safety.settings.restraint_plan_approval.%')->count());
    }

    public function test_actor_payload_mode_reason_and_expected_revision_are_bound_to_global_uuid(): void
    {
        $payload = $this->payload();
        $this->policy()->save($this->manager, $payload);
        $other = $this->staff([Policy::PERMISSION]);
        $this->validation(fn () => $this->policy()->save($other, $payload), 'idempotency_key');
        foreach ([
            ['mode' => 'authorised_reviewer'], ['reason' => 'A different decision'], ['expected_policy_revision' => 1],
        ] as $mutation) {
            $this->validation(fn () => $this->policy()->save($this->manager, array_replace($payload, $mutation)), 'idempotency_key');
        }
        $this->assertSame(1, $this->policy()->snapshot()['policy_revision']);
    }

    public function test_page_receipt_is_reconstructed_and_actor_bound_instead_of_trusting_session_payload(): void
    {
        $accepted = $this->policy()->save($this->manager, $this->payload());
        $forged = array_replace($accepted, ['mode' => 'fabricated_mode', 'accepted' => false]);
        $reconstructed = $this->policy()->receiptForManager($this->manager, $forged);
        $this->assertSame(Policy::DEFAULT_MODE, $reconstructed['mode']);
        $this->assertTrue($reconstructed['accepted']);
        $other = $this->staff([Policy::PERMISSION]);
        $this->assertNull($this->policy()->receiptForManager($other, $accepted));
        $this->assertNull($this->policy()->receiptForManager($this->manager, array_replace($accepted, ['policy_revision' => 20])));
        $this->actingAs($other)->withSession(['health_safety_policy_receipt' => $accepted])
            ->getJson('/health-safety/settings')->assertOk()->assertJsonPath('health_safety_policy_receipt', null);
        $this->actingAs($this->manager)->withSession(['health_safety_policy_receipt' => $forged])
            ->getJson('/health-safety/settings')->assertOk()->assertJsonPath('health_safety_policy_receipt.mode', Policy::DEFAULT_MODE);
    }

    public function test_new_key_with_stale_revision_preserves_saved_value_and_creates_no_receipt_or_audit(): void
    {
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $stale = $this->payload(Policy::DEFAULT_MODE);
        $this->validation(fn () => $this->policy()->save($this->manager, $stale), 'expected_policy_revision');
        $this->assertSame('authorised_reviewer', $this->policy()->snapshot()['mode']);
        $this->assertDatabaseMissing('health_safety_setting_changes', ['command_key' => $stale['idempotency_key']]);
        $this->assertSame(1, AuditLog::where('action', 'like', 'health_safety.settings.restraint_plan_approval.%')->count());
    }

    public function test_new_source_decision_must_recheck_current_policy_but_historical_snapshot_stays_original(): void
    {
        $old = $this->policy()->withLockedPolicy(fn ($policy) => $policy);
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $this->policy()->withLockedPolicy(function ($current): void {
            $this->validation(fn () => $this->policy()->assertUsable($current, 0), 'expected_policy_revision');
            $this->policy()->assertUsable($current, 1);
            $this->assertSame('authorised_reviewer', $current['mode']);
        });
        $this->assertSame(0, $old['policy_revision']);
        $this->assertSame(Policy::DEFAULT_MODE, $old['mode']);
    }
}
