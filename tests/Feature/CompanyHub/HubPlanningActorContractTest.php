<?php

use App\Domain\CompanyHub\Models\HubEditorialPlan;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubPlanning;
use App\Domain\CompanyHub\Services\HubTemplates;
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
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->other->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($this->other, $this->site);
    $this->writer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->writer->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->writer, $this->site);
    $this->writer->permissionOverrides()->attach(Permission::where('key', 'company_hub.write')->sole(), ['allowed' => true]);
    $this->writer = $this->writer->fresh();
    $this->data = ['title' => 'A canonical editorial plan', 'target_date' => now()->toDateString(), 'owner_id' => $this->writer->id,
        'template' => 'resource-collection', 'brief' => 'A private editorial brief.', 'audience' => ['type' => 'all', 'ids' => []], 'actor_id' => $this->editor->id];
    $this->actingAs($this->editor);
});

test('planning save receipts retain flat canonical fields and acknowledge the authenticated actor with legacy omission support', function () {
    $created = $this->postJson('/company-hub/editor/plans', $this->data)->assertOk()->assertJsonPath('actor_id', $this->editor->id)
        ->assertJsonPath('version', 1)->assertJsonPath('hub_page_id', null)->assertJsonPath('brief', $this->data['brief'])->json();
    $this->assertDatabaseHas('hub_editorial_plans', ['id' => $created['id'], 'created_by' => $this->editor->id, 'owner_id' => $this->writer->id]);
    $this->putJson('/company-hub/editor/plans/'.$created['id'], [...$this->data, 'version' => 1, 'title' => 'An updated canonical plan'])
        ->assertOk()->assertJsonPath('id', $created['id'])->assertJsonPath('version', 2)->assertJsonPath('actor_id', $this->editor->id)
        ->assertJsonPath('title', 'An updated canonical plan')->assertJsonPath('owner', $this->writer->name)->assertJsonPath('page_title', null);
    $legacy = $this->postJson('/company-hub/editor/plans', array_diff_key($this->data, ['actor_id' => true]))
        ->assertOk()->assertJsonPath('actor_id', $this->editor->id)->json();
    expect($legacy['id'])->not->toBe($created['id']);
    $this->assertDatabaseCount('hub_editorial_plans', 2);
    $this->assertDatabaseCount('hub_pages', 0);
    Notification::assertNothingSent();
});

test('resource collection plans start the canonical knowledge draft once and acknowledge actor on idempotent legacy retries', function () {
    $plan = $this->postJson('/company-hub/editor/plans', $this->data)->assertOk()->json();
    $url = '/company-hub/editor/plans/'.$plan['id'].'/start';
    $started = $this->postJson($url, ['version' => 1, 'actor_id' => $this->editor->id])->assertOk()->json();
    $this->postJson($url, ['version' => 1])->assertOk()->assertExactJson($started);
    $this->postJson($url, ['version' => 1, 'actor_id' => $this->editor->id])->assertOk()->assertExactJson($started);
    $page = HubPage::findOrFail($started['page_id']);
    expect($started)->toBe(['url' => '/company-hub/manage/'.$page->id.'/edit', 'page_id' => $page->id, 'actor_id' => $this->editor->id])
        ->and($page->kind)->toBe(array_column(HubTemplates::catalogue(), 'kind', 'key')['resource-collection'])
        ->and($page->owner_id)->toBe($this->writer->id)->and($page->draft['planned_for'])->toBe($this->data['target_date'])
        ->and(json_encode($page->draft))->not->toContain($this->data['brief']);
    $this->assertDatabaseCount('hub_pages', 1);
    $this->assertDatabaseCount('hub_revisions', 0);
    $this->assertDatabaseHas('hub_editorial_plans', ['id' => $plan['id'], 'version' => 2, 'hub_page_id' => $page->id]);
    expect(DB::table('audit_logs')->where('action', 'company_hub.plan.draft_started')->count())->toBe(1);
    Notification::assertNothingSent();
});

test('stale actors cannot create change start or reopen linked plans under another currently authorised account', function () {
    $plan = $this->postJson('/company-hub/editor/plans', $this->data)->assertOk()->json();
    $before = (array) DB::table('hub_editorial_plans')->sole();
    $url = '/company-hub/editor/plans/'.$plan['id'];
    $this->postJson('/company-hub/editor/plans', [...$this->data, 'actor_id' => 'another account'])->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    $this->postJson($url.'/start', ['version' => 1, 'actor_id' => null])->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    $this->actingAs($this->other)->postJson('/company-hub/editor/plans', $this->data)->assertConflict()->assertJsonMissingPath('actor_id');
    $this->putJson($url, [...$this->data, 'version' => 1, 'title' => 'Wrong account overwrite'])->assertConflict();
    $this->postJson($url.'/start', ['version' => 1, 'actor_id' => $this->editor->id])->assertConflict();
    expect((array) DB::table('hub_editorial_plans')->sole())->toEqual($before);
    $this->assertDatabaseCount('hub_pages', 0);
    $this->actingAs($this->editor)->postJson($url.'/start', ['version' => 1, 'actor_id' => $this->editor->id])->assertOk();
    $linked = (array) DB::table('hub_editorial_plans')->sole();
    $this->actingAs($this->other)->postJson($url.'/start', ['version' => 1, 'actor_id' => $this->editor->id])->assertConflict();
    expect((array) DB::table('hub_editorial_plans')->sole())->toEqual($linked);
    $this->assertDatabaseCount('hub_pages', 1);
    Notification::assertNothingSent();
});

