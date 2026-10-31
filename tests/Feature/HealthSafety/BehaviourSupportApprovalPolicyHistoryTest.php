<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;

final class BehaviourSupportApprovalPolicyHistoryTest extends BehaviourSupportPolicyTestCase
{
    public function test_restore_appends_revision_and_retains_default_and_all_previous_modes(): void
    {
        $first = $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $restored = $this->policy()->save($this->manager, $this->payload(Policy::DEFAULT_MODE, 1));
        $kept = $this->policy()->save($this->manager, $this->payload(Policy::DEFAULT_MODE, 2));
        $this->assertSame('changed', $first['action']);
        $this->assertSame('restored', $restored['action']);
        $this->assertSame('kept', $kept['action']);
        $this->assertSame([0, 1, 2, 3], HealthSafetySettingChange::orderBy('revision')->pluck('revision')->all());
        $old = HealthSafetySettingChange::where('revision', 1)->sole();
        $this->assertSame('authorised_reviewer', $old->after_value['mode']);
        $this->assertSame($first['policy_digest'], $old->after_digest);
        $this->assertSame(3, $this->policy()->snapshot()['policy_revision']);
    }

    public function test_historical_policy_snapshot_is_detached_and_default_materialization_is_once(): void
    {
        $captured = $this->policy()->withLockedPolicy(fn (array $policy) => $policy);
        $again = $this->policy()->withLockedPolicy(fn (array $policy) => $policy);
        $this->assertSame($captured['setting_change_id'], $again['setting_change_id']);
        $this->assertSame(1, HealthSafetySettingChange::count());
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        $this->assertSame('application_default', $captured['origin']);
        $this->assertSame(0, $captured['policy_revision']);
        $this->assertSame(Policy::DEFAULT_MODE, $captured['mode']);
        $this->assertNull($captured['reviewed_at']);
        $this->assertSame(1, $this->policy()->snapshot()['policy_revision']);
        $this->assertFalse($this->manager->canDo('restraints.approve'));
    }

    public function test_history_cannot_be_updated_or_deleted_through_model_commands(): void
    {
        $this->policy()->save($this->manager, $this->payload());
        $row = HealthSafetySettingChange::where('revision', 1)->sole();
        foreach ([fn () => $row->update(['reason' => 'Rewritten']), fn () => $row->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Immutable policy provenance must refuse mutation.');
            } catch (LogicException) {
                $this->assertDatabaseHas('health_safety_setting_changes', ['id' => $row->id, 'reason' => 'Reviewed organisation workflow.']);
            }
        }
    }

    public function test_default_materialization_and_all_write_rows_roll_back_when_source_callback_or_audit_fails(): void
    {
        try {
            $this->policy()->withLockedPolicy(fn () => throw new RuntimeException('Injected source command failure.'));
            $this->fail('Source failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected source command failure.', $error->getMessage());
        }
        $this->assertSame(0, HealthSafetySettingChange::count());
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function ($audit): void {
            if (str_starts_with($audit->action, 'health_safety.settings.restraint_plan_approval.')) {
                throw new RuntimeException('Injected strict policy audit failure.');
            }
        });
        try {
            $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
            $this->fail('Audit failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected strict policy audit failure.', $error->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseMissing('app_settings', ['key' => Policy::KEY]);
        $this->assertSame(0, HealthSafetySettingChange::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'health_safety.settings.restraint_plan_approval.%')->count());
    }

