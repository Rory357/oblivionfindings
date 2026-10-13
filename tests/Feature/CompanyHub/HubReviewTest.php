<?php

use App\Domain\CompanyHub\Services\HubEditorial;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->writer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->writer->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->writer, $this->site);
    $this->writer->permissionOverrides()->attach(Permission::where('key', 'company_hub.write')->value('id'), ['allowed' => true]);
    $this->writer = $this->writer->fresh();
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->writer, 'news', 'A draft for review');
    $this->base = '/company-hub/manage/'.$this->page->id;
});

test('saved version history returns exact immutable content without exposing it in the initial list', function () {
    $old = $this->publisher->submit($this->writer, $this->page, 1);
    $this->page = $this->publisher->save($this->writer, $this->page, [...$this->page->draft, 'title' => 'A newer draft'], 1);
    $this->actingAs($this->writer)->getJson($this->base.'/revisions')->assertOk()->assertJsonCount(1, 'items')->assertJsonMissingPath('items.0.content')->assertJsonPath('items.0.title', 'A draft for review')->assertJsonPath('items.0.state', 'superseded');
    $this->getJson($this->base.'/revisions/'.$old->id)->assertOk()->assertJsonPath('content.title', 'A draft for review')->assertJsonPath('page_version', 2)->assertJsonPath('can_approve', false);
    $this->get($this->base.'/edit')->assertInertia(fn (Assert $p) => $p->component('company-hub/editor')->has('revisions', 1)->missing('revisions.0.content'));
    expect($old->fresh()->content['title'])->toBe('A draft for review');
});

test('history filters the original snapshot audience and conceals direct foreign and forbidden objects', function () {
    $outside = Site::factory()->create(['is_active' => true]);
    $secret = $this->page->revisions()->create(['created_by' => $this->writer->id, 'draft_version' => 1, 'state' => 'submitted', 'content' => [...$this->page->draft, 'title' => 'Private site snapshot', 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]]);
    $this->actingAs($this->editor)->getJson($this->base.'/revisions')->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('next_cursor', null);
    $this->get($this->base.'/edit')->assertInertia(fn (Assert $p) => $p->has('revisions', 0));
    $this->getJson($this->base.'/revisions/'.$secret->id)->assertNotFound();
    $this->postJson($this->base.'/revisions/'.$secret->id.'/restore', ['version' => 1])->assertNotFound();
    $this->postJson($this->base.'/revisions/'.$secret->id.'/review', ['action' => 'request_changes', 'version' => 1, 'note' => 'Feedback'])->assertNotFound();
    $other = $this->publisher->create($this->editor, 'news', 'Another page');
    $visible = $this->publisher->submit($this->editor, $other, 1);
    $this->getJson($this->base.'/revisions/'.$visible->id)->assertNotFound();
    $this->writer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'company_hub.write')->value('id'), ['allowed' => false]);
    $this->actingAs($this->writer->fresh())->getJson($this->base.'/revisions')->assertNotFound();
    $this->getJson($this->base.'/revisions/'.$secret->id)->assertNotFound();
});

