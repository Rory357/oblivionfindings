<?php

use App\Domain\CompanyHub\Services\HubCelebrations;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\Hr\Services\FeedService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $staff = function (string $name, string $role = 'support_worker', ?Site $site = null): User {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($user, $site ?? $this->site);

        return $user;
    };
    $this->editor = $staff('Birthday editor A', 'hr');
    $this->otherEditor = $staff('Birthday editor B', 'hr');
    $this->person = $staff('Current visible colleague');
    $this->privatePerson = $staff('Other site private colleague', site: $this->outside);
    $this->person->hrEmployeeProfile->update(['date_of_birth' => now()->subYears(30)->format('Y-m-d')]);
    $this->celebrations = app(HubCelebrations::class);
    $this->url = '/company-hub/celebrations/'.$this->person->id;
    $this->payload = ['actor_id' => $this->editor->id, 'version' => 1, 'birthday_enabled' => true];
    $this->snapshot = fn () => [
        'settings' => DB::table('hub_celebration_settings')->orderBy('user_id')->get()->toArray(),
        'audits' => AuditLog::where('action', 'company_hub.birthday_visibility')->orderBy('id')->get()->toArray(),
    ];
});

test('birthday preference reads do not create defaults or disclose birth dates and feed context is actor bound', function () {
    $this->actingAs($this->editor)->getJson('/company-hub/celebrations')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('ready', true)
        ->assertJsonPath('actor_id', $this->editor->id);
    $context = $this->celebrations->read($this->editor);
    $person = collect($context['people'])->firstWhere('id', $this->person->id);
    expect($person)->toBe(['id' => $this->person->id, 'name' => $this->person->name, 'enabled' => false, 'version' => 1])
        ->and(array_column($context['people'], 'id'))->not->toContain($this->privatePerson->id)
        ->and(json_encode($context))->not->toContain('date_of_birth', 'age', 'year', 'updated_by')
        ->and(DB::table('hub_celebration_settings')->count())->toBe(0);
    $feed = app(HubFeed::class)->forUser($this->editor);
    expect($feed['birthdaySettings'])->toBe($context['people'])
        ->and($feed['birthdayPreferenceContext'])->toBe(['ready' => true, 'actor_id' => $this->editor->id])
        ->and(app(HubFeed::class)->forUser($this->person)['birthdaySettings'])->toBe([])
        ->and(collect(app(FeedService::class)->getMilestones()['birthdays'])->pluck('user_id')->all())->not->toContain($this->person->id);
    Notification::assertNothingSent();
});

test('birthday preference acknowledgements save each exact choice and retain off by default milestone privacy', function () {
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertExactJson([
            'saved' => true, 'ready' => true, 'actor_id' => $this->editor->id,
            'person' => ['id' => $this->person->id, 'name' => $this->person->name, 'enabled' => true, 'version' => 2],
        ]);
    $createdAt = DB::table('hub_celebration_settings')->where('user_id', $this->person->id)->value('created_at');
    $birthday = collect(app(FeedService::class)->getMilestones()['birthdays'])->firstWhere('user_id', $this->person->id);
    expect($birthday)->not->toBeNull()->and(array_keys($birthday))->not->toContain('date_of_birth', 'year', 'age')
        ->and($birthday['date'])->toBe(now()->format('M d'));
    $this->putJson($this->url, [...$this->payload, 'version' => 2, 'birthday_enabled' => false])->assertOk()
        ->assertJsonPath('person.enabled', false)->assertJsonPath('person.version', 3);
    $this->putJson($this->url, [...$this->payload, 'version' => 3, 'birthday_enabled' => false])->assertOk()
        ->assertJsonPath('person.version', 4);
    $row = DB::table('hub_celebration_settings')->where('user_id', $this->person->id)->first();
    $auditMeta = AuditLog::where('action', 'company_hub.birthday_visibility')->latest('id')->first()->meta;
    ksort($auditMeta);
    expect((bool) $row->birthday_enabled)->toBeFalse()->and($row->created_at)->toBe($createdAt)
        ->and((int) $row->updated_by)->toBe($this->editor->id)
        ->and($auditMeta)
        ->toBe(['enabled' => false, 'previous_version' => 3, 'version' => 4])
        ->and(collect(app(FeedService::class)->getMilestones()['birthdays'])->pluck('user_id')->all())->not->toContain($this->person->id);
    Notification::assertNothingSent();
});

