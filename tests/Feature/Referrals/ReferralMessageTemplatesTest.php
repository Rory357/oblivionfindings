<?php

use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralMessageTemplates;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\ReferralSettingsImpact;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralSettingsFixtures.php';

function messageTemplateErrors(array $configuration): array
{
    try {
        app(ReferralSettings::class)->validate($configuration);
    } catch (ValidationException $exception) {
        return array_merge(...array_values($exception->errors()));
    }

    return [];
}

function withTemplate(array $changes, int $index = 0): array
{
    $configuration = ReferralSettings::defaults();
    $configuration['communications']['templates'][$index] = [...$configuration['communications']['templates'][$index], ...$changes];

    return $configuration;
}

it('ships safe standard templates, switched off, that validate and fill older versions', function () {
    $defaults = ReferralSettings::defaults();
    expect(messageTemplateErrors($defaults))->toBe([])
        ->and(array_column($defaults['communications']['templates'], 'label'))
        ->toBe(['First contact attempt', 'We tried to call you', 'Documents we still need', 'Appointment reminder', 'Please call us'])
        // Stephan, 21 Oct 2026: someone reviews the wording and switches them on.
        ->and(array_unique(array_column($defaults['communications']['templates'], 'enabled')))->toBe([false])
        ->and(array_column($defaults['communications']['templates'], 'audience', 'id'))
        ->toBe(['first_contact' => 'person', 'tried_to_call' => 'person', 'documents_needed' => 'referrer', 'appointment_reminder' => 'person', 'please_call_us' => 'contact'])
        ->and(ReferralMessageTemplates::standardIds())->toBe(array_column($defaults['communications']['templates'], 'id'));
    // Only names, the reference and the provider's own details can be merged: nothing about health, assessment or funding.
    expect(ReferralMessageTemplates::FIELDS)->toBe(['first_name', 'reference', 'provider_name', 'coordinator_name', 'contact_phone', 'response_hours', 'document_link']);
    foreach ($defaults['communications']['templates'] as $template) {
        expect(array_diff(ReferralMessageTemplates::fields($template['subject'].' '.$template['body']), ReferralMessageTemplates::FIELDS))->toBe([]);
        if ($template['channel'] === 'sms') {
            expect($template['subject'])->toBeNull()
                ->and(ReferralMessageTemplates::smsParts(ReferralMessageTemplates::render($template['body'], ReferralMessageTemplates::allowanceValues($defaults))))
                ->toBeLessThanOrEqual(ReferralMessageTemplates::SMS_MAX_PARTS);
        }
    }

    $legacy = $defaults;
    unset($legacy['communications']);
    expect(ReferralSettings::presentable($legacy)['communications']['templates'])->toBe($defaults['communications']['templates'])
        ->and(ReferralMessageTemplates::templates($legacy))->toBe(ReferralMessageTemplates::templates($defaults));
});

it('rejects unknown merge fields, stray braces, subject mistakes and texts that are too long', function () {
    expect(messageTemplateErrors(withTemplate(['body' => 'Kia ora {first_name}, about your {diagnosis}'])))
        ->toContain('“First contact attempt”: Remove {diagnosis}. Only the listed merge fields can be used, so no health or funding details are ever added.');
    expect(messageTemplateErrors(withTemplate(['subject' => 'Funding {funding_amount}']))[0])->toContain('Remove {funding_amount}');
    expect(messageTemplateErrors(withTemplate(['body' => 'Kia ora {first_name} }']))[0])->toContain('Use braces only around a merge field');
    expect(messageTemplateErrors(withTemplate(['subject' => '']))[0])->toContain('Give the email a subject');
    expect(messageTemplateErrors(withTemplate(['subject' => 'Upload {document_link}']))[0])->toContain('not the subject');
    expect(messageTemplateErrors(withTemplate(['subject' => 'A subject'], 1))[0])->toContain('Text messages have no subject');
    expect(messageTemplateErrors(withTemplate(['body' => str_repeat('Please call us back. ', 25)], 1))[0])->toContain('Shorten this text message');
    // Macrons halve each text part, so the same length can be too long.
    expect(messageTemplateErrors(withTemplate(['body' => str_repeat('Kia ora whānau. ', 13)], 1)))->not->toBe([])
        ->and(messageTemplateErrors(withTemplate(['body' => str_repeat('Kia ora whanau. ', 13)], 1)))->toBe([]);
    expect(messageTemplateErrors(withTemplate(['channel' => 'fax'])))->not->toBe([])
        ->and(messageTemplateErrors(withTemplate(['audience' => 'funder'])))->not->toBe([])
        ->and(messageTemplateErrors(withTemplate(['audience' => 'contact'])))->toBe([])
        ->and(messageTemplateErrors(withTemplate(['label' => 'We tried to call you'])))->toContain('Give each message template its own name.');
    $extra = ReferralSettings::defaults();
    $extra['communications']['templates'][0]['notes'] = 'Unexpected key';
    expect(messageTemplateErrors($extra))->not->toBe([]);
});

