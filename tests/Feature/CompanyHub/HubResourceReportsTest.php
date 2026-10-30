<?php

use App\Domain\CompanyHub\Services\HubEditorial;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Http\Controllers\CompanyHub\HubResourceController;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    // Production route additions are coordinated by root while its current build finishes.
    if (! Route::has('company-hub.reports.index')) {
        Route::middleware(['web', 'auth', 'verified', 'permission:company_hub.view'])->get('/company-hub/pages/{page}/reports', [HubResourceController::class, 'index'])->name('company-hub.reports.index');
    }
    if (! Route::has('company-hub.reports.show')) {
        Route::middleware(['web', 'auth', 'verified', 'permission:company_hub.view'])->get('/company-hub/pages/{page}/reports/{report}', [HubResourceController::class, 'show'])->whereNumber('report')->name('company-hub.reports.show');
    }
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['bio' => 'Private report editor HR details']);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->other = User::factory()->create(['name' => 'Other private reporter', 'role' => 'support_worker', 'approved_at' => now()]);
    $this->other->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->other, $this->site);
    $this->publisher = app(HubPublishing::class);
    // Starter templates begin with unfinished blocks that cannot publish; report tests depend only on the page kind.
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'A resource with corrections', 'blank');
    $this->release = $this->publisher->publish($this->editor, $this->page, 1, null, null);
    $this->snapshot = $this->release->fresh()->content;
    $this->url = '/company-hub/pages/'.$this->page->id.'/reports';
    $this->readUrl = '/company-hub/read/'.$this->page->slug;
    $this->input = ['issue' => 'Broken link', 'note' => 'The resource link needs checking.', 'actor_id' => $this->reader->id, 'revision_id' => $this->release->id];
    $this->createReport = fn () => $this->actingAs($this->reader)->postJson($this->url, $this->input)->assertOk()->json('report.id');
});

test('resource correction create list detail and reader props expose only the current reporter and no private identities', function () {
    $id = ($this->createReport)();
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('report.id', $id)->assertJsonPath('report.version', 1)
        ->assertJsonPath('report.revision_id', $this->release->id)->assertJsonPath('actor_id', $this->reader->id)->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($this->other)->postJson($this->url, [...$this->input, 'actor_id' => $this->other->id, 'note' => 'Another reporter private correction.'])->assertOk();
    $this->assertDatabaseCount('hub_interactions', 2);
    $this->actingAs($this->reader)->getJson($this->url)->assertOk()->assertJsonCount(1, 'reports')
        ->assertJsonPath('reports.0.id', $id)->assertJsonPath('reports.0.current_revision', true)->assertJsonPath('next_cursor', null)
        ->assertHeader('Cache-Control', 'no-store, private');
    $detail = $this->getJson($this->url.'/'.$id)->assertOk()->assertJsonPath('report.id', $id)->json('report');
    expect(array_keys($detail))->toBe(['id', 'revision_id', 'current_revision', 'issue', 'note', 'resolved', 'response', 'version', 'created_at', 'updated_at']);
    $this->get($this->readUrl)->assertOk()->assertInertia(fn (Assert $p) => $p->has('resourceReports.reports', 1)
        ->where('resourceReports.actor_id', $this->reader->id)->where('resourceReports.reports.0.id', $id)
        ->missing('resourceReports.reports.0.user_id')->missing('resourceReports.reports.0.owner')->missing('resourceReports.reports.0.reviewer'));
    $this->getJson($this->url.'?user_id='.$this->other->id)->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'user_id' => $this->other->id])->assertUnprocessable();
    $this->actingAs($this->other)->getJson($this->url.'/'.$id)->assertNotFound();
    $this->actingAs($this->editor)->getJson($this->url.'/'.$id)->assertNotFound();
    $this->get($this->readUrl)->assertInertia(fn (Assert $p) => $p->where('resourceReports.reports', []));
    expect(app(HubParticipation::class)->read($this->reader, $this->page)['comments'])->toBe([])
        ->and($this->release->fresh()->content)->toBe($this->snapshot);
});

