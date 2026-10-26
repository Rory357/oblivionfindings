<?php

use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralSearchIndex;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function workspaceSite(): Site
{
    return Site::factory()->create(['is_active' => true, 'archived' => false]);
}

function workspaceWork($referral, $owner, array $extra = []): ReferralWorkItem
{
    return ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $owner?->id, 'kind' => 'call', 'audience' => 'intake', 'status' => 'open',
        'details' => ['title' => 'Call back about safe contact'], 'due_at' => now()->addDays(2), ...$extra]);
}

function workspaceFunding($referral, array $extra = []): ReferralFundingCase
{
    return ReferralFundingCase::query()->create(['referral_id' => $referral->id, 'settings_version_id' => $referral->settings_version_id,
        'pathway' => 'dss_nasc', 'status' => 'preparing', 'details' => ['pathway_label' => 'DSS / NASC'], ...$extra]);
}

it('finds referrals by hashed name prefixes only within the viewer scope', function () {
    $site = workspaceSite();
    $other = workspaceSite();
    $reader = referralTestUser($site, ['viewAny']);
    $mere = referralTestRecord($site, ['identity' => ['first_name' => 'Mēre', 'last_name' => "O'Brien-Smith", 'preferred_name' => 'Mia']]);
    referralTestRecord($site, ['identity' => ['first_name' => 'Aroha', 'last_name' => 'Ngata']]);
    referralTestRecord($other, ['identity' => ['first_name' => 'Mere', 'last_name' => 'Hidden']]);
    referralTestRecord($site, ['restricted' => true, 'identity' => ['first_name' => 'Mere', 'last_name' => 'Restricted']]);

    foreach (['me', 'MERE', 'mere obri', 'smi', 'obriensmith', 'mia'] as $term) {
        $this->actingAs($reader)->getJson(route('referrals.data', ['q' => $term, 'queue' => 'all']))->assertOk()
            ->assertJsonPath('referrals.total', 1)->assertJsonPath('referrals.data.0.reference', $mere->reference);
    }
    $this->getJson(route('referrals.data', ['q' => 'ere', 'queue' => 'all']))->assertJsonPath('referrals.total', 0);
    $this->getJson(route('referrals.data', ['q' => 'm', 'queue' => 'all']))->assertJsonPath('referrals.total', 0);
    $this->getJson(route('referrals.data', ['q' => strtolower($mere->reference), 'queue' => 'all']))->assertJsonPath('referrals.total', 1);

    $stored = DB::table(ReferralSearchIndex::TABLE)->where('referral_id', $mere->id)->pluck('token_hash');
    expect($stored)->not->toBeEmpty()->and($stored->every(fn ($hash) => preg_match('/^[a-f0-9]{64}$/', $hash) === 1))->toBeTrue()
        ->and($stored->contains('me'))->toBeFalse();
});

it('re-indexes a corrected name so the previous name no longer matches', function () {
    $site = workspaceSite();
    $reader = referralTestUser($site, ['viewAny']);
    $record = referralTestRecord($site, ['identity' => ['first_name' => 'Tama', 'last_name' => 'Wilson']]);
    $record->update(['identity' => ['first_name' => 'Tane', 'last_name' => 'Wilson']]);
    $this->actingAs($reader)->getJson(route('referrals.data', ['q' => 'tama']))->assertJsonPath('referrals.total', 0);
    $this->getJson(route('referrals.data', ['q' => 'tane wil']))->assertJsonPath('referrals.total', 1);
    DB::table(ReferralSearchIndex::TABLE)->delete();
    expect(app(ReferralSearchIndex::class)->rebuild())->toBeGreaterThan(0);
    $this->getJson(route('referrals.data', ['q' => 'tane']))->assertJsonPath('referrals.total', 1);
});

