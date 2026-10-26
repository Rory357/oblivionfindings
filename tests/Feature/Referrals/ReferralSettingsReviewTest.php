<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\ReferralSettingsImpact;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralSettingsFixtures.php';

function referralSettingsPublishInput(string $note = 'Approved at the settings review'): array
{
    return ['effective_at' => now()->toISOString(), 'acknowledge_impact' => true, 'publication_note' => $note];
}

it('discards an unpublished draft with a reason and keeps it in history', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $reader = referralSettingsActor([]);
    $publisher = referralSettingsActor(['settings.publish']);
    $draft = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $url = '/settings/referrals/'.$draft->id.'/discard';
    $this->actingAs($reader)->postJson($url, ['reason' => 'No longer needed'])->assertForbidden();
    $this->actingAs($author)->postJson($url, ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson($url, ['reason' => 'Superseded by the October review'])->assertOk()
        ->assertJsonPath('version.state', 'discarded')->assertJsonPath('version.discard_reason', 'Superseded by the October review');
    $this->postJson($url, ['reason' => 'Superseded by the October review'])->assertOk();
    expect(ReferralEvent::query()->where('kind', 'settings.discarded')->count())->toBe(1)
        ->and(ReferralSettingsVersion::query()->count())->toBe(1);
    $this->actingAs($publisher)->postJson('/settings/referrals/'.$draft->id.'/publish', referralSettingsPublishInput())->assertConflict();

    $second = $service->draft($author, ReferralSettings::defaults(), null, 'Second configuration');
    $this->postJson('/settings/referrals/'.$second->id.'/discard', ['reason' => 'A reviewer declined this draft'])->assertOk();
    $third = $service->draft($author, ReferralSettings::defaults(), null, 'Third configuration');
    $service->publish($publisher, $third->id, now()->toDateTimeString(), 'Approved at the settings review');
    $this->actingAs($author)->postJson('/settings/referrals/'.$third->id.'/discard', ['reason' => 'Try to discard published settings'])->assertConflict();
    expect($third->fresh()->state)->toBe('published')->and((int) $second->fresh()->discarded_by)->toBe($publisher->id);
});

