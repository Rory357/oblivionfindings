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
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->reader);
    $this->notice = HrAnnouncement::create(['title' => 'A useful notice', 'content' => 'Please read this company update.', 'status' => 'published', 'published_at' => now()->subHour(), 'requires_acknowledgement' => true, 'target_audience' => 'site', 'target_value' => (string) $this->site->id, 'created_by' => $this->reader->id]);
    Storage::fake('private');
    Storage::disk('private')->put('hub-notice/test.txt', 'Private notice attachment');
    $this->file = HrAnnouncementAttachment::create(['announcement_id' => $this->notice->id, 'disk' => 'private', 'path' => 'hub-notice/test.txt', 'original_name' => 'Notice.txt', 'mime' => 'text/plain', 'size' => 25, 'uploaded_by' => $this->reader->id]);
    $this->url = '/company-hub/announcements/'.$this->notice->id;
});

test('hub only staff can read and explicitly confirm canonical notices without gaining HR management access', function () {
    $this->actingAs($this->reader);
    $this->get('/hr/announcements/'.$this->notice->id)->assertForbidden();
    $read = $this->getJson($this->url)->assertOk()->assertJsonPath('acknowledged_at', null)->assertJsonPath('attachments.0.url', '/company-hub/announcement-files/'.$this->file->id)->assertJsonMissingPath('tracking')->assertJsonMissingPath('targets');
    expect($read->headers->get('Cache-Control'))->toContain('no-store')->and($this->notice->acknowledgements()->count())->toBe(0);
    $file = $this->get('/company-hub/announcement-files/'.$this->file->id)->assertOk();
    expect($file->headers->get('Cache-Control'))->toContain('no-store');
    $this->postJson($this->url.'/acknowledge', ['version' => $read->json('version'), 'user_id' => 999])->assertOk();
    $this->postJson($this->url.'/acknowledge', ['version' => $read->json('version')])->assertOk();
    expect($this->notice->acknowledgements()->count())->toBe(1)->and($this->notice->acknowledgements()->first()->user_id)->toBe($this->reader->id);
    $this->putJson('/hr/announcements/'.$this->notice->id, ['title' => 'Not permitted'])->assertForbidden();
});

test('notice changes prevent stale read confirmation and withdrawal removes reader and file access', function () {
    $this->actingAs($this->reader);
    $version = $this->getJson($this->url)->assertOk()->json('version');
    $this->notice->update(['content' => 'Changed before acknowledgement']);
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertConflict();
    expect($this->notice->acknowledgements()->count())->toBe(0);
    $version = $this->getJson($this->url)->assertOk()->json('version');
    $this->notice->update(['status' => 'archived']);
    $this->getJson($this->url)->assertNotFound();
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertNotFound();
    $this->get('/company-hub/announcement-files/'.$this->file->id)->assertNotFound();
});

test('notice access rechecks dates current staff target audience and Hub permission for direct reads and confirmations', function () {
    $this->actingAs($this->reader);
    $version = $this->getJson($this->url)->assertOk()->json('version');
    foreach ([['status' => 'draft'], ['status' => 'scheduled'], ['published_at' => now()->addDay()], ['expires_at' => now()->subSecond()], ['target_value' => (string) Site::factory()->create(['is_active' => true])->id]] as $change) {
        $this->notice->update(['status' => 'published', 'published_at' => now()->subHour(), 'expires_at' => null, 'target_value' => (string) $this->site->id, ...$change]);
        $this->getJson($this->url)->assertNotFound();
        $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertNotFound();
        $this->get('/company-hub/announcement-files/'.$this->file->id)->assertNotFound();
    }
    $this->notice->update(['target_value' => (string) $this->site->id, 'requires_acknowledgement' => false]);
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertUnprocessable();
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->actingAs($this->reader->fresh())->getJson($this->url)->assertForbidden();
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    $this->actingAs($this->reader->fresh())->getJson($this->url)->assertForbidden();
});
