<?php

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConfiguration;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralSubmission;
use App\Domain\Referrals\Services\PublicReferralIntake;
use App\Domain\Referrals\Services\ReferralReports;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

/** Published settings with online enquiries on, as a provider's website runs them. */
function contactEnquiryPublication(array $public = []): ReferralSettingsVersion
{
    $configuration = ReferralSettings::defaults();
    $configuration['public'] = [...$configuration['public'], 'enabled' => true, 'provider_name' => 'Example Living',
        'contact_phone' => '0800 000 000', 'notice' => 'Published notice', 'notice_version' => 'contact-1', 'acknowledgement_enabled' => true, ...$public];
    $configuration['intake']['team'] = 'Website intake';

    return ReferralSettingsVersion::query()->create(['version' => 1, 'state' => 'published', 'configuration' => $configuration,
        'published_at' => now()->subDay(), 'effective_at' => now()->subDay()]);
}

function contactEnquiryPayload(array $overrides = []): array
{
    return ['submission_token' => (string) Str::uuid(), 'name' => 'Aroha Example', 'contact' => 'aroha@example.test',
        'channel' => 'email', 'topic' => 'other', 'message' => 'Do you have a brochure I could read?', 'leave_message' => false,
        'website' => '', ...$overrides];
}

function contactEnquiryRunDemoMigration(): void
{
    (require base_path('database/migrations/2026_10_23_120100_publish_oblivion_living_demo_referral_settings.php'))->up();
}

it('saves a message as a general enquiry for the intake team and returns only an ENQ receipt', function () {
    Mail::fake();
    contactEnquiryPublication();
    $response = $this->postJson('/contact', contactEnquiryPayload())->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');
    $response->assertExactJson(['reference' => 'ENQ-1001', 'status' => 'received']);

    $enquiry = CareReferral::query()->sole();
    expect($enquiry->general_enquiry)->toBeTrue()->and($enquiry->entry_kind)->toBe('contact')
        ->and($enquiry->isGeneralEnquiry())->toBeTrue()->and($enquiry->enquiry_number)->toBe(1001)
        ->and($enquiry->site_id)->toBeNull()->and($enquiry->owner_id)->toBeNull()->and($enquiry->team)->toBe('Website intake')
        ->and($enquiry->stage)->toBe('new')->and($enquiry->identity)->toBe([])->and($enquiry->requested_service)->toBe('unsure')
        ->and($enquiry->intake['enquiry'])->toBe(['topic' => 'other', 'message' => 'Do you have a brochure I could read?', 'reply_by' => 'email'])
        ->and($enquiry->intake['submitted_contact'])->toMatchArray(['name' => 'Aroha Example', 'email' => 'aroha@example.test', 'phone' => null, 'channel' => 'email', 'leave_message' => false])
        ->and($enquiry->authority['contact_permission'])->toBeTrue()->and($enquiry->next_due_at)->not->toBeNull();
    // History and the intake notification are saved with it; nothing is sent to the person.
    expect(ReferralEvent::query()->where('referral_id', $enquiry->id)->where('kind', 'referral.received')->sole()->payload)
        ->toMatchArray(['source' => 'website', 'form' => 'contact', 'enquiry' => 'ENQ-1001'])
        ->and(ReferralOutbox::query()->where('referral_id', $enquiry->id)->pluck('kind')->all())->toBe(['intake_received'])
        ->and(ReferralSubmission::query()->sole()->referral_id)->toBe($enquiry->id);
    Mail::assertNothingSent();
    // The receipt is not a way back in.
    $this->get('/referrals/ENQ-1001')->assertNotFound();
});

it('keeps a text or phone preference with the phone number and records the safe-contact answer', function () {
    contactEnquiryPublication();
    $this->postJson('/contact', contactEnquiryPayload(['contact' => '021 555 0100', 'channel' => 'text', 'topic' => null, 'leave_message' => true]))->assertCreated();
    $enquiry = CareReferral::query()->sole();
    expect($enquiry->intake['submitted_contact'])->toMatchArray(['email' => null, 'phone' => '021 555 0100', 'channel' => 'phone', 'leave_message' => true])
        ->and($enquiry->intake['enquiry'])->toMatchArray(['topic' => null, 'reply_by' => 'text']);

    // With no channel chosen, the contact decides it.
    $this->postJson('/contact', contactEnquiryPayload(['contact' => '04 555 0100', 'channel' => null]))->assertCreated();
    expect(CareReferral::query()->latest('id')->first()->intake['enquiry']['reply_by'])->toBe('phone');
});