it('restores an earlier publication into a new draft and keeps later options turned off', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $first = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $service->publish($publisher, $first->id, now()->toDateTimeString());
    $next = ReferralSettings::defaults();
    $next['services'][] = ['id' => 'respite', 'label' => 'Respite', 'enabled' => true];
    $next['choices']['hold'][] = ['id' => 'awaiting_interpreter', 'label' => 'Waiting for an interpreter', 'enabled' => true];
    $next['public']['provider_name'] = 'Renamed provider';
    $second = $service->draft($author, $next, $first->id, 'Add respite and an interpreter hold');
    $service->publish($publisher, $second->id, now()->toDateTimeString());

    $input = ['reason' => 'Return to the original provider name', 'request_key' => (string) Str::uuid()];
    $response = $this->actingAs($author)->postJson('/settings/referrals/'.$first->id.'/restore', $input)->assertCreated()
        ->assertJsonPath('version.state', 'draft')->assertJsonPath('version.base_version_id', $second->id)
        ->assertJsonPath('version.restored_from_version_id', $first->id);
    $restored = ReferralSettingsVersion::query()->findOrFail($response->json('version.id'));
    expect($restored->configuration['public']['provider_name'])->toBe('Oblivion Care')
        ->and(collect($restored->configuration['services'])->firstWhere('id', 'respite')['enabled'])->toBeFalse()
        ->and(collect($restored->configuration['choices']['hold'])->firstWhere('id', 'awaiting_interpreter')['enabled'])->toBeFalse()
        ->and($first->fresh()->configuration['public']['provider_name'])->toBe('Oblivion Care')
        ->and(collect($first->fresh()->configuration['services'])->pluck('id')->all())->toBe(['supported_living'])
        ->and($second->fresh()->configuration['public']['provider_name'])->toBe('Renamed provider');

    $this->postJson('/settings/referrals/'.$first->id.'/restore', $input)->assertCreated()->assertJsonPath('version.id', $restored->id);
    $this->postJson('/settings/referrals/'.$second->id.'/restore', [...$input, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson('/settings/referrals/'.$restored->id.'/restore', [...$input, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
    expect(ReferralEvent::query()->where('kind', 'settings.restored')->count())->toBe(1);

    // The restored draft still needs a different person to publish it.
    $this->postJson('/settings/referrals/'.$restored->id.'/publish', referralSettingsPublishInput())->assertForbidden();
    $this->actingAs($publisher)->postJson('/settings/referrals/'.$restored->id.'/publish', referralSettingsPublishInput())->assertOk();
    expect($service->effective()->id)->toBe($restored->id);
});

it('keeps hold reasons, close reasons and priorities configurable around stable standard choices', function () {
    $service = app(ReferralSettings::class);
    $config = ReferralSettings::defaults();
    $broken = $config;
    array_shift($broken['choices']['hold']);
    expect(fn () => $service->validate($broken))->toThrow(ValidationException::class);
    $broken = $config;
    $broken['choices']['close'] = array_map(fn ($item) => $item['id'] === 'duplicate' ? [...$item, 'enabled' => false] : $item, $config['choices']['close']);
    expect(fn () => $service->validate($broken))->toThrow(ValidationException::class);
    $broken = $config;
    $broken['choices']['hold'][] = ['id' => 'pause', 'label' => 'On the waitlist for a place', 'enabled' => true];
    expect(fn () => $service->validate($broken))->toThrow(ValidationException::class);
    $broken = $config;
    $broken['choices']['priority'] = array_map(fn ($item) => [...$item, 'enabled' => false], $config['choices']['priority']);
    expect(fn () => $service->validate($broken))->toThrow(ValidationException::class);

    $config['choices']['hold'][] = ['id' => 'awaiting_interpreter', 'label' => 'Waiting for an interpreter', 'enabled' => true];
    $config['choices']['close'] = array_map(fn ($item) => $item['id'] === 'spam' ? [...$item, 'enabled' => false] : $item, $config['choices']['close']);
    $config['choices']['priority'][0]['label'] = 'Same day';
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $version = $service->draft($author, $config, null, 'Configure reasons and priorities');
    $service->publish($publisher, $version->id, now()->toDateTimeString());
    expect(ReferralSettings::enabledChoiceKeys($version->configuration, 'close'))->not->toContain('spam')->toContain('duplicate')
        ->and(ReferralSettings::enabledChoiceKeys($version->configuration, 'hold'))->toContain('awaiting_interpreter')
        ->and(ReferralSettings::choiceLabel($version->configuration, 'priority', 'urgent'))->toBe('Same day');

    $next = $version->configuration;
    $next['choices']['hold'] = array_values(array_filter($next['choices']['hold'], fn ($item) => $item['id'] !== 'awaiting_interpreter'));
    expect(fn () => $service->draft($author, $next, $version->id, 'Remove the interpreter reason'))->toThrow(ValidationException::class);

    $legacy = ReferralSettings::defaults();
    unset($legacy['choices']);
    expect(ReferralSettings::enabledChoiceKeys($legacy, 'hold'))->toBe(ReferralLifecycle::HOLD_REASONS)
        ->and(ReferralSettings::choiceLabel($legacy, 'close', 'duplicate'))->toBe('Duplicate referral')
        ->and(ReferralSettings::choiceLabel($legacy, 'close', 'retired_key'))->toBe('Retired key')
        ->and($service->validate($legacy))->not->toHaveKey('choices');
});

it('stores optional pathway contact details for staff without needing governance', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $first = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $service->publish($publisher, $first->id, now()->toDateTimeString());
    $config = ReferralSettings::defaults();
    $config['pathways'][0]['contact'] = ['organisation' => 'Needs assessment service', 'phone' => '0800 000 000', 'email' => 'nasc@example.org',
        'website' => 'https://example.org/nasc', 'region_notes' => 'Covers Auckland and Northland'];
    $draft = $service->draft($author, $config, $first->id, 'Add the needs assessment contact');
    expect($service->requiresGovernance($first->configuration, $draft->configuration))->toBeFalse();
    $service->publish($publisher, $draft->id, now()->toDateTimeString());
    expect($draft->fresh()->configuration['pathways'][0]['contact']['organisation'])->toBe('Needs assessment service');

    foreach ([['website' => 'http://example.org'], ['phone' => 'call reception'], ['email' => 'not-an-email'], ['fax' => '09 000 0000']] as $invalid) {
        $broken = $config;
        $broken['pathways'][0]['contact'] = [...$config['pathways'][0]['contact'], ...$invalid];
        expect(fn () => $service->validate($broken))->toThrow(ValidationException::class);
    }
});

it('previews plain-language changes and governance before anything is saved', function () {
    $author = referralSettingsActor(['settings.manage']);
    $config = ReferralSettings::defaults();
    $config['intake']['first_contact_business_days'] = 4;
    $config['public']['acknowledgement_enabled'] = true;
    $response = $this->actingAs($author)->postJson('/settings/referrals/preview', ['configuration' => $config])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('governance_required', true)->assertJsonPath('impact.version_id', null);
    $lines = collect($response->json('impact.lines'));
    expect($lines->firstWhere('area', 'workflow'))->toMatchArray([
        'text' => 'New referrals are due for first contact within 4 working days instead of 2.', 'loosens' => true,
    ])->and($lines->firstWhere('affects', 'People who choose email contact')['loosens'])->toBeFalse();
    $this->postJson('/settings/referrals/preview', ['configuration' => [...$config, 'services' => 'broken']])->assertUnprocessable();
    expect(ReferralSettingsVersion::query()->count())->toBe(0);

    $before = ReferralSettings::defaults();
    $before['pathways'][0]['evidence_checklist'] = ['Needs assessment summary', 'Support plan'];
    $after = $before;
    $after['pathways'][0]['evidence_checklist'] = ['Support plan'];
    $after['pathways'][0]['contact'] = ['organisation' => 'Needs assessment service', 'phone' => null, 'email' => null, 'website' => null, 'region_notes' => null];
    $after['choices']['close'] = array_map(fn ($item) => $item['id'] === 'spam' ? [...$item, 'enabled' => false] : $item, $before['choices']['close']);
    $texts = collect(app(ReferralSettingsImpact::class)->lines($before, $after));
    expect($texts->pluck('text')->all())->toContain('“DSS / NASC” no longer asks for: Needs assessment summary.')
        ->toContain('Close reasons: “Spam” turned off. Referrals already using a choice keep it.')
        ->and($texts->firstWhere('text', '“DSS / NASC” no longer asks for: Needs assessment summary.')['loosens'])->toBeTrue()
        ->and($texts->filter(fn ($line) => str_contains($line['text'], 'Contact details'))->first()['loosens'])->toBeFalse();
});

it('shows waiting drafts, the audit trail, role grants and standard choices for older versions', function () {
    $author = referralSettingsActor(['settings.manage']);
    $legacy = ReferralSettings::defaults();
    unset($legacy['choices']);
    $draft = app(ReferralSettings::class)->draft($author, $legacy, null, 'Initial configuration');
    $this->actingAs($author)->get('/settings/referrals?version='.$draft->id)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/referrals/index')
            ->where('settings.pending_drafts.0.id', $draft->id)
            ->where('settings.pending_drafts.0.created_by_name', $author->name)
            ->where('settings.audit.0.kind', 'settings.drafted')
            ->where('settings.audit.0.actor_name', $author->name)
            ->where('settings.selected.configuration.choices.close.5.id', 'duplicate')
            ->where('settings.protected_choices.close', ['duplicate', 'other'])
            ->where('settings.delivery.email_configured', false)
            ->has('settings.role_grants')
            ->missing('settings.selected.request_key'));
    $this->getJson('/settings/referrals/migration-candidates')->assertOk()
        ->assertJsonPath('can_migrate', false)->assertJsonPath('data', []);
});

it('records who pays under each pathway and treats a switch to private payment as a weaker safeguard', function () {
    $defaults = ReferralSettings::defaults();
    expect(collect($defaults['pathways'])->firstWhere('id', 'private')['payer_kind'])->toBe('private')
        ->and(collect($defaults['pathways'])->where('payer_kind', 'external')->count())->toBe(count($defaults['pathways']) - 1);
    $service = app(ReferralSettings::class);
    $broken = $defaults;
    $broken['pathways'][0]['payer_kind'] = 'family';
    expect(fn () => $service->validate($broken))->toThrow(ValidationException::class);

    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $first = $service->draft($author, $defaults, null, 'Initial configuration');
    $service->publish($publisher, $first->id, now()->toDateTimeString());
    $config = $defaults;
    $config['pathways'][0]['payer_kind'] = 'private';
    $draft = $service->draft($author, $config, $first->id, 'Treat this pathway as private payment');
    expect($draft->fresh()->configuration['pathways'][0]['payer_kind'])->toBe('private')
        ->and($service->requiresGovernance($first->configuration, $draft->configuration))->toBeTrue()
        ->and(collect(app(ReferralSettingsImpact::class)->lines($first->configuration, $draft->configuration))->firstWhere('area', 'funding')['loosens'])->toBeTrue();

    $legacy = $defaults;
    unset($legacy['pathways'][0]['payer_kind'], $legacy['pathways'][5]['payer_kind']);
    $shown = ReferralSettings::presentable($legacy)['pathways'];
    expect($shown[0]['payer_kind'])->toBe('external')->and($shown[5]['payer_kind'])->toBe('private');
});
