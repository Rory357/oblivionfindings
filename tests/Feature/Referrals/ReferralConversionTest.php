<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralCareHandovers;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\Permission;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';
require_once __DIR__.'/../../Support/ReferralFixtures.php';

it('creates one canonical onboarding person and preserves funding follow-ups across a retried handover', function () {
    [, $actor, $referral, , , $data] = conversionFixture();
    $service = app(ReferralConversionService::class);
    $first = $service->convert($actor, $referral->id, 1, $data);
    $again = $service->convert($actor, $referral->id, 1, $data);
    expect($again)->toBe($first)->and(ReferralConversion::where('referral_id', $referral->id)->count())->toBe(1)
        ->and(ClientOnboardingWorkflow::where('client_id', $first['person']['id'])->count())->toBe(1)
        ->and(ReferralOutbox::where('referral_id', $referral->id)->where('kind', 'handover_committed')->count())->toBe(1)
        ->and($referral->fresh()->lock_version)->toBe(2)->and($referral->fresh()->stage)->toBe('converted');
    $client = Client::findOrFail($first['person']['id']);
    expect($client->status)->toBe('onboarding')->and($client->email)->toBeNull()->and($client->phone)->toBeNull()
        ->and(ReferralWorkItem::where('referral_id', $referral->id)->first()->status)->toBe('open')
        ->and(ReferralConversion::where('referral_id', $referral->id)->first()->receipt['funding_position'])->toContain('pending');
    expect(fn () => $client->update(['status' => 'active']))->toThrow(HttpException::class);
    expect($client->fresh()->status)->toBe('onboarding');
});

it('links an active person into scoped additional onboarding without overwriting established details', function () {
    [$site, $actor, $referral, $context, , $data] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'status' => 'active', 'preferred_name' => 'Established name', 'service_context_id' => $context->id]);
    $data = [...$data, 'target_id' => $person->id, 'target_fingerprint' => ReferralHandoverPreview::fingerprint($person),
        'duplicate_decisions' => [['client_id' => $person->id, 'conclusion' => 'same_person', 'reason' => 'The person confirmed the existing identity record']]];
    $result = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    expect($result['created_person'])->toBeFalse()->and($person->fresh()->status)->toBe('active')->and($person->fresh()->preferred_name)->toBe('Established name')
        ->and(ClientOnboardingWorkflow::findOrFail($result['workflow_id'])->scope)->toBe('additional_support');
});

it('rejects a stale target preview and leaves no partial handover', function () {
    [$site, $actor, $referral, , , $data] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'status' => 'active']);
    $data = [...$data, 'target_id' => $person->id, 'target_fingerprint' => ReferralHandoverPreview::fingerprint($person),
        'duplicate_decisions' => [['client_id' => $person->id, 'conclusion' => 'same_person', 'reason' => 'Identity confirmed with the person before this change']]];
    $person->update(['preferred_name' => 'New preferred name']);
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(ReferralConversion::count())->toBe(0)->and($referral->fresh()->lock_version)->toBe(1);
});

it('rechecks newly found duplicates and unavailable evidence before creating a person', function () {
    [$site, $actor, $referral, , $document, $data] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Casey', 'last_name' => 'Example']);
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    $data['duplicate_decisions'] = [['client_id' => $person->id, 'conclusion' => 'different_person', 'reason' => 'Two distinct people verified with additional identity evidence']];
    Storage::disk('private')->put($document->storage_path, 'Altered evidence');
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(ReferralConversion::count())->toBe(0)->and(ClientOnboardingWorkflow::count())->toBe(0)->and($referral->fresh()->lock_version)->toBe(1);
});

it('rechecks current access when returning an existing handover receipt', function () {
    [, $actor, $referral, , , $data] = conversionFixture();
    app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.convert')->firstOrFail()->id, ['allowed' => false]);
    expect(fn () => app(ReferralConversionService::class)->convert($actor->fresh(), $referral->id, 1, $data))->toThrow(HttpException::class);
});

it('completes administrative onboarding without activating referral support', function () {
    [, $actor, $referral, , , $data] = conversionFixture();
    $result = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    $workflow = ClientOnboardingWorkflow::findOrFail($result['workflow_id']);
    $workflow->steps()->update(['status' => 'completed']);
    $this->actingAs($actor)->post('/operations/onboarding/'.$workflow->id.'/complete')->assertRedirect();
    expect($workflow->fresh()->status)->toBe('completed')->and(Client::findOrFail($result['person']['id'])->status)->toBe('onboarding');
});

it('protects an exact canonical identity through all model save paths', function () {
    [$site] = conversionFixture();
    Client::factory()->create(['site_id' => $site->id, 'nhi_number' => 'ABC1234']);
    expect(fn () => Client::factory()->create(['site_id' => $site->id, 'nhi_number' => 'abc1234']))->toThrow(ValidationException::class);
    expect(Client::byNhi('ABC1234')->count())->toBe(1);
});

// Stephan, 21 Oct 2026: the organisation switch defaults off, so only referral-originated people need a
// recorded service start. A manually created person is activated by onboarding completion as before;
// with the switch on, they stay in onboarding. Neither path creates a referral conversion.
it('activates a manually created person on onboarding completion unless the organisation requires a start review for everyone', function (bool $requireForEveryone, string $expected) {
    requireCommencementReviewForEveryone($requireForEveryone);
    [$site, $actor, , $context] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'onboarding']);
    $workflow = ClientOnboardingWorkflow::create(['client_id' => $person->id, 'site_id' => $site->id, 'service_context_id' => $context->id,
        'scope' => 'person', 'status' => 'in_progress', 'started_at' => now(), 'created_by' => $actor->id, 'assigned_to' => $actor->id]);
    $this->actingAs($actor)->post('/operations/onboarding/'.$workflow->id.'/complete')->assertRedirect();
    expect($workflow->fresh()->status)->toBe('completed')->and($person->fresh()->status)->toBe($expected)
        ->and(ReferralConversion::where('client_id', $person->id)->exists())->toBeFalse();
})->with([
    'switch off (default)' => [false, 'active'],
    'switch on' => [true, 'onboarding'],
]);

