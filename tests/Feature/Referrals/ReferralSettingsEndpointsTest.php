<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralSettingsFixtures.php';

it('separates settings reading from drafting and requires current staff eligibility', function () {
    $reader = referralSettingsActor([]);
    $this->actingAs($reader)->get('/settings/referrals')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/referrals/index')
            ->where('settings.can_manage', false)->where('settings.can_publish', false)->where('settings.configuration.public.enabled', false));
    $input = ['configuration' => ReferralSettings::defaults(), 'base_version_id' => null, 'reason' => 'Initial reviewed settings', 'request_key' => (string) Str::uuid()];
    $this->postJson('/settings/referrals/drafts', $input)->assertForbidden();
    $reader->hrEmployeeProfile->update(['is_active' => false]);
    $this->get('/settings/referrals')->assertForbidden();
    expect(ReferralSettingsVersion::count())->toBe(0);
});

it('saves one draft for an exact retry and rechecks access before returning it', function () {
    $author = referralSettingsActor(['settings.manage']);
    $input = ['configuration' => ReferralSettings::defaults(), 'base_version_id' => null, 'reason' => 'Initial reviewed settings', 'request_key' => (string) Str::uuid()];
    $first = $this->actingAs($author)->postJson('/settings/referrals/drafts', $input)->assertCreated();
    $this->postJson('/settings/referrals/drafts', $input)->assertCreated()->assertJsonPath('version.id', $first->json('version.id'));
    $this->postJson('/settings/referrals/drafts', [...$input, 'reason' => 'A different request'])->assertConflict();
    $author->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.settings.manage')->sole()->id, ['allowed' => false]);
    $this->actingAs($author->fresh())->postJson('/settings/referrals/drafts', $input)->assertForbidden();
    expect(ReferralSettingsVersion::count())->toBe(1)->and(ReferralEvent::where('kind', 'settings.drafted')->count())->toBe(1);
});

it('publishes independently and repeats the same receipt without rewriting history', function () {
    $author = referralSettingsActor(['settings.manage', 'settings.publish']);
    $publisher = referralSettingsActor(['settings.publish']);
    $draft = app(ReferralSettings::class)->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $input = ['effective_at' => now()->toISOString(), 'acknowledge_impact' => true, 'publication_note' => 'Reviewed at the settings meeting'];
    $this->actingAs($author)->postJson('/settings/referrals/'.$draft->id.'/publish', $input)->assertUnprocessable();
    $this->actingAs($publisher)->postJson('/settings/referrals/'.$draft->id.'/publish', [...$input, 'publication_note' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('publication_note');
    $this->actingAs($publisher)->postJson('/settings/referrals/'.$draft->id.'/publish', $input)->assertOk()
        ->assertJsonPath('version.publication_note', 'Reviewed at the settings meeting');
    $this->postJson('/settings/referrals/'.$draft->id.'/publish', $input)->assertOk()->assertJsonPath('version.state', 'published');
    expect(ReferralEvent::where('kind', 'settings.published')->count())->toBe(1);
    $publisher->hrEmployeeProfile->update(['is_active' => false]);
    $this->postJson('/settings/referrals/'.$draft->id.'/publish', $input)->assertForbidden();
});

it('keeps published references stable and requires a new version for a changed notice', function () {
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $service = app(ReferralSettings::class);
    $base = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $service->publish($publisher, $base->id, now()->toISOString());
    $configuration = ReferralSettings::defaults();
    array_pop($configuration['pathways']);
    $input = ['configuration' => $configuration, 'base_version_id' => $base->id, 'reason' => 'Attempt to remove a stable pathway', 'request_key' => (string) Str::uuid()];
    $this->actingAs($author)->postJson('/settings/referrals/drafts', $input)->assertUnprocessable()->assertJsonValidationErrors('configuration.pathways');
    $configuration = ReferralSettings::defaults();
    $configuration['public']['notice'] = 'A changed information collection notice';
    $this->postJson('/settings/referrals/drafts', [...$input, 'configuration' => $configuration])->assertUnprocessable()->assertJsonValidationErrors('configuration.public.notice_version');
});

it('shows scheduled publication separately from the effective configuration and protects request keys', function () {
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $service = app(ReferralSettings::class);
    $first = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $service->publish($publisher, $first->id, now()->toISOString());
    $configuration = ReferralSettings::defaults();
    $configuration['public']['provider_name'] = 'Future provider label';
    $future = $service->draft($author, $configuration, $first->id, 'Scheduled branding update');
    $service->publish($publisher, $future->id, now()->addDay()->toISOString());
    $this->actingAs($publisher)->get('/settings/referrals?version='.$future->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('settings.latest.id', $future->id)->where('settings.effective.id', $first->id)
        ->where('settings.selected.configuration.public.provider_name', 'Future provider label')->missing('settings.selected.request_key')->missing('settings.selected.request_hash'));
});

it('rechecks governance permission for a repeated safeguard-changing publication', function () {
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish', 'settings.governance']);
    $configuration = ReferralSettings::defaults();
    $configuration['intake']['first_contact_business_days'] = 3;
    $draft = app(ReferralSettings::class)->draft($author, $configuration, null, 'Review the contact response target');
    $input = ['effective_at' => now()->toISOString(), 'acknowledge_impact' => true, 'publication_note' => 'Reviewed at the settings meeting'];
    $this->actingAs($publisher)->postJson('/settings/referrals/'.$draft->id.'/publish', $input)->assertOk();
    $publisher->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.settings.governance')->sole()->id, ['allowed' => false]);
    $this->actingAs($publisher->fresh())->postJson('/settings/referrals/'.$draft->id.'/publish', $input)->assertForbidden();
});
