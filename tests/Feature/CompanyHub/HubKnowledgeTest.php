<?php

use App\Domain\CompanyHub\Services\HubCommandSearch;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Orientation team', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id, 'work_email' => 'team@company.test', 'bio' => 'Private HR note']);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'Your first week', 'guide');
    $this->steps = [['id' => 'welcome', 'title' => 'Meet your orientation buddy'], ['id' => 'tools', 'title' => 'Check your essential tools']];
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'resource_type' => 'Checklist', 'checklist' => $this->steps, 'team_ids' => [$this->team->id], 'review_on' => '2027-01-12'], 1);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->url = '/company-hub/pages/'.$this->page->id.'/checklist';
    $this->readUrl = '/company-hub/read/'.$this->page->slug;
    $this->payload = ['actor_id' => $this->reader->id, 'revision_id' => $this->revision->id, 'step_id' => 'welcome', 'step_key' => HubParticipation::stepKey($this->steps[0]), 'done' => true];
});

test('knowledge progress persists privately is idempotent and never acts for another signed in account', function () {
    $this->actingAs($this->reader)->get($this->readUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->where('knowledge.steps.0.done', false)->where('knowledge.version', 2));
    $this->putJson($this->url, $this->payload)->assertOk()->assertJsonPath('steps.0.done', true)->assertJsonPath('actor_id', $this->reader->id)->assertHeader('Cache-Control', 'no-store, private');
    $this->putJson($this->url, $this->payload)->assertOk();
    $this->assertDatabaseCount('hub_interactions', 1);
    $this->assertDatabaseHas('hub_interactions', ['user_id' => $this->reader->id, 'kind' => 'checklist']);
    $this->get($this->readUrl)->assertInertia(fn (Assert $page) => $page->where('knowledge.steps.0.done', true)->missing('knowledge.steps.0.count')->missing('knowledge.steps.0.user_id'));
    $this->putJson($this->url, [...$this->payload, 'user_id' => $this->editor->id])->assertUnprocessable();
    $this->actingAs($this->editor)->get($this->readUrl)->assertInertia(fn (Assert $page) => $page->where('knowledge.steps.0.done', false));
    $this->putJson($this->url, $this->payload)->assertConflict();
    $this->actingAs($this->reader)->putJson($this->url, [...$this->payload, 'done' => false])->assertOk()->assertJsonPath('steps.0.done', false);
    $this->assertDatabaseCount('hub_interactions', 0);
    $this->assertDatabaseCount('hub_reads', 0);
    Notification::assertNothingSent();
});

test('knowledge checklist uses published instruction keys preserves reordered steps and rejects stale tabs', function () {
    $this->actingAs($this->reader)->putJson($this->url, $this->payload)->assertOk();
    $second = [...$this->payload, 'step_id' => 'tools', 'step_key' => HubParticipation::stepKey($this->steps[1])];
    $this->putJson($this->url, $second)->assertOk();
    $draft = $this->page->draft;
    $draft['checklist'] = [$this->steps[1], [...$this->steps[0], 'title' => 'Meet your revised orientation buddy']];
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 2);
    $this->get($this->readUrl)->assertInertia(fn (Assert $page) => $page->where('knowledge.steps.0.title', $this->steps[0]['title'])->where('knowledge.steps.0.done', true));
    expect(app(HubFeed::class)->forUser($this->reader, 'revised orientation')['pages'])->toBe([]);
    $release = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->get($this->readUrl)->assertInertia(fn (Assert $page) => $page->where('knowledge.steps.0.id', 'tools')->where('knowledge.steps.0.done', true)->where('knowledge.steps.1.done', false));
    $this->putJson($this->url, $this->payload)->assertConflict();
    $this->putJson($this->url, [...$this->payload, 'revision_id' => $release->id])->assertConflict();
    $this->putJson($this->url, [...$this->payload, 'revision_id' => $release->id, 'step_key' => HubParticipation::stepKey($draft['checklist'][1])])->assertOk()->assertJsonPath('steps.1.done', true);
    expect(app(HubFeed::class)->forUser($this->reader, 'revised orientation')['pages'])->toHaveCount(1)
        ->and(collect(app(HubCommandSearch::class)->search($this->reader, 'revised orientation'))->pluck('url')->all())->toContain($this->readUrl);
});