it('counts overdue work, holds and saved views from the same scope as the rows', function () {
    $site = workspaceSite();
    $other = workspaceSite();
    $reader = referralTestUser($site, ['viewAny']);
    $lateWork = referralTestRecord($site, ['owner_id' => $reader->id]);
    workspaceWork($lateWork, $reader, ['due_at' => now()->subHour()]);
    referralTestRecord($site, ['next_due_at' => now()->subDay()]);
    referralTestRecord($site, ['hold_reason' => 'capacity_waitlist']);
    referralTestRecord($site, ['stage' => 'ready_for_handover']);
    referralTestRecord($other, ['next_due_at' => now()->subDay()]);
    referralTestRecord($site, ['restricted' => true, 'next_due_at' => now()->subDay()]);

    $response = $this->actingAs($reader)->getJson(route('referrals.data'))->assertOk()
        ->assertJsonPath('stats.open', 4)->assertJsonPath('stats.overdue', 2)->assertJsonPath('stats.unassigned', 3)
        ->assertJsonPath('stats.ready_for_handover', 1)->assertJsonPath('counts.referrals', 4)->assertJsonPath('counts.funding', null);
    $saved = collect($response->json('savedViews'))->pluck('count', 'key');
    expect($saved['capacity_waitlist'])->toBe(1)->and($saved['waiting'])->toBe(1)->and($saved['mine'])->toBe(1)->and($saved['overdue'])->toBe(2);
    $this->getJson(route('referrals.data', ['queue' => 'overdue']))->assertJsonPath('referrals.total', 2)
        ->assertJsonPath('referrals.data.0.is_overdue', true);
    $this->getJson(route('referrals.data', ['queue' => 'capacity_waitlist']))->assertJsonPath('referrals.total', 1);
    $this->getJson(route('referrals.data', ['owner' => (string) $reader->id]))->assertJsonPath('referrals.total', 1);
});

it('lists open funding cases only for permitted viewers and only on visible referrals', function () {
    $site = workspaceSite();
    $other = workspaceSite();
    $reader = referralTestUser($site, ['viewAny']);
    $funder = referralTestUser($site, ['viewAny', 'funding.view']);
    $visible = referralTestRecord($site, ['identity' => ['first_name' => 'Hemi', 'last_name' => 'Parata']]);
    $case = workspaceFunding($visible, ['navigator_id' => $funder->id]);
    workspaceWork($visible, $funder, ['audience' => 'funding', 'funding_case_id' => $case->id, 'details' => ['title' => 'Send the NASC referral'], 'due_at' => now()->subHour()]);
    workspaceFunding($visible, ['status' => 'withdrawn']);
    workspaceFunding(referralTestRecord($other));
    workspaceFunding(referralTestRecord($site, ['restricted' => true]));

    $this->actingAs($reader)->getJson(route('referrals.data', ['view' => 'funding']))->assertForbidden();
    $this->actingAs($funder)->getJson(route('referrals.data', ['view' => 'funding']))->assertOk()
        ->assertJsonPath('funding.total', 1)->assertJsonPath('funding.data.0.id', $case->id)
        ->assertJsonPath('funding.data.0.referral.reference', $visible->reference)
        ->assertJsonPath('funding.data.0.next_action', 'Send the NASC referral')->assertJsonPath('funding.data.0.is_overdue', true)
        ->assertJsonPath('counts.funding', 1);
    $this->getJson(route('referrals.data', ['view' => 'funding', 'queue' => 'mine']))->assertJsonPath('funding.total', 1);
    $this->getJson(route('referrals.data', ['view' => 'funding', 'queue' => 'unassigned']))->assertJsonPath('funding.total', 0);
});

it('lists permitted follow-ups and next actions with due groups and no hidden audiences', function () {
    $site = workspaceSite();
    $other = workspaceSite();
    $reader = referralTestUser($site, ['viewAny']);
    $referral = referralTestRecord($site, ['next_due_at' => now()->subHours(2)]);
    $tracked = referralTestRecord($site);
    $work = workspaceWork($tracked, $reader, ['due_at' => now()->addDays(3)]);
    $tracked->update(['primary_work_item_id' => $work->id]);
    workspaceWork($tracked, $reader, ['audience' => 'funding', 'details' => ['title' => 'Private funding follow-up']]);
    workspaceWork(referralTestRecord($other), $reader);
    workspaceWork(referralTestRecord($site, ['stage' => 'closed', 'next_due_at' => null]), $reader);

    $response = $this->actingAs($reader)->getJson(route('referrals.data', ['view' => 'follow_ups']))->assertOk()
        ->assertJsonPath('followUps.total', 2)->assertJsonPath('followUpGroups.overdue', 1)
        ->assertJsonPath('followUps.data.0.key', 'next-'.$referral->id)->assertJsonPath('followUps.data.0.is_overdue', true)
        ->assertJsonPath('followUps.data.1.key', 'work-'.$work->id)
        ->assertJsonPath('followUps.data.1.href', '/operations/referrals/'.$tracked->reference.'?work='.$work->id)
        ->assertDontSee('Private funding follow-up');
    $this->getJson(route('referrals.data', ['view' => 'follow_ups', 'queue' => 'mine']))->assertJsonPath('followUps.total', 1);
    $this->getJson(route('referrals.data', ['view' => 'follow_ups', 'queue' => 'overdue']))->assertJsonPath('followUps.total', 1);
});