it('keeps published templates: they can be reworded or turned off, never removed or moved to another channel', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $first = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $service->publish($publisher, $first->id, now()->toDateTimeString(), 'Approved at the settings review');

    $removed = ReferralSettings::defaults();
    array_shift($removed['communications']['templates']);
    expect(fn () => $service->draft($author, $removed, $first->id, 'Remove the first template'))
        ->toThrow(ValidationException::class, 'A published message template can’t be removed; turn it off instead.');
    expect(fn () => $service->draft($author, withTemplate(['channel' => 'sms', 'subject' => null, 'body' => 'Kia ora {first_name}']), $first->id, 'Move it to text'))
        ->toThrow(ValidationException::class, 'Add a new template for a different channel or recipient.');
    expect(fn () => $service->draft($author, withTemplate(['audience' => 'referrer']), $first->id, 'Send it to the referrer'))
        ->toThrow(ValidationException::class);

    $changed = withTemplate(['enabled' => true, 'label' => 'First contact', 'body' => 'Kia ora {first_name}, please call {provider_name} on {contact_phone}.']);
    $changed['communications']['templates'][] = ['id' => 'interpreter_offer', 'label' => 'Interpreter offer', 'channel' => 'email', 'audience' => 'referrer',
        'subject' => 'An interpreter for {first_name}', 'body' => 'Kia ora {first_name}, we can arrange an interpreter. Ref {reference}', 'enabled' => true];
    $draft = $service->draft($author, $changed, $first->id, 'Reword and add an interpreter offer');
    $templates = collect($draft->fresh()->configuration['communications']['templates'])->keyBy('id');
    expect($templates['first_contact']['enabled'])->toBeTrue()->and($templates['first_contact']['label'])->toBe('First contact')
        ->and($templates['interpreter_offer']['audience'])->toBe('referrer')
        // Template wording is not a safeguard: contact safety is checked on every send.
        ->and($service->requiresGovernance($first->configuration, $draft->configuration))->toBeFalse();
    $line = collect(app(ReferralSettingsImpact::class)->lines($first->configuration, $draft->configuration))->firstWhere('affects', 'Staff sending messages to people');
    expect($line['text'])->toContain('“First contact” turned on')->toContain('“First contact attempt” renamed “First contact”')
        ->toContain('“First contact” reworded')->toContain('“Interpreter offer” added')
        ->and($line['loosens'])->toBeFalse();
});

it('treats the standard templates of an older publication as published', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $legacy = ReferralSettings::defaults();
    unset($legacy['communications']);
    $old = ReferralSettingsVersion::query()->create(['version' => 1, 'state' => 'published', 'configuration' => $legacy, 'published_at' => now(), 'effective_at' => now(),
        'created_by' => $author->id, 'published_by' => $publisher->id]);
    \App\Domain\Referrals\Models\ReferralConfiguration::query()->updateOrCreate(['id' => 1], ['published_version_id' => $old->id, 'lock_version' => 1]);
    $removed = ReferralSettings::defaults();
    $removed['communications']['templates'] = [];
    expect(fn () => $service->draft($author, $removed, $old->id, 'Drop the standard templates'))->toThrow(ValidationException::class);
    $kept = $service->draft($author, ReferralSettings::defaults(), $old->id, 'Store the standard templates');
    expect(count($kept->configuration['communications']['templates']))->toBe(5);
});

it('carries templates published since into a restored draft, turned off', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $first = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $service->publish($publisher, $first->id, now()->toDateTimeString(), 'Approved at the settings review');
    $next = ReferralSettings::defaults();
    $next['communications']['templates'][] = ['id' => 'call_back', 'label' => 'Call back', 'channel' => 'sms', 'audience' => 'person', 'subject' => null,
        'body' => 'Kia ora {first_name}, please call {provider_name} back. Ref {reference}', 'enabled' => true];
    $second = $service->draft($author, $next, $first->id, 'Add a call-back text');
    $service->publish($publisher, $second->id, now()->toDateTimeString(), 'Approved at the settings review');

    $restored = $service->restore($author, $first->id, 'Return to the first wording', (string) Str::uuid());
    $call = collect($restored->configuration['communications']['templates'])->firstWhere('id', 'call_back');
    expect($call)->not->toBeNull()->and($call['enabled'])->toBeFalse();
});

it('fills merge fields with plain values only, so a value can never add a field', function () {
    expect(ReferralMessageTemplates::render('Kia ora {first_name}, {document_link} {unknown}', ['first_name' => 'Aroha{document_link}']))
        ->toBe('Kia ora Arohadocument_link, {document_link} {unknown}')
        ->and(ReferralMessageTemplates::fields('A {first_name} and {first_name} {reference}'))->toBe(['first_name', 'first_name', 'reference'])
        ->and(ReferralMessageTemplates::smsParts(str_repeat('x', 160)))->toBe(1)
        ->and(ReferralMessageTemplates::smsParts(str_repeat('x', 161)))->toBe(2)
        ->and(ReferralMessageTemplates::smsParts(str_repeat('ā', 70)))->toBe(1)
        ->and(ReferralMessageTemplates::smsParts(str_repeat('ā', 71)))->toBe(2);
});

it('shows the templates and text-message status on the settings page', function () {
    $viewer = referralSettingsActor([]);
    config(['referral-delivery.sms_enabled' => false]);
    $this->actingAs($viewer)->get('/settings/referrals')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('settings.configuration.communications.templates', 5)
        // Nothing published yet: the standard templates are shown switched off, ready to review.
        ->where('settings.configuration.communications.templates.0.enabled', false)
        ->where('settings.delivery.sms_configured', false)
        ->where('settings.permissions', fn ($permissions) => isset($permissions['communications.send'])));
});
