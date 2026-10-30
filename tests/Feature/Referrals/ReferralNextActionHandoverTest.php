<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralCasework;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralWorkQueue;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

it('preserves a canonical standalone next action through handover after normal workflow edits', function (string $operation) {
    [, $actor, $referral, , , $input] = conversionFixture();
    handoverCanonicalPermissions($actor, ['referrals.transition', 'referrals.tasks.manage']);
    $oldWork = ReferralWorkItem::where('referral_id', $referral->id)->sole();
    $next = ['next_action' => 'Review pending assistance after onboarding handover', 'next_due_at' => now()->addDays(3)->toISOString(), 'reason' => 'The person agreed the next preparation step'];
    if ($operation === 'stage') {
        app(ReferralLifecycle::class)->move($actor, $referral->id, 1, [...$next, 'stage' => 'funding_assistance']);
    } else {
        app(ReferralCasework::class)->nextAction($actor, $referral->id, 1, $next);
    }
    $referral->refresh();
    expect($referral->primary_work_item_id)->toBeNull();
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral);
    expect($preview['preparation_checks_passed'])->toBeTrue()->and($preview['open_work'])->toBe(2);
    $service = app(ReferralConversionService::class);
    $receipt = $service->convert($actor, $referral->id, 2, $input);
    expect($service->convert($actor, $referral->id, 2, $input))->toBe($receipt);
    $saved = ReferralConversion::where('referral_id', $referral->id)->sole();
    expect($saved->receipt['next_action'])->toBe(['work_item_id' => null, 'owner_id' => $actor->id, 'title' => $next['next_action'], 'due_at' => $referral->fresh()->next_due_at->toISOString()])
        ->and($referral->fresh()->next_action)->toBe($next['next_action'])
        ->and($oldWork->fresh()->status)->toBe('open')
        ->and(ReferralWorkItem::where('referral_id', $referral->id)->count())->toBe(1)
        ->and(app(ReferralWorkQueue::class)->nextActions($actor)->whereKey($referral->id)->count())->toBe(1);
})->with(['stage', 'next_action']);

it('does not replace an invalid linked primary action with an apparently valid standalone label', function () {
    [, $actor, $referral, , , $input] = conversionFixture();
    ReferralWorkItem::findOrFail($referral->primary_work_item_id)->update(['status' => 'completed']);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral);
    expect(collect($preview['checks'])->firstWhere('key', 'next_action')['passed'])->toBeFalse();
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $input))->toThrow(HttpException::class);
    expect(ReferralConversion::where('referral_id', $referral->id)->exists())->toBeFalse();
});