    public function test_suppressed_history_inserts_cannot_claim_default_identity_or_accepted_save(): void
    {
        $dispatcher = HealthSafetySettingChange::getEventDispatcher();
        HealthSafetySettingChange::setEventDispatcher(clone $dispatcher);
        HealthSafetySettingChange::creating(fn () => false);
        try {
            $this->policy()->withLockedPolicy(fn ($snapshot) => $snapshot);
            $this->fail('A suppressed default identity must reject the source command.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('must be persisted', $error->getMessage());
        } finally {
            HealthSafetySettingChange::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, HealthSafetySettingChange::count());
        $this->assertDatabaseMissing('app_settings', ['key' => Policy::REVISION_KEY]);
        $this->policy()->withLockedPolicy(fn ($snapshot) => $snapshot);
        HealthSafetySettingChange::setEventDispatcher(clone $dispatcher);
        HealthSafetySettingChange::creating(fn () => false);
        try {
            $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
            $this->fail('A suppressed history receipt must reject the accepted save.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('must be persisted', $error->getMessage());
        } finally {
            HealthSafetySettingChange::setEventDispatcher($dispatcher);
        }
        $this->assertSame(1, HealthSafetySettingChange::count());
        $this->assertDatabaseMissing('app_settings', ['key' => Policy::KEY]);
        $this->assertSame(0, $this->policy()->snapshot()['policy_revision']);
        $this->assertSame(0, AuditLog::where('action', 'like', 'health_safety.settings.restraint_plan_approval.%')->count());
    }

    public function test_suppressed_audit_is_not_accepted_as_success(): void
    {
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(fn ($audit) => ! str_starts_with($audit->action, 'health_safety.settings.restraint_plan_approval.'));
        try {
            $this->policy()->save($this->manager, $this->payload());
            $this->fail('A missing audit must reject the whole command.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('exactly one attributable audit', $error->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, HealthSafetySettingChange::count());
        $this->assertDatabaseMissing('app_settings', ['key' => Policy::KEY]);
    }

    public function test_ambiguous_audit_is_not_resolved_by_selecting_the_latest_row(): void
    {
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        $duplicated = false;
        AuditLog::created(function ($audit) use (&$duplicated): void {
            if (! $duplicated && str_starts_with($audit->action, 'health_safety.settings.restraint_plan_approval.')) {
                $duplicated = true;
                AuditLog::create($audit->only(['user_id', 'client_id', 'action', 'auditable_type', 'auditable_id', 'meta', 'ip_address', 'user_agent']));
            }
        });
        try {
            $this->policy()->save($this->manager, $this->payload());
            $this->fail('An ambiguous audit must reject the whole command.');
        } catch (LogicException) {
            $this->assertTrue($duplicated);
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, HealthSafetySettingChange::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'health_safety.settings.restraint_plan_approval.%')->count());
    }

    public function test_deleted_configuration_and_tampered_counter_cannot_claim_a_default_reset(): void
    {
        $this->policy()->save($this->manager, $this->payload('authorised_reviewer'));
        AppSetting::where('key', Policy::KEY)->delete();
        $this->assertFalse($this->policy()->snapshot()['configuration_valid']);
        AppSetting::where('key', Policy::REVISION_KEY)->firstOrFail()->update(['value' => 0]);
        $this->assertFalse($this->policy()->snapshot()['configuration_valid']);
        $this->assertSame(2, HealthSafetySettingChange::count());
    }

    public function test_audit_attribution_is_the_command_actor_not_an_unrelated_authenticated_user(): void
    {
        $unrelated = $this->staff();
        $this->actingAs($unrelated);
        $receipt = $this->policy()->save($this->manager, $this->payload());
        $history = HealthSafetySettingChange::findOrFail($receipt['setting_change_id']);
        $this->assertSame($this->manager->id, $history->audit->user_id);
        $this->assertSame($this->manager->id, $history->actor_snapshot['id']);
        $this->assertSame($receipt['idempotency_key'], $history->audit->meta['idempotency_key']);
        $this->assertSame('health_safety.settings.restraint_plan_approval.kept', $history->audit->action);
    }

    public function test_rollback_refuses_even_default_provenance_without_erasing_any_setting_or_history(): void
    {
        $this->policy()->withLockedPolicy(fn ($snapshot) => $snapshot);
        $migration = require database_path('migrations/2026_10_08_180500_create_health_safety_approval_policy_settings.php');
        try {
            $migration->down();
            $this->fail('Rollback must retain the default identity already used by source commands.');
        } catch (RuntimeException) {
            $this->assertTrue(Schema::hasTable('health_safety_setting_changes'));
            $this->assertSame(1, HealthSafetySettingChange::count());
            $this->assertDatabaseHas('app_settings', ['key' => Policy::REVISION_KEY]);
        }
    }

    public function test_rollback_refuses_present_null_or_malformed_revision_metadata_without_erasing_it(): void
    {
        $migration = require database_path('migrations/2026_10_08_180500_create_health_safety_approval_policy_settings.php');
        foreach ([null, '', 'not_a_revision', '0', ['revision' => 0]] as $value) {
            $row = AppSetting::updateOrCreate(['key' => Policy::REVISION_KEY], ['value' => $value]);
            try {
                $migration->down();
                $this->fail('Present malformed revision evidence must be retained.');
            } catch (RuntimeException) {
                $this->assertTrue(Schema::hasTable('health_safety_setting_changes'));
                $this->assertSame($value, $row->fresh()->value);
            }
        }
    }

    public function test_rollback_refuses_configuration_even_without_history(): void
    {
        AppSetting::create(['key' => Policy::KEY, 'value' => ['schema_version' => 1, 'mode' => Policy::DEFAULT_MODE]]);
        $migration = require database_path('migrations/2026_10_08_180500_create_health_safety_approval_policy_settings.php');
        try {
            $migration->down();
            $this->fail('Rollback must retain configuration evidence.');
        } catch (RuntimeException) {
            $this->assertTrue(Schema::hasTable('health_safety_setting_changes'));
            $this->assertDatabaseHas('app_settings', ['key' => Policy::KEY]);
        }
    }
}
