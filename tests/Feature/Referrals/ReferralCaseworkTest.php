<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralCasework;
use App\Models\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function referralWorkInput(int $ownerId, array $overrides = []): array
{
    return [
        'owner_id' => $ownerId, 'funding_case_id' => null, 'kind' => 'call', 'audience' => 'intake',
        'title' => 'Call about next steps', 'description' => 'Use the recorded safe contact preferences',
        'due_at' => now()->addDay()->toISOString(), 'ends_at' => null, 'make_next_action' => true,
        ...$overrides,
    ];
}

it('assigns central intake to an eligible named owner with an audit record', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $manager = referralTestUser($site, ['viewAny', 'centralIntake', 'assign']);
    $owner = referralTestUser($site, ['viewAssigned']);
    $referral = referralTestRecord(null);
    app(ReferralCasework::class)->assign($manager, $referral->id, 1, [
        'site_id' => $site->id, 'owner_id' => $owner->id, 'team' => 'Intake', 'reason' => 'Accepted by local intake coordinator',
    ]);
    expect($referral->fresh()->owner_id)->toBe($owner->id)
        ->and($referral->fresh()->site_id)->toBe($site->id)
        ->and(app(ReferralAccess::class)->query($owner)->whereKey($referral->id)->exists())->toBeTrue()
        ->and(ReferralEvent::query()->where('kind', 'assignment.changed')->count())->toBe(1);
});

it('rejects out-of-scope and departed assignees without changing ownership', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $manager = referralTestUser($site, ['viewAny', 'assign']);
    $wrongSite = referralTestUser($other, ['viewAssigned']);
    $departed = referralTestUser($site, ['viewAny', 'viewAllSites']);
    $departed->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $referral = referralTestRecord($site);
    foreach ([$wrongSite, $departed] as $candidate) {
        expect(fn () => app(ReferralCasework::class)->assign($manager, $referral->id, 1, [
            'site_id' => $site->id, 'owner_id' => $candidate->id, 'team' => 'Intake', 'reason' => 'Assignment attempt',
        ]))->toThrow(HttpException::class);
    }
    expect($referral->fresh()->owner_id)->toBeNull()->and($referral->fresh()->lock_version)->toBe(1);
});

it('distinguishes an attempted call from a successful human conversation', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'contact']);
    $referral = referralTestRecord($site);
    $data = ['channel' => 'phone', 'outcome' => 'no_answer', 'occurred_at' => now()->toISOString(), 'summary' => 'No answer',
        'audience' => 'intake', 'next_action' => 'Try the preferred afternoon time', 'next_due_at' => now()->addDay()->toISOString()];
    app(ReferralCasework::class)->contact($actor, $referral->id, 1, $data);
    expect($referral->fresh()->first_contact_attempt_at)->not->toBeNull()->and($referral->fresh()->first_human_contact_at)->toBeNull();
    app(ReferralCasework::class)->contact($actor, $referral->id, 2, [...$data, 'outcome' => 'connected', 'summary' => 'Discussed next steps']);
    expect($referral->fresh()->first_human_contact_at)->not->toBeNull()
        ->and(ReferralEvent::query()->where('kind', 'contact.recorded')->count())->toBe(2);
});

it('does not log a permitted message where the person has forbidden messages', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'contact']);
    $referral = referralTestRecord($site, ['intake' => ['submitted_contact' => ['leave_message' => false]]]);
    expect(fn () => app(ReferralCasework::class)->contact($actor, $referral->id, 1, [
        'channel' => 'phone', 'outcome' => 'message_left', 'occurred_at' => now()->toISOString(), 'summary' => 'Left a message',
        'audience' => 'intake', 'next_action' => 'Follow up', 'next_due_at' => now()->addDay()->toISOString(),
    ]))->toThrow(HttpException::class);
    expect(ReferralEvent::query()->count())->toBe(0);
});

it('requires an accountable next step before finishing the current follow-up', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'tasks.manage']);
    $referral = referralTestRecord($site);
    $work = app(ReferralCasework::class)->saveWork($actor, $referral->id, 1, referralWorkInput($actor->id));
    expect($referral->fresh()->primary_work_item_id)->toBe($work->id);
    expect(fn () => app(ReferralCasework::class)->finishWork($actor, $referral->id, 2, $work->id, [
        'work_version' => 1, 'outcome' => 'completed', 'note' => 'Spoke with the person',
    ]))->toThrow(HttpException::class);
    expect($work->fresh()->status)->toBe('open');
    app(ReferralCasework::class)->finishWork($actor, $referral->id, 2, $work->id, [
        'work_version' => 1, 'outcome' => 'completed', 'note' => 'Spoke with the person',
        'next_action' => 'Arrange assessment', 'next_due_at' => now()->addDays(2)->toISOString(),
    ]);
    expect($work->fresh()->status)->toBe('completed')->and($referral->fresh()->primary_work_item_id)->toBeNull()
        ->and($referral->fresh()->next_action)->toBe('Arrange assessment');
});

it('keeps restricted task details out of the general next-action queue', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'tasks.manage', 'assessment.view', 'assessment.manage']);
    $referral = referralTestRecord($site);
    expect(fn () => app(ReferralCasework::class)->saveWork($actor, $referral->id, 1, referralWorkInput($actor->id, [
        'audience' => 'assessment', 'title' => 'Restricted assessment details',
    ])))->toThrow(HttpException::class);
    expect(ReferralWorkItem::query()->count())->toBe(0)->and($referral->fresh()->next_action)->toBe('Confirm safe contact');
});

it('does not grant record access just by assigning a follow-up', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'tasks.manage']);
    $unassigned = referralTestUser($site, ['viewAssigned']);
    $referral = referralTestRecord($site);
    expect(fn () => app(ReferralCasework::class)->saveWork($actor, $referral->id, 1, referralWorkInput($unassigned->id)))->toThrow(HttpException::class);
    expect(ReferralWorkItem::query()->count())->toBe(0);
});

it('denies a nested task belonging to another referral and retains work after conversion', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'tasks.manage']);
    $first = referralTestRecord($site);
    $second = referralTestRecord($site, ['stage' => 'converted']);
    $work = app(ReferralCasework::class)->saveWork($actor, $first->id, 1, referralWorkInput($actor->id));
    expect(fn () => app(ReferralCasework::class)->finishWork($actor, $second->id, 1, $work->id, [
        'work_version' => 1, 'outcome' => 'completed', 'note' => 'Wrong referral attempt',
    ]))->toThrow(ModelNotFoundException::class);
    $ongoing = app(ReferralCasework::class)->saveWork($actor, $second->id, 1, referralWorkInput($actor->id, ['make_next_action' => false]));
    expect($ongoing->fresh()->status)->toBe('open')->and($second->fresh()->stage)->toBe('converted');
});
