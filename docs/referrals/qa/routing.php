<?php

// All mutations are fictional, exact-preview-only and rolled back.
require __DIR__.'/inspect-delivery.php';

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\PublicReferralIntake;
use App\Domain\Referrals\Services\ReferralIntakeFunding;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralRouting;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\StaffReferralIntake;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$author = User::where('email', 'author@referrals-preview.test')->sole();
$reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
$checks = [];
$check = function ($condition, $name) use (&$checks) {
    if (! $condition) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
DB::beginTransaction();
try {
    $configuration = $version->configuration;
    $configuration['routing'] = ['enabled' => true, 'fallback_owner_ids' => [$author->id], 'fallback_max_open' => 1000, 'rules' => [
        ['id' => 'qa_auckland', 'label' => 'Fictional Auckland team', 'enabled' => true, 'service_ids' => ['supported_living'], 'regions' => ['Auckland'],
            'site_id' => $author->hrEmployeeProfile->primary_site_id, 'team' => 'Fictional routing QA', 'owner_ids' => [$reviewer->id], 'max_open' => 1000],
    ]];
    $service = app(ReferralRouting::class);
    $settings = app(ReferralSettings::class);
    $check($settings->validate(ReferralSettings::defaults())['routing']['enabled'] === false, 'Disabled default routing remains valid without fallback owners');
    $configuration = $settings->validate($configuration);
    $repeated = $configuration;
    $repeated['routing']['rules'][] = [...$configuration['routing']['rules'][0], 'id' => 'qa_second_rule'];
    $check(count($settings->validate($repeated)['routing']['rules']) === 2, 'Different routing rules may share regions and eligible owners');
    foreach (['owner_ids', 'regions'] as $key) {
        $bad = $configuration;
        $bad['routing']['rules'][0][$key][] = $bad['routing']['rules'][0][$key][0];
        try {
            $settings->validate($bad);
            throw new RuntimeException('Duplicate value within one routing rule accepted');
        } catch (ValidationException) {
            $checks[] = 'Repeated '.$key.' within one rule rejected';
        }
    }
    $check($service->choose($configuration, 'supported_living', ' AUCKLAND ')['owner_id'] === $reviewer->id, 'Match service and normalized region');
    $check($service->choose($configuration, 'unsure', 'Auckland')['owner_id'] === $author->id, 'Nonmatching service uses fallback');
    $check($service->choose($configuration, 'supported_living', 'Waikato')['owner_id'] === $author->id, 'Nonmatching region uses fallback');
    $leave = HrLeaveRequest::factory()->create(['user_id' => $reviewer->id, 'status' => 'approved', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $check($service->choose($configuration, 'supported_living', 'Auckland')['owner_id'] === $author->id, 'Approved leave uses fallback without exposing leave details');
    $leave->update(['status' => 'cancelled']);
    foreach (['viewAny', 'viewAssigned'] as $key) {
        $reviewer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.'.$key)->sole()->id, ['allowed' => false]);
    }
    $check($service->choose($configuration, 'supported_living', 'Auckland')['owner_id'] === $author->id, 'Revoked read access cannot receive an assignment');
    try {
        $settings->validate($configuration);
        throw new RuntimeException('Invalid candidates were accepted');
    } catch (ValidationException) {
        $checks[] = 'Publication validation rejects ineligible rule owners';
    }
    $reviewer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.viewAssigned')->sole()->id, ['allowed' => true]);
    $configuration['routing']['rules'][0]['max_open'] = 1;
    $configuration['routing']['fallback_max_open'] = 1;
    foreach ([$author, $reviewer] as $owner) {
        CareReferral::create(['settings_version_id' => $version->id, 'site_id' => $owner->hrEmployeeProfile->primary_site_id, 'owner_id' => $owner->id,
            'source' => 'phone', 'entry_kind' => 'self', 'identity' => [], 'intake' => [], 'authority' => [], 'assessment' => [],
            'next_action' => 'Fictional workload fixture', 'next_due_at' => now()->addDay(), 'stage_changed_at' => now()]);
    }
    $fallback = $service->choose($configuration, 'supported_living', 'Auckland');
    $check($fallback['owner_id'] === null && $fallback['site_id'] === null, 'Full rule and fallback stay in central queue');
    $configuration['routing']['rules'][0]['max_open'] = 1000;
    $configuration['routing']['fallback_max_open'] = 1000;
    $configuration['routing']['rules'][0]['funding_situations'] = ['need_help', 'underway'];
    $configuration['routing']['rules'][0]['pathway_ids'] = ['dss_nasc'];
    $configuration['routing']['rules'][0]['label'] = 'Fictional sensitive funding navigator';
    $settings->validate($configuration);
    $check($service->choose($configuration, 'supported_living', 'Auckland', fundingSituation: 'need_help', pathwayId: 'dss_nasc')['owner_id'] === $reviewer->id, 'Funding situation and reported pathway select the eligible navigator');
    $check($service->choose($configuration, 'supported_living', 'Auckland', fundingSituation: 'private', pathwayId: 'dss_nasc')['owner_id'] === $author->id, 'Nonmatching funding situation uses fallback');
    $check($service->choose($configuration, 'supported_living', 'Auckland', fundingSituation: 'need_help', pathwayId: 'acc')['owner_id'] === $author->id, 'Nonmatching pathway uses fallback');
    $check($service->choose($configuration, 'supported_living', 'Auckland')['owner_id'] === $author->id, 'Unknown funding context never guesses a pathway');
    foreach (['funding_situations', 'pathway_ids'] as $key) {
        $bad = $configuration;
        $bad['routing']['rules'][0][$key][] = $bad['routing']['rules'][0][$key][0];
        try {
            $settings->validate($bad);
            throw new RuntimeException('Repeated funding routing condition accepted');
        } catch (ValidationException) {
            $checks[] = 'Repeated '.$key.' within one rule rejected';
        }
    }
    $fundingPermission = Permission::where('key', 'referrals.funding.view')->sole();
    $reviewer->permissionOverrides()->updateExistingPivot($fundingPermission->id, ['allowed' => false]);
    $check($service->choose($configuration, 'supported_living', 'Auckland', fundingSituation: 'need_help', pathwayId: 'dss_nasc')['owner_id'] === $author->id, 'Revoked funding access cannot receive funding-rule assignment');
    try {
        $settings->validate($configuration);
        throw new RuntimeException('Funding rule accepted an owner without funding access');
    } catch (ValidationException) {
        $checks[] = 'Publication rejects funding-rule owner without funding access';
    }
    $reviewer->permissionOverrides()->updateExistingPivot($fundingPermission->id, ['allowed' => true]);
    $candidate = ReferralSettingsVersion::create(['version' => $version->version + 1, 'state' => 'published', 'configuration' => $configuration,
        'published_at' => now(), 'effective_at' => now()]);
    $payload = ['submission_token' => (string) Str::uuid(), 'settings_version_id' => $candidate->id, 'notice_version' => $configuration['public']['notice_version'],
        'purpose' => 'support', 'relationship' => 'self', 'authority_status' => 'self',
        'contact' => ['name' => 'Fictional routing test', 'email' => '', 'phone' => '0200000000', 'channel' => 'phone', 'safe_time' => '', 'leave_message' => false, 'communication' => ''],
        'person' => ['first_name' => 'Fictional', 'last_name' => 'Routing', 'preferred_name' => ''], 'region' => 'Auckland', 'requested_service' => 'supported_living',
        'goals' => 'Fictional routing verification only', 'funding_situation' => 'need_help', 'funding_pathway_id' => 'dss_nasc', 'contact_permission' => true, 'privacy_acknowledged' => true, 'website' => ''];
    $intake = app(PublicReferralIntake::class);
    $receipt = $intake->submit($payload);
    $record = CareReferral::where('reference', $receipt['reference'])->sole();
    $check((int) $record->owner_id === $reviewer->id && (int) $record->site_id === (int) $configuration['routing']['rules'][0]['site_id'], 'Actual public intake applies only published server routing');
    $check($receipt === $intake->submit($payload), 'Routed intake remains idempotent');
    $check(array_keys($receipt) === ['reference', 'status'], 'Public receipt reveals no assignment metadata');
    $check($record->intake['reported_pathway']['id'] === 'dss_nasc' && $record->intake['reported_pathway']['status'] === 'reported_unverified', 'Encrypted intake preserves unverified pathway wording');
    $check(! str_contains(DB::table('care_referrals')->where('id', $record->id)->value('intake'), 'reported_unverified'), 'Reported pathway is encrypted at rest');
    $check(DB::table('care_referral_funding_cases')->where('referral_id', $record->id)->count() === 0 && $record->client_id === null, 'Reported pathway creates neither funding approval nor a supported person');
    $author->permissionOverrides()->updateExistingPivot($fundingPermission->id, ['allowed' => false]);
    $projection = app(ReferralPresenter::class)->detail($author->fresh(), $record);
    $check(! isset($projection['funding_intake']) && ! str_contains(json_encode($projection), 'Fictional sensitive funding navigator'), 'Funding-restricted reader cannot see reported pathway or routing explanation');
    $author->permissionOverrides()->updateExistingPivot($fundingPermission->id, ['allowed' => true]);
    foreach ([['funding_pathway_id' => 'not_a_pathway'], ['funding_pathway_id' => '0'], ['purpose' => 'business', 'person' => []], ['authority_status' => 'unsure', 'person' => []]] as $patch) {
        try {
            $intake->submit([...$payload, ...$patch, 'submission_token' => (string) Str::uuid()]);
            throw new RuntimeException('Invalid or minimal-enquiry pathway accepted');
        } catch (ValidationException) {
            $checks[] = 'Invalid/minimal enquiry pathway rejected: '.implode(',', array_keys($patch));
        }
    }
    $baseWithoutPathway = $payload;
    unset($baseWithoutPathway['funding_pathway_id']);
    $check($intake->validate($baseWithoutPathway) === $intake->validate([...$baseWithoutPathway, 'funding_pathway_id' => null]), 'Empty new field preserves legacy normalized replay payload');
    $privateConfiguration = $configuration;
    foreach ($privateConfiguration['pathways'] as &$pathway) {
        if ($pathway['id'] === 'dss_nasc') {
            $pathway['enabled'] = false;
        }
    }
    unset($pathway);
    try {
        $settings->validate($privateConfiguration);
        throw new RuntimeException('Disabled routing pathway accepted');
    } catch (ValidationException) {
        $checks[] = 'Enabled rule cannot target a disabled pathway';
    }
    try {
        ReferralIntakeFunding::snapshot($privateConfiguration, $payload);
        throw new RuntimeException('Disabled intake pathway accepted');
    } catch (ValidationException) {
        $checks[] = 'Disabled intake pathway rejected';
    }
    $check(array_keys(ReferralIntakeFunding::options($configuration)[0]) === ['id', 'label', 'personal_income', 'service_ids'], 'Public pathway projection excludes private evidence and policy fields');
    $incomeReceipt = $intake->submit([...$payload, 'submission_token' => (string) Str::uuid(), 'funding_pathway_id' => 'personal_income']);
    $incomeRecord = CareReferral::where('reference', $incomeReceipt['reference'])->sole();
    $check($incomeRecord->intake['reported_pathway']['personal_income'] && (int) $incomeRecord->owner_id === $author->id
        && DB::table('care_referral_funding_cases')->where('referral_id', $incomeRecord->id)->count() === 0, 'Income-assistance enquiry stays separate from provider funding and follows its matching fallback');
    $phone = app(StaffReferralIntake::class)->create($author->fresh(), [
        'form' => [...$payload, 'submission_token' => (string) Str::uuid()], 'source' => 'phone', 'site_id' => $author->hrEmployeeProfile->primary_site_id,
        'successful_contact' => false, 'notice_method' => 'written', 'notice_reason' => null, 'next_action' => 'Fictional phone intake verification', 'next_due_at' => now()->addDay()->toISOString(),
    ]);
    $check($phone->intake['reported_pathway']['id'] === 'dss_nasc' && (int) $phone->owner_id === $author->id, 'Phone intake retains the reported pathway and its explicitly accountable recording owner');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
