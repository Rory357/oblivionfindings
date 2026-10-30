<?php

use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    $this->participation = app(HubParticipation::class);
    $this->story = function (string $title): array {
        $page = $this->publisher->create($this->editor, 'news', $title, 'blank')->fresh();
        $draft = [...$page->draft, 'comments_enabled' => true, 'sections' => [['id' => 'main', 'columns' => 1, 'blocks' => [
            ['id' => 'story', 'type' => 'text', 'title' => 'Our current story', 'body' => 'A complete story for canonical discussion contracts.'],
        ]]]];
        $page = $this->publisher->save($this->editor, $page, $draft, $page->draft_version);
        $revision = $this->publisher->publish($this->editor, $page, $page->draft_version, null, null);

        return [$page->fresh(), $revision];
    };
    [$this->page, $this->revision] = ($this->story)('A canonical shared story');
    $this->url = '/company-hub/pages/'.$this->page->id.'/interactions';
    $this->context = ['actor_id' => $this->reader->id, 'revision_id' => $this->revision->id];
    $this->comment = function (string $body, ?int $parent = null): int {
        $this->participation->write($this->reader, $this->page, ['kind' => 'comment', 'body' => $body, 'parent_id' => $parent]);

        return (int) DB::table('hub_interactions')->where('hub_page_id', $this->page->id)->max('id');
    };
    Notification::fake();
    $this->actingAs($this->reader);
});

test('current actor and release accept canonical comments and replies while omitted legacy context remains valid', function () {
    $this->post($this->url, [...$this->context, 'kind' => 'comment', 'body' => '  A current root comment.  '])->assertRedirect()->assertSessionHasNoErrors();
    $parent = (int) DB::table('hub_interactions')->sole()->id;
    $this->actingAs($this->other)->post($this->url, [...$this->context, 'actor_id' => $this->other->id, 'kind' => 'comment',
        'parent_id' => $parent, 'body' => 'A current reply.'])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->reader)->post($this->url, ['kind' => 'comment', 'body' => 'A legacy root comment.'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post($this->url, ['kind' => 'comment', 'parent_id' => $parent, 'body' => 'A legacy reply.'])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseCount('hub_interactions', 4);
    $this->assertDatabaseHas('hub_interactions', ['user_id' => $this->reader->id, 'parent_id' => null, 'body' => 'A current root comment.']);
    $this->assertDatabaseHas('hub_interactions', ['user_id' => $this->other->id, 'parent_id' => $parent, 'body' => 'A current reply.']);
    $read = $this->participation->read($this->reader, $this->page);
    expect($read['actor_id'])->toBe($this->reader->id)->and($read['revision_id'])->toBe($this->revision->id)->and($read['comments'])->toHaveCount(4);
});

test('stale actor comments and replies cannot insert records or dispatch conversation delivery', function () {
    $parent = ($this->comment)('A retained root comment.');
    $before = (array) DB::table('hub_interactions')->sole();
    $this->actingAs($this->other);
    foreach ([null, $parent] as $replyTo) {
        $this->postJson($this->url, [...$this->context, 'kind' => 'comment', 'body' => 'A stale account submission.', 'parent_id' => $replyTo])->assertConflict();
    }
    $this->actingAs($this->reader)->postJson($this->url, ['actor_id' => $this->other->id, 'kind' => 'comment', 'body' => 'A stale actor without revision context.'])->assertConflict();
    $this->assertDatabaseCount('hub_interactions', 1);
    expect((array) DB::table('hub_interactions')->sole())->toEqual($before);
    Notification::assertNothingSent();
});

test('comment revision checks bind to the current release rather than an unpublished saved draft', function () {
    $parent = ($this->comment)('Discussion retained across story releases.');
    $original = $this->revision->content;
    $draft = $this->page->draft;
    $draft['sections'][0]['blocks'][0]['body'] = 'The next complete authored edition.';
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, $this->page->draft_version);
    $this->post($this->url, [...$this->context, 'kind' => 'comment', 'body' => 'The published edition is still current.'])->assertRedirect()->assertSessionHasNoErrors();
    $latest = $this->publisher->publish($this->editor, $this->page, $this->page->draft_version, null, null);
    foreach ([null, $parent] as $replyTo) {
        $this->postJson($this->url, ['revision_id' => $this->revision->id, 'kind' => 'comment', 'body' => 'A superseded story submission.', 'parent_id' => $replyTo])->assertConflict();
    }
    $this->post($this->url, [...$this->context, 'revision_id' => $latest->id, 'kind' => 'comment', 'parent_id' => $parent,
        'body' => 'A reply with the refreshed published edition.'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->revision->fresh()->content)->toEqual($original)
        ->and($this->participation->read($this->reader, $this->page)['revision_id'])->toBe($latest->id);
    $this->assertDatabaseCount('hub_interactions', 3);
});

test('retained actor objects cannot preserve ended staff access or withdrawn Hub permission for comment writes', function () {
    $cached = $this->reader->load('hrEmployeeProfile');
    expect($cached->canDo('company_hub.view'))->toBeTrue();
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    expect($cached->hrEmployeeProfile->is_active)->toBeTrue();
    $write = fn () => $this->participation->write($cached, $this->page, [...$this->context, 'kind' => 'comment', 'body' => 'A stale work identity.']);
    foreach (['ended_staff', 'withdrawn_permission'] as $denial) {
        if ($denial === 'withdrawn_permission') {
            $this->reader->hrEmployeeProfile()->update(['is_active' => true]);
            $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole(), ['allowed' => false]);
        }
        try {
            $write();
            test()->fail('Current comment author access must be rechecked.');
        } catch (HttpException $error) {
            expect($error->getStatusCode())->toBe(404);
        }
    }
    $this->assertDatabaseCount('hub_interactions', 0);
    Notification::assertNothingSent();
});

