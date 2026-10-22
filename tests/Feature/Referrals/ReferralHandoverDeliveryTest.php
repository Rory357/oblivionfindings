<?php

use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralDelivery;
use App\Models\ClientOnboardingWorkflow;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

it('delivers a handover once to the current eligible owner instead of the conversion-time owner', function () {
    [$site, $formerOwner, $referral, , , $input] = conversionFixture();
    $receipt = app(ReferralConversionService::class)->convert($formerOwner, $referral->id, 1, $input);
    $owner = referralTestUser($site, []);
    handoverCanonicalPermissions($owner, ['clients.viewAny', 'clients.update', 'onboarding.view']);
    ClientOnboardingWorkflow::findOrFail($receipt['workflow_id'])->update(['assigned_to' => $owner->id]);
    $row = ReferralOutbox::where('referral_id', $referral->id)->where('kind', 'handover_committed')->sole();
    $delivery = app(ReferralDelivery::class);
    $delivery->deliver($row->id);
    $delivery->deliver($row->id);
    $notices = DB::table('notifications')->where('data->kind', 'referral_handover')->get();
    expect($row->fresh()->state)->toBe('delivered')->and($notices)->toHaveCount(1)
        ->and((int) $notices->first()->notifiable_id)->toBe($owner->id)
        ->and($notices->first()->data)->not->toContain('Casey')->not->toContain($referral->reference)
        ->and(json_decode($notices->first()->data, true)['url'])->toBe('/operations/onboarding');
});

it('holds the handover notice when its owner is no longer current staff', function () {
    [, $owner, $referral, , , $input] = conversionFixture();
    app(ReferralConversionService::class)->convert($owner, $referral->id, 1, $input);
    $owner->hrEmployeeProfile->update(['is_active' => false]);
    $row = ReferralOutbox::where('referral_id', $referral->id)->where('kind', 'handover_committed')->sole();
    app(ReferralDelivery::class)->deliver($row->id);
    expect($row->fresh()->state)->toBe('pending')->and($row->fresh()->last_error_code)->toBe('no_eligible_handover_owner')
        ->and(DB::table('notifications')->where('data->kind', 'referral_handover')->count())->toBe(0);
});

it('cancels an obsolete onboarding notice without claiming delivery', function () {
    [, $owner, $referral, , , $input] = conversionFixture();
    $receipt = app(ReferralConversionService::class)->convert($owner, $referral->id, 1, $input);
    ClientOnboardingWorkflow::findOrFail($receipt['workflow_id'])->update(['status' => 'completed']);
    $row = ReferralOutbox::where('referral_id', $referral->id)->where('kind', 'handover_committed')->sole();
    app(ReferralDelivery::class)->deliver($row->id);
    expect($row->fresh()->state)->toBe('cancelled')->and($row->fresh()->delivered_at)->toBeNull()
        ->and(DB::table('notifications')->where('data->kind', 'referral_handover')->count())->toBe(0);
});
