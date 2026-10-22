<?php

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

function providerWebsitePublication(array $public = []): ReferralSettingsVersion
{
    $configuration = ReferralSettings::defaults();
    $configuration['public'] = [...$configuration['public'], 'enabled' => true, 'provider_name' => 'Example Support', 'contact_phone' => '0800 000 000', 'notice' => 'Current published notice', 'notice_version' => 'public-1', ...$public];
    $configuration['services'][] = ['id' => 'unpublished_support', 'label' => 'Private service', 'enabled' => false];

    return ReferralSettingsVersion::create(['version' => 1, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now()->subDay(), 'effective_at' => now()->subDay()]);
}

it('serves each company page with only the current public projection', function (string $url, string $pageName) {
    $version = providerWebsitePublication();
    $future = $version->configuration;
    $future['public']['provider_name'] = 'Not effective yet';
    ReferralSettingsVersion::create(['version' => 2, 'state' => 'published', 'configuration' => $future, 'published_at' => now(), 'effective_at' => now()->addDay()]);
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('public-provider/show')
            ->where('page', $pageName)->where('provider.name', 'Example Support')
            ->where('provider.notice', 'Current published notice')->has('provider.services', 1)
            ->missing('provider.pathways')->missing('provider.proposals')->missing('provider.intake')->missing('provider.business_calendar'));
})->with([
    ['/', 'home'], ['/services', 'services'], ['/services/supported_living', 'service'], ['/funding', 'funding'],
    ['/about', 'about'], ['/contact', 'contact'], ['/privacy-policy', 'privacy'], ['/terms', 'terms'], ['/accessibility', 'accessibility'],
]);

it('rejects unpublished services and normalises intake prefills without trusting arbitrary queries', function () {
    providerWebsitePublication();
    $this->get('/services/unpublished_support')->assertNotFound();
    $this->get('/referrals/new?purpose=funding&service=supported_living')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('initialPurpose', 'funding')->where('initialService', 'supported_living')->where('intake.notice_version', 'public-1')->where('provider.notice_version', 'public-1'));
    $this->get('/referrals/new?purpose=converted&service=unpublished_support')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('initialPurpose', 'support')->where('initialService', 'unsure'));
});

it('keeps unpublished intake closed and provides no invented phone contact', function () {
    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('provider.intake_enabled', false)->where('provider.phone', '')->where('provider.notice', ''));
    $this->get('/referrals/new')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('intake.enabled', false));
});

it('retires old software links and never reports or sends an old contact form as delivered', function () {
    Mail::fake();
    $this->get('/features')->assertRedirect('/services');
    $this->get('/pricing')->assertRedirect('/funding');
    $this->get('/smart-monitoring')->assertRedirect('/services');
    $this->get('/privacy')->assertRedirect('/privacy-policy');
    $this->post('/contact', ['name' => 'Example Person', 'email' => 'example@example.test', 'message' => 'Earlier draft'])->assertStatus(303)->assertRedirect('/contact?previous_form=retired');
    $this->postJson('/contact', ['message' => 'Earlier draft'])->assertStatus(409)->assertJsonPath('enquiry_url', '/referrals/new');
    $this->get('/contact?previous_form=retired')->assertInertia(fn (AssertableInertia $page) => $page->where('previousFormRetired', true));
    Mail::assertNothingSent();
    expect(CareReferral::count())->toBe(0);
});