test('planning versions and current audience or owner checks reject writes before draft creation', function () {
    $plan = $this->postJson('/company-hub/editor/plans', $this->data)->assertOk()->json();
    $url = '/company-hub/editor/plans/'.$plan['id'];
    $this->putJson($url, [...$this->data, 'version' => 1])->assertOk()->assertJsonPath('version', 2);
    $this->putJson($url, [...$this->data, 'version' => 1])->assertConflict();
    $this->postJson($url.'/start', ['version' => 1, 'actor_id' => $this->editor->id])->assertConflict();
    $this->postJson('/company-hub/editor/plans', [...$this->data, 'audience' => ['type' => 'sites', 'ids' => [$this->outside->id]]])->assertUnprocessable();
    $this->writer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'company_hub.write')->sole()->id, ['allowed' => false]);
    $this->postJson($url.'/start', ['version' => 2, 'actor_id' => $this->editor->id])->assertUnprocessable();
    $this->postJson('/company-hub/editor/plans', $this->data)->assertUnprocessable();
    $this->assertDatabaseCount('hub_editorial_plans', 1);
    $this->assertDatabaseCount('hub_pages', 0);
    $this->assertDatabaseHas('hub_editorial_plans', ['id' => $plan['id'], 'version' => 2, 'hub_page_id' => null]);
});

test('linked plan settings stay locked and editable editorial metadata never changes the linked draft', function () {
    $plan = $this->postJson('/company-hub/editor/plans', $this->data)->assertOk()->json();
    $url = '/company-hub/editor/plans/'.$plan['id'];
    $pageId = $this->postJson($url.'/start', ['version' => 1, 'actor_id' => $this->editor->id])->assertOk()->json('page_id');
    $snapshot = HubPage::findOrFail($pageId)->draft;
    foreach ([['owner_id' => $this->editor->id], ['template' => 'story'], ['audience' => ['type' => 'sites', 'ids' => [$this->site->id]]]] as $change) {
        $this->putJson($url, [...$this->data, 'version' => 2, ...$change])->assertUnprocessable()->assertJsonValidationErrors('plan');
    }
    $date = now()->addDays(3)->toDateString();
    $this->putJson($url, [...$this->data, 'version' => 2, 'title' => 'A revised editorial target', 'target_date' => $date, 'brief' => 'Revised private work.'])
        ->assertOk()->assertJsonPath('version', 3)->assertJsonPath('actor_id', $this->editor->id)->assertJsonPath('hub_page_id', $pageId)
        ->assertJsonPath('title', 'A revised editorial target')->assertJsonPath('page_title', $snapshot['title'])->assertJsonPath('target_date', $date);
    expect(HubPage::findOrFail($pageId)->draft)->toEqual($snapshot);
    Notification::assertNothingSent();
});

test('planning actor refresh preserves current staff permissions and private object denial before mismatch details', function () {
    $private = $this->postJson('/company-hub/editor/plans', [...$this->data, 'owner_id' => $this->editor->id])->assertOk()->json();
    $url = '/company-hub/editor/plans/'.$private['id'];
    $this->actingAs($this->writer)->putJson($url, [...$this->data, 'version' => 1])->assertNotFound();
    $this->postJson($url.'/start', ['version' => 1, 'actor_id' => $this->editor->id])->assertNotFound();
    $cached = $this->editor->load('hrEmployeeProfile');
    expect($cached->canDo('company_hub.view'))->toBeTrue();
    $plan = HubEditorialPlan::findOrFail($private['id']);
    $planning = app(HubPlanning::class);
    foreach (['ended_staff', 'withdrawn_permission'] as $denial) {
        if ($denial === 'ended_staff') {
            $this->editor->hrEmployeeProfile()->update(['is_active' => false]);
        } else {
            $this->editor->hrEmployeeProfile()->update(['is_active' => true]);
            $this->editor->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole(), ['allowed' => false]);
        }
        foreach ([fn () => $planning->save($cached, [...$this->data, 'owner_id' => $this->editor->id, 'version' => 1], $plan),
            fn () => $planning->start($cached, $plan, 1, ['actor_id' => $this->editor->id])] as $attempt) {
            try {
                $attempt();
                test()->fail('The current planning actor must remain authorised.');
            } catch (HttpException $error) {
                expect($error->getStatusCode())->toBe(403);
            }
        }
    }
    $this->assertDatabaseCount('hub_editorial_plans', 1);
    $this->assertDatabaseCount('hub_pages', 0);
    $this->assertDatabaseHas('hub_editorial_plans', ['id' => $private['id'], 'version' => 1, 'hub_page_id' => null]);
    Notification::assertNothingSent();
});
