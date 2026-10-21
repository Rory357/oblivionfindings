<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Models\Site;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function referralNextStep(array $overrides = []): array
{
    return ['reason' => 'Reviewed with the person and intake team', 'next_action' => 'Discuss the available support', 'next_due_at' => now()->addDays(3)->toISOString(), ...$overrides];
}

it('requires a current named owner for progress and does not allow direct conversion stages', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site);
    $service = app(ReferralLifecycle::class);
    expect(fn () => $service->move($actor, $record->id, 1, referralNextStep(['stage' => 'triage'])))->toThrow(HttpException::class);
    $record->update(['owner_id' => $actor->id]);
    $service->move($actor, $record->id, 1, referralNextStep(['stage' => 'triage']));
    expect($record->fresh()->stage)->toBe('triage')->and($record->fresh()->lock_version)->toBe(2);
    expect(fn () => $service->move($actor, $record->id, 2, referralNextStep(['stage' => 'converted'])))->toThrow(ValidationException::class);
    // Ready for handover is a readiness-gated stage, not a free move: it needs handover access and passing checks.
    expect(fn () => $service->move($actor, $record->id, 2, referralNextStep(['stage' => 'ready_for_handover'])))->toThrow(HttpException::class);
});

it('keeps waiting periods separate from stage and historic contact time', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site, ['owner_id' => $actor->id, 'stage' => 'assessment', 'stage_changed_at' => now()->subDay(), 'first_human_contact_at' => now()->subHours(2)]);
    $stageAt = $record->stage_changed_at->toISOString();
    $contactAt = $record->first_human_contact_at->toISOString();
    $service = app(ReferralLifecycle::class);
    $service->hold($actor, $record->id, 1, referralNextStep(['hold_reason' => 'awaiting_funding']));
    expect($record->fresh()->stage)->toBe('assessment')->and($record->fresh()->hold_reason)->toBe('awaiting_funding')
        ->and($record->fresh()->stage_changed_at->toISOString())->toBe($stageAt)
        ->and($record->fresh()->first_human_contact_at->toISOString())->toBe($contactAt);
    $service->hold($actor, $record->id, 2, referralNextStep(['hold_reason' => null]));
    expect($record->fresh()->hold_reason)->toBeNull()->and(ReferralEvent::where('referral_id', $record->id)->count())->toBe(2);
});

it('does not progress minimal enquiries into person assessment', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site, ['owner_id' => $actor->id, 'general_enquiry' => true]);
    expect(fn () => app(ReferralLifecycle::class)->move($actor, $record->id, 1, referralNextStep(['stage' => 'assessment'])))->toThrow(HttpException::class);
    expect($record->fresh()->lock_version)->toBe(1);
});

it('rolls back closure when restricted unfinished work cannot be resolved by the actor', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'close', 'tasks.manage']);
    $record = referralTestRecord($site);
    foreach (['intake', 'funding'] as $audience) {
        ReferralWorkItem::create(['referral_id' => $record->id, 'owner_id' => $actor->id, 'audience' => $audience, 'details' => ['title' => 'Follow up'], 'due_at' => now()->addDay()]);
    }
    expect(fn () => app(ReferralLifecycle::class)->close($actor, $record->id, 1, [
        'closure_reason' => 'person_withdrew', 'reason' => 'The person chose not to proceed', 'person_informed' => true,
        'communication_note' => 'Discussed by phone today', 'cancel_open_work' => true,
    ]))->toThrow(HttpException::class);
    expect($record->fresh()->stage)->toBe('new')->and($record->fresh()->lock_version)->toBe(1)
        ->and(ReferralWorkItem::where('referral_id', $record->id)->where('status', 'open')->count())->toBe(2)
        ->and(ReferralEvent::where('referral_id', $record->id)->count())->toBe(0);
});

it('closes with explicit task disposition and reopens without reviving cancelled work', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'close', 'reopen', 'tasks.manage']);
    $record = referralTestRecord($site, ['owner_id' => $actor->id]);
    $work = ReferralWorkItem::create(['referral_id' => $record->id, 'owner_id' => $actor->id, 'audience' => 'intake', 'details' => ['title' => 'Follow up'], 'due_at' => now()->addDay()]);
    $record->update(['primary_work_item_id' => $work->id]);
    $service = app(ReferralLifecycle::class);
    $service->close($actor, $record->id, 1, ['closure_reason' => 'person_withdrew', 'reason' => 'The person chose not to proceed', 'person_informed' => true, 'communication_note' => 'Discussed by phone today', 'cancel_open_work' => true]);
    expect($record->fresh()->stage)->toBe('closed')->and($work->fresh()->status)->toBe('cancelled')->and($record->fresh()->next_due_at)->toBeNull();
    $service->reopen($actor, $record->id, 2, referralNextStep());
    expect($record->fresh()->stage)->toBe('triage')->and($work->fresh()->status)->toBe('cancelled')
        ->and($record->fresh()->closure_reason)->toBeNull()->and($record->fresh()->primary_work_item_id)->toBeNull();
});

it('applies lifecycle permissions and version checks through HTTP endpoints', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site, ['owner_id' => $actor->id]);
    $url = route('referrals.lifecycle', [$record->reference, 'transition']);
    $this->actingAs($actor)->postJson($url, ['version' => 1, ...referralNextStep(['stage' => 'triage'])])->assertOk()->assertJsonPath('saved', true);
    $this->postJson($url, ['version' => 1, ...referralNextStep(['stage' => 'assessment'])])->assertConflict();
    $this->postJson(route('referrals.lifecycle', [$record->reference, 'close']), ['version' => 2, 'closure_reason' => 'other', 'reason' => 'Review finished', 'person_informed' => false, 'communication_note' => 'No safe contact channel', 'cancel_open_work' => false])->assertForbidden();
});
