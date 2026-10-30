<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubCommandSearch;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->other->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->other, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->steps = [['id' => 'prepare', 'title' => 'Instructiononlyprocessneedle'], ['id' => 'finish', 'title' => 'Leave the shared space ready']];
    $this->publishProcess = function (string $kind = 'knowledge', array $extra = []) {
        $page = $this->publisher->create($this->editor, $kind, 'A shared process', 'blank');
        $page = $this->publisher->save($this->editor, $page, [...$page->draft, 'resource_type' => 'Process', 'checklist' => $this->steps,
            'sections' => [['id' => 'instructions', 'columns' => 1, 'blocks' => [['id' => 'body', 'type' => 'text', 'title' => 'Practical guidance', 'body' => 'Complete shared guidance.']]]], ...$extra], 1);
        $this->publisher->publish($this->editor, $page, 2, null, null);

        return $page->fresh();
    };
    $this->input = fn (HubPage $page, array $step, bool $done = true) => ['actor_id' => $this->reader->id, 'revision_id' => $page->currentRelease->id,
        'step_id' => $step['id'], 'step_key' => HubParticipation::stepKey($step), 'done' => $done];
});

test('published Process resources read search and persist personal progress only for the current account', function (string $kind) {
    $page = ($this->publishProcess)($kind);
    $snapshot = $page->currentRelease->content;
    $url = '/company-hub/pages/'.$page->id.'/checklist';
    $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('selectedPage.content.resource_type', 'Process')->has('knowledge.steps', 2)->where('knowledge.steps.0.done', false)
        ->where('knowledge.steps.0.key', HubParticipation::stepKey($this->steps[0])));
    expect(array_column(app(HubFeed::class)->forUser($this->reader, 'Instructiononlyprocessneedle')['pages'], 'id'))->toBe([$page->id])
        ->and(array_column(app(HubCommandSearch::class)->search($this->reader, 'Instructiononlyprocessneedle'), 'url'))->toContain('/company-hub/read/'.$page->slug);
    $input = ($this->input)($page, $this->steps[0]);
    $this->putJson($url, $input)->assertOk()->assertJsonPath('actor_id', $this->reader->id)->assertJsonPath('revision_id', $page->currentRelease->id)
        ->assertJsonPath('steps.0.done', true)->assertHeader('Cache-Control', 'no-store, private');
    $this->putJson($url, $input)->assertOk();
    $this->assertDatabaseCount('hub_interactions', 1);
    $this->putJson($url, [...$input, 'user_id' => $this->other->id])->assertUnprocessable();
    $this->actingAs($this->other)->get('/company-hub/read/'.$page->slug)->assertInertia(fn (Assert $p) => $p->where('knowledge.steps.0.done', false)->missing('knowledge.steps.0.user_id')->missing('knowledge.steps.0.count'));
    $this->putJson($url, $input)->assertConflict();
    $this->actingAs($this->reader)->putJson($url, ($this->input)($page, $this->steps[0], false))->assertOk()->assertJsonPath('steps.0.done', false);
    $this->assertDatabaseCount('hub_interactions', 0);
    expect($page->currentRelease->fresh()->content)->toBe($snapshot);
    Notification::assertNothingSent();
})->with(['knowledge', 'page']);

test('Process progress follows published instruction keys retains unchanged reorders and rejects stale revisions or instructions', function () {
    $page = ($this->publishProcess)();
    $url = '/company-hub/pages/'.$page->id.'/checklist';
    $original = $page->currentRelease;
    $snapshot = $original->content;
    $input = ($this->input)($page, $this->steps[0]);
    $this->actingAs($this->reader)->putJson($url, $input)->assertOk();
    $this->putJson($url, ($this->input)($page, $this->steps[1]))->assertOk();
    $changed = [$this->steps[1], [...$this->steps[0], 'title' => 'Revisedprocessinstructionneedle']];
    $this->publisher->save($this->editor, $page, [...$page->draft, 'checklist' => $changed], 2);
    $this->get('/company-hub/read/'.$page->slug)->assertInertia(fn (Assert $p) => $p->where('knowledge.steps.0.title', $this->steps[0]['title'])->where('knowledge.steps.0.done', true));
    expect(app(HubFeed::class)->forUser($this->reader, 'Revisedprocessinstructionneedle')['pages'])->toBe([])
        ->and(app(HubCommandSearch::class)->search($this->reader, 'Revisedprocessinstructionneedle'))->toBe([]);
    $release = $this->publisher->publish($this->editor, $page->fresh(), 3, null, null);
    $page = $page->fresh();
    $this->get('/company-hub/read/'.$page->slug)->assertInertia(fn (Assert $p) => $p->where('knowledge.steps.0.id', 'finish')->where('knowledge.steps.0.done', true)->where('knowledge.steps.1.done', false));
    $this->putJson($url, $input)->assertConflict();
    $this->putJson($url, [...$input, 'revision_id' => $release->id])->assertConflict();
    $this->putJson($url, [...($this->input)($page, $changed[1]), 'step_id' => 'forged'])->assertNotFound();
    $this->putJson($url, ($this->input)($page, $changed[1]))->assertOk()->assertJsonPath('steps.1.done', true);
    expect(array_column(app(HubFeed::class)->forUser($this->reader, 'Revisedprocessinstructionneedle')['pages'], 'id'))->toBe([$page->id])
        ->and($original->fresh()->content)->toBe($snapshot);
});

