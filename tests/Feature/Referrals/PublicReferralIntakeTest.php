<?php

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralSubmission;
use App\Domain\Referrals\Services\PublicReferralIntake;
use App\Domain\Referrals\Services\ReferralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

function publicReferralFixture(): array
{
    $config = ReferralSettings::defaults();
    $config['public'] = [...$config['public'], 'enabled' => true, 'notice' => 'Test privacy notice', 'notice_version' => 'test-1', 'contact_phone' => '0800 000 000', 'acknowledgement_enabled' => true];
    $version = ReferralSettingsVersion::query()->create([
        'version' => 1, 'state' => 'published', 'configuration' => $config, 'published_at' => now()->subDay(), 'effective_at' => now()->subDay(),
    ]);

    return [
        'submission_token' => (string) Str::uuid(), 'settings_version_id' => $version->id, 'notice_version' => 'test-1',
        'purpose' => 'funding', 'relationship' => 'self', 'authority_status' => 'self',
        'contact' => ['name' => 'Test Person', 'email' => 'person@example.test', 'phone' => '', 'channel' => 'email', 'safe_time' => '', 'leave_message' => false, 'communication' => 'Please write plainly'],
        'person' => ['first_name' => 'Test', 'last_name' => 'Person', 'preferred_name' => 'Test'],
        'region' => 'Auckland', 'requested_service' => 'supported_living', 'goals' => 'Help exploring support',
        'funding_situation' => 'need_help', 'contact_permission' => true, 'privacy_acknowledged' => true, 'website' => '',
    ];
}

it('durably saves an enquiry before returning a minimal receipt without sending email inline', function () {
    Mail::fake();
    $payload = publicReferralFixture();
    $receipt = app(PublicReferralIntake::class)->submit($payload);
    $referral = CareReferral::query()->sole();
    expect($receipt)->toBe(['reference' => $referral->reference, 'status' => 'received'])
        ->and($referral->stage)->toBe('new')->and($referral->site_id)->toBeNull()->and($referral->owner_id)->toBeNull()
        ->and($referral->authority['staff_review'])->toBe('pending')
        ->and($referral->intake['funding_situation'])->toBe('need_help')
        ->and(ReferralOutbox::query()->count())->toBe(2)
        // Routing is recorded separately for the funding audience: a rule label can reveal a funding condition.
        ->and(ReferralEvent::query()->orderBy('id')->get(['kind', 'audience'])->map(fn ($event) => $event->kind.':'.$event->audience)->all())
        ->toBe(['referral.received:intake', 'referral.routing_decided:funding'])
        ->and(DB::table('care_referrals')->value('identity'))->not->toContain('"first_name"');
    Mail::assertNothingSent();
});

it('replays an identical retry without duplicating referrals or queued messages', function () {
    $payload = publicReferralFixture();
    $service = app(PublicReferralIntake::class);
    $first = $service->submit($payload);
    $second = $service->submit(array_reverse($payload, true));
    expect($second)->toBe($first)->and(CareReferral::query()->count())->toBe(1)
        ->and(ReferralSubmission::query()->count())->toBe(1)->and(ReferralOutbox::query()->count())->toBe(2);
    $payload['goals'] = 'Different enquiry';
    expect(fn () => $service->submit($payload))->toThrow(HttpException::class)
        ->and(CareReferral::query()->count())->toBe(1);
});

it('rejects staff-only properties from public submissions', function () {
    $payload = publicReferralFixture();
    $payload['stage'] = 'converted';
    $payload['owner_id'] = 1;
    expect(fn () => app(PublicReferralIntake::class)->submit($payload))->toThrow(ValidationException::class)
        ->and(CareReferral::query()->count())->toBe(0);
});

it('accepts a process enquiry without funding or another person identity when authority is uncertain', function () {
    $payload = publicReferralFixture();
    $payload['relationship'] = 'family';
    $payload['authority_status'] = 'unsure';
    $payload['person'] = [];
    $payload['funding_situation'] = 'unsure';
    $payload['requested_service'] = 'unsure';
    app(PublicReferralIntake::class)->submit($payload);
    $referral = CareReferral::query()->sole();
    expect($referral->general_enquiry)->toBeTrue()->and($referral->identity)->toBe([])
        ->and($referral->authority['third_party_notice'])->toBe('pending_review');
});

it('requires safe channel details and prevents acknowledgement to a non-preferred email', function () {
    $payload = publicReferralFixture();
    $payload['contact']['channel'] = 'phone';
    expect(fn () => app(PublicReferralIntake::class)->submit($payload))->toThrow(ValidationException::class);
    $payload['contact']['phone'] = '0210000000';
    app(PublicReferralIntake::class)->submit($payload);
    expect(ReferralOutbox::query()->where('kind', 'public_acknowledgement')->count())->toBe(0)
        ->and(ReferralOutbox::query()->where('kind', 'intake_received')->count())->toBe(1);
});

it('rejects stale privacy notices and unavailable services without a saved receipt', function () {
    $payload = publicReferralFixture();
    $payload['notice_version'] = 'old';
    expect(fn () => app(PublicReferralIntake::class)->submit($payload))->toThrow(ValidationException::class);
    $payload['notice_version'] = 'test-1';
    $payload['requested_service'] = 'unpublished_service';
    expect(fn () => app(PublicReferralIntake::class)->submit($payload))->toThrow(ValidationException::class)
        ->and(ReferralSubmission::query()->count())->toBe(0);
});

it('rolls the referral back if its durable notification work cannot be saved', function () {
    $payload = publicReferralFixture();
    $dispatcher = ReferralOutbox::getEventDispatcher();
    ReferralOutbox::setEventDispatcher(clone $dispatcher);
    ReferralOutbox::creating(fn () => throw new RuntimeException('Simulated outbox failure'));
    try {
        expect(fn () => app(PublicReferralIntake::class)->submit($payload))->toThrow(RuntimeException::class);
        expect(CareReferral::query()->count())->toBe(0)->and(ReferralSubmission::query()->count())->toBe(0);
    } finally {
        ReferralOutbox::setEventDispatcher($dispatcher);
    }
});

it('exposes only published public settings and returns no private data through the public endpoint', function () {
    $payload = publicReferralFixture();
    $this->get(route('referrals.public.create'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public-referrals/create')
            ->where('intake.enabled', true)
            ->where('intake.notice_version', 'test-1')
            // The public pathway choice carries only what the form needs: no guidance, contacts or payer kind.
            ->has('intake.pathways.0', fn (AssertableInertia $pathway) => $pathway->hasAll(['id', 'label', 'service_ids', 'personal_income']))
            ->missing('intake.proposals'));
    $response = $this->postJson(route('referrals.public.store'), $payload)->assertCreated();
    $response->assertExactJson(['reference' => CareReferral::query()->sole()->reference, 'status' => 'received']);
    $this->get('/referrals/'.$response->json('reference'))->assertNotFound();
});

it('refuses oversized public submissions before creating any referral', function () {
    $payload = publicReferralFixture();
    $payload['goals'] = str_repeat('x', 40000);
    $this->postJson(route('referrals.public.store'), $payload)->assertStatus(413);
    expect(CareReferral::query()->count())->toBe(0);
});
