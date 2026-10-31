<?php

namespace Tests\Feature\Incidents;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IncidentIndependencePolicyTest extends IncidentIndependenceTestCase
{
    private function savePolicy(string $mode = 'assignment_only', int $revision = 0, ?string $uuid = null): array
    {
        return app(Policy::class)->save($this->manager, ['mode' => $mode, 'expected_policy_revision' => $revision,
            'request_uuid' => $uuid ?? (string) Str::uuid(), 'reason' => 'Explicit independent-handling policy review.']);
    }
    public function test_missing_default_is_unreviewed_not_an_organisation_decision(): void
    {
        $dto = app(Policy::class)->editor($this->manager);
        $this->assertSame('block_involved_access', $dto['independence_policy']['mode']);
        $this->assertSame('application_default_unreviewed', $dto['independence_policy']['origin']);
        $this->assertSame(0, $dto['independence_policy']['policy_revision']);
        $this->assertNull($dto['independence_policy']['reviewed_by']); $this->assertNull($dto['independence_policy']['reviewed_at']);
        $this->assertSame([], $dto['independence_history']['data']);
    }
    public function test_settings_only_manager_can_change_policy_without_private_incident_grants(): void
    {
        $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.owner.assign', false);
        $result = $this->savePolicy();
        $this->assertSame('assignment_only', $result['mode']); $this->assertSame(1, $result['policy_revision']);
        $this->assertSame($result, app(Policy::class)->receiptForManager($this->manager, $result['request_uuid']));
        $this->assertCount(8, $result);
    }
    public function test_policy_exact_replay_does_not_add_history_or_audit(): void
    {
        $uuid = (string) Str::uuid(); $first = $this->savePolicy(uuid: $uuid); $count = AuditLog::count();
        $this->assertSame($first, $this->savePolicy(uuid: strtoupper($uuid)));
        $this->assertSame(1, HealthSafetySettingChange::where('setting_key', Policy::KEY)->count()); $this->assertSame($count, AuditLog::count());
    }
    public function test_stale_policy_preserves_previous_configuration(): void
    {
        $this->savePolicy(); $this->validation(fn () => $this->savePolicy('block_involved_access'), 'expected_policy_revision');
        $this->assertSame('assignment_only', app(Policy::class)->editor($this->manager)['independence_policy']['mode']);
    }
    public function test_uuid_cannot_cross_routing_or_change_intent(): void
    {
        $routing = $this->settings(); $this->validation(fn () => $this->savePolicy(uuid: $routing['request_uuid']), 'request_uuid');
        $first = $this->savePolicy(); $this->validation(fn () => $this->savePolicy('block_involved_access', 1, $first['request_uuid']), 'request_uuid');
    }
    public function test_settings_only_actor_rejects_declaration_uuid_before_private_acceptance_materialisation(): void
    {
        $command = $this->command(); $this->accept($command);
        $this->allow($this->manager, 'incidents.viewAny', false);
        $this->allow($this->manager, 'incidents.owner.assign', false);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->validation(fn () => $this->savePolicy(uuid: $command['request_uuid']), 'request_uuid');
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        $identityQueries = array_values(array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'health_safety_setting_changes')
            && in_array($command['request_uuid'], $query['bindings'], true)));
        $this->assertCount(1, $identityQueries);
        $sql = strtolower($identityQueries[0]['query']);
        $this->assertStringNotContainsString('select *', $sql);
        foreach (['after_value', 'before_value', 'accepted_result', 'actor_snapshot', 'fingerprint', 'reason'] as $privateColumn) {
            $this->assertStringNotContainsString($privateColumn, $sql);
        }
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', Policy::KEY)->count());
    }
    public function test_current_settings_grant_and_staff_withdrawal_precede_receipt(): void
    {
        $first = $this->savePolicy(); $this->allow($this->manager, Policy::PERMISSION, false);
        $this->denial(fn () => app(Policy::class)->receiptForManager($this->manager, $first['request_uuid']));
        $this->allow($this->manager, Policy::PERMISSION); $this->assertSame($first, app(Policy::class)->receiptForManager($this->manager, $first['request_uuid']));
        DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->denial(fn () => app(Policy::class)->editor($this->manager));
    }
    public function test_receipt_is_actor_bound(): void
    {
        $first = $this->savePolicy(); $other = $this->staff([Policy::PERMISSION]);
        $this->assertNull(app(Policy::class)->receiptForManager($other, $first['request_uuid']));
    }
    public function test_malformed_present_configuration_is_unusable_not_default(): void
    {
        AppSetting::create(['key' => Policy::KEY, 'value' => ['mode' => 'invented']]);
        $dto = app(Policy::class)->editor($this->manager)['independence_policy'];
        $this->assertFalse($dto['configuration_valid']); $this->assertNull($dto['mode']); $this->assertSame('repair_required', $dto['origin']);
    }
    public function test_acceptance_audit_tamper_is_detected_without_relabeling_history(): void
    {
        $first = $this->savePolicy(); $row = HealthSafetySettingChange::where('command_key', $first['request_uuid'])->firstOrFail();
        AuditLog::whereKey($row->audit_id)->update(['meta' => json_encode(['accepted' => true])]);
        $this->validation(fn () => app(Policy::class)->receiptForManager($this->manager, $first['request_uuid']), 'policy');
        $this->assertSame('repair_required', app(Policy::class)->editor($this->manager)['independence_history']['data'][0]['integrity']);
    }
    public function test_policy_save_veto_is_atomic(): void
    {
        $this->veto(HealthSafetySettingChange::class, fn () => $this->savePolicy());
        $this->assertDatabaseMissing('app_settings', ['key' => Policy::KEY]);
        $this->assertSame(0, HealthSafetySettingChange::where('setting_key', Policy::KEY)->count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'incidents.independence.policy.%')->count());
    }
    public function test_policy_editor_never_materialises_declaration_acceptances(): void
    {
        $accepted = $this->accept($this->command()); $queries = []; DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $dto = app(Policy::class)->editor($this->manager); $this->assertSame([], $dto['independence_history']['data']);
        $this->assertStringNotContainsString('incident_independent_handling', implode(' ', $queries));
        $this->assertStringNotContainsString((string) $accepted['request_uuid'], json_encode($dto));
    }
    public function test_rollback_refuses_present_null_policy_and_declaration_history(): void
    {
        $migration = require database_path('migrations/2026_10_12_200800_add_incident_independent_handling.php');
        AppSetting::create(['key' => Policy::KEY, 'value' => null]);
        try { $migration->down(); $this->fail('Expected destructive rollback refusal.'); } catch (\RuntimeException) { $this->assertDatabaseHas('app_settings', ['key' => Policy::KEY]); }
    }
}