it('numbers general enquiries from a counter that never reuses a number, and leaves referrals alone', function () {
    contactEnquiryPublication();
    $service = app(PublicReferralIntake::class);
    expect($service->submitContact(contactEnquiryPayload())['reference'])->toBe('ENQ-1001')
        ->and($service->submitContact(contactEnquiryPayload())['reference'])->toBe('ENQ-1002');
    // Even if the latest number disappeared, the next one is new.
    CareReferral::query()->where('enquiry_number', 1002)->update(['enquiry_number' => null]);
    expect($service->submitContact(contactEnquiryPayload())['reference'])->toBe('ENQ-1003');
    DB::table('care_referral_enquiry_sequence')->where('id', 1)->update(['last_number' => 2047]);
    expect($service->submitContact(contactEnquiryPayload())['reference'])->toBe('ENQ-2048');

    // A care referral keeps its own reference format and gets no ENQ number.
    $referral = referralTestRecord(null);
    expect($referral->enquiry_number)->toBeNull()->and($referral->displayReference())->toBe($referral->reference)
        ->and(CareReferral::query()->whereNotNull('enquiry_number')->distinct()->count('enquiry_number'))->toBe(3);
});

it('gives a general question from the enquiry form an ENQ reference too', function () {
    $payload = publicReferralFixtureForContactTest();
    $receipt = app(PublicReferralIntake::class)->submit($payload);
    $record = CareReferral::query()->sole();
    expect($record->entry_kind)->toBe('business')->and($record->isGeneralEnquiry())->toBeTrue()
        ->and($receipt)->toBe(['reference' => 'ENQ-1001', 'status' => 'received']);
});

