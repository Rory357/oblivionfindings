<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
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
use Illuminate\Http\Request;
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
    $this->otherEditor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->otherEditor->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($this->otherEditor, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'A resource with a next review date', 'blank')->fresh();
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft,
        'summary' => 'The current published resource summary.', 'review_on' => '2026-11-01',
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'sections' => [['id' => 'resource', 'columns' => 1, 'blocks' => [
            ['id' => 'instructions', 'type' => 'text', 'title' => 'Current instructions', 'body' => 'Complete canonical resource instructions.'],
            ['id' => 'retained-work', 'type' => 'text', 'title' => '', 'body' => 'Keep the complete hidden authored work.', 'hidden' => true, 'tone' => 'soft', 'min_height' => 280],
        ]]],
    ], $this->page->draft_version);
    $this->release = $this->publisher->publish($this->editor, $this->page, $this->page->draft_version, null, null);
    $this->published = $this->release->fresh()->content;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->page->draft,
        'summary' => 'Separate unsubmitted saved work must remain intact.',
    ], $this->page->draft_version);
    $this->saved = $this->page->fresh()->draft;
    $this->pageVersion = $this->page->draft_version;
    $this->reportUrl = '/company-hub/pages/'.$this->page->id.'/reports';
    $this->reportId = (int) $this->actingAs($this->reader)->postJson($this->reportUrl, [
        'issue' => 'Out of date', 'note' => 'Please check the review date and current instructions.',
        'actor_id' => $this->reader->id, 'revision_id' => $this->release->id,
    ])->assertOk()->json('report.id');
    $this->resolveUrl = '/company-hub/manage/'.$this->page->id.'/reports/'.$this->reportId;
    $this->review = ['resolved' => true, 'response' => 'The correction is being checked in the saved draft.', 'version' => 1, 'actor_id' => $this->editor->id];
    $this->reportSnapshot = (array) DB::table('hub_interactions')->where('id', $this->reportId)->sole();
    $this->reportBody = json_decode($this->reportSnapshot['body'], true);
    Notification::fake();
    $this->actingAs($this->editor);
});

test('resource review date add and clear save only the current complete draft and acknowledge canonical report versions without publishing', function () {
    $next = '2026-12-15';
    $this->putJson($this->resolveUrl, [...$this->review, 'review_on' => $next, 'page_version' => $this->pageVersion,
        'draft' => ['title' => 'An ignored client snapshot'], 'title' => 'An ignored replacement title',
    ])->assertOk()->assertExactJson([
        'saved' => true, 'actor_id' => $this->editor->id, 'page_id' => $this->page->id, 'report_id' => $this->reportId,
        'report_version' => 2, 'page_version' => $this->pageVersion + 1, 'review_on' => $next,
    ])->assertHeader('Cache-Control', 'no-store, private');
    $updated = $this->page->fresh();
    expect($updated->draft)->toEqual([...$this->saved, 'review_on' => $next])
        ->and($updated->draft_version)->toBe($this->pageVersion + 1)
        ->and($updated->liveRevision()->id)->toBe($this->release->id)
        ->and($this->release->fresh()->content)->toEqual($this->published)
        ->and($this->page->revisions()->count())->toBe(1);
    $body = json_decode(DB::table('hub_interactions')->where('id', $this->reportId)->value('body'), true);
    expect($body)->toEqual([...$this->reportBody, 'resolved' => true, 'response' => $this->review['response'], 'version' => 2]);
    $this->actingAs($this->reader)->getJson($this->reportUrl.'/'.$this->reportId)->assertOk()
        ->assertJsonPath('report.response', $this->review['response'])->assertJsonPath('report.version', 2)
        ->assertJsonPath('report.current_revision', true)->assertJsonMissingPath('report.review_on')->assertJsonMissingPath('report.actor_id');
    $this->actingAs($this->editor)->putJson($this->resolveUrl, [...$this->review, 'version' => 2, 'resolved' => false,
        'response' => 'Reopened while the review date is cleared in the draft.', 'review_on' => null, 'page_version' => $this->pageVersion + 1,
    ])->assertOk()->assertJsonPath('report_version', 3)->assertJsonPath('page_version', $this->pageVersion + 2)->assertJsonPath('review_on', null);
    expect($this->page->fresh()->draft)->toEqual([...$this->saved, 'review_on' => null])
        ->and($this->release->fresh()->content)->toEqual($this->published)
        ->and($this->page->fresh()->liveRevision()->id)->toBe($this->release->id)
        ->and($this->page->revisions()->count())->toBe(1);
    $this->actingAs($this->reader)->getJson($this->reportUrl.'/'.$this->reportId)->assertOk()->assertJsonPath('report.resolved', false)->assertJsonPath('report.version', 3);
    expect(AuditLog::where('action', 'company_hub.resource_report_reviewed')->where('auditable_id', $this->page->id)->count())->toBe(2);
    Notification::assertNothingSent();
});

