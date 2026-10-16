<?php

use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Learning together', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->feed = app(FeedService::class);
    $this->kudos = $this->feed->sendKudos($this->editor, $this->reader->id, 'teamwork', 'Thank you for a thoughtful welcome.');
    $this->url = '/company-hub/recognition/spotlight';
    $this->payload = ['actor_id' => $this->editor->id, 'version' => 1, 'kudos_id' => $this->kudos->id];
    Notification::fake();
});

test('the shared editorial spotlight persists independently of search pagination and reader social state', function () {
    $this->feed->toggleReaction($this->kudos, $this->reader->id, 'heart');
    $reply = $this->feed->addReply($this->kudos, $this->reader->id, 'Thank you for noticing.');
    $this->kudos->update(['created_at' => now()->subDay()]);
    $this->kudos->feedPost()->update(['created_at' => now()->subDay()]);
    foreach (range(1, 24) as $index) {
        $this->feed->sendKudos($this->editor, $this->reader->id, 'teamwork', 'Newer appreciation '.$index);
    }
    Notification::fake();
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->editor->id)->assertJsonPath('version', 2)->assertJsonPath('can_feature', true)
        ->assertJsonPath('spotlight.id', $this->kudos->id)->assertJsonPath('selection_unavailable', false)
        ->assertJsonPath('spotlight.recipient_type', 'person')->assertJsonPath('spotlight.to_id', $this->reader->id)
        ->assertJsonPath('spotlight.to_department_id', null)->assertJsonPath('spotlight.team_ids', [$this->team->id])
        ->assertJsonPath('spotlight.recipient_url', '/company-hub/people/'.$this->reader->hrEmployeeProfile()->sole()->id)
        ->assertJsonPath('spotlight.reactions.heart.count', 1)->assertJsonPath('spotlight.reactions.heart.mine', false)
        ->assertJsonPath('spotlight.replies.0.id', $reply->id)->assertJsonPath('spotlight.can_reply', true);
    $feed = app(HubFeed::class)->forUser($this->reader);
    expect(collect($feed['kudos'])->pluck('id')->all())->not->toContain($this->kudos->id)
        ->and($feed['recognition']['spotlight']['id'])->toBe($this->kudos->id)->and($feed['recognition']['can_feature'])->toBeFalse()
        ->and($feed['recognition']['spotlight']['reactions']['heart']['mine'])->toBeTrue();
    $this->actingAs($this->reader)->get('/company-hub?view=shoutouts&search=no-such-recognition')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('kudos', [])->where('recognition.spotlight.id', $this->kudos->id)->where('recognition.version', 2));
    expect($this->kudos->fresh()->message)->toBe('Thank you for a thoughtful welcome.');
    $this->assertDatabaseCount('hr_kudos', 25);
    $this->assertDatabaseCount('hr_kudos_reactions', 1);
    $this->assertDatabaseCount('hr_kudos_replies', 1);
    Notification::assertNothingSent();
});

