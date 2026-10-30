<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Services\ReferralAssessment;
use App\Domain\Referrals\Services\ReferralIntakeCorrection;
use App\Models\Site;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function referralCorrectionInput(array $changes = []): array
{
    return ['person' => ['first_name' => 'Example', 'last_name' => 'Person', 'preferred_name' => null, 'date_of_birth' => null],
        'contact' => ['name' => 'Example Person', 'phone' => '0215550123', 'email' => null, 'channel' => 'phone', 'safe_time' => 'Weekday afternoons', 'leave_message' => false, 'communication' => null],
        'requested_service' => 'supported_living', 'goals' => 'Build confidence at home', 'region' => 'Auckland', 'convert_to_referral' => false, 'reason' => 'Details confirmed with the person', ...$changes];
}

it('only completes minimal enquiries once current collection authority exists', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'edit']);
    $record = referralTestRecord($site, ['general_enquiry' => true, 'identity' => []]);
    $service = app(ReferralIntakeCorrection::class);
    expect(fn () => $service->save($actor, $record->id, 1, referralCorrectionInput(['convert_to_referral' => true])))->toThrow(HttpException::class);
    $record->update(['authority' => ['review' => ['state' => 'current', 'version' => 1, 'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true]]]]);
    $service->save($actor, $record->id, 1, referralCorrectionInput(['convert_to_referral' => true]));
    expect($record->fresh()->general_enquiry)->toBeFalse()->and($record->fresh()->identity['first_name'])->toBe('Example')
        ->and($record->fresh()->source)->toBe('website')->and($record->fresh()->client_id)->toBeNull()
        ->and(ReferralAssessment::scope($record->fresh(), 'collect'))->toBeTrue();
});

it('keeps minimal enquiries minimal unless explicitly completed', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'edit']);
    $record = referralTestRecord($site, ['general_enquiry' => true, 'identity' => []]);
    expect(fn () => app(ReferralIntakeCorrection::class)->save($actor, $record->id, 1, referralCorrectionInput()))->toThrow(HttpException::class);
    expect($record->fresh()->identity)->toBe([])->and($record->fresh()->lock_version)->toBe(1);
});

it('preserves correction history and requires authority and readiness review after identity changes', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'edit']);
    $record = referralTestRecord($site, ['stage' => 'ready_for_handover', 'authority' => ['review' => ['state' => 'current', 'version' => 1, 'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true, 'assist' => true]]]]);
    app(ReferralIntakeCorrection::class)->save($actor, $record->id, 1, referralCorrectionInput());
    expect($record->fresh()->stage)->toBe('assessment')->and($record->fresh()->assessment['invalidated_at'])->not->toBeNull()
        ->and(ReferralAssessment::scope($record->fresh(), 'assist'))->toBeFalse();
    $event = ReferralEvent::where('referral_id', $record->id)->where('kind', 'intake.corrected')->first();
    expect($event->payload['previous']['person']['first_name'])->toBe('Private identity')
        ->and($event->payload['current']['person']['first_name'])->toBe('Example');
});