it('rejects missing, malformed and unexpected fields without saving anything', function (array $overrides, string $field, ?string $message = null) {
    contactEnquiryPublication();
    $response = $this->postJson('/contact', contactEnquiryPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors([$field]);
    if ($message) {
        expect($response->json('errors')[$field][0])->toBe($message);
    }
    expect(CareReferral::query()->count())->toBe(0)->and(ReferralSubmission::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'form.name', 'Enter your name.'],
    'no contact' => [['contact' => ''], 'form.contact', 'Enter an email address or phone number.'],
    'bad email' => [['contact' => 'aroha@'], 'form.contact', 'Check the email address or phone number. We need it to reply.'],
    'bad phone' => [['contact' => '12ab'], 'form.contact', 'Check the email address or phone number. We need it to reply.'],
    'text to an email' => [['channel' => 'text'], 'form.channel', 'To get a text, give us a phone number instead of an email address.'],
    'email to a phone' => [['contact' => '021 555 0100', 'channel' => 'email'], 'form.channel', 'To get an email, give us an email address instead of a phone number.'],
    'unknown channel' => [['channel' => 'fax'], 'form.channel'],
    'unknown topic' => [['topic' => 'complaint'], 'form.topic'],
    'no message' => [['message' => ''], 'form.message', 'Write a short message.'],
    'short message' => [['message' => 'Hi'], 'form.message', 'Write a little more so we know how to help.'],
    'long message' => [['message' => str_repeat('a', 2001)], 'form.message', 'Keep your message under 2,000 characters.'],
    'no safe-contact answer' => [['leave_message' => null], 'form.leave_message'],
    'bad token' => [['submission_token' => 'not-a-uuid'], 'form.submission_token'],
    'staff-only field' => [['stage' => 'converted'], 'form'],
]);

it('refuses a filled-in honeypot', function () {
    contactEnquiryPublication();
    $this->postJson('/contact', contactEnquiryPayload(['website' => 'https://spam.example']))->assertUnprocessable()->assertJsonValidationErrors(['form.website']);
    expect(CareReferral::query()->count())->toBe(0);
});

it('returns the same receipt for a retry and refuses changed content under the same key', function () {
    contactEnquiryPublication();
    $payload = contactEnquiryPayload();
    $first = $this->postJson('/contact', $payload)->assertCreated()->json();
    $this->postJson('/contact', $payload)->assertCreated()->assertExactJson($first);
    $this->postJson('/contact', [...$payload, 'message' => 'A different message entirely'])->assertStatus(409);
    expect(CareReferral::query()->count())->toBe(1)->and(ReferralOutbox::query()->count())->toBe(1);

    // A key already used for a website referral can't be replayed as a message.
    $referral = publicReferralFixtureForContactTest(['purpose' => 'support', 'authority_status' => 'self', 'relationship' => 'self', 'person' => ['first_name' => 'Test', 'last_name' => 'Person', 'preferred_name' => '']]);
    app(PublicReferralIntake::class)->submit($referral);
    $this->postJson('/contact', contactEnquiryPayload(['submission_token' => $referral['submission_token']]))->assertStatus(409);
});

it('throttles the public endpoint', function () {
    contactEnquiryPublication();
    foreach (range(1, 6) as $attempt) {
        $this->postJson('/contact', contactEnquiryPayload(['name' => '']))->assertUnprocessable();
    }
    $this->postJson('/contact', contactEnquiryPayload())->assertStatus(429);
    expect(CareReferral::query()->count())->toBe(0);
});

it('says plainly when online messages are off and saves nothing', function () {
    $this->postJson('/contact', contactEnquiryPayload())->assertStatus(503);
    contactEnquiryPublication(['enabled' => false, 'notice' => '']);
    $this->postJson('/contact', contactEnquiryPayload())->assertStatus(503);
    expect(CareReferral::query()->count())->toBe(0)->and(ReferralSubmission::query()->count())->toBe(0);
});

it('keeps general enquiries in their own queue and out of every referral count', function () {
    contactEnquiryPublication();
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny', 'centralIntake']);
    $referral = referralTestRecord(null, ['next_due_at' => now()->subDay()]);
    // A process enquiry about a person (authority not yet confirmed) is still referral work.
    $process = referralTestRecord(null, ['general_enquiry' => true, 'entry_kind' => 'support', 'identity' => []]);
    app(PublicReferralIntake::class)->submitContact(contactEnquiryPayload());
    $enquiry = CareReferral::query()->where('entry_kind', 'contact')->sole();
    $enquiry->update(['next_due_at' => now()->subDay()]);

    $data = $this->actingAs($reader)->getJson(route('referrals.data'))->assertOk()
        ->assertJsonPath('stats.open', 2)->assertJsonPath('stats.unassigned', 2)->assertJsonPath('stats.overdue', 1)
        ->assertJsonPath('stats.awaiting_contact', 2)->assertJsonPath('counts.referrals', 2)->assertJsonPath('counts.enquiries', 1)->json();
    expect(collect($data['referrals']['data'])->pluck('reference')->sort()->values()->all())->toBe(collect([$referral->reference, $process->reference])->sort()->values()->all())
        ->and(collect($data['savedViews'])->firstWhere('key', 'overdue')['count'])->toBe(1);
    foreach (['all', 'unassigned', 'overdue', 'central', 'awaiting_contact'] as $queue) {
        expect(collect($this->getJson(route('referrals.data', ['queue' => $queue]))->json('referrals.data'))->pluck('reference'))
            ->not->toContain($enquiry->reference);
    }
    $this->getJson(route('referrals.data', ['layout' => 'pipeline']))->assertOk()
        ->assertJsonPath('pipeline.0.total', 2);

    // Their own queue, with the ENQ reference on the row.
    $this->getJson(route('referrals.data', ['view' => 'enquiries']))->assertOk()
        ->assertJsonPath('view', 'enquiries')->assertJsonPath('referrals.total', 1)
        ->assertJsonPath('referrals.data.0.reference', $enquiry->reference)->assertJsonPath('referrals.data.0.enquiry_reference', 'ENQ-1001')
        ->assertJsonPath('referrals.data.0.is_general_enquiry', true)->assertJsonPath('referrals.data.0.name', 'Enquiry from Aroha Example');
    $this->getJson(route('referrals.data', ['view' => 'enquiries', 'queue' => 'overdue']))->assertJsonPath('referrals.total', 1);
    $this->getJson(route('referrals.data', ['view' => 'enquiries', 'q' => 'ENQ-1001']))->assertJsonPath('referrals.total', 1);
    $this->getJson(route('referrals.data', ['view' => 'enquiries', 'q' => 'aroha']))->assertJsonPath('referrals.total', 1);
    $this->getJson(route('referrals.data', ['view' => 'enquiries', 'layout' => 'pipeline']))->assertJsonPath('filters.layout', 'table');
    // A queue that belongs to referrals falls back to the enquiry list's own.
    $this->getJson(route('referrals.data', ['view' => 'enquiries', 'queue' => 'ready_for_handover']))->assertJsonPath('filters.queue', 'open');

    // The profile shows the message and its ENQ reference.
    $this->getJson(route('referrals.show.data', $enquiry->reference))->assertOk()
        ->assertJsonPath('referral.enquiry_reference', 'ENQ-1001')->assertJsonPath('referral.enquiry.message', 'Do you have a brochure I could read?')
        ->assertJsonPath('referral.enquiry.reply_by', 'email')->assertJsonPath('referral.contact.leave_message', false);
    // Closed, it leaves the open queue but stays in the enquiry list's "All records".
    $enquiry->update(['stage' => 'closed', 'closure_reason' => 'information_only', 'closed_at' => now()]);
    $this->getJson(route('referrals.data'))->assertJsonPath('counts.enquiries', 0);
    $this->getJson(route('referrals.data', ['view' => 'enquiries', 'queue' => 'all']))->assertJsonPath('referrals.total', 1);
});

it('becomes a referral once the intake is completed', function () {
    contactEnquiryPublication();
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny', 'centralIntake']);
    app(PublicReferralIntake::class)->submitContact(contactEnquiryPayload());
    $enquiry = CareReferral::query()->sole();
    // What ReferralIntakeCorrection does on "convert to referral": the record is no longer a general enquiry.
    $enquiry->update(['general_enquiry' => false, 'identity' => ['first_name' => 'Aroha']]);
    $this->actingAs($reader)->getJson(route('referrals.data'))->assertJsonPath('counts.referrals', 1)->assertJsonPath('counts.enquiries', 0)
        ->assertJsonPath('referrals.data.0.enquiry_reference', 'ENQ-1001')->assertJsonPath('referrals.data.0.is_general_enquiry', false);
});

it('leaves general enquiries out of referral reports and exports', function () {
    config(['referral-reports.small_group_threshold' => 1]);
    contactEnquiryPublication();
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'centralIntake', 'reports.view']);
    referralTestRecord(null);
    app(PublicReferralIntake::class)->submitContact(contactEnquiryPayload());
    $reports = app(ReferralReports::class);
    $filters = $reports->filters([]);
    $report = $reports->build($actor, $filters, CarbonImmutable::now('UTC'));
    expect(collect($report['metrics'])->firstWhere('key', 'received')['value'])->toBe(1)
        ->and(collect($report['metrics'])->firstWhere('key', 'unassigned')['value'])->toBe(0)
        ->and(array_sum(array_column($report['groups']['source'], 'count')))->toBe(1)
        ->and(array_sum(array_column($report['groups']['stage'], 'count')))->toBe(1)
        ->and($reports->query($actor, $filters)->where('entry_kind', 'contact')->count())->toBe(0);
    foreach (['received', 'unassigned', 'overdue'] as $metric) {
        expect($reports->metric($reports->query($actor, $reports->filters(['metric' => $metric])), $actor, $metric, CarbonImmutable::now('UTC'))->pluck('entry_kind'))
            ->not->toContain('contact');
    }
});

