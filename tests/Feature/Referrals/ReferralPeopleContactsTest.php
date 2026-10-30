<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Services\ReferralPeopleContacts;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

function referralPeopleContactFixture(): array
{
    [, $actor, $referral] = conversionFixture();
    handoverCanonicalPermissions($actor, ['referrals.assessment.manage']);
    $authority = $referral->authority;
    $authority['review']['scopes']['contact'] = true;
    $referral->update(['authority' => $authority]);
    $input = ['submission_key' => (string) Str::uuid(), 'contact_id' => (string) Str::uuid(), 'contact_version' => 0,
        'name' => 'Fictional Support Person', 'role' => 'supporter', 'relationship' => 'Chosen support person', 'organisation' => null,
        'phone' => '021 555 0188', 'email' => null, 'channel' => 'phone', 'safe_time' => 'Weekday afternoon contact', 'leave_message' => false,
        'communication' => 'Use plain language', 'state' => 'current', 'may_contact' => true, 'purpose' => 'Arrange participation in the support assessment',
        'authority_note' => null, 'review_note' => 'Identity, details and permission checked with the person', 'review_due_at' => now()->addDay()->toISOString(), 'confirmed' => true];

    return [$actor->fresh(), $referral, $input];
}

it('stores one private reviewed contact through the route and retries exactly without creating portal membership', function () {
    [$actor, $referral, $input] = referralPeopleContactFixture();
    $users = User::count();
    $kins = NextOfKin::count();
    $url = '/operations/referrals/'.$referral->reference.'/people-contacts';
    $response = $this->actingAs($actor)->postJson($url, ['version' => 1, ...$input])->assertOk()->assertJsonPath('contact_version', 1);
    $this->postJson($url, ['version' => 1, ...$input])->assertOk()->assertExactJson($response->json());
    expect(User::count())->toBe($users)->and(NextOfKin::count())->toBe($kins);
    expect($referral->fresh()->getRawOriginal('intake'))->not->toContain('Fictional Support Person');
    $event = ReferralEvent::where('referral_id', $referral->id)->where('kind', 'person_contact.reviewed')->sole();
    expect($event->audience)->toBe('assessment')->and($event->getRawOriginal('payload'))->not->toContain($input['phone']);
    expect(app(ReferralPeopleContacts::class)->present($actor, $referral->fresh())[0]['review_current'])->toBeTrue();
    $this->postJson($url, ['version' => 1, ...$input, 'name' => 'Changed replay'])->assertConflict();
});

it('requires both assessment permissions and denies cross-record private contacts', function () {
    [$actor, $referral, $input] = referralPeopleContactFixture();
    app(ReferralPeopleContacts::class)->save($actor, $referral->id, 1, $input);
    $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'referrals.assessment.view')->sole()->id => ['allowed' => false]]);
    expect(app(ReferralPeopleContacts::class)->present($actor->fresh(), $referral->fresh()))->toBe([]);
    expect(app(ReferralPresenter::class)->detail($actor->fresh(), $referral->fresh()))->not->toHaveKey('people_contacts');
    $this->actingAs($actor->fresh())->postJson('/operations/referrals/'.$referral->reference.'/people-contacts', ['version' => 1, ...$input])->assertForbidden();
    $referral->refresh()->update(['restricted' => true]);
    $this->postJson('/operations/referrals/'.$referral->reference.'/people-contacts', ['version' => 1, ...$input])->assertNotFound();
});

it('invalidates old contact reviews after authority changes and still permits a truthful withdrawal', function () {
    [$actor, $referral, $input] = referralPeopleContactFixture();
    $service = app(ReferralPeopleContacts::class);
    $service->save($actor, $referral->id, 1, $input);
    $authority = $referral->fresh()->authority;
    $authority['review']['version']++;
    $authority['review']['state'] = 'withdrawn';
    $authority['review']['scopes'] = ['collect' => false, 'contact' => false, 'assist' => false, 'service' => false];
    $referral->refresh()->update(['authority' => $authority]);
    expect($service->present($actor, $referral->fresh())[0]['review_current'])->toBeFalse();
    $withdraw = [...$input, 'submission_key' => (string) Str::uuid(), 'contact_version' => 1, 'state' => 'withdrawn', 'may_contact' => false, 'confirmed' => false];
    expect(fn () => $service->save($actor, $referral->id, 2, [...$withdraw, 'phone' => '021 555 0198']))->toThrow(HttpException::class);
    $service->save($actor, $referral->id, 2, $withdraw);
    expect($service->present($actor, $referral->fresh())[0]['details']['state'])->toBe('withdrawn');
});

it('rejects stale contact reviews and permissions extending beyond reviewed authority', function () {
    [$actor, $referral, $input] = referralPeopleContactFixture();
    $service = app(ReferralPeopleContacts::class);
    expect(fn () => $service->save($actor, $referral->id, 1, [...$input, 'review_due_at' => now()->addYear()->toISOString()]))->toThrow(HttpException::class);
    expect(fn () => $service->save($actor, $referral->id, 1, [...$input, 'role' => 'representative']))->toThrow(HttpException::class);
    $service->save($actor, $referral->id, 1, $input);
    expect(fn () => $service->save($actor, $referral->id, 2, [...$input, 'submission_key' => (string) Str::uuid()]))->toThrow(HttpException::class);
    expect($referral->fresh()->lock_version)->toBe(2);
});