test('history pagination filters inaccessible versions before applying its cursor', function () {
    $outside = Site::factory()->create(['is_active' => true]);
    for ($i = 1; $i <= 30; $i++) {
        $this->page->revisions()->create(['created_by' => $this->writer->id, 'draft_version' => $i, 'state' => 'superseded', 'content' => $this->page->draft]);
        $this->page->revisions()->create(['created_by' => $this->writer->id, 'draft_version' => $i, 'state' => 'superseded', 'content' => [...$this->page->draft, 'title' => 'Hidden snapshot', 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]]);
    }
    $first = $this->actingAs($this->editor)->getJson($this->base.'/revisions')->assertOk()->assertJsonCount(25, 'items')->assertDontSee('Hidden snapshot')->json();
    $second = $this->getJson($this->base.'/revisions?before='.$first['next_cursor'])->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('next_cursor', null)->assertDontSee('Hidden snapshot')->json();
    expect(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')))->toBe([]);
});

test('publishers return feedback without changing the live release and authors can revise and resubmit', function () {
    $live = $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $this->page = $this->publisher->save($this->writer, $this->page, [...$this->page->draft, 'title' => 'Proposed improvement'], 1);
    $submitted = $this->publisher->submit($this->writer, $this->page, 2);
    $url = $this->base.'/revisions/'.$submitted->id.'/review';
    $this->actingAs($this->writer)->postJson($url, ['action' => 'request_changes', 'version' => 2, 'note' => 'Change the next step.'])->assertForbidden();
    $this->actingAs($this->editor)->postJson($url, ['action' => 'request_changes', 'version' => 2, 'note' => '  '])->assertUnprocessable();
    $this->postJson($url, ['action' => 'request_changes', 'version' => 1, 'note' => 'Change the next step.'])->assertConflict();
    $this->postJson($url, ['action' => 'request_changes', 'version' => 2, 'note' => 'Explain the next step for staff.'])->assertOk();
    expect($submitted->fresh()->state)->toBe('changes_requested')->and($submitted->fresh()->reviewed_by)->toBe($this->editor->id)->and($this->page->fresh()->liveRevision()->id)->toBe($live->id);
    expect(collect(app(HubEditorial::class)->workspace($this->writer))->firstWhere('id', $this->page->id)['status'])->toBe('changes_requested');
    $this->actingAs($this->writer)->getJson($this->base.'/revisions/'.$submitted->id)->assertOk()->assertJsonPath('review_note', 'Explain the next step for staff.')->assertJsonPath('can_approve', false);
    $this->actingAs($this->editor)->postJson($this->base.'/revisions/'.$submitted->id.'/approve')->assertConflict();
    $this->page = $this->publisher->save($this->writer, $this->page, [...$this->page->draft, 'summary' => 'A clear next step.'], 2);
    $new = $this->publisher->submit($this->writer, $this->page, 3);
    $this->postJson($this->base.'/revisions/'.$new->id.'/approve')->assertOk();
    expect($this->page->fresh()->liveRevision()->id)->toBe($new->id)->and($submitted->fresh()->review_note)->toBe('Explain the next step for staff.');
});

test('author withdrawal and draft edits prevent later approval of obsolete submissions', function () {
    $submitted = $this->publisher->submit($this->writer, $this->page, 1);
    $this->actingAs($this->writer)->postJson($this->base.'/revisions/'.$submitted->id.'/review', ['action' => 'withdraw', 'version' => 1])->assertOk();
    $this->actingAs($this->editor)->postJson($this->base.'/revisions/'.$submitted->id.'/approve')->assertConflict();
    $new = $this->publisher->submit($this->writer, $this->page, 1);
    $this->page = $this->publisher->save($this->writer, $this->page, [...$this->page->draft, 'summary' => 'Changed after submission.'], 1);
    $this->postJson($this->base.'/revisions/'.$new->id.'/approve')->assertConflict();
    expect($new->fresh()->state)->toBe('superseded')->and($this->page->fresh()->liveRevision())->toBeNull();
});

test('cancelling a future release preserves the current live page and rejects late or unauthorised cancellation', function () {
    $live = $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $this->page = $this->publisher->save($this->writer, $this->page, [...$this->page->draft, 'title' => 'Tomorrow update'], 1);
    $schedule = $this->publisher->publish($this->editor, $this->page, 2, now()->addDay()->toIso8601String(), null);
    $url = $this->base.'/revisions/'.$schedule->id.'/review';
    $this->actingAs($this->writer)->postJson($url, ['action' => 'cancel_schedule', 'version' => 2])->assertForbidden();
    $this->actingAs($this->editor)->postJson($url, ['action' => 'cancel_schedule', 'version' => 2])->assertOk();
    $this->postJson($url, ['action' => 'cancel_schedule', 'version' => 2])->assertConflict();
    $this->travel(2)->days();
    expect($this->page->fresh()->liveRevision()->id)->toBe($live->id)->and($schedule->fresh()->state)->toBe('cancelled');
    $this->postJson($this->base.'/revisions/'.$live->id.'/review', ['action' => 'cancel_schedule', 'version' => 2])->assertConflict();
});

test('archived pages can still be restored into a draft and deliberately republished', function () {
    $revision = $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $this->publisher->archive($this->editor, $this->page, 1);
    $page = $this->publisher->restore($this->editor, $this->page, $revision, 2);
    $this->publisher->publish($this->editor, $page, 3, null, null);
    expect($page->fresh()->archived_at)->toBeNull()->and($page->fresh()->liveRevision())->not->toBeNull();
});