it('stores, validates and publishes the Contact us page details', function () {
    $settings = app(ReferralSettings::class);
    $configuration = ReferralSettings::defaults();
    expect($configuration['public'])->toMatchArray(['office_address' => '', 'contact_email' => '', 'opening_hours' => '']);
    $configuration['public'] = [...$configuration['public'], 'office_address' => 'Level 1, 18 Example Street, Porirua 5022',
        'contact_email' => 'kiaora@example.test', 'opening_hours' => 'Monday to Friday, 8.30am to 5pm'];
    expect($settings->validate($configuration)['public']['contact_email'])->toBe('kiaora@example.test');
    expect(fn () => $settings->validate([...$configuration, 'public' => [...$configuration['public'], 'contact_email' => 'not an email']]))
        ->toThrow(ValidationException::class);
    expect(fn () => $settings->validate([...$configuration, 'public' => [...$configuration['public'], 'opening_hours' => str_repeat('x', 201)]]))
        ->toThrow(ValidationException::class);

    // Versions published before these existed read as blank.
    $older = ReferralSettings::defaults();
    unset($older['public']['office_address'], $older['public']['contact_email'], $older['public']['opening_hours']);
    expect($settings->validate($older)['public'])->not->toHaveKey('contact_email')
        ->and(ReferralSettings::presentable($older)['public'])->toMatchArray(['office_address' => '', 'contact_email' => '', 'opening_hours' => '']);

    // The public projection carries them to the website; blank when not set.
    contactEnquiryPublication(['office_address' => 'Level 1, 18 Example Street, Porirua 5022', 'contact_email' => 'kiaora@example.test', 'opening_hours' => 'Weekdays 9 to 5']);
    $this->get('/contact')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('provider.address', 'Level 1, 18 Example Street, Porirua 5022')->where('provider.email', 'kiaora@example.test')
        ->where('provider.opening_hours', 'Weekdays 9 to 5')->missing('provider.intake'));
});