test('resource correction responses resolve and reopen visibly with stale review rejection and unchanged publication', function () {
    $id = ($this->createReport)();
    $resolve = '/company-hub/manage/'.$this->page->id.'/reports/'.$id;
    $body = ['resolved' => true, 'response' => 'The updated link is being reviewed in the resource draft.', 'version' => 1];
    $this->putJson($resolve, $body)->assertNotFound();
    $this->actingAs($this->editor)->putJson($resolve, $body)->assertOk();
    $this->putJson($resolve, [...$body, 'response' => 'A stale response must not replace this one.'])->assertConflict();
    $this->actingAs($this->reader)->getJson($this->url.'/'.$id)->assertOk()->assertJsonPath('report.resolved', true)
        ->assertJsonPath('report.response', $body['response'])->assertJsonPath('report.version', 2);
    $this->actingAs($this->editor)->putJson($resolve, ['resolved' => false, 'response' => 'Reopened to check the remaining steps.', 'version' => 2])->assertOk();
    $this->actingAs($this->reader)->get($this->readUrl)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('resourceReports.reports.0.resolved', false)->where('resourceReports.reports.0.version', 3)
        ->where('resourceReports.reports.0.response', 'Reopened to check the remaining steps.'));
    expect(AuditLog::where('action', 'company_hub.resource_report_reviewed')->where('auditable_id', $this->page->id)->count())->toBe(2)
        ->and($this->page->fresh()->draft_version)->toBe(1)->and($this->release->fresh()->content)->toBe($this->snapshot);
    Notification::assertNothingSent();
});

test('resource correction earlier releases remain tagged while stale accounts releases and invalid revision bindings fail safely', function () {
    $id = ($this->createReport)();
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'summary' => 'A revised resource description.'], 1);
    $this->getJson($this->url.'/'.$id)->assertOk()->assertJsonPath('report.current_revision', true);
    $current = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->getJson($this->url.'/'.$id)->assertOk()->assertJsonPath('report.current_revision', false)
        ->assertJsonPath('report.revision_id', $this->release->id)->assertJsonPath('revision_id', $current->id);
    $this->postJson($this->url, $this->input)->assertConflict();
    $this->postJson($this->url, [...$this->input, 'revision_id' => $current->id, 'actor_id' => $this->other->id])->assertConflict();
    $this->postJson($this->url, [...$this->input, 'revision_id' => $current->id])->assertOk()->assertJsonPath('report.current_revision', true);
    $foreign = $this->publisher->create($this->editor, 'knowledge', 'A different private resource', 'blank');
    $foreignRelease = $this->publisher->publish($this->editor, $foreign, 1, null, null);
    $invalid = DB::table('hub_interactions')->insertGetId(['hub_page_id' => $this->page->id, 'user_id' => $this->reader->id, 'kind' => 'resource_report',
        'body' => json_encode(['issue' => 'Other', 'note' => 'An invalid release binding.', 'revision_id' => $foreignRelease->id, 'resolved' => false, 'response' => '', 'version' => 1, 'private_staff' => ['name' => $this->other->name]]),
        'created_at' => now(), 'updated_at' => now()]);
    $this->getJson($this->url.'/'.$invalid)->assertNotFound();
    $this->getJson('/company-hub/pages/'.$foreign->id.'/reports/'.$id)->assertNotFound();
    $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'reports');
    $this->assertDatabaseCount('hub_interactions', 3);
    expect($this->release->fresh()->content)->toBe($this->snapshot);
});

test('resource correction list detail and creation recheck the current page staff site and permission boundary', function (string $change) {
    $id = ($this->createReport)();
    if ($change === 'archive') {
        $this->publisher->archive($this->editor, $this->page, 1);
    } elseif ($change === 'expire') {
        $this->release->update(['expires_at' => now()->addHour()]);
        $this->travel(2)->hours();
    } elseif ($change === 'audience') {
        $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'audience' => ['type' => 'sites', 'ids' => [$this->site->id]]], 1);
        $this->publisher->publish($this->editor, $this->page, 2, null, null);
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    } elseif ($change === 'former staff') {
        $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    } else {
        $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole()->id, ['allowed' => false]);
    }
    $this->actingAs($this->reader->fresh());
    $expected = $change === 'permission' ? 403 : 404;
    $this->getJson($this->url)->assertStatus($expected);
    $this->getJson($this->url.'/'.$id)->assertStatus($expected);
    $this->get($this->readUrl)->assertStatus($expected);
    $this->postJson($this->url, $this->input)->assertStatus($expected);
    $this->assertDatabaseCount('hub_interactions', 1);
})->with(['archive', 'expire', 'audience', 'former staff', 'permission']);