test('knowledge reader projects only visible current work contacts and published same team resources', function () {
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $outside = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($outside, $otherSite, ['department_id' => $this->team->id, 'work_email' => 'outside@company.test']);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['department_id' => $this->team->id, 'secondary_site_ids' => [$otherSite->id], 'work_email' => 'team@company.test', 'bio' => 'Private HR note']);
    $this->editor = $this->editor->fresh();
    foreach (['visible', 'private', 'draft', 'expired'] as $status) {
        $related = $this->publisher->create($this->editor, 'knowledge', $status.' guide');
        $related = $this->publisher->save($this->editor, $related, [...$related->draft, 'team_ids' => [$this->team->id], 'audience' => $status === 'private' ? ['type' => 'sites', 'ids' => [$otherSite->id]] : ['type' => 'all', 'ids' => []]], 1);
        if ($status !== 'draft') {
            $this->publisher->publish($this->editor, $related, 2, null, $status === 'expired' ? now()->addHour()->toIso8601String() : null);
        }
    }
    $this->travel(2)->hours();
    $this->actingAs($this->reader)->get($this->readUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('knowledge.owner.id', $this->editor->hrEmployeeProfile()->sole()->id)->where('knowledge.owner.work_email', 'team@company.test')
        ->missing('knowledge.owner.bio')->missing('knowledge.owner.date_of_birth')->missing('knowledge.owner.user_id')
        ->where('knowledge.contacts', fn ($contacts) => collect($contacts)->where('work_email', 'outside@company.test')->isEmpty())
        ->has('knowledge.related', 1)->where('knowledge.related.0.title', 'visible guide')->where('selectedPage.content.review_on', '2027-01-12'));
    $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->get($this->readUrl)->assertInertia(fn (Assert $page) => $page->where('knowledge.owner', null)->where('knowledge.contacts', fn ($contacts) => collect($contacts)->where('work_email', 'team@company.test')->isEmpty()));
});

test('knowledge checklist writes enforce current page audience lifecycle and staff access', function () {
    $draftOnly = $this->publisher->create($this->editor, 'knowledge', 'Private draft');
    $this->actingAs($this->reader)->putJson('/company-hub/pages/'.$draftOnly->id.'/checklist', $this->payload)->assertNotFound();
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $other->id]);
    $draft = [...$this->page->draft, 'audience' => ['type' => 'sites', 'ids' => [$this->site->id]]];
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, 2);
    $release = $this->publisher->publish($this->editor, $this->page, 3, null, now()->addHour()->toIso8601String());
    $payload = [...$this->payload, 'revision_id' => $release->id];
    $this->actingAs($this->reader->fresh())->putJson($this->url, $payload)->assertNotFound();
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $this->actingAs($this->reader->fresh())->putJson($this->url, $payload)->assertOk();
    $this->travel(2)->hours();
    $this->putJson($this->url, $payload)->assertNotFound();
    $this->get($this->readUrl)->assertNotFound();
    $this->travelBack();
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->reader->fresh())->putJson($this->url, $payload)->assertNotFound();
});

test('knowledge editors validate checklist shape and guide type changes stop checklist writes', function () {
    foreach ([array_fill(0, 13, $this->steps[0]), [$this->steps[0], $this->steps[0]], [['id' => '../bad', 'title' => 'Invalid']], [['id' => 'empty', 'title' => '']], [['id' => 'too-long', 'title' => str_repeat('x', 301)]]] as $steps) {
        $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$this->page->id, ['version' => 2, 'content' => [...$this->page->draft, 'checklist' => $steps]])->assertUnprocessable();
    }
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'resource_type' => 'Guide'], 2);
    $release = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->actingAs($this->reader)->get($this->readUrl)->assertInertia(fn (Assert $page) => $page->where('knowledge.steps', []));
    $this->putJson($this->url, [...$this->payload, 'revision_id' => $release->id])->assertNotFound();
});