test('birthday preference saves reject an old account even when both editors opened the same version', function () {
    $opened = $this->celebrations->read($this->editor);
    expect(collect($opened['people'])->firstWhere('id', $this->person->id)['version'])->toBe(1);
    $before = ($this->snapshot)();
    $this->actingAs($this->otherEditor)->putJson($this->url, $this->payload)->assertConflict()
        ->assertJsonPath('message', 'Your signed-in account changed. Reopen birthday sharing before saving.');
    $this->getJson('/company-hub/celebrations?actor_id='.$this->editor->id)->assertConflict()->assertJsonMissingPath('people');
    expect(fn () => $this->celebrations->save($this->otherEditor, $this->person->id, $this->payload, true))->toThrow(HttpException::class)
        ->and(($this->snapshot)())->toEqual($before);
    $this->getJson('/company-hub/celebrations')->assertJsonPath('actor_id', $this->otherEditor->id);
});

test('birthday preference versions protect against competing editors without changing a confirmed choice on denial', function () {
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertOk();
    $before = ($this->snapshot)();
    $other = ['actor_id' => $this->otherEditor->id, 'version' => 1, 'birthday_enabled' => false];
    $this->actingAs($this->otherEditor)->putJson($this->url, $other)->assertConflict();
    expect(($this->snapshot)())->toEqual($before);
    $this->putJson($this->url, [...$other, 'version' => 2])->assertOk()->assertJsonPath('person.version', 3);
    $before = ($this->snapshot)();
    $this->actingAs($this->editor)->putJson($this->url, [...$this->payload, 'version' => 2])->assertConflict();
    expect(($this->snapshot)())->toEqual($before)
        ->and(collect($this->celebrations->read($this->editor)['people'])->firstWhere('id', $this->person->id))
        ->toMatchArray(['enabled' => false, 'version' => 3]);
});

