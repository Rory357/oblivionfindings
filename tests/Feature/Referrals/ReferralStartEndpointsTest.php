<?php

use App\Domain\Referrals\Models\ReferralStartDecision;
use App\Domain\Referrals\Services\ReferralAgreementTerms;
use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Models\ServiceAgreement;
use App\Models\Site;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralStartFixtures.php';

it('exposes a private bounded review and rejects another person agreement or an inaccessible referral', function () {
    $f = referralStartFixture();
    $this->actingAs($f['manager'])->getJson(route('referrals.service-start.show', $f['referral']->reference))
        ->assertOk()->assertJsonPath('person.id', $f['person']->id)->assertJsonPath('agreements.0.id', $f['agreement']->id)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson(route('referrals.service-start.check', $f['referral']->reference), ['version' => 5, ...$f['input']])->assertOk()->assertJsonPath('ready', true);
    $otherAgreement = ServiceAgreement::factory()->create();
    $this->postJson(route('referrals.service-start.check', $f['referral']->reference), ['version' => 5, ...$f['input'], 'service_agreement_id' => $otherAgreement->id])->assertNotFound();
    $outsider = referralTestUser(Site::factory()->create(), ['viewAny', 'commence', 'assessment.view', 'funding.view']);
    handoverCanonicalPermissions($outsider, ['clients.viewAny', 'clients.update', 'care_plans.viewAny', 'service_agreements.viewAny']);
    $this->actingAs($outsider)->getJson(route('referrals.service-start.show', $f['referral']->reference))->assertNotFound();
    $this->postJson(route('referrals.service-start.update', [$f['referral']->reference, 'schedule']), ['version' => 5, ...$f['input']])->assertNotFound();
    expect(ReferralStartDecision::count())->toBe(0);
});

it('requires a fresh review when canonical terms changed before the approval click', function () {
    $f = referralStartFixture();
    $f['agreement']->update(['terms' => 'New terms supplied while the manager was reviewing.']);
    $this->actingAs($f['manager'])->postJson(route('referrals.service-start.check', $f['referral']->reference), ['version' => 5, ...$f['input']])->assertConflict();
    $this->postJson(route('referrals.service-start.update', [$f['referral']->reference, 'schedule']), ['version' => 5, ...$f['input']])->assertConflict();
    expect(ReferralStartDecision::count())->toBe(0)->and($f['person']->fresh()->status)->toBe('onboarding');
});

it('checks line-level and whole-agreement usage without treating consumption as changed contract terms', function () {
    $f = referralStartFixture();
    $terms = app(ReferralAgreementTerms::class);
    $before = $terms->fingerprint($f['agreement']->fresh());
    $f['line']->update(['budget_used' => '5000']);
    expect($terms->fingerprint($f['agreement']->fresh()))->toBe($before);
    expect(fn () => app(ReferralServiceCommencement::class)->schedule($f['manager'], $f['referral']->id, 5, $f['input']))->toThrow(HttpException::class);
    $f['line']->update(['budget_used' => '0']);
    $f['agreement']->lineItems()->create(['description' => 'Other consumed service', 'unit_price' => '1', 'quantity' => '9000', 'unit' => 'hour', 'budget_allocated' => '9000', 'budget_used' => '9000']);
    $input = [...$f['input'], 'agreement_fingerprint' => $terms->fingerprint($f['agreement']->fresh()), 'submission_key' => (string) Str::uuid()];
    expect(fn () => app(ReferralServiceCommencement::class)->schedule($f['manager'], $f['referral']->id, 5, $input))->toThrow(HttpException::class);
    expect(ReferralStartDecision::count())->toBe(0)->and($f['agreement']->fresh()->budget_used)->toBe('0.00');
});
