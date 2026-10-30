<?php

use App\Domain\Referrals\Models\ReferralProposalReview;
use App\Domain\Referrals\Services\ReferralProposalReadiness;
use App\Domain\Referrals\Services\ReferralProposals;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

beforeEach(function () {
    Storage::fake('private');
});

require_once __DIR__.'/../../Support/ReferralProposalFixtures.php';

it('preserves unknown costs and does not convert budget periods into a misleading total', function () {
    [, $author, , $record, $tax, $case, $decision] = referralProposalFixture();
    $input = referralProposalInput($tax, $case, $decision);
    $input['lines'][] = [...$input['lines'][0], 'key' => 'monthly', 'period' => 'month', 'quantity' => null, 'unit_rate' => null, 'tax_rate_id' => null, 'price_basis' => 'unknown'];
    $proposal = app(ReferralProposals::class)->save($author, $record->id, 1, $input);
    expect($proposal->proposal['totals'])->toHaveCount(2)->and($proposal->proposal['totals'][0]['known_gross'])->toBe('552.00')
        ->and($proposal->proposal['totals'][1]['known'])->toBeFalse()->and($proposal->proposal['lines'][1]['estimate']['gross'])->toBeNull();
});

it('requires separate company approval and person acceptance for the exact proposal revision', function () {
    [, $author, $approver, $record, $tax, $case, $decision, $evidence] = referralProposalFixture();
    $service = app(ReferralProposals::class);
    $input = referralProposalInput($tax, $case, $decision);
    $proposal = $service->save($author, $record->id, 1, $input);
    $author->permissionOverrides()->attach(Permission::where('key', 'referrals.proposals.approve')->firstOrFail(), ['allowed' => true]);
    expect(fn () => $service->approve($author, $record->id, 2, $proposal->id, referralProposalApproval()))->toThrow(HttpException::class);
    $service->accept($author, $record->id, 2, $proposal->id, referralProposalAcceptance($evidence));
    $service->approve($approver, $record->id, 3, $proposal->id, referralProposalApproval());
    expect(app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal)['ready'])->toBeTrue();
    $revision = $service->save($author, $record->id, 4, [...$input, 'previous_proposal_id' => $proposal->id, 'reason' => 'Revised terms require a new review']);
    expect($revision->version)->toBe(2)->and(app(ReferralProposalReadiness::class)->inspect($record->fresh(), $revision)['ready'])->toBeFalse()
        ->and(ReferralProposalReview::where('proposal_id', $proposal->id)->count())->toBe(2)
        ->and(ReferralProposalReview::where('proposal_id', $revision->id)->count())->toBe(0);
    expect(fn () => $proposal->update(['proposal' => []]))->toThrow(LogicException::class);
});

it('detects overlapping allocations and permits only conditional approval while coverage is unresolved', function () {
    [, $author, $approver, $record, $tax, $case, $decision] = referralProposalFixture();
    $input = referralProposalInput($tax, $case, $decision);
    $input['allocations'][] = $input['allocations'][0];
    $service = app(ReferralProposals::class);
    $proposal = $service->save($author, $record->id, 1, $input);
    $readiness = app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal);
    expect($readiness['coverage']['usable'])->toBeFalse();
    expect(fn () => $service->approve($approver, $record->id, 2, $proposal->id, referralProposalApproval()))->toThrow(HttpException::class);
    $service->approve($approver, $record->id, 2, $proposal->id, referralProposalApproval(['result' => 'conditional', 'conditions' => 'Resolve overlapping allocations before any service commitment']));
    expect(app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal)['internal_approval_current'])->toBeFalse();
});

it('enforces approval delegation and currency-period matching without inferring conversions', function () {
    [, $author, $approver, $record, $tax, $case, $decision] = referralProposalFixture();
    $input = referralProposalInput($tax, $case, $decision);
    $input['lines'][0]['period'] = 'month';
    $service = app(ReferralProposals::class);
    $proposal = $service->save($author, $record->id, 1, $input);
    expect(app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal)['coverage']['usable'])->toBeFalse();
    $this->actingAs($approver)->postJson(route('referrals.proposals.review', [$record->reference, $proposal->id, 'approval']), ['version' => 2, ...referralProposalApproval(['result' => 'conditional'])])->assertForbidden();
    $before = ReferralSettings::defaults();
    $after = $before;
    $after['proposals']['approval_limits'][] = ['currency' => 'NZD', 'period' => 'month', 'role_id' => null, 'limit_cents' => 50000];
    expect(app(ReferralSettings::class)->requiresGovernance($before, $after))->toBeTrue();
});

