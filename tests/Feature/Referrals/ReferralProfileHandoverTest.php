<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Domain\Referrals\Services\ReferralProfileHandover;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

function fictionalProfileReview(array $fields): array
{
    return ['fields' => $fields, 'verification_note' => 'Fictional person reviewed these details and agreed to record them', 'confirmed' => true];
}

it('carries verified cultural details into a new person and an idempotent encrypted receipt', function () {
    [, $actor, $referral, , , $data] = conversionFixture();
    $data['profile_handover'] = fictionalProfileReview(['languages' => ['English', 'te reo Māori'], 'preferred_pronouns' => 'they/them', 'iwi' => 'Fictional self-identified affiliation']);
    $service = app(ReferralConversionService::class);
    $result = $service->convert($actor, $referral->id, 1, $data);
    expect($service->convert($actor, $referral->id, 1, $data))->toBe($result);
    $person = Client::findOrFail($result['person']['id']);
    expect($person->languages)->toBe(['English', 'te reo Māori'])->and($person->preferred_pronouns)->toBe('they/them')
        ->and($person->phone)->toBeNull()->and($person->email)->toBeNull()->and($person->status)->toBe('onboarding');
    $conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
    expect($conversion->receipt['profile_mappings'])->toHaveCount(3)
        ->and($conversion->getRawOriginal('receipt'))->not->toContain('Fictional self-identified affiliation');
    $data['profile_handover']['fields']['iwi'] = 'Changed retry';
    expect(fn () => $service->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(Client::count())->toBe(1);
});

it('adds only missing values when linking and rejects overwriting established cultural facts atomically', function () {
    [$site, $actor, $referral, $context, , $data] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'ethnicity' => 'Existing choice']);
    $data = [...$data, 'target_id' => $person->id, 'target_fingerprint' => ReferralHandoverPreview::fingerprint($person),
        'duplicate_decisions' => [['client_id' => $person->id, 'conclusion' => 'same_person', 'reason' => 'The person confirmed their existing identity record']],
        'profile_handover' => fictionalProfileReview(['ethnicity' => 'Different value', 'languages' => ['English']])];
    $service = app(ReferralConversionService::class);
    expect(fn () => $service->convert($actor, $referral->id, 1, $data))->toThrow(ValidationException::class);
    expect($person->fresh()->ethnicity)->toBe('Existing choice')->and($person->fresh()->languages)->toBeNull()
        ->and(ReferralConversion::count())->toBe(0)->and(ClientOnboardingWorkflow::count())->toBe(0);
    $data['profile_handover'] = fictionalProfileReview(['languages' => ['English']]);
    $service->convert($actor, $referral->id, 1, $data);
    expect($person->fresh()->ethnicity)->toBe('Existing choice')->and($person->fresh()->languages)->toBe(['English'])->and($person->fresh()->status)->toBe('active');
});

it('includes cultural changes in the target fingerprint even within the same timestamp', function () {
    [$site, $actor, $referral, , , $data] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Casey', 'last_name' => 'Example']);
    $before = ReferralHandoverPreview::fingerprint($person);
    DB::table('clients')->where('id', $person->id)->update(['religion' => 'New self-identified choice']);
    expect(ReferralHandoverPreview::fingerprint($person->fresh()))->not->toBe($before);
    $data = [...$data, 'target_id' => $person->id, 'target_fingerprint' => $before,
        'duplicate_decisions' => [['client_id' => $person->id, 'conclusion' => 'same_person', 'reason' => 'The person confirmed their existing identity record']]];
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(ReferralConversion::count())->toBe(0);
});

it('does not disclose existing cultural values without canonical update access', function () {
    [$site, $actor, $referral] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'ethnicity' => 'Private choice']);
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.update')->sole()->id, ['allowed' => false]);
    expect(fn () => app(ReferralHandoverPreview::class)->inspect($actor->fresh(), $referral, $person->id))->toThrow(HttpException::class);
});

it('requires verified whitelisted bounded facts and rejects contact or status injection', function () {
    $service = app(ReferralProfileHandover::class);
    foreach ([fictionalProfileReview(['phone' => '0211234567']), fictionalProfileReview(['status' => 'active']),
        fictionalProfileReview(['preferred_pronouns' => str_repeat('x', 51)]), fictionalProfileReview(['languages' => ['English', 'English']]),
        fictionalProfileReview(['ethnicity' => '   ']), [...fictionalProfileReview(['ethnicity' => 'Chosen identity']), 'confirmed' => false],
        [...fictionalProfileReview(['ethnicity' => 'Chosen identity']), 'verification_note' => '          ']] as $input) {
        expect(fn () => $service->validate($input))->toThrow(ValidationException::class);
    }
});
