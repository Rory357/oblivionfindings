<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\InternalSupportPreparation;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Domain\Referrals\Services\ReferralServiceSettings;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Client;
use App\Models\ServiceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralConversionFixtures.php';
require_once __DIR__.'/../../Support/ReferralStartFixtures.php';

function referralDeliverySettings(?array $types): ReferralSettingsVersion
{
    $settings = app(ReferralSettings::class)->effective();
    $configuration = $settings->configuration;
    $configuration['services'][0]['context_types'] = $types;

    return ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published',
        'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
}

it('validates setting types and requires governance to widen a published restriction', function () {
    $service = app(ReferralSettings::class);
    $legacy = ReferralSettings::defaults();
    expect(ReferralServiceSettings::contextTypes($legacy, 'supported_living'))->toBeNull()
        ->and(ReferralServiceSettings::contextTypes($legacy, 'unknown'))->toBe([]);
    $limited = $legacy;
    $limited['services'][0]['context_types'] = ['home_support'];
    expect($service->validate($limited)['services'][0]['context_types'])->toBe(['home_support'])
        ->and($service->requiresGovernance($legacy, $limited))->toBeFalse()
        ->and($service->requiresGovernance($limited, $legacy))->toBeTrue();
    $wider = $limited;
    $wider['services'][0]['context_types'][] = 'planned_respite';
    expect($service->requiresGovernance($limited, $wider))->toBeTrue();
    foreach ([[], ['invented_setting']] as $invalid) {
        $bad = $limited;
        $bad['services'][0]['context_types'] = $invalid;
        expect(fn () => $service->validate($bad))->toThrow(ValidationException::class);
    }
    $limited['services'][0]['enabled'] = false;
    expect(ReferralServiceSettings::contextTypes($limited, 'supported_living'))->toBe([]);
});

it('filters handover choices and rejects a forged incompatible setting before creating a person', function () {
    [$site, $actor, $referral, , , $input] = conversionFixture();
    referralDeliverySettings(['planned_respite']);
    $allowed = ServiceContext::factory()->create(['site_id' => $site->id, 'type' => 'planned_respite', 'is_active' => true]);
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral);
    expect(array_column($preview['service_contexts'], 'id'))->toBe([$allowed->id]);
    expect(fn () => app(ReferralConversionService::class)->convert($actor, $referral->id, 1, $input))->toThrow(ModelNotFoundException::class);
    expect(Client::count())->toBe(0)->and(ReferralConversion::count())->toBe(0);
    $receipt = app(ReferralConversionService::class)->convert($actor, $referral->id, 1, [...$input, 'service_context_id' => $allowed->id]);
    expect(Client::findOrFail($receipt['person']['id'])->service_context_id)->toBe($allowed->id);
});

it('uses current service mappings for internal preparation while preserving the existing person', function () {
    [$site, $actor, , $context] = conversionFixture();
    handoverCanonicalPermissions($actor, ['referrals.create']);
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'onboarding']);
    $settings = referralDeliverySettings(['planned_respite']);
    $allowed = ServiceContext::factory()->create(['site_id' => $site->id, 'type' => 'planned_respite', 'is_active' => true]);
    $service = app(InternalSupportPreparation::class);
    $options = $service->options($actor, $person);
    expect($options['services'][0]['context_types'])->toBe(['planned_respite']);
    $input = ['submission_key' => (string) Str::uuid(), 'person_fingerprint' => $options['person']['fingerprint'], 'settings_version_id' => $settings->id,
        'site_id' => $site->id, 'service_context_id' => $context->id, 'requested_service' => 'supported_living', 'team' => 'Intake',
        'reason' => 'Review a new episode of support with the person', 'next_action' => 'Review participation and authority',
        'next_due_at' => now()->addDay()->toISOString(), 'acknowledge_review' => true];
    expect(fn () => $service->create($actor, $person->id, $input))->toThrow(ModelNotFoundException::class);
    $case = $service->create($actor, $person->id, [...$input, 'service_context_id' => $allowed->id]);
    expect($case->intake['service_context_id'])->toBe($allowed->id)
        ->and($person->fresh()->status)->toBe('onboarding')->and($person->fresh()->service_context_id)->toBe($context->id);
});

it('requires renewed approval after the delivery policy changes and cannot start an excluded setting', function () {
    $f = referralStartFixture();
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    $this->travel(1)->minutes();
    referralDeliverySettings(['planned_respite']);
    expect(fn () => $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput()))->toThrow(HttpException::class);
    referralDeliverySettings(['home_support']);
    expect(fn () => $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput()))->toThrow(HttpException::class);
    expect($f['person']->fresh()->status)->toBe('onboarding')->and(ReferralServiceStart::sole()->status)->toBe('scheduled');
});