test('resource correction reader responses never broaden the editor queue or comment moderation boundary', function () {
    $id = ($this->createReport)();
    $this->other->permissionOverrides()->attach(Permission::where('key', 'company_hub.moderate')->sole()->id, ['allowed' => true]);
    $this->actingAs($this->other->fresh())->getJson($this->url)->assertOk()->assertJsonPath('reports', []);
    $this->putJson('/company-hub/manage/'.$this->page->id.'/reports/'.$id, ['resolved' => true, 'response' => 'A moderator cannot review a private resource report.', 'version' => 1])->assertNotFound();
    $this->putJson('/company-hub/pages/'.$this->page->id.'/interactions/'.$id.'/moderation', ['actor_id' => $this->other->id, 'revision_id' => $this->release->id, 'version' => 1, 'hidden' => true])->assertNotFound();
    $this->actingAs($this->reader)->deleteJson('/company-hub/pages/'.$this->page->id.'/interactions/'.$id)->assertNotFound();
    $this->actingAs($this->editor)->getJson('/company-hub/pages/'.$this->page->id.'/moderation?'.http_build_query(['actor_id' => $this->editor->id, 'revision_id' => $this->release->id]))->assertOk()->assertJsonPath('hidden_comments', []);
    $queue = collect(app(HubEditorial::class)->workspace($this->editor))->firstWhere('id', $this->page->id);
    expect($queue['reports'][0]['id'])->toBe($id)->and(app(HubParticipation::class)->read($this->reader, $this->page)['comments'])->toBe([]);
    $news = $this->publisher->create($this->editor, 'news', 'A story is not a resource', 'blank');
    $this->publisher->publish($this->editor, $news, 1, null, null);
    $this->actingAs($this->reader)->getJson('/company-hub/pages/'.$news->id.'/reports')->assertNotFound();
    $this->postJson('/company-hub/pages/'.$news->id.'/reports', ['issue' => 'Other', 'note' => 'Wrong page type'])->assertUnprocessable();
    $this->get('/company-hub/read/'.$news->slug)->assertOk()->assertInertia(fn (Assert $p) => $p->where('resourceReports', null));
    Notification::assertNothingSent();
});

test('resource correction pagination is bounded private and uses report identities without exposing extra stored fields', function () {
    $ids = [];
    foreach (range(1, 51) as $index) {
        $ids[] = DB::table('hub_interactions')->insertGetId(['hub_page_id' => $this->page->id, 'user_id' => $this->reader->id, 'kind' => 'resource_report',
            'body' => json_encode(['issue' => 'Other', 'note' => 'Own correction '.$index, 'revision_id' => $this->release->id, 'resolved' => false, 'response' => '', 'version' => 1,
                'reviewer_id' => $this->editor->id, 'private_staff' => 'Private report editor HR details']), 'created_at' => now(), 'updated_at' => now()]);
    }
    $first = $this->actingAs($this->reader)->getJson($this->url)->assertOk()->assertJsonCount(50, 'reports')->json();
    expect(array_column($first['reports'], 'id'))->toBe(array_reverse(array_slice($ids, 1)))
        ->and($first['next_cursor'])->toBe($ids[1])->and(json_encode($first))->not->toContain('Private report editor HR details', 'reviewer_id');
    $this->getJson($this->url.'?before='.$first['next_cursor'])->assertOk()->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.id', $ids[0])->assertJsonPath('next_cursor', null);
    foreach (['0', '-1', '2147483648', 'text'] as $before) {
        $this->getJson($this->url.'?before='.$before)->assertUnprocessable();
    }
    $this->getJson($this->url.'/2147483648')->assertNotFound();
});