it('serves the focused referral for in-place actions only within scope', function () {
    $site = workspaceSite();
    $other = workspaceSite();
    $reader = referralTestUser($site, ['viewAny']);
    $own = referralTestRecord($site);
    $hidden = referralTestRecord($other);
    $this->actingAs($reader)->get(route('referrals.index', ['focus' => $own->reference, 'act' => 'contact']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('referral.reference', $own->reference)
            ->where('filters.act', 'contact')->missing('referral.assessment'));
    $this->get(route('referrals.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('referral', null));
    $this->get(route('referrals.index', ['focus' => $hidden->reference]))->assertNotFound();
});

it('gates linkable profile tabs with the permission the tab is shown with', function () {
    $site = workspaceSite();
    $reader = referralTestUser($site, ['viewAny']);
    $funder = referralTestUser($site, ['viewAny', 'funding.view']);
    $record = referralTestRecord($site);
    $this->actingAs($reader)->get(route('referrals.show', [$record->reference, 'tab' => 'funding']))->assertForbidden();
    $this->get(route('referrals.show', [$record->reference, 'tab' => 'activity']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('initialTab', 'activity'));
    $this->actingAs($funder)->get(route('referrals.show', [$record->reference, 'tab' => 'funding']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('initialTab', 'funding'));
    $this->get(route('referrals.show', [$record->reference, 'tab' => 'handover']))->assertForbidden();
});

it('searches the calendar referral picker by name within the planner scope', function () {
    $site = workspaceSite();
    $other = workspaceSite();
    $planner = referralTestUser($site, ['viewAny', 'tasks.manage']);
    $match = referralTestRecord($site, ['identity' => ['first_name' => 'Wiremu', 'last_name' => 'Tane']]);
    referralTestRecord($other, ['identity' => ['first_name' => 'Wiremu', 'last_name' => 'Elsewhere']]);
    referralTestRecord($site, ['stage' => 'closed', 'identity' => ['first_name' => 'Wiremu', 'last_name' => 'Closed']]);
    $this->actingAs($planner)->getJson('/operations/referrals/calendar/referral-options?q=wir')->assertOk()
        ->assertJsonCount(1, 'referrals')->assertJsonPath('referrals.0.reference', $match->reference)->assertJsonPath('has_more', false);
});

it('keeps intake drafts private to their author, encrypted and short-lived', function () {
    $site = workspaceSite();
    $author = referralTestUser($site, ['viewAny', 'create']);
    $colleague = referralTestUser($site, ['viewAny', 'create']);
    $reader = referralTestUser($site, ['viewAny']);
    $payload = ['step' => 1, 'source' => 'phone', 'form' => ['person' => ['first_name' => 'Ana', 'last_name' => 'Kereama'], 'goals' => 'Private call notes']];

    $reference = $this->actingAs($author)->postJson(route('referrals.drafts.store'), ['payload' => $payload])->assertOk()
        ->assertJsonPath('draft.label', 'Ana Kereama')->json('draft.reference');
    expect(DB::table('care_referral_intake_drafts')->value('payload'))->not->toContain('Kereama');
    $this->getJson(route('referrals.drafts.show', $reference))->assertOk()->assertJsonPath('draft.payload.form.goals', 'Private call notes');
    $this->putJson(route('referrals.drafts.update', $reference), ['payload' => [...$payload, 'step' => 2]])->assertOk()->assertJsonPath('draft.step', 2);
    $this->get(route('referrals.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('drafts', 1)->where('drafts.0.reference', $reference));

    $this->actingAs($colleague)->getJson(route('referrals.drafts.show', $reference))->assertNotFound();
    $this->deleteJson(route('referrals.drafts.destroy', $reference))->assertNotFound();
    $this->getJson(route('referrals.drafts.index'))->assertOk()->assertJsonCount(0, 'drafts');
    $this->actingAs($reader)->postJson(route('referrals.drafts.store'), ['payload' => $payload])->assertForbidden();

    DB::table('care_referral_intake_drafts')->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($author)->getJson(route('referrals.drafts.show', $reference))->assertNotFound();
    $this->getJson(route('referrals.drafts.index'))->assertJsonCount(0, 'drafts');
    expect(DB::table('care_referral_intake_drafts')->count())->toBe(0);

    $second = $this->postJson(route('referrals.drafts.store'), ['payload' => $payload])->json('draft.reference');
    app(\App\Domain\Referrals\Services\ReferralIntakeDrafts::class)->consumed($colleague, $second);
    expect(DB::table('care_referral_intake_drafts')->count())->toBe(1);
    app(\App\Domain\Referrals\Services\ReferralIntakeDrafts::class)->consumed($author, $second);
    expect(DB::table('care_referral_intake_drafts')->count())->toBe(0);
});