test('birthday guarded saves require provenance and reject spoofed targets or private birth data without a write', function () {
    $this->actingAs($this->editor);
    $before = ($this->snapshot)();
    foreach (['actor_id', 'version'] as $field) {
        $this->putJson($this->url, array_diff_key($this->payload, [$field => true]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    foreach (['user_id' => $this->privatePerson->id, 'person_id' => $this->privatePerson->id,
        'updated_by' => $this->otherEditor->id, 'date_of_birth' => '1990-01-01', 'age' => 36, 'year' => 1990] as $field => $value) {
        $this->putJson($this->url, [...$this->payload, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->putJson($this->url, [...$this->payload, 'birthday_enabled' => 'false'])->assertUnprocessable();
    expect(($this->snapshot)())->toEqual($before);
});

test('birthday settings deny readers and recheck current actor staff sites and permission after opening', function () {
    $before = ($this->snapshot)();
    $this->actingAs($this->person)->getJson('/company-hub/celebrations')->assertForbidden();
    $this->putJson($this->url, [...$this->payload, 'actor_id' => $this->person->id])->assertForbidden();
    $this->celebrations->read($this->editor);
    $this->actingAs($this->editor);
    $this->editor->hrEmployeeProfile()->update(['is_active' => false]);
    $this->getJson('/company-hub/celebrations')->assertForbidden();
    $this->putJson($this->url, $this->payload)->assertForbidden();
    $this->editor->hrEmployeeProfile()->update(['is_active' => true]);
    $this->site->update(['is_active' => false]);
    $this->getJson('/company-hub/celebrations')->assertForbidden();
    $this->putJson($this->url, $this->payload)->assertForbidden();
    $this->site->update(['is_active' => true]);
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'company_hub.celebrations')->value('id'), ['allowed' => false]);
    $this->getJson('/company-hub/celebrations')->assertForbidden();
    $this->putJson($this->url, $this->payload)->assertForbidden();
    expect(fn () => $this->celebrations->read($this->editor))->toThrow(HttpException::class)
        ->and(($this->snapshot)())->toEqual($before);
});

test('birthday targets remain current visible staff and private or withdrawn people cannot be updated', function () {
    $before = ($this->snapshot)();
    $this->actingAs($this->editor)->putJson('/company-hub/celebrations/'.$this->privatePerson->id, $this->payload)->assertNotFound();
    $this->person->hrEmployeeProfile()->update(['is_active' => false]);
    $this->putJson($this->url, $this->payload)->assertNotFound();
    $this->person->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => $this->outside->id]);
    $this->putJson($this->url, $this->payload)->assertNotFound();
    $this->person->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $this->person->update(['approved_at' => null]);
    $this->putJson($this->url, $this->payload)->assertNotFound();
    $this->person->update(['approved_at' => now(), 'role' => 'client']);
    $this->putJson($this->url, $this->payload)->assertNotFound();
    $this->person->delete();
    $this->putJson($this->url, $this->payload)->assertNotFound();
    expect(($this->snapshot)())->toEqual($before);
});

test('legacy birthday saves keep redirects and invalidate guarded editors while supplied context is still checked', function () {
    $this->actingAs($this->editor)->put($this->url, ['birthday_enabled' => true])->assertRedirect();
    $createdAt = DB::table('hub_celebration_settings')->where('user_id', $this->person->id)->value('created_at');
    $this->putJson($this->url, $this->payload)->assertConflict();
    $this->put($this->url, ['birthday_enabled' => false])->assertRedirect();
    expect(collect($this->celebrations->read($this->editor)['people'])->firstWhere('id', $this->person->id))
        ->toMatchArray(['enabled' => false, 'version' => 3])
        ->and(DB::table('hub_celebration_settings')->where('user_id', $this->person->id)->value('created_at'))->toBe($createdAt);
    $before = ($this->snapshot)();
    $this->put($this->url, [...$this->payload, 'actor_id' => $this->otherEditor->id, 'version' => 3])->assertConflict();
    $this->put($this->url, [...$this->payload, 'version' => 2])->assertConflict();
    expect(($this->snapshot)())->toEqual($before);
});

test('birthday schema pending reads retain saved choices and guarded writes fail without changing settings', function () {
    $this->actingAs($this->editor)->putJson($this->url, $this->payload)->assertOk();
    $before = ($this->snapshot)();
    $original = Schema::getFacadeRoot();
    $pending = Mockery::mock(get_class($original), [$original->getConnection()])->makePartial();
    $pending->shouldReceive('hasColumn')->with('hub_celebration_settings', 'version')->andReturn(false);
    Schema::swap($pending);
    try {
        $response = $this->getJson('/company-hub/celebrations')->assertOk()->assertJsonPath('ready', false);
        expect(collect($response->json('people'))->firstWhere('id', $this->person->id))
            ->toMatchArray(['enabled' => true, 'version' => null]);
        $feed = app(HubFeed::class)->forUser($this->editor);
        expect($feed['birthdayPreferenceContext'])->toBe(['ready' => false, 'actor_id' => $this->editor->id])
            ->and(collect($feed['birthdaySettings'])->firstWhere('id', $this->person->id))
            ->toMatchArray(['enabled' => true, 'version' => null]);
        $this->putJson($this->url, [...$this->payload, 'version' => 2, 'birthday_enabled' => false])->assertStatus(503);
        $this->putJson('/company-hub/celebrations/'.$this->privatePerson->id, $this->payload)->assertNotFound();
        expect(($this->snapshot)())->toEqual($before);
        $this->put($this->url, ['birthday_enabled' => false])->assertRedirect();
    } finally {
        Schema::swap($original);
    }
    expect((bool) DB::table('hub_celebration_settings')->where('user_id', $this->person->id)->value('birthday_enabled'))->toBeFalse();
});

test('birthday setting and audit changes roll back together if recording the audit cannot complete', function () {
    $before = ($this->snapshot)();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with(strtolower($query->sql), 'insert into `audit_logs`')
            && in_array('company_hub.birthday_visibility', $query->bindings, true)) {
            throw new RuntimeException('Birthday audit fixture rejected the transaction.');
        }
    });
    expect(fn () => $this->celebrations->save($this->editor, $this->person->id, $this->payload, true))
        ->toThrow(RuntimeException::class, 'Birthday audit fixture rejected the transaction.')
        ->and(($this->snapshot)())->toEqual($before);
    Notification::assertNothingSent();
});
