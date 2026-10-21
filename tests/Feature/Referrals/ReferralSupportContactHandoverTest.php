<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralPeopleContacts;
use App\Domain\Referrals\Services\ReferralSupportContactHandovers;
use App\Models\Client;
use App\Models\ClientEmergencyContact;
use App\Models\ClientSupportContact;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

function supportContactHandoverFixture(): array
{
    [, $actor, $referral, , , $data] = conversionFixture();
    handoverCanonicalPermissions($actor, ['referrals.assessment.manage']);
    $authority = $referral->authority;
    $authority['review']['scopes']['contact'] = true;
    $referral->update(['authority' => $authority]);
    $contact = ['submission_key' => (string) Str::uuid(), 'contact_id' => (string) Str::uuid(), 'contact_version' => 0,
        'name' => 'Fictional Supporter', 'role' => 'supporter', 'relationship' => 'Chosen support person', 'organisation' => null,
        'phone' => '021 555 0188', 'email' => null, 'channel' => 'phone', 'safe_time' => 'Weekday afternoon only', 'leave_message' => false,
        'communication' => 'Use plain language', 'state' => 'current', 'may_contact' => true, 'purpose' => 'Help arrange ongoing support appointments',
        'authority_note' => null, 'review_note' => 'Identity, details and permission checked with the person', 'review_due_at' => now()->addDay()->toISOString(), 'confirmed' => true];
    app(ReferralPeopleContacts::class)->save($actor->fresh(), $referral->id, 1, $contact);
    $data['support_contacts'] = [['contact_id' => $contact['contact_id'], 'contact_version' => 1, 'purpose' => 'Ongoing support coordinating care appointments', 'confirmed' => true]];

    return [$actor->fresh(), $referral->fresh(), $data, $contact];
}

it('hands over one encrypted support contact without changing emergency contacts or portal membership', function () {
    [$actor, $referral, $data] = supportContactHandoverFixture();
    $users = User::count();
    $kins = NextOfKin::count();
    $emergency = ClientEmergencyContact::count();
    $service = app(ReferralConversionService::class);
    $receipt = $service->convert($actor, $referral->id, 2, $data);
    expect($service->convert($actor, $referral->id, 2, $data))->toBe($receipt);
    $person = Client::findOrFail($receipt['person']['id']);
    $contact = ClientSupportContact::where('client_id', $person->id)->sole();
    expect($contact->release['details']['safe_time'])->toBe('Weekday afternoon only')
        ->and($contact->release['details']['leave_message'])->toBeFalse()
        ->and($contact->getRawOriginal('release'))->not->toContain('Fictional Supporter')
        ->and($contact->toArray())->not->toHaveKey('release')
        ->and($person->service_start_date)->toBeNull()
        ->and(User::count())->toBe($users)->and(NextOfKin::count())->toBe($kins)->and(ClientEmergencyContact::count())->toBe($emergency);
    expect(app(ReferralSupportContactHandovers::class)->present($actor, $person)[0]['review_current'])->toBeTrue();
    expect(ReferralConversion::where('referral_id', $referral->id)->sole()->receipt['support_contacts'][0]['id'])->toBe($contact->id);
});

it('refuses a stale or unauthorized contact handover without partial person creation', function () {
    [$actor, $referral, $data] = supportContactHandoverFixture();
    $stale = $data;
    $stale['support_contacts'][0]['contact_version'] = 999;
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 2, $stale))->toThrow(HttpException::class);
    expect(Client::count())->toBe(0)->and(ClientSupportContact::count())->toBe(0);
    $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'referrals.assessment.manage')->sole()->id => ['allowed' => false]]);
    expect(fn () => app(ReferralConversionService::class)->convert($actor->fresh(), $referral->id, 2, $data))->toThrow(HttpException::class);
    expect(Client::count())->toBe(0)->and(ClientSupportContact::count())->toBe(0);
});

it('withholds canonical phone and email immediately after withdrawal and hides restricted sources', function () {
    [$actor, $referral, $data, $source] = supportContactHandoverFixture();
    $receipt = app(ReferralConversionService::class)->convert($actor, $referral->id, 2, $data);
    $person = Client::findOrFail($receipt['person']['id']);
    app(ReferralPeopleContacts::class)->save($actor, $referral->id, 3, [...$source, 'submission_key' => (string) Str::uuid(), 'contact_version' => 1,
        'state' => 'withdrawn', 'may_contact' => false, 'leave_message' => false, 'confirmed' => false, 'review_note' => 'The person withdrew permission after handover']);
    $service = app(ReferralSupportContactHandovers::class);
    $projected = $service->present($actor, $person)[0];
    expect($projected['review_current'])->toBeFalse()->and($projected['details'])->not->toHaveKeys(['phone', 'email']);
    $referral->refresh()->update(['restricted' => true]);
    $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'referrals.restricted')->sole()->id => ['allowed' => false]]);
    expect($service->present($actor->fresh(), $person))->toBe([]);
});