it('describes a change to the Contact us details on review', function () {
    $before = ReferralSettings::defaults();
    $after = $before;
    $after['public']['opening_hours'] = 'Weekdays 9 to 5';
    $lines = app(\App\Domain\Referrals\Services\ReferralSettingsImpact::class)->lines($before, $after);
    expect(collect($lines)->pluck('text'))->toContain('The Contact us page shows updated office address, email or opening hours.')
        ->and(app(ReferralSettings::class)->requiresGovernance($before, $after))->toBeFalse();
});

it('publishes the Oblivion Living demo settings only on a demo server, once, and never over real settings', function () {
    config(['demo.site' => false]);
    contactEnquiryRunDemoMigration();
    expect(ReferralSettingsVersion::query()->count())->toBe(0);

    config(['demo.site' => true]);
    contactEnquiryRunDemoMigration();
    contactEnquiryRunDemoMigration();
    $version = ReferralSettingsVersion::query()->sole();
    $public = $version->configuration['public'];
    expect($version->state)->toBe('published')->and((int) ReferralConfiguration::query()->find(1)->published_version_id)->toBe((int) $version->id)
        ->and($public)->toMatchArray(['enabled' => true, 'provider_name' => 'Oblivion Living', 'contact_phone' => '0800 000 000',
            'office_address' => 'Level 1, 18 Demo Street, Porirua 5022', 'notice_version' => 'demo-2026-10-23'])
        ->and($public['notice'])->toStartWith('DEMO PLACEHOLDER')->and($public['opening_hours'])->not->toBe('')
        ->and(array_column($version->configuration['services'], 'label'))->toBe(['Supported living', 'In the community', 'Short breaks'])
        ->and(app(ReferralSettings::class)->effective()?->id)->toBe($version->id);
    // The public forms now work end to end.
    $this->postJson('/contact', contactEnquiryPayload())->assertCreated()->assertJsonPath('reference', 'ENQ-1001');
});

it('leaves an existing published version alone when the demo switch is on', function () {
    $existing = contactEnquiryPublication();
    config(['demo.site' => true]);
    contactEnquiryRunDemoMigration();
    expect(ReferralSettingsVersion::query()->count())->toBe(1)
        ->and($existing->fresh()->configuration['public']['provider_name'])->toBe('Example Living');
});

/** The website referral form's fixture, as a general question ("business"). */
function publicReferralFixtureForContactTest(array $overrides = []): array
{
    $version = ReferralSettingsVersion::query()->where('state', 'published')->first() ?? contactEnquiryPublication();

    return [
        'submission_token' => (string) Str::uuid(), 'settings_version_id' => $version->id, 'notice_version' => 'contact-1',
        'purpose' => 'business', 'relationship' => 'general', 'authority_status' => 'unsure',
        'contact' => ['name' => 'Test Person', 'email' => 'person@example.test', 'phone' => '', 'channel' => 'email', 'safe_time' => '', 'leave_message' => false, 'communication' => ''],
        'person' => [], 'region' => '', 'requested_service' => 'unsure', 'goals' => '',
        'funding_situation' => 'unsure', 'contact_permission' => true, 'privacy_acknowledged' => true, 'website' => '',
        ...$overrides,
    ];
}
