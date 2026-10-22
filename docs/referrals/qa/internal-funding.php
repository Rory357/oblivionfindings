<?php

// Only the disposable preview schema is extended. All scenario data is rolled back.
require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralInternalArrangement;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralInternalFunding;
use App\Domain\Referrals\Services\ReferralProposalCoverage;
use App\Domain\Referrals\Services\ReferralProposalReadiness;
use App\Domain\Referrals\Services\ReferralProposals;
use App\Models\Client;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

if (! Schema::hasTable('care_referral_internal_arrangements')) {
    (require dirname(__DIR__, 3).'/database/migrations/2026_10_18_150800_create_referral_internal_arrangements.php')->up();
}
$actor = User::where('email', 'author@referrals-preview.test')->sole();
$reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
$referral = CareReferral::where('reference', '01M55ZQRKFN2MMX8HVQ1DXY9V2')->sole();
if (($referral->identity['first_name'] ?? '') !== 'Fictional') {
    throw new RuntimeException('Fictional referral required.');
}
$checks = [];
$check = function ($condition, $name) use (&$checks) {
    if (! $condition) {
        throw new RuntimeException($name);
    } $checks[] = $name;
};
$denied = function (callable $action, int $status, string $name) use ($check) {
    try {
        $action();
        throw new RuntimeException('Unexpected acceptance: '.$name);
    } catch (HttpException $error) {
        $check($error->getStatusCode() === $status, $name.' ('.$error->getStatusCode().')');
    }
};
$service = app(ReferralInternalFunding::class);
$proposals = app(ReferralProposals::class);
$coverage = app(ReferralProposalCoverage::class);
$publish = function (array $configuration) {
    return ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published', 'configuration' => $configuration, 'effective_at' => now(), 'published_at' => now()]);
};
DB::beginTransaction();
try {
    $old = $proposals->latest($referral);
    $check($coverage->inspect($referral, $old->proposal)['usable'], 'Existing external funding remains usable without an internal arrangement');
    $check(app(ReferralProposalReadiness::class)->inspect($referral, $old)['internal_approval_current'], 'Existing independent proposal approval token remains valid without an internal arrangement');
    $body = Arr::only($old->proposal, ['title', 'starts_on', 'ends_on', 'support_rationale', 'funding_position', 'capacity_plan', 'person_participation', 'reason', 'lines', 'allocations']);
    $body['previous_proposal_id'] = $old->id;
    $body['title'] = 'Fictional temporary company support';
    $body['allocations'] = [];
    $body['lines'] = array_map(fn ($line) => [...Arr::only($line, ['key', 'service_id', 'description', 'quantity', 'unit', 'unit_label', 'period', 'currency', 'unit_rate', 'tax_rate_id', 'price_basis', 'price_source']), 'period' => 'total'], $body['lines']);
    $proposal = $proposals->save($actor, $referral->id, $referral->fresh()->lock_version, $body);
    $input = ['payer' => 'Fictional company budget', 'budget_reference' => 'FICTIONAL-QA-TEMP-001', 'cost_cap' => '600.00',
        'reason' => 'Fictional QA temporary company commitment only', 'review_due_at' => now()->addDay()->toISOString(), 'evidence_ids' => [3]];
    $denied(fn () => $service->prepare($actor, $referral->id, $referral->fresh()->lock_version, $proposal->id, $input), 422, 'Disabled setting blocks preparation');
    $configuration = $version->configuration;
    $configuration['proposals']['temporary_funding_enabled'] = true;
    $publish($configuration);
    $gap = $service->gap($referral, $proposal);
    $check($gap['ready'] && $gap['gross'] === '552.00', 'Full-term gap includes canonical recorded tax');
    $denied(fn () => $service->prepare($actor, $referral->id, $referral->fresh()->lock_version, $proposal->id, [...$input, 'cost_cap' => '10']), 422, 'Cap below the full-period gap is rejected');
    $item = $service->prepare($actor, $referral->id, $referral->fresh()->lock_version, $proposal->id, $input);
    $check(! $coverage->inspect($referral, $proposal->proposal)['usable'], 'Prepared company arrangement does not count as approved coverage');
    $decision = ['result' => 'approved', 'note' => 'Fictional QA independent company review only'];
    $denied(fn () => $service->decide($actor, $referral->id, $referral->fresh()->lock_version, $item->id, $decision), 422, 'Author cannot approve own company arrangement');
    $denied(fn () => $service->decide($reviewer, $referral->id, $referral->fresh()->lock_version, $item->id, $decision), 403, 'Weekly delegation cannot approve a full-period company cap');
    $configuration['proposals']['approval_limits'][] = ['currency' => 'NZD', 'period' => 'total', 'role_id' => null, 'limit_cents' => 100000];
    $publish($configuration);
    $beforeToken = $proposals->token($referral, $proposal);
    $service->decide($reviewer, $referral->id, $referral->fresh()->lock_version, $item->id, $decision);
    $check($coverage->inspect($referral, $proposal->proposal)['usable'], 'Independently approved exact arrangement covers the gap');
    $check($proposals->token($referral, $proposal) !== $beforeToken, 'Company approval changes proposal prerequisite token');
    $task = ReferralWorkItem::where('referral_id', $referral->id)->where('audience', 'funding')->get()->first(fn ($work) => ($work->details['arrangement_id'] ?? null) === $item->id);
    $check($task && (int) $task->owner_id === $reviewer->id, 'Approval creates dated review assigned to the delegate');
    $denied(fn () => $service->decide($reviewer, $referral->id, $referral->fresh()->lock_version, $item->id, $decision), 409, 'Duplicate approval cannot create another review task');
    $check(! $service->availability($referral, $item, now()->addDays(2)->toDateString())['usable'], 'Future start after review deadline is blocked');
    $document = ReferralEvidence::findOrFail(3);
    $metadata = $document->metadata;
    $document->update(['metadata' => [...$metadata, 'superseded_by_id' => 999]]);
    $check(! $coverage->inspect($referral, $proposal->proposal)['usable'], 'Superseded evidence removes usable company coverage');
    $document->update(['metadata' => $metadata]);
    $service->decide($reviewer, $referral->id, $referral->fresh()->lock_version, $item->id, ['result' => 'revoked', 'note' => 'Fictional QA revocation audit only']);
    $check(! $coverage->inspect($referral, $proposal->proposal)['usable'], 'Revocation removes company coverage');
    $check(Client::findOrFail($referral->client_id)->status === 'active', 'Revocation does not silently stop active care');
    $reviewer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.proposals.approve')->sole()->id, ['allowed' => false]);
    $denied(fn () => $service->decide($reviewer->fresh(), $referral->id, $referral->fresh()->lock_version, $item->id, $decision), 403, 'Revoked approval permission is rechecked');
    foreach (['viewAny', 'viewAssigned'] as $key) {
        $reviewer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.'.$key)->sole()->id, ['allowed' => false]);
    }
    $denied(fn () => $service->decide($reviewer->fresh(), $referral->id, $referral->fresh()->lock_version, $item->id, $decision), 404, 'Company funding action does not grant parent visibility');
    $check(ReferralInternalArrangement::where('referral_id', $referral->id)->count() === 1, 'Only one immutable arrangement for the exact proposal');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
