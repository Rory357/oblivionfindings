<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

it('shows an archived same-name person as archived instead of blocking handover', function () {
    [$site, $actor, $referral, , , $data] = conversionFixture();
    $archived = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'status' => 'inactive']);
    DB::table('clients')->where('id', $archived->id)->update(['deleted_at' => now()]);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral->fresh());
    expect($preview['matches']['restricted_review_required'])->toBeFalse()
        ->and($preview['matches']['candidates'])->toHaveCount(1)
        ->and($preview['matches']['candidates'][0]['archived'])->toBeTrue()
        ->and($preview['matches']['candidates'][0]['can_link'])->toBeFalse();
    $receipt = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, [...$data,
        'duplicate_decisions' => [['client_id' => $archived->id, 'conclusion' => 'different_person', 'reason' => 'Archived record has a different history and birth date']]]);
    expect($receipt['created_person'])->toBeTrue()->and($receipt['person']['id'])->not->toBe($archived->id);
});

it('lets an identity reviewer record a reasoned decision about matches they cannot open, without disclosing them', function () {
    [, $actor, $referral, , , $data] = conversionFixture();
    $hidden = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id,
        'first_name' => 'Casey', 'last_name' => 'Example', 'date_of_birth' => '1991-02-03']);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral->fresh());
    expect($preview['matches']['restricted_review_required'])->toBeTrue()->and($preview['matches']['candidates'])->toBe([])
        ->and($preview['matches']['can_record_restricted_review'])->toBeTrue()
        ->and(json_encode($preview['matches']))->not->toContain('1991-02-03');
    $service = app(ReferralConversionService::class);
    expect(fn () => $service->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(fn () => $service->convert($actor, $referral->id, 1, [...$data, 'restricted_match_review' => ['reason' => 'too short', 'acknowledged' => true]]))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    $review = ['reason' => 'Checked identity with the person; they have never received support from us before.', 'acknowledged' => true];
    $receipt = $service->convert($actor, $referral->id, 1, [...$data, 'restricted_match_review' => $review]);
    expect($receipt['created_person'])->toBeTrue()->and($receipt['person']['id'])->not->toBe($hidden->id);
    $conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
    expect($conversion->receipt['restricted_match_review']['reason'])->toBe($review['reason'])
        ->and($conversion->receipt['restricted_match_review']['reviewed_by'])->toBe($actor->id);
});

it('still requires an identity reviewer when the actor cannot resolve possible matches', function () {
    [$site, $actor, $referral, , , $data] = conversionFixture();
    Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Casey', 'last_name' => 'Example']);
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.duplicates.manage')->firstOrFail()->id, ['allowed' => false]);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor->fresh(), $referral->fresh());
    expect($preview['matches']['restricted_review_required'])->toBeTrue()->and($preview['matches']['can_record_restricted_review'])->toBeFalse();
    $review = ['reason' => 'Attempting to proceed without identity review authority at all.', 'acknowledged' => true];
    expect(fn () => app(ReferralConversionService::class)->convert($actor->fresh(), $referral->id, 1, [...$data, 'restricted_match_review' => $review]))
        ->toThrow(HttpException::class);
    expect(ReferralConversion::where('referral_id', $referral->id)->exists())->toBeFalse();
});
