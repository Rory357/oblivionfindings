<?php

// Exact disposable preview bootstrap; every scenario mutation rolls back.
require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Services\ReferralSettingsMigration;
use App\Models\Client;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

$actor = User::where('email', 'author@referrals-preview.test')->sole();
$record = CareReferral::where('reference', '01M55ZQRKFN2MMX8HVQ1DXY9V2')->sole();
if (($record->identity['first_name'] ?? '') !== 'Fictional') {
    throw new RuntimeException('Fictional journey required.');
}
$checks = [];
$check = function ($condition, $name) use (&$checks) {
    if (! $condition) {
        throw new RuntimeException($name);
    } $checks[] = $name;
};
$service = app(ReferralSettingsMigration::class);
DB::beginTransaction();
try {
    $preview = $service->preview($actor, $record);
    $check($preview['ready'] && count($preview['funding']) > 0, 'Preview identifies old referral and funding guide versions');
    $fundingCase = ReferralFundingCase::where('referral_id', $record->id)->sole();
    $originalDetails = $fundingCase->details;
    $fundingCase->update(['details' => [...$originalDetails, 'service_id' => 'incompatible_qa_service']]);
    $incompatible = $service->preview($actor, $record);
    $check(! $incompatible['ready'], 'Preview blocks an incompatible open funding service');
    try {
        $service->apply($actor, $record->id, $record->lock_version, ['target_id' => $incompatible['target']['id'], 'preview_token' => $incompatible['preview_token'],
            'reason' => 'Fictional incompatible migration must be blocked', 'acknowledge_reviews' => true, 'submission_key' => (string) Str::uuid()]);
        throw new RuntimeException('Incompatible migration accepted');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 422, 'Direct apply cannot bypass incompatible funding');
    }
    $fundingCase->update(['details' => $originalDetails]);
    $input = ['target_id' => $preview['target']['id'], 'preview_token' => $preview['preview_token'], 'reason' => 'Fictional QA migration and review test only', 'acknowledge_reviews' => true, 'submission_key' => (string) Str::uuid()];
    try {
        $service->apply($actor, $record->id, $record->lock_version, [...$input, 'preview_token' => str_repeat('0', 64)]);
        throw new RuntimeException('Stale preview accepted');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 409, 'Reject a stale or altered preview');
    }
    $before = $record->only(['owner_id', 'site_id', 'next_due_at', 'authority']);
    $decisions = ReferralFundingDecision::whereIn('funding_case_id', ReferralFundingCase::where('referral_id', $record->id)->select('id'))->get()->toJson();
    $result = $service->apply($actor, $record->id, $record->lock_version, $input);
    $check($result['saved'] && (int) $record->fresh()->settings_version_id === $input['target_id'], 'Apply current publication');
    $check($record->fresh()->only(['owner_id', 'site_id', 'next_due_at', 'authority']) == $before, 'Preserve ownership, site, due date and authority evidence');
    $case = ReferralFundingCase::where('referral_id', $record->id)->sole();
    $check((int) $case->settings_version_id === $input['target_id'] && $case->details['verification']['status'] === 'review_required', 'Update open guide and require funding reverification');
    $check(ReferralFundingDecision::where('funding_case_id', $case->id)->get()->toJson() === $decisions, 'Preserve immutable funding decisions');
    $check(Client::findOrFail($record->client_id)->status === 'active', 'Do not stop an active supported person');
    $start = ReferralServiceStart::where('referral_id', $record->id)->sole();
    $check($start->status === 'commenced' && $start->handover['review_required'], 'Preserve commencement and flag service readiness review');
    $check($service->apply($actor, $record->id, $record->lock_version, $input) === $result, 'Exact migration retry returns the same receipt');
    try {
        $service->apply($actor, $record->id, $record->lock_version, [...$input, 'reason' => 'Changed retry must not be accepted']);
        throw new RuntimeException('Changed retry accepted');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 409, 'Reject reused migration key with a different reason');
    }
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.edit')->sole()->id, ['allowed' => false]);
    try {
        $service->apply($actor->fresh(), $record->id, $record->lock_version, $input);
        throw new RuntimeException('Revoked access replayed');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 403, 'Recheck revoked action access before retry receipt');
    }
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.viewAny')->sole()->id, ['allowed' => false]);
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.viewAssigned')->sole()->id, ['allowed' => false]);
    try {
        $service->preview($actor->fresh(), $record);
        throw new RuntimeException('Hidden referral previewed');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 404, 'Settings governance does not grant referral visibility');
    }
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
