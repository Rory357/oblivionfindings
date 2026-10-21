<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\Site;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

it('returns only visible referrals and scopes counts to the same records', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny']);
    $own = referralTestRecord($site);
    referralTestRecord($other);
    referralTestRecord(null);
    referralTestRecord($site, ['restricted' => true]);
    $this->actingAs($reader)->getJson(route('referrals.data'))->assertOk()
        ->assertJsonPath('referrals.total', 1)->assertJsonPath('referrals.data.0.reference', $own->reference)
        ->assertJsonPath('stats.open', 1)->assertJsonPath('stats.awaiting_contact', 1)
        ->assertJsonMissingPath('referrals.data.0.identity')->assertJsonMissingPath('referrals.data.0.assessment');
});

it('omits restricted sections and filters tasks and activity before pagination', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny']);
    $referral = referralTestRecord($site, ['intake' => ['funding_agency' => 'Private funding information']]);
    foreach (['intake', 'assessment', 'funding'] as $audience) {
        ReferralWorkItem::query()->create([
            'referral_id' => $referral->id, 'owner_id' => $reader->id, 'audience' => $audience,
            'details' => ['title' => $audience.' work'], 'due_at' => now()->addDay(),
        ]);
        ReferralEvent::query()->create([
            'referral_id' => $referral->id, 'kind' => 'test.event', 'audience' => $audience,
            'payload' => ['note' => $audience.' history'], 'occurred_at' => now(),
        ]);
    }
    $this->actingAs($reader)->getJson(route('referrals.show.data', $referral->reference))->assertOk()
        ->assertJsonMissingPath('referral.assessment')->assertJsonMissingPath('referral.authority')
        ->assertJsonMissingPath('referral.funding_intake')->assertJsonPath('referral.work.total', 1)
        ->assertJsonPath('referral.work.data.0.audience', 'intake')->assertJsonPath('referral.activity.total', 1)
        ->assertJsonPath('referral.profile.readiness', null)->assertJsonPath('referral.profile.funding', null)
        ->assertJsonPath('referral.profile.service_start', null);
});

it('summarises handover readiness and funding position for the profile without estimating', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny', 'assessment.view', 'funding.view']);
    $referral = referralTestRecord($site, ['assessment' => [
        'version' => 1, 'authority_version' => null, 'review_due_at' => now()->addMonth()->toISOString(),
        'identity_status' => 'verified', 'intention' => 'other_provider', 'suitability' => 'more_information', 'capacity' => 'waitlist',
        'goals' => 'Live closer to whānau', 'participation' => 'Took part in the review', 'safety_needs' => 'No specific needs identified',
    ]]);
    $response = $this->actingAs($reader)->getJson(route('referrals.show.data', $referral->reference))->assertOk()
        ->assertJsonPath('referral.profile.funding.proposal_version', null)->assertJsonPath('referral.profile.funding.totals', [])
        ->assertJsonPath('referral.profile.funding.cases.total', 0);
    $states = collect($response->json('referral.profile.readiness'))->pluck('state', 'key')->all();
    expect($states)->toBe([
        'identity' => 'confirmed', 'authority' => 'to_review', 'choice' => 'attention',
        'suitability' => 'to_review', 'capacity' => 'attention', 'safe_start' => 'confirmed',
    ]);
});

it('renders the scoped staff workspace and profile without caching personal data', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny']);
    $record = referralTestRecord($site, ['owner_id' => $reader->id]);
    $this->actingAs($reader)->get(route('referrals.index'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('operations/referrals/index')
            ->where('referrals.total', 1)->where('referrals.data.0.owner_name', $reader->name)
            ->where('permissions.create', false)->where('options.intake', null));
    $this->get(route('referrals.show', $record->reference))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('operations/referrals/show')
            ->where('referral.reference', $record->reference)->missing('referral.assessment')
            ->where('referral.work_counts.open', 0));
});

it('bounds pipeline previews and applies queues and site privacy to lane counts', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny']);
    foreach (range(1, 13) as $index) {
        referralTestRecord($site);
    }
    referralTestRecord($site, ['restricted' => true]);
    referralTestRecord($other);
    referralTestRecord($site, ['stage' => 'closed']);
    $this->actingAs($reader)->getJson(route('referrals.data', ['layout' => 'pipeline', 'queue' => 'open']))->assertOk()
        ->assertJsonPath('stats.open', 13)->assertJsonPath('referrals.total', 0)
        ->assertJsonPath('pipeline.0.total', 13)->assertJsonCount(8, 'pipeline.0.data')
        ->assertJsonPath('pipeline.0.overdue', 0)->assertJsonPath('pipeline.7.total', 0);
});

it('only offers eligible task owners and denies owner lookup without assignment permission', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'tasks.manage', 'assessment.view', 'assessment.manage']);
    $eligible = referralTestUser($site, ['viewAny', 'assessment.view']);
    referralTestUser($site, ['viewAny']);
    $record = referralTestRecord($site);
    $query = ['reference' => $record->reference, 'site_id' => $site->id, 'purpose' => 'work', 'audience' => 'assessment'];
    $this->actingAs($actor)->getJson(route('referrals.staff-options', $query))->assertOk()
        ->assertJsonCount(2, 'staff')->assertJsonFragment(['id' => $eligible->id, 'name' => $eligible->name]);
    $this->getJson(route('referrals.staff-options', [...$query, 'purpose' => 'owner']))->assertForbidden();
});

it('denies direct cross-site reads and mutations while permitting only authorized actions', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny']);
    $own = referralTestRecord($site);
    $hidden = referralTestRecord($other);
    $this->actingAs($reader)->getJson(route('referrals.show.data', $hidden->reference))->assertNotFound();
    $input = ['version' => 1, 'next_action' => 'Contact again', 'next_due_at' => now()->addDay()->toISOString(), 'reason' => 'Person requested a later call'];
    $this->patchJson(route('referrals.next-action', $hidden->reference), $input)->assertNotFound();
    $this->patchJson(route('referrals.next-action', $own->reference), $input)->assertForbidden();
    expect($own->fresh()->lock_version)->toBe(1);
});
