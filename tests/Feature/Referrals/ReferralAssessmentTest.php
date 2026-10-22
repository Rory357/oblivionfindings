<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralAssessment;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\Site;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function referralAuthorityReview(array $changes = []): array
{
    return ['state' => 'current', 'basis' => 'self', 'basis_note' => 'Discussed directly with the person', 'representative' => null,
        'participation' => 'Person participated with their chosen supporter', 'review_due_at' => now()->addMonth()->toISOString(),
        'scopes' => ['contact' => true, 'collect' => true, 'assist' => true, 'service' => false], 'notice' => 'notice_given',
        'notice_note' => 'The person reviewed the published notice', 'reason' => 'Initial authority review completed', 'sharing' => [], ...$changes];
}

function referralAssessmentReview(): array
{
    return ['identity_status' => 'verified', 'identity_note' => 'Checked identity with the person', 'intention' => 'proceed', 'intention_note' => 'Person wishes to proceed with this provider',
        'suitability' => 'suitable', 'suitability_note' => 'Requested support is within the service scope', 'capacity' => 'waitlist', 'capacity_note' => 'Confirm availability with the service manager',
        'goals' => 'Build confidence with everyday activities', 'participation' => 'Person participated in the planning conversation', 'safety_needs' => 'Further care information remains to be collected',
        'review_due_at' => now()->addMonth()->toISOString(), 'reason' => 'Provider assessment discussion completed'];
}

it('preserves the original declaration and restricted review versions when authority is withdrawn', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'assessment.view', 'assessment.manage']);
    $record = referralTestRecord($site, ['authority' => ['declaration' => 'confirmed', 'contact_permission' => true]]);
    $service = app(ReferralAssessment::class);
    $service->authority($actor, $record->id, 1, referralAuthorityReview(['sharing' => [['recipient' => 'Example assessment agency', 'purpose' => 'Prepare a support assessment', 'information' => 'Goals and requested support only', 'expires_at' => now()->addWeek()->toISOString()]]]));
    expect(ReferralAssessment::scope($record->fresh(), 'assist'))->toBeTrue()->and(ReferralAssessment::currentSharing($record->fresh()))->toHaveCount(1);
    $service->authority($actor, $record->id, 2, referralAuthorityReview(['state' => 'withdrawn', 'scopes' => ['contact' => false, 'collect' => false, 'assist' => false, 'service' => false], 'reason' => 'Person withdrew the authority today']));
    expect(ReferralAssessment::scope($record->fresh(), 'contact'))->toBeFalse()->and(ReferralAssessment::currentSharing($record->fresh()))->toBe([])
        ->and($record->fresh()->authority['declaration'])->toBe('confirmed')->and($record->fresh()->authority['review']['version'])->toBe(2);
    $history = ReferralEvent::where('referral_id', $record->id)->where('kind', 'authority.reviewed')->orderByDesc('id')->first();
    expect($history->audience)->toBe('assessment')->and($history->payload['previous']['review']['state'])->toBe('current');
});

it('does not grant permissions through pending authority or unreviewed privacy exceptions', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'assessment.view', 'assessment.manage']);
    $record = referralTestRecord($site);
    $service = app(ReferralAssessment::class);
    expect(fn () => $service->authority($actor, $record->id, 1, referralAuthorityReview(['state' => 'pending'])))->toThrow(HttpException::class);
    expect(fn () => $service->authority($actor, $record->id, 1, referralAuthorityReview(['notice' => 'exception_reviewed'])))->toThrow(HttpException::class);
    expect($record->fresh()->lock_version)->toBe(1)->and(ReferralEvent::where('referral_id', $record->id)->count())->toBe(0);
});

it('expires permissions without requiring a background job to rewrite stored consent', function () {
    $record = referralTestRecord(null, ['authority' => ['contact_permission' => true, 'review' => [
        'state' => 'current', 'review_due_at' => now()->subMinute()->toISOString(), 'scopes' => ['contact' => true, 'assist' => true],
        'sharing' => [['recipient' => 'Example agency', 'expires_at' => now()->addDay()->toISOString()]],
    ]]]);
    expect(ReferralAssessment::scope($record, 'contact'))->toBeFalse()->and(ReferralAssessment::scope($record, 'assist'))->toBeFalse()
        ->and(ReferralAssessment::currentSharing($record))->toBe([]);
});

it('requires collection authority before assessment and binds the review to the authority version', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'assessment.view', 'assessment.manage']);
    $record = referralTestRecord($site);
    $service = app(ReferralAssessment::class);
    expect(fn () => $service->assess($actor, $record->id, 1, referralAssessmentReview()))->toThrow(HttpException::class);
    $service->authority($actor, $record->id, 1, referralAuthorityReview());
    $service->assess($actor, $record->id, 2, referralAssessmentReview());
    expect($record->fresh()->assessment['authority_version'])->toBe(1)->and($record->fresh()->assessment['capacity'])->toBe('waitlist')
        ->and($record->fresh()->stage)->toBe('new')->and($record->fresh()->client_id)->toBeNull();
});

it('invalidates scheduled starts while preserving active people and retaining one outstanding review task', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'assessment.view', 'assessment.manage']);
    $client = Client::factory()->create(['status' => 'active']);
    $agreement = ServiceAgreement::factory()->create(['client_id' => $client->id]);
    $record = referralTestRecord($site, ['client_id' => $client->id, 'stage' => 'converted', 'converted_at' => now()]);
    $proposal = ReferralProposal::create(['referral_id' => $record->id, 'version' => 1, 'proposal' => [], 'authored_by' => $actor->id, 'authored_at' => now()]);
    $start = ReferralServiceStart::create(['referral_id' => $record->id, 'client_id' => $client->id, 'proposal_id' => $proposal->id, 'service_agreement_id' => $agreement->id, 'approved_by' => $actor->id, 'agreed_date' => today()->addDay(), 'handover' => [], 'status' => 'scheduled']);
    $service = app(ReferralAssessment::class);
    $service->authority($actor, $record->id, 1, referralAuthorityReview());
    expect($start->fresh()->status)->toBe('review_required')->and($start->fresh()->approved_by)->toBeNull()->and($client->fresh()->status)->toBe('active');
    $service->authority($actor, $record->id, 2, referralAuthorityReview(['reason' => 'Updated the participation information']));
    expect(ReferralWorkItem::where('referral_id', $record->id)->where('status', 'open')->count())->toBe(1);
    $start->update(['commenced_at' => now(), 'status' => 'started', 'approved_by' => $actor->id]);
    $service->authority($actor, $record->id, 3, referralAuthorityReview());
    expect($start->fresh()->status)->toBe('started')->and($start->fresh()->handover['review_required'])->toBeTrue()
        ->and($client->fresh()->status)->toBe('active');
});

it('denies review mutation without sensitive write access and keeps review payloads out of ordinary responses', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'assessment.view', 'assessment.manage']);
    $reader = referralTestUser($site, ['viewAny']);
    $record = referralTestRecord($site);
    app(ReferralAssessment::class)->authority($actor, $record->id, 1, referralAuthorityReview());
    $this->actingAs($reader)->postJson(route('referrals.reviews.store', [$record->reference, 'authority']), ['version' => 2, ...referralAuthorityReview()])->assertForbidden();
    $this->getJson(route('referrals.show.data', $record->reference))->assertOk()->assertJsonMissingPath('referral.authority')
        ->assertJsonMissingPath('referral.authority_scopes')->assertJsonMissingPath('referral.current_sharing')->assertJsonPath('referral.activity.total', 0);
});
