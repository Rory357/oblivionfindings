<?php

use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\Permission;
use App\Models\Site;

require_once __DIR__.'/../../Support/ReferralHandoverFixtures.php';

it('previews pending funding without creating or activating a person or completing work', function () {
    [, $actor, $referral] = handoverPreviewFixture();
    $people = Client::count();
    $workflows = ClientOnboardingWorkflow::count();
    $response = $this->actingAs($actor)->getJson(route('referrals.handover.preview', $referral->reference))->assertOk();
    $response->assertJsonPath('preparation_checks_passed', true)->assertJsonPath('funding.proposal_readiness.ready', false)
        ->assertJsonPath('open_work', 1)->assertJsonPath('conversion', null);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Client::count())->toBe($people)->and(ClientOnboardingWorkflow::count())->toBe($workflows)
        ->and($referral->fresh()->lock_version)->toBe(1)->and($referral->fresh()->converted_at)->toBeNull()
        ->and(ReferralWorkItem::where('referral_id', $referral->id)->first()->status)->toBe('open');
    $contact = collect($response->json('fields'))->firstWhere('key', 'contact');
    expect($contact['submitted']['name'])->toBe('Parent Example')->and($contact['submitted']['leave_message'])->toBeFalse()
        ->and($contact['destination'])->toContain('verify before creating a person contact');
});

it('keeps existing active person details distinct from submitted referral values', function () {
    [$site, $actor, $referral] = handoverPreviewFixture();
    $person = Client::factory()->create(['site_id' => $site->id, 'status' => 'active', 'first_name' => 'Casey', 'last_name' => 'Example', 'preferred_name' => 'Established name']);
    $referral->update(['identity' => [...$referral->identity, 'preferred_name' => 'Submitted name']]);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral->fresh(), $person->id);
    $field = collect($preview['fields'])->firstWhere('key', 'preferred_name');
    expect($preview['target']['status'])->toBe('active')->and($field['submitted'])->toBe('Submitted name')->and($field['current'])->toBe('Established name')
        ->and($person->fresh()->status)->toBe('active')->and($person->fresh()->preferred_name)->toBe('Established name');
});

it('does not expose inaccessible possible matches or allow a guessed target', function () {
    [, $actor, $referral] = handoverPreviewFixture();
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $person = Client::factory()->create(['site_id' => $other->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'date_of_birth' => '1991-02-03']);
    $this->actingAs($actor)->getJson(route('referrals.handover.preview', $referral->reference))->assertOk()
        ->assertJsonPath('matches.restricted_review_required', true)->assertJsonPath('matches.candidates', [])->assertDontSee('1991-02-03');
    $this->getJson(route('referrals.handover.preview', ['reference' => $referral->reference, 'target_id' => $person->id]))->assertNotFound();
});

it('requires sensitive referral permissions and independent canonical update permission', function () {
    [$site, $actor, $referral] = handoverPreviewFixture();
    $reader = referralTestUser($site, ['viewAny', 'convert', 'assessment.view']);
    $this->actingAs($reader)->getJson(route('referrals.handover.preview', $referral->reference))->assertForbidden();
    $person = Client::factory()->create(['site_id' => $site->id]);
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.update')->firstOrFail()->id, ['allowed' => false]);
    $this->actingAs($actor->fresh())->getJson(route('referrals.handover.preview', ['reference' => $referral->reference, 'target_id' => $person->id]))->assertForbidden();
});

it('treats same names as candidates regardless of a differing or unknown birth date', function () {
    [$site, $actor, $referral] = handoverPreviewFixture();
    $one = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'date_of_birth' => '1980-01-01']);
    $two = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'date_of_birth' => '1990-01-01']);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral);
    expect($preview['matches']['review_required'])->toBeTrue()->and(array_column($preview['matches']['candidates'], 'id'))->toBe([$one->id, $two->id])
        ->and($preview['target'])->toBeNull();
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.duplicates.manage')->firstOrFail()->id, ['allowed' => false]);
    $restricted = app(ReferralHandoverPreview::class)->inspect($actor->fresh(), $referral);
    expect($restricted['matches']['candidates'])->toBe([])->and($restricted['matches']['restricted_review_required'])->toBeTrue();
});

it('fails preparation checks when authority or assessment expires without fabricating readiness', function () {
    [, $actor, $referral] = handoverPreviewFixture();
    $referral->update(['authority' => ['review' => [...$referral->authority['review'], 'review_due_at' => now()->subMinute()->toISOString()]],
        'assessment' => [...$referral->assessment, 'invalidated_at' => now()->toISOString()]]);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral->fresh());
    expect($preview['preparation_checks_passed'])->toBeFalse()
        ->and(collect($preview['checks'])->firstWhere('key', 'authority')['passed'])->toBeFalse()
        ->and(collect($preview['checks'])->firstWhere('key', 'assessment')['passed'])->toBeFalse();
});
