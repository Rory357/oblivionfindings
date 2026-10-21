<?php

use App\Domain\Referrals\Services\InternalSupportPreparation;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralWorkflow;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Site;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralHandoverFixtures.php';

function preparationLifecycleStep(array $overrides = []): array
{
    return ['reason' => 'Reviewed with the person and the support team', 'next_action' => 'Confirm the agreed next step', 'next_due_at' => now()->addDays(3)->toISOString(), ...$overrides];
}

function preparationLifecycleFixture(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAssigned', 'create', 'transition', 'close', 'reopen']);
    handoverCanonicalPermissions($actor, ['clients.viewAny', 'clients.update']);
    referralTestRecord($site); // Publish the demonstration configuration.
    $context = ServiceContext::create(['site_id' => $site->id, 'name' => 'Internal preparation setting', 'type' => 'home_support', 'is_active' => true]);
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'onboarding']);
    $options = app(InternalSupportPreparation::class)->options($actor, $person);
    $data = ['submission_key' => (string) Str::uuid(), 'person_fingerprint' => $options['person']['fingerprint'], 'settings_version_id' => $options['settings_version_id'],
        'site_id' => $site->id, 'service_context_id' => $context->id, 'requested_service' => 'supported_living', 'team' => 'Support preparation',
        'reason' => 'Review this existing person for new daily living support', 'next_action' => 'Arrange participation and authority review', 'next_due_at' => now()->addDay()->toISOString(), 'acknowledge_review' => true];

    return [$site, $actor, $person, $data];
}

it('lets an internal preparation be held, closed and reopened while keeping one open case per person, site and service', function () {
    [$site, $actor, $person, $data] = preparationLifecycleFixture();
    $preparations = app(InternalSupportPreparation::class);
    $service = app(ReferralLifecycle::class);
    $case = $preparations->create($actor, $person->id, $data);
    $service->hold($actor, $case->id, $case->fresh()->lock_version, preparationLifecycleStep(['hold_reason' => 'awaiting_person']));
    expect($case->fresh()->hold_reason)->toBe('awaiting_person')->and($case->fresh()->stage)->toBe('assessment');
    $service->close($actor, $case->id, $case->fresh()->lock_version, ['closure_reason' => 'person_withdrew', 'reason' => 'The person decided not to proceed for now',
        'person_informed' => true, 'communication_note' => 'Discussed with the person in person', 'cancel_open_work' => false]);
    expect($case->fresh()->stage)->toBe('closed')->and($case->fresh()->client_id)->toBe($person->id);
    // Closing no longer strands the person: a new preparation can be opened.
    $second = $preparations->create($actor, $person->id, [...$data, 'submission_key' => (string) Str::uuid()]);
    expect($second->id)->not->toBe($case->id);
    expect(fn () => $service->reopen($actor, $case->id, $case->fresh()->lock_version, preparationLifecycleStep()))->toThrow(HttpException::class);
    expect($case->fresh()->stage)->toBe('closed');
    $service->close($actor, $second->id, $second->fresh()->lock_version, ['closure_reason' => 'other', 'reason' => 'Opened again in error',
        'person_informed' => false, 'communication_note' => 'Internal correction only', 'cancel_open_work' => false]);
    $service->reopen($actor, $case->id, $case->fresh()->lock_version, preparationLifecycleStep());
    expect($case->fresh()->stage)->toBe('triage')->and($case->fresh()->client_id)->toBe($person->id)->and($case->fresh()->closed_at)->toBeNull();
    expect(collect(app(ReferralWorkflow::class)->targets($actor, $case->fresh()))->pluck('id'))->toContain('assessment');
});

it('keeps genuine referrals linked to a person protected from lifecycle changes', function () {
    [$site, $actor, $person] = preparationLifecycleFixture();
    $genuine = referralTestRecord($site, ['owner_id' => $actor->id, 'client_id' => $person->id, 'stage' => 'assessment']);
    $service = app(ReferralLifecycle::class);
    expect(fn () => $service->hold($actor, $genuine->id, $genuine->lock_version, preparationLifecycleStep(['hold_reason' => 'awaiting_person'])))->toThrow(HttpException::class);
    expect($genuine->fresh()->hold_reason)->toBeNull();
});

it('reaches ready for handover only when readiness passes, and returns by transition', function () {
    [, $actor, $referral] = handoverPreviewFixture();
    handoverCanonicalPermissions($actor, ['referrals.transition']);
    $referral->update(['stage' => 'assessment']);
    $actor = $actor->fresh();
    expect(collect(app(ReferralWorkflow::class)->targets($actor, $referral->fresh()))->pluck('id'))->toContain(ReferralLifecycle::READY);
    $service = app(ReferralLifecycle::class);
    $service->move($actor, $referral->id, $referral->fresh()->lock_version, preparationLifecycleStep(['stage' => ReferralLifecycle::READY]));
    expect($referral->fresh()->stage)->toBe(ReferralLifecycle::READY);
    $service->move($actor, $referral->id, $referral->fresh()->lock_version, preparationLifecycleStep(['stage' => 'assessment']));
    expect($referral->fresh()->stage)->toBe('assessment');
    // An expired authority review fails Gate A, so the stage is refused and nothing changes.
    $referral->update(['authority' => ['review' => [...$referral->fresh()->authority['review'], 'review_due_at' => now()->subMinute()->toISOString()]]]);
    $version = $referral->fresh()->lock_version;
    expect(fn () => $service->move($actor, $referral->id, $version, preparationLifecycleStep(['stage' => ReferralLifecycle::READY])))->toThrow(HttpException::class);
    expect($referral->fresh()->stage)->toBe('assessment')->and($referral->fresh()->lock_version)->toBe($version);
});