test('legacy response only reviews preserve saved date content and page version while returning current actor receipts', function () {
    $this->putJson($this->resolveUrl, array_diff_key($this->review, ['actor_id' => true]))->assertOk()->assertExactJson([
        'saved' => true, 'actor_id' => $this->editor->id, 'page_id' => $this->page->id, 'report_id' => $this->reportId,
        'report_version' => 2, 'page_version' => $this->pageVersion, 'review_on' => $this->saved['review_on'],
    ]);
    $this->putJson($this->resolveUrl, [...$this->review, 'version' => 2, 'resolved' => false, 'response' => 'A current actor response only update.'])
        ->assertOk()->assertJsonPath('actor_id', $this->editor->id)->assertJsonPath('report_version', 3)->assertJsonPath('page_version', $this->pageVersion);
    expect($this->page->fresh()->draft)->toEqual($this->saved)->and($this->page->fresh()->draft_version)->toBe($this->pageVersion)
        ->and($this->release->fresh()->content)->toEqual($this->published)->and($this->page->revisions()->count())->toBe(1);
    Notification::assertNothingSent();
});

test('resource review validation actor object and optimistic version denials never partially save report or draft', function () {
    foreach (['2026-12-15', null] as $dateWithoutActor) {
        $this->putJson($this->resolveUrl, array_diff_key([
            ...$this->review, 'review_on' => $dateWithoutActor, 'page_version' => $this->pageVersion,
        ], ['actor_id' => true]))->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    }
    foreach ([
        ['review_on' => null], ['review_on' => '2026-12-15'], ['page_version' => $this->pageVersion],
        ['review_on' => '2026-02-30', 'page_version' => $this->pageVersion],
        ['review_on' => null, 'page_version' => null], ['review_on' => null, 'page_version' => 0],
        ['actor_id' => null], ['actor_id' => 'another account'], ['actor_id' => 2147483648], ['version' => 0],
    ] as $invalid) {
        $this->putJson($this->resolveUrl, [...$this->review, ...$invalid])->assertUnprocessable();
    }
    $date = ['review_on' => '2026-12-15', 'page_version' => $this->pageVersion];
    $this->putJson($this->resolveUrl, [...$this->review, ...$date, 'version' => 2])->assertConflict();
    $this->putJson($this->resolveUrl, [...$this->review, ...$date, 'page_version' => $this->pageVersion - 1])->assertConflict();
    $this->actingAs($this->otherEditor)->putJson($this->resolveUrl, [...$this->review, ...$date])->assertConflict();
    $this->actingAs($this->reader)->putJson($this->resolveUrl, [...$this->review, ...$date])->assertNotFound();
    $this->actingAs($this->editor);
    $foreign = $this->publisher->create($this->editor, 'knowledge', 'An unrelated resource', 'blank')->fresh();
    $this->putJson('/company-hub/manage/'.$foreign->id.'/reports/'.$this->reportId, [...$this->review, ...$date])->assertNotFound();
    foreach (['0', '-1', '2147483648', 'unknown'] as $id) {
        $this->putJson('/company-hub/manage/'.$this->page->id.'/reports/'.$id, $this->review)->assertNotFound();
    }
    $otherKind = DB::table('hub_interactions')->insertGetId(['hub_page_id' => $this->page->id, 'user_id' => $this->reader->id,
        'kind' => 'comment', 'body' => json_encode($this->reportBody), 'created_at' => now(), 'updated_at' => now()]);
    $this->putJson('/company-hub/manage/'.$this->page->id.'/reports/'.$otherKind, $this->review)->assertNotFound();
    $malformed = DB::table('hub_interactions')->insertGetId(['hub_page_id' => $this->page->id, 'user_id' => $this->reader->id,
        'kind' => 'resource_report', 'body' => json_encode([...$this->reportBody, 'version' => '1']), 'created_at' => now(), 'updated_at' => now()]);
    $this->putJson('/company-hub/manage/'.$this->page->id.'/reports/'.$malformed, $this->review)->assertNotFound();
    expect((array) DB::table('hub_interactions')->where('id', $this->reportId)->sole())->toEqual($this->reportSnapshot)
        ->and($this->page->fresh()->draft)->toEqual($this->saved)->and($this->page->fresh()->draft_version)->toBe($this->pageVersion)
        ->and($this->release->fresh()->content)->toEqual($this->published)
        ->and(AuditLog::where('action', 'company_hub.resource_report_reviewed')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('reviewed scheduled and archived resources allow response only while prohibiting review date draft changes', function (string $state) {
    if ($state === 'submitted') {
        $this->publisher->submit($this->editor, $this->page, $this->pageVersion);
    } elseif ($state === 'scheduled') {
        $this->publisher->publish($this->editor, $this->page, $this->pageVersion, now()->addDay()->toIso8601String(), null);
    } else {
        $this->publisher->archive($this->editor, $this->page, $this->pageVersion);
    }
    $locked = $this->page->fresh();
    $revisions = $locked->revisions()->get()->mapWithKeys(fn ($revision) => [$revision->id => [$revision->state, $revision->content]])->all();
    $this->putJson($this->resolveUrl, [...$this->review, 'review_on' => null, 'page_version' => $locked->draft_version])->assertConflict();
    expect((array) DB::table('hub_interactions')->where('id', $this->reportId)->sole())->toEqual($this->reportSnapshot);
    $this->putJson($this->resolveUrl, $this->review)->assertOk()->assertJsonPath('report_version', 2)
        ->assertJsonPath('page_version', $locked->draft_version)->assertJsonPath('review_on', $locked->draft['review_on']);
    $after = $locked->fresh();
    expect($after->draft)->toEqual($locked->draft)->and($after->draft_version)->toBe($locked->draft_version)
        ->and($after->archived_at?->toIso8601String())->toBe($locked->archived_at?->toIso8601String())
        ->and($after->revisions()->get()->mapWithKeys(fn ($revision) => [$revision->id => [$revision->state, $revision->content]])->all())->toEqual($revisions)
        ->and($this->release->fresh()->content)->toEqual($this->published)
        ->and($after->liveRevision()?->id)->toBe($state === 'archived' ? null : $this->release->id);
    expect(AuditLog::where('action', 'company_hub.resource_report_reviewed')->where('auditable_id', $this->page->id)->count())->toBe(1);
    Notification::assertNothingSent();
})->with(['submitted', 'scheduled', 'archived']);

test('resource review date report response and audit roll back atomically and retain the original versions for a safe retry', function () {
    $fail = true;
    DB::listen(function ($query) use (&$fail): void {
        if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_logs')
            && in_array('company_hub.resource_report_reviewed', $query->bindings, true)) {
            $fail = false;
            throw new RuntimeException('Isolated resource review audit failure');
        }
    });
    $input = [...$this->review, 'review_on' => '2026-12-15', 'page_version' => $this->pageVersion];
    $this->withoutExceptionHandling();
    expect(fn () => $this->putJson($this->resolveUrl, $input))->toThrow(RuntimeException::class, 'Isolated resource review audit failure');
    expect((array) DB::table('hub_interactions')->where('id', $this->reportId)->sole())->toEqual($this->reportSnapshot)
        ->and($this->page->fresh()->draft)->toEqual($this->saved)->and($this->page->fresh()->draft_version)->toBe($this->pageVersion)
        ->and($this->release->fresh()->content)->toEqual($this->published)
        ->and(AuditLog::where('action', 'company_hub.resource_report_reviewed')->count())->toBe(0);
    $this->withExceptionHandling();
    $this->putJson($this->resolveUrl, $input)->assertOk()->assertJsonPath('report_version', 2)->assertJsonPath('page_version', $this->pageVersion + 1);
    Notification::assertNothingSent();
});

test('resource review refreshes cached actor staff permission and approved site access before response only or date writes', function () {
    $cached = $this->editor->load('hrEmployeeProfile');
    $access = app(HubAccess::class);
    expect($access->canEdit($cached, $this->page))->toBeTrue();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $permission = Permission::where('key', 'company_hub.view')->sole();
    foreach (['former staff', 'permission', 'site'] as $change) {
        if ($change === 'former staff') {
            $this->editor->hrEmployeeProfile()->update(['is_active' => false]);
        } elseif ($change === 'permission') {
            $this->editor->hrEmployeeProfile()->update(['is_active' => true]);
            $this->editor->permissionOverrides()->attach($permission, ['allowed' => false]);
        } else {
            $this->editor->permissionOverrides()->detach($permission);
            $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
        }
        foreach ([$this->review, [...$this->review, 'review_on' => null, 'page_version' => $this->pageVersion]] as $input) {
            $request = Request::create($this->resolveUrl, 'PUT', $input);
            $request->setUserResolver(fn () => $cached);
            try {
                app(HubResourceController::class)->resolve($request, $this->page, (string) $this->reportId, $access);
                $this->fail('A retained account must not preserve revoked resource review access.');
            } catch (HttpException $error) {
                expect($error->getStatusCode())->toBe(404);
            }
        }
    }
    expect((array) DB::table('hub_interactions')->where('id', $this->reportId)->sole())->toEqual($this->reportSnapshot)
        ->and(HubPage::findOrFail($this->page->id)->draft)->toEqual($this->saved)
        ->and($this->page->fresh()->draft_version)->toBe($this->pageVersion)
        ->and(AuditLog::where('action', 'company_hub.resource_report_reviewed')->count())->toBe(0);
    Notification::assertNothingSent();
});
