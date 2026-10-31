<?php

use App\Domain\Referrals\Services\ReferralRespiteReview;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\Site;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralStartFixtures.php';

it('offers the matching service-start review only to the currently eligible reviewer', function () {
    $f = referralStartFixture(false, 'planned_respite');
    $booking = RespiteBooking::factory()->create(['client_id' => $f['person']->id, 'location_id' => $f['site']->id,
        'service_agreement_id' => $f['agreement']->id, 'start_at' => now(), 'end_at' => now()->addDay()]);
    $review = app(ReferralRespiteReview::class);
    $url = '/operations/referrals/'.$f['referral']->reference.'?tab=service-start';
    expect($review->url($f['manager'], $f['person'], $booking))->toBe($url);
    $this->actingAs($f['manager'])->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('operations/referrals/show')->where('initialTab', 'service-start'));
    $f['manager']->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.commence')->sole()->id, ['allowed' => false]);
    expect($review->url($f['manager']->fresh(), $f['person'], $booking))->toBeNull();
    $this->actingAs($f['manager']->fresh())->get($url)->assertForbidden();
});

it('does not expose a review link for a different site or a restricted referral', function () {
    $f = referralStartFixture(false, 'planned_respite');
    $booking = RespiteBooking::factory()->create(['client_id' => $f['person']->id, 'location_id' => Site::factory()->create()->id]);
    $review = app(ReferralRespiteReview::class);
    expect($review->url($f['manager'], $f['person'], $booking))->toBeNull();
    $booking->location_id = $f['site']->id;
    $f['referral']->update(['restricted' => true]);
    expect($review->url($f['manager']->fresh(), $f['person'], $booking))->toBeNull();
    $this->actingAs($f['manager']->fresh())->get('/operations/referrals/'.$f['referral']->reference.'?tab=service-start')->assertNotFound();
});