test('context checks preserve current parent settings audience and archived page denials', function () {
    $parent = ($this->comment)('Current parent.');
    $child = ($this->comment)('Current child.', $parent);
    [$otherPage, $otherRevision] = ($this->story)('Another canonical story');
    $this->postJson('/company-hub/pages/'.$otherPage->id.'/interactions', [...$this->context, 'revision_id' => $otherRevision->id,
        'kind' => 'comment', 'parent_id' => $parent, 'body' => 'A foreign parent.'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->context, 'kind' => 'comment', 'parent_id' => $child, 'body' => 'An unsupported nested reply.'])->assertUnprocessable();
    $this->participation->remove($this->reader, $this->page, $parent);
    $this->postJson($this->url, [...$this->context, 'kind' => 'comment', 'parent_id' => $parent, 'body' => 'A removed parent.'])->assertUnprocessable();
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'comments_enabled' => false], $this->page->draft_version);
    $disabled = $this->publisher->publish($this->editor, $this->page, $this->page->draft_version, null, null);
    $this->postJson($this->url, [...$this->context, 'revision_id' => $disabled->id, 'kind' => 'comment', 'body' => 'A disabled discussion.'])->assertForbidden();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->editor->hrEmployeeProfile()->update(['secondary_site_ids' => [$outside->id]]);
    $draft = [...$this->page->draft, 'comments_enabled' => true, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]];
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, $this->page->draft_version);
    $this->publisher->publish($this->editor, $this->page, $this->page->draft_version, null, null);
    $this->postJson($this->url, [...$this->context, 'kind' => 'comment', 'body' => 'A stale private story.'])->assertNotFound();
    $this->publisher->archive($this->editor, $this->page, $this->page->draft_version);
    $this->postJson($this->url, [...$this->context, 'kind' => 'comment', 'body' => 'An archived story.'])->assertNotFound();
    $this->assertDatabaseCount('hub_interactions', 2);
});
