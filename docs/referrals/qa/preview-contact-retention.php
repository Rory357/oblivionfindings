<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Privacy\Retention\RetentionExecutionService;
use App\Models\Client;
use App\Models\DataRetentionPolicy;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$statePath = __DIR__.'/runtime/qa-contact-retention-preview.json';
$reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
$permission = Permission::firstOrCreate(['key' => 'privacy.manageRetention'], ['description' => 'Manage data retention policies', 'group' => 'privacy', 'module' => 'Compliance']);
$mode = $argv[2] ?? '';
if ($mode === 'prepare') {
    if (file_exists($statePath)) {
        throw new RuntimeException('Restore the existing retention preview before preparing another.');
    }
    $state = DB::transaction(function () use ($reviewer, $permission, $statePath) {
        $prior = DB::table('permission_user')->where('user_id', $reviewer->id)->where('permission_id', $permission->id)->first();
        $policy = DataRetentionPolicy::create(['model_type' => Client::class, 'policy_name' => 'Fictional contact-retention preview — no eligible people',
            'hard_delete_after_years' => 1, 'retention_conditions' => ['site_id' => -1], 'applies_to_soft_deleted' => true,
            'active_case_exemption' => true, 'legal_hold_exemption' => true, 'active' => true, 'execution_state' => 'draft']);
        $preview = app(RetentionExecutionService::class)->preview($policy, User::where('email', 'author@referrals-preview.test')->sole());
        if ($preview['eligible_count'] !== 0) {
            throw new RuntimeException('Preview must contain no eligible people.');
        }
        $state = ['policy_id' => $policy->id, 'reviewer_id' => $reviewer->id, 'permission_id' => $permission->id, 'prior_allowed' => $prior ? (bool) $prior->allowed : null];
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        $reviewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

        return $state;
    });
    echo json_encode(['prepared' => $state['policy_id'], 'eligible' => 0]).PHP_EOL;
} elseif ($mode === 'stale-preview') {
    $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if ($state['reviewer_id'] !== $reviewer->id || $state['permission_id'] !== $permission->id) {
        throw new RuntimeException('Preview identity mismatch.');
    }
    DB::transaction(function () use ($state, $reviewer, $permission) {
        $policy = DataRetentionPolicy::findOrFail($state['policy_id']);
        if ($policy->active || ! str_starts_with($policy->policy_name, 'Fictional contact-retention preview') || $policy->retention_conditions !== ['site_id' => -1]) {
            throw new RuntimeException('Only the restored empty fictional policy may be used.');
        }
        // Simulate historical approval metadata solely for the stale-state browser check.
        $policy->update(['active' => true, 'execution_state' => 'approved', 'preview_fingerprint' => str_repeat('0', 64), 'approved_fingerprint' => str_repeat('0', 64)]);
        $reviewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    });
    echo json_encode(['stale_fixture' => $state['policy_id'], 'eligible' => 0]).PHP_EOL;
} elseif ($mode === 'restore') {
    if (! file_exists($statePath)) {
        throw new RuntimeException('No saved preview state.');
    }
    $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if ($state['reviewer_id'] !== $reviewer->id || $state['permission_id'] !== $permission->id) {
        throw new RuntimeException('Preview identity mismatch.');
    }
    DB::transaction(function () use ($state, $reviewer, $permission) {
        $policy = DataRetentionPolicy::findOrFail($state['policy_id']);
        if (! str_starts_with($policy->policy_name, 'Fictional contact-retention preview')) {
            throw new RuntimeException('Unexpected policy.');
        }
        $policy->update(['active' => false, 'execution_state' => 'draft', 'approved_fingerprint' => null, 'approved_at' => null, 'approved_by_user_id' => null]);
        if ($state['prior_allowed'] === null) {
            $reviewer->permissionOverrides()->detach($permission->id);
        } else {
            $reviewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $state['prior_allowed']]]);
        }
    });
    $actual = DB::table('permission_user')->where('user_id', $reviewer->id)->where('permission_id', $permission->id)->first();
    if (($actual ? (bool) $actual->allowed : null) !== $state['prior_allowed']) {
        throw new RuntimeException('Permission restoration failed.');
    }
    echo json_encode(['restored' => true, 'policy_inactive' => ! DataRetentionPolicy::findOrFail($state['policy_id'])->active]).PHP_EOL;
} else {
    throw new RuntimeException('Choose prepare or restore.');
}
