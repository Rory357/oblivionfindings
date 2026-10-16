<?php

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAttachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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
    $this->reader = $staff('Announcement reader A');
    $this->other = $staff('Announcement reader B');
    $this->notice = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->other->id, 'status' => 'published', 'published_at' => now()->subHour(),
        'content' => 'Read and confirm this exact shared announcement.',
        'target_audience' => 'site', 'target_value' => (string) $this->site->id,
    ]);
    Storage::fake('private');
    Storage::disk('private')->put('announcement-actor/guide.txt', 'Current authorised announcement file');
    $this->file = HrAnnouncementAttachment::create([
        'announcement_id' => $this->notice->id, 'disk' => 'private', 'path' => 'announcement-actor/guide.txt',
        'original_name' => 'Guide.txt', 'mime' => 'text/plain', 'size' => 37, 'uploaded_by' => $this->other->id,
    ]);
    $this->url = '/company-hub/announcements/'.$this->notice->id;
});

test('Hub announcement actor context and receipt confirm the exact current edition once', function () {
    $read = $this->actingAs($this->reader)->getJson($this->url)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->reader->id)->assertJsonPath('id', $this->notice->id)
        ->assertJsonPath('acknowledged_at', null)->assertJsonMissingPath('saved')
        ->assertJsonMissingPath('targets')->assertJsonMissingPath('tracking');
    expect($this->notice->acknowledgementHistory()->count())->toBe(0);
    $payload = ['actor_id' => $read->json('actor_id'), 'version' => $read->json('version')];
    $saved = $this->postJson($this->url.'/acknowledge', [...$payload, 'user_id' => $this->other->id])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('saved', true)->assertJsonPath('actor_id', $this->reader->id)
        ->assertJsonPath('id', $this->notice->id)->assertJsonPath('version', $payload['version'])
        ->assertJsonPath('read_version', $read->json('read_version'));
    expect($saved->json('acknowledged_at'))->not->toBeNull();
    $receipt = $this->notice->acknowledgements()->sole();
    $this->postJson($this->url.'/acknowledge', $payload)->assertOk()
        ->assertJsonPath('saved', true)->assertJsonPath('acknowledged_at', $saved->json('acknowledged_at'));
    expect($this->notice->acknowledgementHistory()->count())->toBe(1)
        ->and($receipt->user_id)->toBe($this->reader->id)->and($receipt->read_version)->toBe($read->json('read_version'));
    $this->actingAs($this->other)->getJson($this->url)->assertOk()
        ->assertJsonPath('actor_id', $this->other->id)->assertJsonPath('acknowledged_at', null)
        ->assertJsonPath('previously_acknowledged', false);
    Notification::assertNothingSent();
});

test('Hub announcements reject an old account tab when the replacement actor can read the same version', function () {
    $read = $this->actingAs($this->reader)->getJson($this->url)->assertOk();
    $current = $this->actingAs($this->other)->getJson($this->url)->assertOk()
        ->assertJsonPath('actor_id', $this->other->id)->assertJsonPath('version', $read->json('version'));
    $this->postJson($this->url.'/acknowledge', ['actor_id' => $read->json('actor_id'), 'version' => $read->json('version')])
        ->assertConflict()->assertJsonPath('message', 'Your signed-in account changed. Reload this announcement before confirming it.');
    expect($this->notice->acknowledgementHistory()->count())->toBe(0);
    $this->postJson($this->url.'/acknowledge', ['actor_id' => $current->json('actor_id'), 'version' => $current->json('version')])
        ->assertOk()->assertJsonPath('actor_id', $this->other->id)->assertJsonPath('saved', true);
    expect($this->notice->acknowledgements()->sole()->user_id)->toBe($this->other->id);
    $this->actingAs($this->reader)->getJson($this->url)->assertOk()->assertJsonPath('acknowledged_at', null);
    Notification::assertNothingSent();
});

test('Hub announcements require valid expected actor provenance without recording any confirmation', function () {
    $version = $this->actingAs($this->reader)->getJson($this->url)->assertOk()->json('version');
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    foreach ([null, 0, -1, 'unknown', [], 2147483648] as $actor) {
        $this->postJson($this->url.'/acknowledge', ['actor_id' => $actor, 'version' => $version])
            ->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    }
    $this->postJson($this->url.'/acknowledge', ['actor_id' => $this->reader->id])->assertUnprocessable()->assertJsonValidationErrors('version');
    $this->postJson($this->url.'/acknowledge', ['actor_id' => $this->reader->id, 'version' => str_repeat('0', 64)])->assertConflict();
    expect($this->notice->acknowledgementHistory()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('Hub announcement adapter rechecks withdrawn current actor access instead of cached request permissions', function () {
    $version = $this->actingAs($this->reader)->getJson($this->url)->assertOk()->json('version');
    $this->reader->load(['permissionOverrides', 'roles.permissions', 'hrEmployeeProfile']);
    expect($this->reader->canDo('company_hub.view'))->toBeTrue();
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    expect($this->reader->canDo('company_hub.view'))->toBeTrue();
    $this->actingAs($this->reader)->getJson($this->url)->assertForbidden();
    $this->get('/company-hub/announcement-files/'.$this->file->id)->assertForbidden();
    $this->postJson($this->url.'/acknowledge', ['actor_id' => $this->reader->id, 'version' => $version])->assertForbidden();
    $this->reader->permissionOverrides()->detach();
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    $this->get('/company-hub/announcement-files/'.$this->file->id)->assertForbidden();
    $this->postJson($this->url.'/acknowledge', ['actor_id' => $this->reader->id, 'version' => $version])->assertForbidden();
    expect($this->notice->acknowledgementHistory()->count())->toBe(0);
    Notification::assertNothingSent();
});
