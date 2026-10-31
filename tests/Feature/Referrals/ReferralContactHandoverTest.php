<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Services\Clients\ClientContactPreferences;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

function fictionalContactReview(array $overrides = []): array
{
    return ['phone' => '021 555 0100', 'email' => 'person@example.test', 'channel' => 'phone', 'safe_time' => 'Fictional weekday mornings only',
        'leave_message' => false, 'communication' => 'Fictional interpreter preference',
        'verification_note' => 'Fictional person verified their own details and contact instructions', 'confirmed' => true, ...$overrides];
}

it('hands over verified personal contacts without copying referrer details or starting services', function () {
    [, $actor, $referral, , , $data] = conversionFixture();
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral);
    expect($preview['contact_profile']['phone'])->toBeNull()->and($preview['contact_profile']['preferences'])->toBeNull();
    $data['contact_handover'] = fictionalContactReview();
    $service = app(ReferralConversionService::class);
    $result = $service->convert($actor, $referral->id, 1, $data);
    expect($service->convert($actor, $referral->id, 1, $data))->toBe($result);
    $person = Client::findOrFail($result['person']['id']);
    expect($person->phone)->toBe('021 555 0100')->and($person->email)->toBe('person@example.test')
        ->and($person->status)->toBe('onboarding')->and($person->service_start_date)->toBeNull()
        ->and($person->contact_preferences['leave_message'])->toBeFalse()
        ->and($person->getRawOriginal('contact_preferences'))->not->toContain('Fictional weekday')
        ->and($person->toArray())->not->toHaveKey('contact_preferences');
    $conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
    expect($conversion->receipt['contact_mapping']['phone'])->toBe($person->phone)
        ->and($conversion->getRawOriginal('receipt'))->not->toContain('person@example.test');
    $data['contact_handover']['safe_time'] = 'Changed retry';
    expect(fn () => $service->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(Client::count())->toBe(1);
});

it('preserves existing contact values and atomically rejects replacement through linking', function () {
    [$site, $actor, $referral, $context, , $data] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'phone' => '021 existing', 'email' => null]);
    $data = [...$data, 'target_id' => $person->id, 'target_fingerprint' => ReferralHandoverPreview::fingerprint($person),
        'duplicate_decisions' => [['client_id' => $person->id, 'conclusion' => 'same_person', 'reason' => 'Person confirmed their existing record']],
        'contact_handover' => fictionalContactReview()];
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data))->toThrow(ValidationException::class);
    expect($person->fresh()->phone)->toBe('021 existing')->and($person->fresh()->contact_preferences)->toBeNull()->and(ReferralConversion::count())->toBe(0);
    $data['contact_handover']['phone'] = '021 existing';
    app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    expect($person->fresh()->phone)->toBe('021 existing')->and($person->fresh()->email)->toBe('person@example.test');
});

it('records profile corrections with current access and rejects stale reviews', function () {
    [$site, $actor] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id]);
    $service = app(ClientContactPreferences::class);
    $before = ClientContactPreferences::fingerprint($person);
    $review = $this->actingAs($actor)->putJson('/operations/clients/'.$person->id.'/contact-preferences', ['fingerprint' => $before, 'review' => fictionalContactReview()])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('contact_profile');
    expect($review['review_current'])->toBeTrue()->and($review['preferences'])->not->toHaveKey('verification_note');
    expect(fn () => $service->update($actor, $person->id, $before, fictionalContactReview(['phone' => '021 changed'])))->toThrow(HttpException::class);
    $service->update($actor, $person->id, $review['fingerprint'], fictionalContactReview(['safe_time' => 'Fictional afternoons only']));
    expect($person->fresh()->contact_preferences['safe_time'])->toBe('Fictional afternoons only');
    expect(AuditLog::where('auditable_id', $person->id)->pluck('meta')->toJson())->not->toContain('Fictional weekday', 'Fictional afternoons', 'Fictional interpreter', 'person@example.test', '021 555 0100');
    $person->refresh()->update(['phone' => '021 changed elsewhere']);
    expect($service->preview($person->fresh())['review_current'])->toBeFalse();
});

it('denies profile contact corrections without canonical update permission', function () {
    [$site, $actor] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id]);
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.update')->sole()->id, ['allowed' => false]);
    $this->actingAs($actor->fresh())->putJson('/operations/clients/'.$person->id.'/contact-preferences',
        ['fingerprint' => ClientContactPreferences::fingerprint($person), 'review' => fictionalContactReview()])->assertForbidden();
    expect($person->fresh()->contact_preferences)->toBeNull();
});

it('requires coherent verified instructions and permits an explicit no-direct-contact choice', function () {
    $service = app(ClientContactPreferences::class);
    foreach ([['phone' => null], ['email' => 'not-an-email'], ['confirmed' => false], ['leave_message' => null],
        ['verification_note' => '   '], ['channel' => 'none', 'leave_message' => true], ['channel' => 'sms']] as $overrides) {
        expect(fn () => $service->validate(fictionalContactReview($overrides)))->toThrow(ValidationException::class);
    }
    expect($service->validate(fictionalContactReview(['channel' => 'none', 'phone' => null, 'email' => null]))['channel'])->toBe('none');
});
