<?php

use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\Site;
use App\Services\Tasks\Providers\ReferralTaskProvider;
use App\Services\Tasks\TaskAggregator;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function calendarReferralWork($referral, $owner, array $extra = []): ReferralWorkItem
{
    return ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $owner->id, 'kind' => 'call', 'audience' => 'intake', 'status' => 'open',
        'details' => ['title' => 'Safe-contact follow-up', 'description' => 'Private narrative excluded from calendar and task cards'],
        'due_at' => now()->addDay(), 'ends_at' => null, ...$extra]);
}

function referralCalendarRange(array $extra = []): string
{
    return '/operations/referrals/calendar/events?'.http_build_query(['start' => now()->startOfDay()->toISOString(), 'end' => now()->addDays(3)->startOfDay()->toISOString(), ...$extra]);
}

it('scopes calendar and shared tasks by site ownership privacy and audience before projection', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAssigned']);
    $referral = referralTestRecord($site, ['owner_id' => $actor->id]);
    $visible = calendarReferralWork($referral, $actor);
    $funding = calendarReferralWork($referral, $actor, ['audience' => 'funding', 'details' => ['title' => 'Private funding follow-up']]);
    calendarReferralWork($referral, $actor, ['audience' => 'assessment', 'details' => ['title' => 'Private assessment']]);
    foreach ([referralTestRecord($other, ['owner_id' => $actor->id]), referralTestRecord($site, ['restricted' => true, 'owner_id' => $actor->id]), referralTestRecord($site), referralTestRecord(null, ['owner_id' => $actor->id])] as $hidden) {
        calendarReferralWork($hidden, $actor);
    }
    $response = $this->actingAs($actor)->getJson(referralCalendarRange())->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(2, 'events');
    $response->assertJsonFragment(['id' => 'referral_work-'.$visible->id])->assertDontSee('Private funding follow-up')->assertDontSee('Private assessment')->assertDontSee('Private narrative');
    $tasks = app(ReferralTaskProvider::class)->authorizedTasks($actor);
    expect(array_column($tasks, 'id'))->toEqualCanonicalizing(['referral_work-'.$visible->id, 'referral_next-'.$referral->id]);
    expect(app(TaskAggregator::class)->findItemFor($actor, 'referral_work', $funding->id))->toBeNull();
    $this->get('/operations/referrals/'.$referral->reference.'?work='.$funding->id)->assertNotFound();
});

it('requires central intake explicitly and denies departed staff even with a broad grant', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'viewAllSites', 'centralIntake']);
    $central = referralTestRecord(null);
    calendarReferralWork($central, $actor);
    $this->actingAs($actor)->getJson(referralCalendarRange())->assertOk()->assertJsonCount(2, 'events');
    $actor->hrEmployeeProfile->update(['is_active' => false]);
    $this->actingAs($actor->fresh())->getJson(referralCalendarRange())->assertForbidden();
    $this->get('/operations/referrals/calendar')->assertForbidden();
    expect(app(ReferralTaskProvider::class)->authorizedTasks($actor->fresh()))->toBe([]);
});

it('uses exclusive range ends and includes overlapping appointments without duplicating primary work', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny']);
    $referral = referralTestRecord($site, ['stage' => 'converted']);
    $start = now()->startOfDay();
    $end = $start->copy()->addDay();
    $overlap = calendarReferralWork($referral, $actor, ['due_at' => $start->copy()->subHour(), 'ends_at' => $start->copy()->addHour()]);
    $referral->update(['primary_work_item_id' => $overlap->id, 'next_due_at' => $overlap->due_at]);
    calendarReferralWork($referral, $actor, ['due_at' => $start->copy()->subHour(), 'ends_at' => $start]);
    calendarReferralWork($referral, $actor, ['due_at' => $end]);
    calendarReferralWork($referral, $actor, ['due_at' => $start->copy()->addHour(), 'status' => 'completed']);
    $this->actingAs($actor)->getJson(referralCalendarRange(['start' => $start->toISOString(), 'end' => $end->toISOString()]))->assertOk()->assertJsonCount(1, 'events')->assertJsonPath('events.0.id', 'referral_work-'.$overlap->id)->assertJsonPath('events.0.editable', false);
});

it('paginates dense periods and opens a precise work item beyond the profile first page', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny']);
    $referral = referralTestRecord($site);
    for ($i = 0; $i < 251; $i++) {
        $last = calendarReferralWork($referral, $actor);
    }
    $first = $this->actingAs($actor)->getJson(referralCalendarRange())->assertOk()->assertJsonPath('next_page', 2)->assertJsonCount(251, 'events');
    $second = $this->getJson(referralCalendarRange(['page' => 2]))->assertOk()->assertJsonPath('next_page', null)->assertJsonCount(1, 'events');
    expect(array_intersect(array_column($first->json('events'), 'id'), array_column($second->json('events'), 'id')))->toBe([]);
    $this->get('/operations/referrals/'.$referral->reference.'?work='.$last->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('selectedWork.id', $last->id)->where('initialTab', 'work'));
    $another = referralTestRecord($site);
    $this->get('/operations/referrals/'.$another->reference.'?work='.$last->id)->assertNotFound();
});

it('keeps selection and planning permissions separate from viewing the calendar', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny']);
    $planner = referralTestUser($site, ['viewAny', 'tasks.manage']);
    $referral = referralTestRecord($site);
    $this->actingAs($reader)->get('/operations/referrals/calendar')->assertOk();
    $this->getJson('/operations/referrals/calendar/referral-options')->assertForbidden();
    $url = '/operations/referrals/'.$referral->reference.'?'.http_build_query(['plan_at' => now()->addDay()->toISOString()]);
    $this->get($url)->assertForbidden();
    $this->actingAs($planner)->getJson('/operations/referrals/calendar/referral-options?q='.$referral->reference)->assertOk()->assertJsonCount(1, 'referrals');
    $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('initialTab', 'work')->where('initialPlanAt', fn ($value) => is_string($value)));
    $this->getJson(referralCalendarRange(['end' => now()->addDays(95)->toISOString()]))->assertUnprocessable();
    $this->getJson(referralCalendarRange(['end' => now()->subDay()->toISOString()]))->assertUnprocessable();
});

it('keeps task identities distinct when model IDs collide and retains work history only when requested', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'funding.view']);
    $referral = referralTestRecord($site);
    $work = calendarReferralWork($referral, $actor, ['audience' => 'funding', 'status' => 'completed']);
    $provider = app(ReferralTaskProvider::class);
    expect(array_column($provider->authorizedTasks($actor), 'id'))->toBe(['referral_next-'.$referral->id]);
    $all = $provider->authorizedTasks($actor, ['include_done' => true]);
    expect(count($all))->toBe(2)->and(collect($all)->firstWhere('id', 'referral_work-'.$work->id)->restricted)->toBeTrue();
    expect(app(TaskAggregator::class)->findItemFor($actor, 'referral_work', $work->id)?->bucket)->toBe('done');
    expect(app(TaskAggregator::class)->findItemFor($actor, 'referrals', $work->id))->toBeNull();
});
