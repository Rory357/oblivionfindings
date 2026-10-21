<?php

// Bootstrap only the guarded disposable preview. All scenario changes roll back.
require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Services\ReferralSettingsImpact;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$actor = User::where('email', 'author@referrals-preview.test')->sole();
$service = app(ReferralSettingsImpact::class);
$checks = [];
$check = function (bool $condition, string $name) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
$grant = function (string $key, bool $allowed) use ($actor): void {
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.'.$key)->sole()->id, ['allowed' => $allowed]);
};
DB::beginTransaction();
try {
    foreach (['viewAllSites', 'centralIntake', 'restricted'] as $key) {
        $grant($key, false);
    }
    $configuration = $version->configuration;
    $configuration['services'][0]['enabled'] = false;
    foreach ($configuration['pathways'] as &$pathway) {
        $pathway['enabled'] = false;
    }
    unset($pathway);
    // Candidate snapshot only; it is never saved or published.
    $candidate = clone $version;
    $candidate->configuration = $configuration;
    $before = $service->inspect($actor->fresh(), $candidate);
    $site = $actor->hrEmployeeProfile->primary_site_id;
    $other = Site::create(['name' => 'Fictional impact QA other site', 'type' => 'residential', 'is_active' => true, 'archived' => false]);
    $create = fn (array $overrides = []) => CareReferral::create([
        'settings_version_id' => $version->id, 'site_id' => $site, 'owner_id' => null,
        'source' => 'phone', 'entry_kind' => 'self', 'requested_service' => $configuration['services'][0]['id'],
        'stage' => 'new', 'identity' => ['first_name' => 'Fictional impact QA'], 'intake' => [],
        'authority' => [], 'assessment' => [], 'stage_changed_at' => now(),
        'next_action' => 'Fictional impact check only', 'next_due_at' => now()->addDay(), ...$overrides,
    ]);
    $visible = $create();
    $create(['site_id' => $other->id]);
    $create(['site_id' => null]);
    $restricted = $create(['restricted' => true]);
    $create(['stage' => 'closed']);
    foreach ([$visible, $restricted] as $referral) {
        ReferralFundingCase::create(['referral_id' => $referral->id, 'settings_version_id' => $version->id,
            'pathway' => 'dss_nasc', 'status' => 'draft', 'personal_income' => false, 'details' => []]);
    }
    $after = $service->inspect($actor->fresh(), $candidate);
    $check($after['open_referrals'] === $before['open_referrals'] + 1, 'Exclude other sites, central intake, restricted and closed referrals');
    $check($after['without_owner'] === $before['without_owner'] + 1, 'Count only visible missing owners');
    $check($after['outside_enabled_services'] === $before['outside_enabled_services'] + 1, 'Show visible disabled-service impact');
    $check($after['funding_outside_enabled_pathways'] === $before['funding_outside_enabled_pathways'] + 1, 'Funding impact excludes restricted parent referrals');
    $grant('funding.view', false);
    $check($service->inspect($actor->fresh(), $candidate)['funding_outside_enabled_pathways'] === null, 'Do not disclose funding totals without audience permission');
    $grant('viewAny', false);
    $grant('viewAssigned', false);
    $empty = $service->inspect($actor->fresh(), $candidate);
    $check($empty['open_referrals'] === 0 && $empty['without_owner'] === 0, 'Settings access does not grant referral visibility');
    $grant('viewAssigned', true);
    $assignedBefore = $service->inspect($actor->fresh(), $candidate)['open_referrals'];
    $visible->update(['owner_id' => $actor->id]);
    $check($service->inspect($actor->fresh(), $candidate)['open_referrals'] === $assignedBefore + 1, 'Assigned-only impact follows record ownership');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
