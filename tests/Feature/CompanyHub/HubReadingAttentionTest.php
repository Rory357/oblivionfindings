<?php

use App\Domain\CompanyHub\Services\HubReadingAttention;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Services\AnnouncementReadState;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $staff = function (string $name): User {
        $user = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'support_worker')->sole());
        ensureCanonicalHrStaffProfile($user, $this->site);

        return $user;
    };
    $this->reader = $staff('Required reading colleague');
    $this->other = $staff('Another current colleague');
    $this->notice = fn (array $attributes = []): HrAnnouncement => HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->other->id, 'target_audience' => 'site', 'target_value' => (string) $this->site->id,
        'status' => 'published', 'published_at' => now()->subHour(), 'is_pinned' => false,
        ...$attributes,
    ]);
    $this->attention = app(HubReadingAttention::class);
    $this->actingAs($this->reader);
});

test('required reading is authoritative beyond feed and chunk limits and independent of search and pins', function () {
    $oldest = ($this->notice)(['title' => 'Oldest required instructions', 'published_at' => now()->subDays(3)]);
    ($this->notice)(['title' => 'Second required instructions', 'published_at' => now()->subDays(2)]);
    HrAnnouncement::factory()->count(51)->requiresAck()->create([
        'created_by' => $this->other->id, 'target_audience' => 'site', 'target_value' => (string) $this->site->id,
        'status' => 'published', 'published_at' => now()->subHour(), 'is_pinned' => true,
    ]);
    $expected = ['pending_count' => 53, 'first' => ['id' => $oldest->id, 'title' => $oldest->title]];

    $this->get('/company-hub')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('company-hub/index')->has('announcements', 10)->where('readingAttention', $expected));
    $this->get('/company-hub?search=no-result-attention-fixture-20261015')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('announcements', 0)->where('readingAttention', $expected));
    expect(DB::table('hr_announcement_acknowledgements')->count())->toBe(0)
        ->and(DB::table('hub_update_seen')->count())->toBe(0)
        ->and(DB::table('hub_reads')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('only this colleagues current version confirmation clears required reading and an edit makes it pending again', function () {
    $notice = ($this->notice)(['title' => 'Current required guidance']);
    $reads = app(AnnouncementReadState::class);
    $reads->confirm($notice, $this->other, $reads->token($notice));
    expect($this->attention->forUser($this->reader))->toBe([
        'pending_count' => 1, 'first' => ['id' => $notice->id, 'title' => $notice->title],
    ]);

    $reads->confirm($notice, $this->reader, $reads->token($notice));
    expect($this->attention->forUser($this->reader))->toBe(['pending_count' => 0, 'first' => null]);
    $notice->update(['title' => 'Revised required guidance']);
    $notice->refresh();
    expect($this->attention->forUser($this->reader))->toBe([
        'pending_count' => 1, 'first' => ['id' => $notice->id, 'title' => $notice->title],
    ])->and($notice->acknowledgementHistory()->count())->toBe(2)
        ->and($notice->acknowledgements()->count())->toBe(0);

    $reads->confirm($notice, $this->reader, $reads->token($notice));
    expect($this->attention->forUser($this->reader))->toBe(['pending_count' => 0, 'first' => null])
        ->and($notice->acknowledgementHistory()->count())->toBe(3)
        ->and(DB::table('hub_update_seen')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('required reading excludes inactive optional and inaccessible audiences using canonical targets', function () {
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $visible = ($this->notice)(['title' => 'Guidance addressed to this colleague', 'target_audience' => 'user', 'target_value' => (string) $this->other->id]);
    $visible->targets()->createMany([
        ['type' => 'site', 'value' => (string) $outside->id],
        ['type' => 'user', 'value' => (string) $this->reader->id],
    ]);
    foreach ([
        ['requires_acknowledgement' => false],
        ['status' => 'draft'],
        ['status' => 'scheduled', 'published_at' => now()->addDay()],
        ['status' => 'published', 'published_at' => now()->addMinute()],
        ['status' => 'archived'],
        ['expires_at' => now()],
        ['target_audience' => 'site', 'target_value' => (string) $outside->id],
        ['target_audience' => 'user', 'target_value' => (string) $this->other->id],
        ['target_audience' => 'unsupported', 'target_value' => 'unavailable'],
    ] as $attributes) {
        ($this->notice)($attributes);
    }
    ($this->notice)(['title' => 'Removed guidance'])->delete();
    $invalid = ($this->notice)(['title' => 'Conflicting audience evidence']);
    $invalid->targets()->createMany([
        ['type' => 'all', 'value' => null],
        ['type' => 'site', 'value' => (string) $this->site->id],
    ]);
    expect($this->attention->forUser($this->reader))->toBe([
        'pending_count' => 1, 'first' => ['id' => $visible->id, 'title' => $visible->title],
    ]);

    $visible->targets()->where('type', 'user')->update(['value' => (string) $this->other->id]);
    expect($this->attention->forUser($this->reader))->toBe(['pending_count' => 0, 'first' => null]);
    Notification::assertNothingSent();
});

test('required reading rechecks retained actor permission current staff and current site audience', function () {
    ($this->notice)();
    $actor = $this->reader->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
    expect($actor->canDo('company_hub.view'))->toBeTrue();
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    expect(fn () => $this->attention->forUser($actor))->toThrow(HttpException::class);
    $this->reader->permissionOverrides()->detach();
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    expect(fn () => $this->attention->forUser($actor))->toThrow(HttpException::class);
    $this->reader->hrEmployeeProfile()->update(['is_active' => true]);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    expect($this->attention->forUser($actor))->toBe(['pending_count' => 0, 'first' => null]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $this->site->update(['is_active' => false]);
    expect($this->attention->forUser($actor))->toBe(['pending_count' => 0, 'first' => null]);
    $this->site->update(['is_active' => true]);
    expect($this->attention->forUser($actor)['pending_count'])->toBe(1);
    Notification::assertNothingSent();
});
