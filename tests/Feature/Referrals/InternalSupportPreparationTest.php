<?php

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Services\InternalSupportPreparation;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Site;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';

function internalPreparationFixture(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAssigned', 'create']);
    handoverCanonicalPermissions($actor, ['clients.viewAny', 'clients.update']);
    referralTestRecord($site); // Publish the existing demonstration configuration.
    $context = ServiceContext::create(['site_id' => $site->id, 'name' => 'Internal preparation setting', 'type' => 'home_support', 'is_active' => true]);
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'onboarding']);
    $options = app(InternalSupportPreparation::class)->options($actor, $person);
    $data = ['submission_key' => (string) Str::uuid(), 'person_fingerprint' => $options['person']['fingerprint'], 'settings_version_id' => $options['settings_version_id'],
        'site_id' => $site->id, 'service_context_id' => $context->id, 'requested_service' => 'supported_living', 'team' => 'Support preparation',
        'reason' => 'Review this existing person for new daily living support', 'next_action' => 'Arrange participation and authority review', 'next_due_at' => now()->addDay()->toISOString(), 'acknowledge_review' => true];

    return [$site, $actor, $person, $context, $data];
}

it('opens one owned internal case without inventing authority, contact, an external acknowledgement or a new person', function () {
    [, $actor, $person, , $data] = internalPreparationFixture();
    $original = $person->fresh()->getAttributes();
    $personCount = Client::count();
    $url = '/operations/clients/'.$person->id.'/support-preparation';
    $this->actingAs($actor)->getJson($url)->assertOk()->assertJsonPath('person.id', $person->id);
    $response = $this->postJson($url, $data)->assertOk()->assertJsonPath('service_commenced', false);
    $reference = $response->json('reference');
    $case = CareReferral::where('reference', $reference)->firstOrFail();
    $this->postJson($url, $data)->assertOk()->assertJsonPath('reference', $reference);
    expect($case->source)->toBe('internal')->and($case->entry_kind)->toBe(InternalSupportPreparation::KIND)
        ->and($case->client_id)->toBe($person->id)->and($case->owner_id)->toBe($actor->id)
        ->and($case->stage)->toBe('assessment')->and($case->assessment)->toBe([])
        ->and($case->authority['contact_permission'])->toBeFalse()->and($case->authority['staff_review'])->toBe('pending')
        ->and($case->intake)->not->toHaveKey('submitted_contact')->and(Client::count())->toBe($personCount)
        ->and($person->fresh()->getAttributes())->toBe($original)
        ->and(ReferralOutbox::where('referral_id', $case->id)->pluck('kind')->all())->toBe(['intake_received'])
        ->and(ReferralConversion::count())->toBe(0);
    $this->postJson($url, [...$data, 'reason' => 'A different submitted reason must not reuse this key'])->assertConflict();
    $this->postJson($url, [...$data, 'submission_key' => (string) Str::uuid()])->assertConflict();
    expect(CareReferral::where('client_id', $person->id)->count())->toBe(1);
});

it('rejects stale person and settings reviews and unavailable service settings before opening a case', function () {
    [, $actor, $person, $context, $data] = internalPreparationFixture();
    $this->actingAs($actor);
    $url = '/operations/clients/'.$person->id.'/support-preparation';
    $this->postJson($url, [...$data, 'person_fingerprint' => str_repeat('0', 64)])->assertConflict();
    $this->postJson($url, [...$data, 'settings_version_id' => $data['settings_version_id'] + 1])->assertConflict();
    $this->postJson($url, [...$data, 'next_due_at' => now()->subDay()->toISOString()])->assertUnprocessable();
    $this->postJson($url, [...$data, 'requested_service' => 'not_offered'])->assertUnprocessable();
    $context->update(['is_active' => false]);
    $this->postJson($url, $data)->assertNotFound();
    expect(CareReferral::where('client_id', $person->id)->count())->toBe(0);
});

it('checks canonical person, destination site and current permissions separately and hides inaccessible existing cases', function () {
    [$site, $actor, $person, , $data] = internalPreparationFixture();
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Client::factory()->create(['site_id' => $otherSite->id, 'status' => 'onboarding']);
    referralTestRecord($site, ['client_id' => $person->id, 'restricted' => true, 'owner_id' => $actor->id]);
    $this->actingAs($actor)->getJson('/operations/clients/'.$other->id.'/support-preparation')->assertNotFound();
    $url = '/operations/clients/'.$person->id.'/support-preparation';
    $this->getJson($url)->assertOk()->assertJsonPath('existing', []);
    $this->postJson($url, [...$data, 'site_id' => $otherSite->id])->assertForbidden();
    $this->postJson($url, $data)->assertOk();
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.update')->firstOrFail()->id, ['allowed' => false]);
    $this->actingAs($actor->fresh())->postJson($url, $data)->assertForbidden();
    $this->getJson($url)->assertForbidden();
    expect(CareReferral::where('client_id', $person->id)->where('entry_kind', InternalSupportPreparation::KIND)->count())->toBe(1);
});

it('binds internal handover to the canonical person and claims an unscoped legacy onboarding workflow', function () {
    [$site, $actor, $referral, $context, , $data] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'status' => 'onboarding']);
    $referral->update(['entry_kind' => InternalSupportPreparation::KIND, 'source' => 'internal', 'client_id' => $person->id]);
    $legacy = ClientOnboardingWorkflow::createForClient($person, $actor->id);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral->fresh());
    expect($preview['target_bound'])->toBeTrue()->and($preview['target']['id'])->toBe($person->id);
    $count = Client::count();
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    $other = Client::factory()->create(['site_id' => $site->id, 'status' => 'onboarding']);
    expect(fn () => app(ReferralHandoverPreview::class)->inspect($actor, $referral->fresh(), $other->id))->toThrow(HttpException::class);
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, [...$data, 'target_id' => $other->id]))->toThrow(HttpException::class);
    $data = [...$data, 'target_id' => $person->id, 'target_fingerprint' => $preview['target']['fingerprint'],
        'duplicate_decisions' => [['client_id' => $person->id, 'conclusion' => 'same_person', 'reason' => 'Existing person confirmed through the current identity review']]];
    $result = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    expect($result['created_person'])->toBeFalse()->and($result['person']['id'])->toBe($person->id)
        ->and($result['workflow_id'])->toBe($legacy->id)->and(Client::count())->toBe($count + 1)
        ->and($person->fresh()->status)->toBe('onboarding')->and($legacy->fresh()->source_referral_id)->toBe($referral->id)
        ->and($legacy->fresh()->site_id)->toBe($site->id)->and($legacy->fresh()->service_context_id)->toBe($context->id);
});
