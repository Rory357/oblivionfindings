<?php

use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralCasework;
use App\Domain\Referrals\Services\ReferralFunding;
use App\Domain\Referrals\Services\ReferralFundingDecisionService;
use App\Models\Permission;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

beforeEach(function () {
    Storage::fake('private');
});

function referralFundingFixture(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'funding.view', 'funding.manage', 'tasks.manage']);
    $verifier = referralTestUser($site, ['viewAny', 'funding.view', 'funding.verify']);
    $record = referralTestRecord($site, ['owner_id' => $actor->id, 'authority' => ['review' => ['state' => 'current', 'version' => 1,
        'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['assist' => true, 'collect' => true], 'sharing' => []]]]);

    return [$site, $actor, $verifier, $record];
}

function referralFundingOpenInput(int $navigatorId, array $overrides = []): array
{
    return ['pathway' => 'dss_nasc', 'navigator_id' => $navigatorId, 'previous_case_id' => null, 'service_id' => 'supported_living',
        'starting_point' => 'The person would like help with funding', 'assistance_requested' => 'Prepare for an external assessment',
        'person_review_plan' => 'Review the information together before submission', 'provider_role_explained' => true,
        'next_action' => 'Discuss the next funding steps', 'next_due_at' => now()->addDay()->toISOString(), ...$overrides];
}

function referralFundingEvidence(int $referralId): ReferralEvidence
{
    $bytes = "%PDF-1.4\nExample funding evidence\n%%EOF";
    $path = 'referral-evidence/'.Str::uuid().'.bin';
    Storage::disk('private')->put($path, $bytes);

    return ReferralEvidence::create(['referral_id' => $referralId, 'audience' => 'funding', 'state' => 'available', 'disk' => 'private',
        'storage_path' => $path, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'metadata' => ['title' => 'Example external decision', 'mime' => 'application/pdf']]);
}

function referralFundingDecisionInput(int $evidenceId, array $overrides = []): array
{
    return ['case_version' => 1, 'outcome' => 'approved', 'decision_maker' => 'Example external agency', 'external_reference' => 'FICTIONAL-DECISION-1', 'decision_date' => today('Pacific/Auckland')->toDateString(),
        'evidence_ids' => [$evidenceId], 'existing_authorization' => true, 'conditions' => 'No additional conditions stated', 'valid_from' => today('Pacific/Auckland')->toDateString(),
        'valid_until' => today('Pacific/Auckland')->addMonths(3)->toDateString(), 'review_date' => null, 'deadline_source' => null,
        'lines' => [['service_id' => 'supported_living', 'quantity' => '12', 'provider_quantity' => '8', 'unit' => 'hours', 'unit_label' => null, 'period' => 'week', 'currency' => 'NZD', 'restrictions' => null]],
        'reason' => 'Existing external authorization provided by the person', 'next_action' => 'Arrange independent verification', 'next_due_at' => now()->addDay()->toISOString(), ...$overrides];
}

it('opens a scoped case and funding follow-up without leaking its title into the intake queue', function () {
    [$site, $actor, , $record] = referralFundingFixture();
    $navigator = referralTestUser($site, ['viewAssigned', 'funding.view', 'funding.manage']);
    expect(app(ReferralAccess::class)->query($navigator)->whereKey($record->id)->exists())->toBeFalse();
    $case = app(ReferralFunding::class)->open($actor, $record->id, 1, referralFundingOpenInput($navigator->id));
    $work = ReferralWorkItem::findOrFail($case->details['primary_work_item_id']);
    expect($case->status)->toBe('exploring')->and($work->audience)->toBe('funding')->and($work->owner_id)->toBe($navigator->id)
        ->and($record->fresh()->next_action)->toBe('Confirm safe contact')
        ->and(app(ReferralAccess::class)->query($navigator)->whereKey($record->id)->exists())->toBeTrue();
});

it('keeps personal income assistance separate and rejects incompatible service allocation', function () {
    [, $actor, , $record] = referralFundingFixture();
    $service = app(ReferralFunding::class);
    expect(fn () => $service->open($actor, $record->id, 1, referralFundingOpenInput($actor->id, ['pathway' => 'personal_income'])))->toThrow(HttpException::class);
    $case = $service->open($actor, $record->id, 1, referralFundingOpenInput($actor->id, ['pathway' => 'personal_income', 'service_id' => null]));
    expect($case->personal_income)->toBeTrue()->and(app(ReferralFundingDecisionService::class)->availability($case)['usable'])->toBeFalse();
});

it('requires actual submission before external waiting stages and retains completed task history', function () {
    [, $actor, , $record] = referralFundingFixture();
    $service = app(ReferralFunding::class);
    $case = $service->open($actor, $record->id, 1, referralFundingOpenInput($actor->id));
    expect(fn () => $service->progress($actor, $record->id, 2, $case->id, ['case_version' => 1, 'status' => 'awaiting_decision', 'reason' => 'Awaiting external response', 'person_outcome' => 'Person wishes to continue', 'cancel_open_work' => false, 'next_action' => 'Call the agency', 'next_due_at' => now()->addDay()->toISOString()]))->toThrow(HttpException::class);
    $work = ReferralWorkItem::findOrFail($case->details['primary_work_item_id']);
    $finish = ['work_version' => $work->lock_version, 'outcome' => 'completed', 'note' => 'Planning conversation completed'];
    expect(fn () => app(ReferralCasework::class)->finishWork($actor, $record->id, 2, $work->id, $finish))->toThrow(HttpException::class);
    app(ReferralCasework::class)->finishWork($actor, $record->id, 2, $work->id, [...$finish, 'next_action' => 'Prepare the evidence', 'next_due_at' => now()->addDay()->toISOString()]);
    expect($work->fresh()->status)->toBe('completed')->and($case->fresh()->details['primary_work_item_id'])->not->toBe($work->id)
        ->and(ReferralWorkItem::where('funding_case_id', $case->id)->where('status', 'open')->count())->toBe(1);
});

it('records original units and requires a separate reviewer before funding becomes usable', function () {
    [, $actor, $verifier, $record] = referralFundingFixture();
    $case = app(ReferralFunding::class)->open($actor, $record->id, 1, referralFundingOpenInput($actor->id));
    $evidence = referralFundingEvidence($record->id);
    $service = app(ReferralFundingDecisionService::class);
    $decision = $service->record($actor, $record->id, 2, $case->id, referralFundingDecisionInput($evidence->id));
    expect($service->availability($case->fresh())['usable'])->toBeFalse()->and($decision->decision['lines'][0]['unit'])->toBe('hours');
    $review = ['case_version' => 2, 'decision_id' => $decision->id, 'status' => 'verified', 'conditions_resolved' => true, 'provider_compatible' => true,
        'scope_note' => 'Checked source authorization and provider purchase scope', 'evidence_ids' => [$evidence->id], 'review_due_at' => now()->addMonth()->toISOString(),
        'next_action' => 'Prepare a support proposal', 'next_due_at' => now()->addDay()->toISOString()];
    $this->actingAs($actor)->postJson(route('referrals.funding.update', [$record->reference, $case->id, 'verification']), ['version' => 3, ...$review])->assertForbidden();
    $actor->permissionOverrides()->attach(Permission::where('key', 'referrals.funding.verify')->firstOrFail(), ['allowed' => true]);
    $this->postJson(route('referrals.funding.update', [$record->reference, $case->id, 'verification']), ['version' => 3, ...$review])->assertUnprocessable();
    $service->verify($verifier, $record->id, 3, $case->id, $review);
    expect($service->availability($case->fresh(), 'supported_living')['usable'])->toBeTrue()
        ->and($service->availability($case->fresh(), 'supported_living', today('Pacific/Auckland')->addMonths(2)->toDateString())['usable'])->toBeFalse()
        ->and($service->availability($case->fresh(), 'different_service')['usable'])->toBeFalse();
    $evidence->update(['state' => 'quarantined']);
    expect($service->availability($case->fresh())['usable'])->toBeFalse();
});

it('restores navigator accountability during suspended authority without reopening assistance permission', function () {
    [$site, $actor, , $record] = referralFundingFixture();
    $oldNavigator = referralTestUser($site, ['viewAssigned', 'funding.view', 'funding.manage']);
    $newNavigator = referralTestUser($site, ['viewAssigned', 'funding.view', 'funding.manage']);
    $service = app(ReferralFunding::class);
    $case = $service->open($actor, $record->id, 1, referralFundingOpenInput($oldNavigator->id));
    $primary = ReferralWorkItem::findOrFail($case->details['primary_work_item_id']);
    $extra = ReferralWorkItem::create(['referral_id' => $record->id, 'funding_case_id' => $case->id, 'owner_id' => $oldNavigator->id, 'kind' => 'call', 'audience' => 'funding', 'due_at' => now()->addDay(), 'details' => ['title' => 'Return agency call']]);
    $record->update(['authority' => ['review' => ['state' => 'withdrawn', 'version' => 2, 'scopes' => []]]]);
    $this->actingAs($actor)->postJson(route('referrals.funding.update', [$record->reference, $case->id, 'reassign']), ['version' => 2, 'case_version' => 1, 'navigator_id' => $newNavigator->id,
        'reason' => 'Previous navigator no longer handles this work', 'next_action' => 'Review the paused assistance with the person', 'next_due_at' => now()->addDay()->toISOString()])->assertOk();
    expect($case->fresh()->navigator_id)->toBe($newNavigator->id)->and($primary->fresh()->owner_id)->toBe($newNavigator->id)->and($extra->fresh()->owner_id)->toBe($newNavigator->id)
        ->and($record->fresh()->authority['review']['state'])->toBe('withdrawn')
        ->and(app(ReferralAccess::class)->query($oldNavigator)->whereKey($record->id)->exists())->toBeFalse()
        ->and(app(ReferralAccess::class)->query($newNavigator)->whereKey($record->id)->exists())->toBeTrue();
    expect(fn () => $service->open($actor, $record->id, 3, referralFundingOpenInput($newNavigator->id)))->toThrow(HttpException::class);
});

it('resets verification on a new external decision without changing the old immutable decision', function () {
    [, $actor, , $record] = referralFundingFixture();
    $case = app(ReferralFunding::class)->open($actor, $record->id, 1, referralFundingOpenInput($actor->id));
    $evidence = referralFundingEvidence($record->id);
    $service = app(ReferralFundingDecisionService::class);
    $first = $service->record($actor, $record->id, 2, $case->id, referralFundingDecisionInput($evidence->id));
    $service->record($actor, $record->id, 3, $case->id, referralFundingDecisionInput($evidence->id, ['case_version' => 2, 'outcome' => 'declined', 'lines' => [], 'reason' => 'A revised external decision was received']));
    expect($first->fresh()->outcome)->toBe('approved')->and(ReferralFundingDecision::where('funding_case_id', $case->id)->count())->toBe(2)
        ->and($case->fresh()->details['verification']['status'])->toBe('unverified')
        ->and($service->availability($case->fresh())['usable'])->toBeFalse();
});

it('denies nested funding mutations and history outside the visible parent', function () {
    [$site, $actor, , $record] = referralFundingFixture();
    $case = app(ReferralFunding::class)->open($actor, $record->id, 1, referralFundingOpenInput($actor->id));
    $other = referralTestRecord($site);
    $this->actingAs($actor)->getJson(route('referrals.funding.decisions', [$other->reference, $case->id]))->assertNotFound();
    $reader = referralTestUser($site, ['viewAny']);
    $this->actingAs($reader)->getJson(route('referrals.show.data', $record->reference))->assertOk()->assertJsonMissingPath('referral.funding');
    $this->getJson(route('referrals.funding.decisions', [$record->reference, $case->id]))->assertForbidden();
});

it('keeps the primary funding task with its navigator and prevents new work on ended assistance', function () {
    [$site, $actor, , $record] = referralFundingFixture();
    $other = referralTestUser($site, ['viewAny', 'funding.view', 'funding.manage']);
    $funding = app(ReferralFunding::class);
    $case = $funding->open($actor, $record->id, 1, referralFundingOpenInput($actor->id));
    $work = ReferralWorkItem::findOrFail($case->details['primary_work_item_id']);
    $input = ['owner_id' => $actor->id, 'funding_case_id' => $case->id, 'kind' => 'funding_follow_up', 'audience' => 'funding',
        'title' => str_repeat('Funding next step ', 12), 'description' => 'Follow up the agency response', 'due_at' => now()->addDays(2)->toISOString(), 'ends_at' => null, 'make_next_action' => false, 'work_version' => $work->lock_version];
    $casework = app(ReferralCasework::class);
    expect(fn () => $casework->saveWork($actor, $record->id, 2, [...$input, 'owner_id' => $other->id], $work->id))->toThrow(HttpException::class);
    $casework->saveWork($actor, $record->id, 2, $input, $work->id);
    expect($case->fresh()->details['next_action'])->toBe($input['title'])->and($case->fresh()->lock_version)->toBe(2);
    $funding->progress($actor, $record->id, 3, $case->id, ['case_version' => 2, 'status' => 'withdrawn', 'reason' => 'Person no longer wants assistance', 'person_outcome' => 'Person has chosen another provider', 'cancel_open_work' => true]);
    expect(fn () => $casework->saveWork($actor, $record->id, 4, $input))->toThrow(HttpException::class);
    expect($work->fresh()->status)->toBe('cancelled')->and(ReferralWorkItem::where('funding_case_id', $case->id)->where('status', 'open')->count())->toBe(0);
});

it('accepts an unknown external reference and identifies private payers from the case pathway configuration', function () {
    [, $actor, , $record] = referralFundingFixture();
    $funding = app(ReferralFunding::class);
    $decisions = app(ReferralFundingDecisionService::class);
    $evidence = referralFundingEvidence($record->id);
    // Default template: the private pathway confirms a private arrangement; an unknown reference stays unknown.
    $private = $funding->open($actor, $record->id, 1, referralFundingOpenInput($actor->id, ['pathway' => 'private']));
    $decision = $decisions->record($actor, $record->id, 2, $private->id, referralFundingDecisionInput($evidence->id, ['outcome' => 'private_confirmed', 'external_reference' => null]));
    expect($decision->decision['external_reference'])->toBeNull();
    // A configured payer kind decides, not the pathway's literal id.
    $other = $funding->open($actor, $record->id, 3, referralFundingOpenInput($actor->id, ['pathway' => 'other']));
    $other->update(['details' => [...$other->details, 'guide' => [...$other->details['guide'], 'payer_kind' => 'private']]]);
    expect(ReferralFunding::privatePayer($other->fresh()))->toBeTrue();
    $decisions->record($actor, $record->id, 4, $other->id, referralFundingDecisionInput($evidence->id, ['outcome' => 'private_confirmed']));
    $external = $funding->open($actor, $record->id, 5, referralFundingOpenInput($actor->id, ['pathway' => 'private']));
    $external->update(['details' => [...$external->details, 'guide' => [...$external->details['guide'], 'payer_kind' => 'external']]]);
    expect(fn () => $decisions->record($actor, $record->id, 6, $external->id, referralFundingDecisionInput($evidence->id, ['outcome' => 'private_confirmed'])))->toThrow(HttpException::class);
});

it('links a review attempt only to a finished attempt, once', function () {
    [, $actor, , $record] = referralFundingFixture();
    $funding = app(ReferralFunding::class);
    $first = $funding->open($actor, $record->id, 1, referralFundingOpenInput($actor->id));
    expect(fn () => $funding->open($actor, $record->id, 2, referralFundingOpenInput($actor->id, ['previous_case_id' => $first->id])))->toThrow(HttpException::class);
    app(ReferralFundingDecisionService::class)->record($actor, $record->id, 2, $first->id, referralFundingDecisionInput(referralFundingEvidence($record->id)->id, ['outcome' => 'declined', 'lines' => []]));
    $review = $funding->open($actor, $record->id, 3, referralFundingOpenInput($actor->id, ['previous_case_id' => $first->id]));
    expect($review->previous_case_id)->toBe($first->id);
    expect(fn () => $funding->open($actor, $record->id, 4, referralFundingOpenInput($actor->id, ['previous_case_id' => $first->id])))->toThrow(HttpException::class);
});

it('records a submission without an agency acknowledgement and records the acknowledgement later once', function () {
    [, $actor, , $record] = referralFundingFixture();
    $funding = app(ReferralFunding::class);
    $case = $funding->open($actor, $record->id, 1, referralFundingOpenInput($actor->id));
    $evidence = referralFundingEvidence($record->id);
    $funding->submission($actor, $record->id, 2, $case->id, ['case_version' => 1, 'destination' => 'Example assessment agency', 'channel' => 'email',
        'submitted_at' => now()->toISOString(), 'external_reference' => null, 'submitted_by' => 'person', 'person_reviewed' => true,
        'evidence_ids' => [$evidence->id], 'authority_version' => 1, 'sharing_index' => null, 'authority_deviation' => null,
        'next_action' => 'Check for an acknowledgement', 'next_due_at' => now()->addDays(2)->toISOString()]);
    $submission = $case->fresh()->details['last_submission'];
    expect($submission['acknowledgement'])->toBeNull()->and($submission['acknowledged_at'])->toBeNull();
    $progress = ['case_version' => 2, 'status' => 'awaiting_decision', 'reason' => 'Agency confirmed receipt by email', 'person_outcome' => 'Person wishes to continue',
        'cancel_open_work' => false, 'next_action' => 'Await the agency decision', 'next_due_at' => now()->addWeek()->toISOString(), 'acknowledgement' => 'Receipt confirmed, reference to follow'];
    $funding->progress($actor, $record->id, 3, $case->id, $progress);
    $submission = $case->fresh()->details['last_submission'];
    expect($submission['acknowledgement'])->toBe('Receipt confirmed, reference to follow')->and($submission['acknowledged_at'])->not->toBeNull()
        ->and($submission['acknowledgement_recorded_by'])->toBe($actor->id);
    expect(fn () => $funding->progress($actor, $record->id, 4, $case->id, [...$progress, 'case_version' => 3]))->toThrow(HttpException::class);
});
