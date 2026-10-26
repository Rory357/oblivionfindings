<?php

use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Models\Client;
use App\Models\Site;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralStartFixtures.php';

it('records the actual start through commencement and rejects profile date changes or clearing', function () {
    $f = referralStartFixture();
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    expect(fn () => $f['person']->fresh()->update(['service_start_date' => today()->toDateString()]))->toThrow(HttpException::class);
    expect($f['person']->fresh()->service_start_date)->toBeNull();
    $this->travel(1)->minutes();
    $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput());
    $date = today('Pacific/Auckland')->toDateString();
    expect($f['person']->fresh()->service_start_date->toDateString())->toBe($date);
    foreach ([null, today('Pacific/Auckland')->subDay()->toDateString(), today('Pacific/Auckland')->addDay()->toDateString()] as $changed) {
        expect(fn () => $f['person']->fresh()->update(['service_start_date' => $changed]))->toThrow(HttpException::class);
        $this->actingAs($f['manager'])->put(route('operations.clients.update', $f['person']), [
            'first_name' => $f['person']->first_name, 'last_name' => $f['person']->last_name,
            'status' => 'active', 'service_start_date' => $changed,
        ])->assertSessionHasErrors('service_start_date');
        expect($f['person']->fresh()->service_start_date->toDateString())->toBe($date);
    }
    $f['person']->fresh()->update(['preferred_name' => 'Chosen name', 'service_start_date' => $date]);
    expect($f['person']->fresh()->preferred_name)->toBe('Chosen name');
});

it('preserves legacy dates and rejects a fabricated date when the review is required for everyone', function () {
    requireCommencementReviewForEveryone();
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['create']);
    handoverCanonicalPermissions($actor, ['clients.viewAny', 'clients.update', 'clients.create']);
    $legacy = Client::factory()->create(['site_id' => $site->id, 'status' => 'active', 'service_start_date' => '2025-03-04']);
    $legacy->update(['preferred_name' => 'Existing support']);
    expect($legacy->fresh()->service_start_date->toDateString())->toBe('2025-03-04');
    expect(fn () => $legacy->fresh()->update(['service_start_date' => null]))->toThrow(HttpException::class);
    $count = Client::count();
    $this->actingAs($actor)->post(route('operations.clients.store'), [
        'first_name' => 'Fictional', 'last_name' => 'Unstarted', 'status' => 'onboarding',
        'site_id' => $site->id, 'service_start_date' => today()->toDateString(),
    ])->assertSessionHasErrors('service_start_date');
    expect(Client::count())->toBe($count);
});