it('invalidates proposal readiness when canonical tax or source evidence changes', function () {
    [, $author, $approver, $record, $tax, $case, $decision, $evidence] = referralProposalFixture();
    $service = app(ReferralProposals::class);
    $proposal = $service->save($author, $record->id, 1, referralProposalInput($tax, $case, $decision));
    $service->accept($author, $record->id, 2, $proposal->id, referralProposalAcceptance($evidence));
    $service->approve($approver, $record->id, 3, $proposal->id, referralProposalApproval());
    $tax->update(['is_active' => false]);
    expect(app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal)['ready'])->toBeFalse();
    $tax->update(['is_active' => true]);
    Storage::disk('private')->put($evidence->storage_path, 'Changed source bytes');
    $readiness = app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal);
    expect($readiness['ready'])->toBeFalse()->and($readiness['person_acceptance_current'])->toBeFalse()->and($readiness['coverage']['usable'])->toBeFalse();
});

it('requires acceptance from the identified private payer separately from the person', function () {
    [, $author, $approver, $record, $tax, $case, $decision, $evidence] = referralProposalFixture(true);
    $service = app(ReferralProposals::class);
    $proposal = $service->save($author, $record->id, 1, referralProposalInput($tax, $case, $decision));
    $service->accept($author, $record->id, 2, $proposal->id, referralProposalAcceptance($evidence));
    $service->approve($approver, $record->id, 3, $proposal->id, referralProposalApproval());
    expect(app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal)['ready'])->toBeFalse();
    $service->accept($author, $record->id, 4, $proposal->id, referralProposalAcceptance($evidence, ['kind' => 'payer_acceptance', 'funding_case_id' => $case->id, 'capacity' => 'payer', 'signatory' => 'Example private payer']));
    expect(app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal)['ready'])->toBeTrue();
    $case->update(['details' => [...$case->details, 'roles' => ['invoice_payer' => 'Different payer']]]);
    expect(app(ReferralProposalReadiness::class)->inspect($record->fresh(), $proposal)['private_payers'][0]['accepted'])->toBeFalse();
});

it('denies proposal history and nested reviews outside the authorized referral', function () {
    [$site, $author, , $record, $tax, $case, $decision] = referralProposalFixture();
    $proposal = app(ReferralProposals::class)->save($author, $record->id, 1, referralProposalInput($tax, $case, $decision));
    $other = referralTestRecord($site);
    $this->actingAs($author)->getJson(route('referrals.proposals.reviews', [$other->reference, $proposal->id]))->assertNotFound();
    $reader = referralTestUser($site, ['viewAny']);
    $this->actingAs($reader)->getJson(route('referrals.proposals.index', $record->reference))->assertForbidden();
});

it('retains approval after assistance completes but rejects withdrawal and dates outside the proposal', function () {
    [, $author, $approver, $record, $tax, $case, $decision, $evidence] = referralProposalFixture();
    $service = app(ReferralProposals::class);
    $input = referralProposalInput($tax, $case, $decision);
    $proposal = $service->save($author, $record->id, 1, $input);
    $service->accept($author, $record->id, 2, $proposal->id, referralProposalAcceptance($evidence));
    $service->approve($approver, $record->id, 3, $proposal->id, referralProposalApproval());
    $case->update(['status' => 'closed']);
    $readiness = app(ReferralProposalReadiness::class);
    expect($readiness->inspect($record->fresh(), $proposal)['ready'])->toBeTrue();
    expect($readiness->inspect($record->fresh(), $proposal, today('Pacific/Auckland')->subDay()->toDateString())['ready'])->toBeFalse();
    expect($readiness->inspect($record->fresh(), $proposal, today('Pacific/Auckland')->addMonth()->addDay()->toDateString())['ready'])->toBeFalse();
    $case->update(['status' => 'withdrawn']);
    expect($readiness->inspect($record->fresh(), $proposal)['internal_approval_current'])->toBeFalse();
});

it('refuses a nonzero canonical tax rate classified as exempt', function () {
    [, $author, , $record, $tax, $case, $decision] = referralProposalFixture();
    $tax->update(['type' => 'exempt']);
    expect(fn () => app(ReferralProposals::class)->save($author, $record->id, 1, referralProposalInput($tax, $case, $decision)))->toThrow(HttpException::class);
});