it('requires an explicit care handover and an owner who can view onboarding', function () {
    [, $actor, $referral, , , $data] = conversionFixture();
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, [...$data, 'acknowledge_care_handover' => false]))->toThrow(ValidationException::class);
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'onboarding.view')->firstOrFail()->id, ['allowed' => false]);
    expect(fn () => app(ReferralConversionService::class)->convert($actor->fresh(), $referral->id, 1, $data))->toThrow(HttpException::class);
    expect(ReferralConversion::count())->toBe(0)->and($referral->fresh()->lock_version)->toBe(1);
});

it('hands reviewed care facts to a canonical onboarding owner without disclosing the source funding case', function () {
    [$site, $actor, $referral, , , $data] = conversionFixture();
    $owner = referralTestUser($site, []);
    handoverCanonicalPermissions($owner, ['clients.viewAny', 'onboarding.view', 'onboarding.edit']);
    $data['onboarding_owner_id'] = $owner->id;
    $this->actingAs($actor)->getJson(route('referrals.staff-options', ['reference' => $referral->reference, 'site_id' => $site->id, 'purpose' => 'onboarding', 'q' => $owner->name]))
        ->assertOk()->assertJsonFragment(['id' => $owner->id, 'name' => $owner->name]);
    $result = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    $workflow = ClientOnboardingWorkflow::findOrFail($result['workflow_id']);
    $packets = app(ReferralCareHandovers::class)->forWorkflow($owner, $workflow);
    expect($packets)->toHaveCount(1)->and($packets[0]['status'])->toBe('current')
        ->and($packets[0]['source_url'])->toBeNull()->and($packets[0]['care'])->toBe([
            'goals' => 'Support daily living choice', 'participation' => 'Use plain language and allow time to decide',
            'safety_needs' => 'Review the agreed mobility support with the person',
        ]);
    $this->actingAs($owner)->get('/operations/onboarding/'.$workflow->id)->assertOk()
        ->assertInertia(fn ($page) => $page->component('operations/onboarding/Show')->has('care_handovers', 1)
            ->where('care_handovers.0.source_url', null)->where('care_handovers.0.care.goals', 'Support daily living choice'));
    expect(json_encode($packets))->not->toContain('Funding application', 'family@example.test', $data['identity_review'], $data['handover_note']);
    $this->getJson(route('referrals.show', $referral->reference))->assertNotFound();
});

it('withholds handover facts after authority changes and when current person privacy or site access is absent', function () {
    [$site, $actor, $referral, , , $data] = conversionFixture();
    $result = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $data);
    $workflow = ClientOnboardingWorkflow::findOrFail($result['workflow_id']);
    $care = app(ReferralCareHandovers::class);
    expect($care->forWorkflow($actor, $workflow))->toHaveCount(1);
    $referral->refresh()->update(['restricted' => true]);
    expect($care->forWorkflow($actor, $workflow))->toBe([]);
    $referral->update(['restricted' => false, 'authority' => ['review' => ['version' => 2, 'state' => 'withdrawn', 'scopes' => []]]]);
    $packet = $care->forWorkflow($actor, $workflow)[0];
    expect($packet['status'])->toBe('review_required')->and($packet['care'])->toBeNull();
    $outsider = referralTestUser(Site::factory()->create(), []);
    handoverCanonicalPermissions($outsider, ['clients.viewAny', 'onboarding.viewAny']);
    expect($care->forWorkflow($outsider, $workflow))->toBe([]);
    $this->actingAs($outsider)->get('/operations/onboarding/'.$workflow->id)->assertNotFound();
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.viewAny')->firstOrFail()->id, ['allowed' => false]);
    expect($care->forWorkflow($actor->fresh(), $workflow))->toBe([]);
});

it('filters overdue onboarding before pagination and treats today as due today in New Zealand', function () {
    [$site, $actor] = conversionFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'status' => 'onboarding']);
    $old = ClientOnboardingWorkflow::create(['client_id' => $person->id, 'site_id' => $site->id, 'status' => 'in_progress', 'started_at' => now()->subMonth(), 'created_by' => $actor->id]);
    $old->forceFill(['created_at' => now()->subMonth()])->save();
    $old->steps()->create(['step_name' => 'Outstanding review', 'step_order' => 1, 'status' => 'pending', 'due_date' => now('Pacific/Auckland')->subDay()->toDateString()]);
    foreach (range(1, 21) as $index) {
        $workflow = ClientOnboardingWorkflow::create(['client_id' => $person->id, 'site_id' => $site->id, 'status' => 'in_progress', 'started_at' => now(), 'created_by' => $actor->id]);
        $workflow->steps()->create(['step_name' => 'Due today', 'step_order' => 1, 'status' => 'pending', 'due_date' => now('Pacific/Auckland')->toDateString()]);
    }
    $this->actingAs($actor)->get('/operations/onboarding?overdue=1')->assertOk()->assertInertia(fn ($page) => $page
        ->component('operations/onboarding/Index')->where('workflows.total', 1)->where('workflows.data.0.id', $old->id)
        ->where('workflows.data.0.overdue_steps', 1)->where('stats.overdue_steps', 1)->where('filters.overdue', true));
});