test('featuring requires current hub entry layout and recognition permissions and exact signed in actor', function () {
    $this->actingAs($this->reader)->putJson($this->url, $this->payload)->assertForbidden();
    $this->actingAs($this->editor)->putJson($this->url, [...$this->payload, 'actor_id' => $this->reader->id])->assertConflict();
    $this->putJson($this->url, [...$this->payload, 'user_id' => $this->reader->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->putJson($this->url, $this->payload)->assertOk();
    $recognition = Permission::where('key', 'hr.recognition.view')->sole();
    $this->editor->permissionOverrides()->syncWithoutDetaching([$recognition->id => ['allowed' => false]]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertOk()->assertJsonPath('can_feature', false)
        ->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', false);
    $this->putJson($this->url, [...$this->payload, 'version' => 2])->assertForbidden();
    $this->editor->permissionOverrides()->updateExistingPivot($recognition->id, ['allowed' => true]);
    $layout = Permission::where('key', 'company_hub.layout')->sole();
    $this->editor->permissionOverrides()->syncWithoutDetaching([$layout->id => ['allowed' => false]]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertOk()->assertJsonPath('can_feature', false)->assertJsonPath('spotlight.id', $this->kudos->id);
    $this->putJson($this->url, [...$this->payload, 'version' => 2])->assertForbidden();
    $this->editor->permissionOverrides()->updateExistingPivot($layout->id, ['allowed' => true]);
    $hub = Permission::where('key', 'company_hub.view')->sole();
    $this->editor->permissionOverrides()->syncWithoutDetaching([$hub->id => ['allowed' => false]]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, [...$this->payload, 'version' => 2])->assertForbidden();
    $this->editor->permissionOverrides()->updateExistingPivot($hub->id, ['allowed' => true]);
    $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->editor->fresh())->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, [...$this->payload, 'version' => 2])->assertForbidden();
    expect(DB::table('hub_recognition_spotlight')->sole()->version)->toBe(2);
    Notification::assertNothingSent();
});

test('competing editorial selections reject stale versions and a failed audit rolls back the shared selection', function () {
    $otherEditor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $otherEditor->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($otherEditor, $this->site, ['department_id' => $this->team->id]);
    $second = $this->feed->sendKudos($this->editor, $this->reader->id, 'innovation', 'A useful new idea.');
    Notification::fake();
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertOk()->assertJsonPath('version', 2);
    $this->actingAs($otherEditor)->getJson($this->url)->assertOk()->assertJsonPath('spotlight.id', $this->kudos->id)->assertJsonPath('version', 2);
    $this->putJson($this->url, ['actor_id' => $otherEditor->id, 'version' => 2, 'kudos_id' => $second->id])->assertOk()->assertJsonPath('version', 3);
    $this->actingAs($this->editor)->putJson($this->url, [...$this->payload, 'version' => 2])->assertConflict();
    $this->getJson($this->url)->assertJsonPath('spotlight.id', $second->id)->assertJsonPath('version', 3);
    $failAudit = true;
    DB::listen(function ($query) use (&$failAudit): void {
        if ($failAudit && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_logs')) {
            $failAudit = false;
            throw new RuntimeException('Isolated spotlight audit failure');
        }
    });
    expect(fn () => app(HubRecognition::class)->select($this->editor, [...$this->payload, 'version' => 3]))
        ->toThrow(RuntimeException::class, 'Isolated spotlight audit failure');
    expect((int) DB::table('hub_recognition_spotlight')->sole()->kudos_id)->toBe($second->id)
        ->and((int) DB::table('hub_recognition_spotlight')->sole()->version)->toBe(3);
    Notification::assertNothingSent();
});

test('private audience missing and deleted recognition never leaks selected ids or text and editors can recover', function () {
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertOk();
    $this->kudos->update(['is_public' => false]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', true)->assertJsonMissingPath('kudos_id');
    $this->putJson($this->url, [...$this->payload, 'version' => 2])->assertUnprocessable()->assertJsonValidationErrors('kudos_id');
    $this->putJson($this->url, [...$this->payload, 'version' => 2, 'kudos_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('kudos_id');
    $this->actingAs($this->reader)->getJson($this->url)->assertOk()->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', false);
    expect(app(HubFeed::class)->forUser($this->reader)['kudos'])->toBe([]);
    $this->kudos->update(['is_public' => true]);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->kudos->feedPost()->update(['target_audience' => 'site', 'target_value' => (string) $outside->id]);
    // Even the giver's canonical direct-access shortcut cannot feature this audience.
    $this->actingAs($this->editor)->getJson($this->url)->assertOk()->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', true);
    $this->putJson($this->url, [...$this->payload, 'version' => 2])->assertUnprocessable()->assertJsonValidationErrors('kudos_id');
    $this->kudos->feedPost()->update(['target_audience' => 'all', 'target_value' => null]);
    $this->kudos->delete();
    $this->getJson($this->url)->assertOk()->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', true)->assertJsonMissingPath('kudos_id');
    expect((int) DB::table('hub_recognition_spotlight')->sole()->kudos_id)->toBe($this->kudos->id);
    $new = $this->feed->sendKudos($this->editor, $this->reader->id, 'teamwork', 'Another public appreciation.');
    Notification::fake();
    $this->putJson($this->url, [...$this->payload, 'version' => 2, 'kudos_id' => null])->assertOk()->assertJsonPath('version', 3)
        ->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', false);
    expect(app(HubFeed::class)->forUser($this->reader)['recognition']['spotlight'])->toBeNull()->and(HrKudos::whereKey($new->id)->exists())->toBeTrue();
    Notification::assertNothingSent();
});

test('spotlight source participants and person picker recheck current staff and approved site membership', function () {
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertOk();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    $this->getJson($this->url)->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', true);
    $this->putJson($this->url, [...$this->payload, 'version' => 2])->assertUnprocessable()->assertJsonValidationErrors('kudos_id');
    $outsideFeed = app(HubFeed::class)->forUser($this->reader->fresh());
    expect($outsideFeed['kudos'])->toBe([])->and($outsideFeed['recognition']['spotlight'])->toBeNull()
        ->and(array_column($outsideFeed['employees'], 'id'))->not->toContain($this->editor->id)
        ->and($outsideFeed['recognitionTeams'])->toBe([]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id, 'end_date' => today()->subDay()]);
    $this->actingAs($this->editor)->getJson($this->url)->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', true);
    expect(array_column(app(HubFeed::class)->forUser($this->editor->fresh())['employees'], 'id'))->not->toContain($this->reader->id);
    $this->reader->hrEmployeeProfile()->update(['end_date' => null]);
    $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->reader->fresh())->getJson($this->url)->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', false);
    $this->editor->hrEmployeeProfile()->update(['end_date' => null]);
    $recognition = app(HubRecognition::class);
    expect($recognition->read($this->editor)['spotlight']['id'])->toBe($this->kudos->id);
    $this->site->update(['archived' => true, 'archived_at' => now()]);
    expect($recognition->read($this->editor)['spotlight'])->toBeNull();
    expect(fn () => $recognition->select($this->editor, [...$this->payload, 'version' => 2]))->toThrow(ValidationException::class);
    Notification::assertNothingSent();
});

test('whole team spotlight keeps canonical typed recipients snapshot reply eligibility and safe team metadata', function () {
    $teamKudos = $this->feed->sendTeamKudos($this->editor, $this->team->id, 'teamwork', 'Thank you to our whole learning team.');
    Notification::fake();
    $this->actingAs($this->editor)->putJson($this->url, [...$this->payload, 'kudos_id' => $teamKudos->id])->assertOk()
        ->assertJsonPath('spotlight.recipient_type', 'team')->assertJsonPath('spotlight.to_id', null)
        ->assertJsonPath('spotlight.to_department_id', $this->team->id)->assertJsonPath('spotlight.team_name', $this->team->name)
        ->assertJsonPath('spotlight.team_ids', [$this->team->id])->assertJsonPath('spotlight.recipient_url', null);
    $this->actingAs($this->reader)->getJson($this->url)->assertOk()->assertJsonPath('spotlight.can_reply', true);
    $feed = app(HubFeed::class)->forUser($this->reader);
    expect($feed['recognitionTeams'])->toBe([['id' => $this->team->id, 'name' => $this->team->name, 'member_count' => 2]])
        ->and(collect($feed['kudos'])->firstWhere('id', $teamKudos->id)['recipient_type'])->toBe('team');
    $lateMember = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $lateMember->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($lateMember, $this->site, ['department_id' => $this->team->id]);
    $this->actingAs($lateMember)->getJson($this->url)->assertOk()->assertJsonPath('spotlight.recipient_type', 'team')->assertJsonPath('spotlight.can_reply', true);
    $this->team->update(['is_active' => false]);
    $this->actingAs($this->editor)->getJson($this->url)->assertOk()->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', true);
    $this->team->delete();
    expect($teamKudos->fresh()->recipient_type)->toBe('team')->and($teamKudos->fresh()->to_department_id)->toBeNull();
    $this->getJson($this->url)->assertOk()->assertJsonPath('spotlight', null)->assertJsonPath('selection_unavailable', true);
    $this->putJson($this->url, [...$this->payload, 'version' => 2, 'kudos_id' => null])->assertOk()->assertJsonPath('selection_unavailable', false);
    $this->assertDatabaseCount('hr_kudos', 2);
    Notification::assertNothingSent();
});

test('migration pending reads remain empty and writes fail safely without creating shared settings', function () {
    $schema = Schema::getFacadeRoot();
    Schema::partialMock()->shouldReceive('hasTable')->andReturnUsing(fn ($table) => $table === 'hub_recognition_spotlight' ? false : $schema->hasTable($table));
    $this->actingAs($this->editor)->getJson($this->url)->assertOk()->assertJsonPath('spotlight', null)->assertJsonPath('can_feature', false)->assertJsonPath('selection_unavailable', false);
    $this->putJson($this->url, $this->payload)->assertStatus(503);
    expect(DB::table('hub_recognition_spotlight')->sole()->kudos_id)->toBeNull()->and((int) DB::table('hub_recognition_spotlight')->sole()->version)->toBe(1);
    Notification::assertNothingSent();
});

test('recognition recipient metadata retains eligible user identities and exposes only public work labels', function () {
    $this->team->sites()->attach($this->site->id);
    $this->reader->update(['email' => 'private-login@company.test']);
    $this->reader->hrEmployeeProfile()->update([
        'position_title' => 'Public work role', 'preferred_name' => 'Optional profile name',
        'bio' => 'Private HR biography', 'work_email' => 'work-contact@company.test', 'date_of_birth' => '2000-01-03',
    ]);
    $legacyIds = (new UserSiteAccessService)->applyStaffScope(app(HrCurrentStaffService::class)->currentUsersQuery(), $this->editor->fresh())
        ->orderBy('name')->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    $recognition = app(HubRecognition::class);
    $recipients = $recognition->recipients($this->editor);
    $expected = ['id' => $this->reader->id, 'name' => $this->reader->name, 'position' => 'Public work role',
        'site' => $this->site->name, 'department' => $this->team->name, 'department_id' => $this->team->id];
    expect(array_column($recipients, 'id'))->toBe($legacyIds)->and(collect($recipients)->firstWhere('id', $this->reader->id))->toBe($expected);
    expect(json_encode($recipients))->not->toContain('private-login@company.test', 'Private HR biography', 'work-contact@company.test', '2000-01-03', 'manager_id', 'primary_site_id', 'secondary_site_ids');
    expect(app(HubFeed::class)->forUser($this->editor)['employees'])->toBe($recipients);
    $this->actingAs($this->editor)->get('/company-hub?view=shoutouts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('employees', fn ($items) => collect($items)->firstWhere('id', $this->reader->id) === $expected));
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'hr.recognition.give')->sole(), ['allowed' => false]);
    expect($recognition->recipients($this->editor))->toBe([])->and(app(HubFeed::class)->forUser($this->editor->fresh())['employees'])->toBe([]);
    Notification::assertNothingSent();
});

test('recognition recipient labels redact inaccessible primary sites and departments while secondary site eligibility and current withdrawals remain canonical', function () {
    $outside = Site::factory()->create(['name' => 'Restricted primary site', 'is_active' => true, 'archived' => false]);
    $privateTeam = HrDepartment::create(['name' => 'Restricted primary department', 'is_active' => true]);
    $privateTeam->sites()->attach($outside->id);
    User::factory()->create(['approved_at' => null]);
    $person = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $person->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($person, $outside, ['secondary_site_ids' => [$this->site->id],
        'department_id' => $privateTeam->id, 'department' => 'Private legacy department label', 'position_title' => 'Public work position']);
    $profile = $person->hrEmployeeProfile()->sole();
    $recognition = app(HubRecognition::class);
    $expected = ['id' => $person->id, 'name' => $person->name, 'position' => 'Public work position', 'site' => null, 'department' => null, 'department_id' => null];
    $recipients = $recognition->recipients($this->editor);
    expect($person->id)->not->toBe($profile->id)->and(collect($recipients)->firstWhere('id', $person->id))->toBe($expected);
    expect(json_encode($recipients))->not->toContain($outside->name, $privateTeam->name, 'Private legacy department label');
    $person->hrEmployeeProfile()->update(['department_id' => $this->team->id]);
    expect(collect($recognition->recipients($this->editor))->firstWhere('id', $person->id)['department'])->toBe($this->team->name);
    $this->team->update(['is_active' => false]);
    expect(collect($recognition->recipients($this->editor))->firstWhere('id', $person->id))->toBe($expected);
    $person->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
    expect(array_column($recognition->recipients($this->editor), 'id'))->not->toContain($person->id);
    $person->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id], 'is_active' => false]);
    expect(array_column($recognition->recipients($this->editor), 'id'))->not->toContain($person->id);
    $person->hrEmployeeProfile()->update(['is_active' => true]);
    expect(array_column($recognition->recipients($this->editor), 'id'))->toContain($person->id);
    $this->site->update(['archived' => true, 'archived_at' => now()]);
    expect($recognition->recipients($this->editor))->toBe([]);
    Notification::assertNothingSent();
});