test('Process personal steps deny unpublished withdrawn expired private and former staff access', function () {
    $public = ($this->publishProcess)();
    $input = ($this->input)($public, $this->steps[0]);
    $draft = $this->publisher->create($this->editor, 'knowledge', 'Unpublished process', 'blank');
    $this->publisher->save($this->editor, $draft, [...$draft->draft, 'resource_type' => 'Process', 'checklist' => $this->steps], 1);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => [$outside->id]]);
    $private = ($this->publishProcess)('page', ['checklist' => [['id' => 'private', 'title' => 'Privateprocessinstructionneedle']], 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]);
    $expired = ($this->publishProcess)();
    $expired->currentRelease->update(['expires_at' => now()->subMinute()]);
    $withdrawn = ($this->publishProcess)();
    $this->publisher->archive($this->editor, $withdrawn, 2);
    foreach ([$draft, $private, $expired, $withdrawn] as $page) {
        $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertNotFound();
        $this->putJson('/company-hub/pages/'.$page->id.'/checklist', $input)->assertNotFound();
    }
    expect(app(HubFeed::class)->forUser($this->reader, 'Privateprocessinstructionneedle')['pages'])->toBe([])
        ->and(app(HubCommandSearch::class)->search($this->reader, 'Privateprocessinstructionneedle'))->toBe([]);
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->reader)->putJson('/company-hub/pages/'.$public->id.'/checklist', $input)->assertNotFound();
    $this->assertDatabaseCount('hub_interactions', 0);
});

test('Process prose needs no personal steps and resource type changes retain snapshots and existing Checklist progress', function () {
    $prose = ($this->publishProcess)('page', ['checklist' => []]);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$prose->slug)->assertInertia(fn (Assert $p) => $p->where('knowledge.steps', []));
    $this->putJson('/company-hub/pages/'.$prose->id.'/checklist', ($this->input)($prose, $this->steps[0]))->assertNotFound();
    $page = ($this->publishProcess)('knowledge', ['resource_type' => 'Checklist']);
    $original = $page->currentRelease;
    $snapshot = $original->content;
    $url = '/company-hub/pages/'.$page->id.'/checklist';
    $this->putJson($url, ($this->input)($page, $this->steps[0]))->assertOk();
    $this->publisher->save($this->editor, $page, [...$page->draft, 'resource_type' => 'Process'], 2);
    $this->publisher->publish($this->editor, $page->fresh(), 3, null, null);
    $this->get('/company-hub/read/'.$page->slug)->assertInertia(fn (Assert $p) => $p->where('knowledge.steps.0.done', true));
    $page = $page->fresh();
    $this->publisher->save($this->editor, $page, [...$page->draft, 'resource_type' => 'Guide'], 3);
    $this->publisher->publish($this->editor, $page->fresh(), 4, null, null);
    $page = $page->fresh();
    $this->get('/company-hub/read/'.$page->slug)->assertInertia(fn (Assert $p) => $p->where('knowledge.steps', []));
    $this->putJson($url, ($this->input)($page, $this->steps[0]))->assertNotFound();
    expect(app(HubFeed::class)->forUser($this->reader, 'Instructiononlyprocessneedle')['pages'])->toBe([])
        ->and(app(HubCommandSearch::class)->search($this->reader, 'Instructiononlyprocessneedle'))->toBe([])
        ->and($page->currentRelease->fresh()->content['checklist'])->toEqual($this->steps)
        ->and($original->fresh()->content)->toBe($snapshot)
        ->and(DB::table('hub_interactions')->where('kind', 'checklist')->count())->toBe(1);
    Notification::assertNothingSent();
});
