<?php

use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ServiceContext;
use App\Models\Site;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralStartFixtures.php';

it('lets an established active person move between sites and services without a commencement review', function (bool $requiredForEveryone) {
    requireCommencementReviewForEveryone($requiredForEveryone);
    $site = Site::factory()->create();
    $person = Client::factory()->create(['status' => 'active', 'site_id' => $site->id, 'service_start_date' => '2025-03-04']);
    $other = Site::factory()->create();
    $context = ServiceContext::create(['name' => 'Fictional home support', 'type' => 'home_support', 'is_active' => true]);
    $person->update(['site_id' => $other->id, 'service_context_id' => $context->id]);
    expect($person->fresh()->site_id)->toBe($other->id)->and($person->fresh()->service_context_id)->toBe($context->id)
        ->and($person->fresh()->status)->toBe('active')->and($person->fresh()->service_start_date->toDateString())->toBe('2025-03-04');
})->with(['organisation switch off' => false, 'organisation switch on' => true]);

it('activates and reactivates people without a referral when the organisation switch is off', function () {
    $inactive = Client::factory()->create(['status' => 'inactive']);
    $inactive->update(['status' => 'active']);
    expect($inactive->fresh()->status)->toBe('active');
    $onboarding = Client::factory()->create(['status' => 'onboarding']);
    $onboarding->update(['status' => 'active', 'service_start_date' => '2026-10-01']);
    expect($onboarding->fresh()->status)->toBe('active')->and($onboarding->fresh()->service_start_date->toDateString())->toBe('2026-10-01');
});

it('blocks activation without a recorded commencement when the switch is on, but not site moves', function () {
    requireCommencementReviewForEveryone();
    foreach (['inactive', 'onboarding'] as $status) {
        $person = Client::factory()->create(['status' => $status]);
        expect(fn () => $person->update(['status' => 'active']))->toThrow(HttpException::class);
        expect($person->fresh()->status)->toBe($status);
    }
    $active = Client::factory()->create(['status' => 'active']);
    $site = Site::factory()->create();
    $active->update(['site_id' => $site->id]);
    expect($active->fresh()->site_id)->toBe($site->id)->and($active->fresh()->status)->toBe('active');
});

it('still requires commencement for a referral-converted person and keeps the recorded start valid afterwards', function () {
    $f = referralStartFixture();
    expect($f['person']->fresh()->status)->toBe('onboarding');
    expect(fn () => $f['person']->fresh()->update(['status' => 'active']))->toThrow(HttpException::class);
    expect($f['person']->fresh()->status)->toBe('onboarding');
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    expect(fn () => $f['person']->fresh()->update(['status' => 'active']))->toThrow(HttpException::class);
    $this->travel(1)->minutes();
    $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput());
    expect($f['person']->fresh()->status)->toBe('active');
    // Routine care-plan edits and site moves after commencement do not invalidate the recorded start.
    $f['plan']->update(['content' => ['domains' => [['key' => 'mobility', 'strategies' => [['text' => 'Updated routine']]]]]]);
    $other = Site::factory()->create();
    $f['person']->fresh()->update(['site_id' => $other->id]);
    $f['person']->fresh()->update(['status' => 'inactive']);
    $f['person']->fresh()->update(['status' => 'active']);
    expect($f['person']->fresh()->status)->toBe('active')->and($f['person']->fresh()->site_id)->toBe($other->id);
});

it('completes onboarding into active support only for people outside the commencement review', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, []);
    handoverCanonicalPermissions($actor, ['clients.viewAny', 'clients.update', 'onboarding.edit', 'onboarding.view', 'onboarding.viewAny']);
    $person = Client::factory()->create(['status' => 'onboarding', 'site_id' => $site->id]);
    $workflow = ClientOnboardingWorkflow::createForClient($person, $actor->id);
    $workflow->steps()->update(['status' => 'completed']);
    $this->actingAs($actor)->post(route('operations.onboarding.complete', $workflow->id))->assertRedirect()->assertSessionHasNoErrors();
    expect($workflow->fresh()->status)->toBe('completed')->and($person->fresh()->status)->toBe('active');

    requireCommencementReviewForEveryone();
    $strict = Client::factory()->create(['status' => 'onboarding', 'site_id' => $site->id]);
    $second = ClientOnboardingWorkflow::createForClient($strict, $actor->id);
    $second->steps()->update(['status' => 'completed']);
    $this->actingAs($actor)->post(route('operations.onboarding.complete', $second->id))->assertRedirect()->assertSessionHasNoErrors();
    expect($second->fresh()->status)->toBe('completed')->and($strict->fresh()->status)->toBe('onboarding');
});
