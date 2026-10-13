<?php

use App\Domain\Hr\Jobs\PublishDueAnnouncementsJob;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\AnnouncementInboxBridge;
use App\Domain\Hr\Services\AnnouncementReadState;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    Notification::fake();
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    $site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->reader, $site);
    $this->notice = HrAnnouncement::factory()->requiresAck()->create(['created_by' => $this->editor->id, 'status' => 'published', 'published_at' => now()->subHour(), 'content' => 'First version', 'target_audience' => 'all']);
    $this->url = '/company-hub/announcements/'.$this->notice->id;
});

test('editing resets current confirmation while retaining exact earlier content and receipt history', function () {
    $this->actingAs($this->reader);
    $version = $this->getJson($this->url)->assertOk()->json('version');
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertOk();
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertOk();
    expect($this->notice->acknowledgementHistory()->count())->toBe(1);
    $this->notice->update(['is_pinned' => true]);
    expect($this->getJson($this->url)->json('version'))->toBe($version);
    $this->notice->update(['content' => 'Second version']);
    $read = $this->getJson($this->url)->assertOk()->assertJsonPath('acknowledged_at', null);
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertConflict();
    expect($this->notice->acknowledgements()->count())->toBe(0);
    $snapshot = json_decode(DB::table('hr_announcement_read_versions')->where('announcement_id', $this->notice->id)->where('read_version', 1)->value('content'), true);
    expect($snapshot['content'])->toBe('First version');
    $this->actingAs($this->editor)->getJson('/hr/announcements/'.$this->notice->id.'/tracking')->assertOk()->assertJsonPath('acknowledged', 0)->assertJsonPath('history.0.version', 1)->assertJsonPath('history.0.name', $this->reader->name);
    $this->actingAs($this->reader)->postJson($this->url.'/acknowledge', ['version' => $read->json('version')])->assertOk();
    expect($this->notice->acknowledgements()->count())->toBe(1)->and($this->notice->acknowledgementHistory()->count())->toBe(2);
    expect(HrAnnouncement::withCount('acknowledgements')->find($this->notice->id)->acknowledgements_count)->toBe(1);
});

test('attachment changes require a fresh read and unversioned historical receipts are retained without false confirmation', function () {
    DB::table('hr_announcement_acknowledgements')->insert(['announcement_id' => $this->notice->id, 'user_id' => $this->reader->id, 'read_version' => 0, 'acknowledged_at' => now()]);
    $this->actingAs($this->reader);
    $version = $this->getJson($this->url)->assertOk()->assertJsonPath('acknowledged_at', null)->json('version');
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertOk();
    $file = $this->notice->attachments()->create(['disk' => 'private', 'path' => 'read-version.pdf', 'original_name' => 'Read this.pdf', 'mime' => 'application/pdf', 'size' => 42, 'uploaded_by' => $this->editor->id]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('acknowledged_at', null);
    $this->postJson($this->url.'/acknowledge', ['version' => $version])->assertConflict();
    $file->delete();
    expect($this->notice->acknowledgementHistory()->count())->toBe(2)->and($this->notice->acknowledgements()->count())->toBe(0);
});

test('canonical acknowledgements require an active audience and the exact version including manager overrides', function () {
    $this->actingAs($this->editor);
    $this->get('/hr/announcements/'.$this->notice->id)->assertInertia(fn (Assert $page) => $page->where('can.confirm', true));
    $version = app(AnnouncementReadState::class)->token($this->notice->fresh());
    $this->postJson('/hr/announcements/'.$this->notice->id.'/acknowledge')->assertUnprocessable();
    $this->post('/hr/announcements/'.$this->notice->id.'/acknowledge', ['version' => $version])->assertRedirect();
    $this->notice->update(['content' => 'Changed again']);
    $this->postJson('/hr/announcements/'.$this->notice->id.'/acknowledge-for', ['user_id' => $this->reader->id, 'version' => $version])->assertConflict();
    $version = app(AnnouncementReadState::class)->token($this->notice->fresh());
    $this->post('/hr/announcements/'.$this->notice->id.'/acknowledge-for', ['user_id' => $this->reader->id, 'version' => $version])->assertRedirect();
    expect($this->notice->acknowledgements()->first()->acknowledged_by)->toBe($this->editor->id);
    foreach (['draft', 'scheduled', 'archived'] as $status) {
        $this->notice->update(['status' => $status]);
        $this->get('/hr/announcements/'.$this->notice->id)->assertInertia(fn (Assert $page) => $page->where('can.confirm', false));
        $this->postJson('/hr/announcements/'.$this->notice->id.'/acknowledge', ['version' => $version])->assertNotFound();
        $this->postJson('/hr/announcements/'.$this->notice->id.'/acknowledge-for', ['user_id' => $this->reader->id, 'version' => $version])->assertNotFound();
    }
});

test('weekly publication retains NZ wall time deadlines files audience and bell opt out without duplicate delivery', function () {
    $this->travelTo(Carbon::parse('2027-09-20 10:00:00', 'Pacific/Auckland')->utc());
    $this->notice->update(['status' => 'scheduled', 'published_at' => now()->utc(), 'expires_at' => Carbon::parse('2027-09-21 23:59:59', 'Pacific/Auckland')->utc(), 'ack_deadline' => Carbon::parse('2027-09-21 23:59:59', 'Pacific/Auckland')->utc(), 'recurrence' => 'weekly', 'recurrence_ends_at' => Carbon::parse('2027-09-28 23:59:59', 'Pacific/Auckland')->utc(), 'push_to_bell' => false]);
    expect($this->notice->fresh()->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-09-20 10:00');
    $this->notice->targets()->create(['type' => 'user', 'value' => (string) $this->reader->id]);
    $this->notice->attachments()->create(['disk' => 'private', 'path' => 'weekly.pdf', 'original_name' => 'Weekly.pdf', 'mime' => 'application/pdf', 'size' => 42, 'uploaded_by' => $this->editor->id]);
    $job = new PublishDueAnnouncementsJob;
    $job->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    $job->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    $next = HrAnnouncement::where('recurrence_source_id', $this->notice->id)->sole();
    expect($next->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-09-27 10:00')
        ->and($next->published_at->format('Y-m-d H:i'))->toBe('2027-09-26 21:00')
        ->and($next->expires_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i:s'))->toBe('2027-09-28 23:59:59')
        ->and($next->ack_deadline->eq($next->expires_at))->toBeTrue()
        ->and($next->push_to_bell)->toBeFalse()->and($next->attachments()->sole()->path)->toBe('weekly.pdf')
        ->and($next->targets()->sole()->value)->toBe((string) $this->reader->id)
        ->and($this->notice->fresh()->inbox_announcement_id)->toBeNull();
    Notification::assertCount(1);
    $this->travelTo($next->published_at);
    $job->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    expect(HrAnnouncement::where('recurrence_source_id', $next->id)->count())->toBe(0);
});

test('monthly recurrences keep the original month end and expired backlogs do not notify', function () {
    $this->travelTo(Carbon::parse('2027-01-31 10:00:00', 'Pacific/Auckland')->utc());
    $this->notice->update(['status' => 'scheduled', 'published_at' => now()->utc(), 'recurrence' => 'monthly', 'expires_at' => null]);
    $job = new PublishDueAnnouncementsJob;
    $job->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    $february = HrAnnouncement::where('recurrence_source_id', $this->notice->id)->sole();
    expect($february->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-02-28 10:00');
    $this->travelTo($february->published_at);
    $job->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    $march = HrAnnouncement::where('recurrence_source_id', $february->id)->sole();
    expect($march->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-03-31 10:00');
    $march->update(['expires_at' => $march->published_at->copy()->addDay()]);
    $this->travelTo($march->published_at->copy()->addDays(2));
    Notification::fake();
    $job->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    expect($march->fresh()->status)->toBe('archived');
    Notification::assertNothingSent();
});

test('immediate publication schedules once and preserves its bell preference when reopened or restored', function () {
    $this->travelTo(Carbon::parse('2027-01-18 10:00:00', 'Pacific/Auckland')->utc());
    $payload = ['title' => 'Immediate recurring notice', 'content' => 'Weekly update', 'priority' => 'urgent', 'intent' => 'publish', 'targets' => [['type' => 'all', 'value' => null]], 'recurrence' => 'weekly', 'push_to_bell' => false];
    $this->actingAs($this->editor)->post('/hr/announcements', $payload)->assertRedirect()->assertSessionHasNoErrors();
    $notice = HrAnnouncement::where('title', $payload['title'])->whereNull('recurrence_source_id')->sole();
    $next = HrAnnouncement::where('recurrence_source_id', $notice->id)->sole();
    $this->getJson('/hr/announcements/'.$notice->id.'/edit-data')->assertOk()->assertJsonPath('push_to_bell', false);
    $this->put('/hr/announcements/'.$notice->id, $payload)->assertRedirect();
    $this->post('/hr/announcements/'.$notice->id.'/publish')->assertRedirect();
    expect(HrAnnouncement::where('recurrence_source_id', $notice->id)->count())->toBe(1)
        ->and($notice->fresh()->inbox_announcement_id)->toBeNull()->and($next->push_to_bell)->toBeFalse();
});

test('a weekly repeat in the skipped clock hour returns to the original time after the transition', function () {
    $this->travelTo(Carbon::parse('2027-09-19 02:30:00', 'Pacific/Auckland')->utc());
    $this->notice->update(['status' => 'scheduled', 'published_at' => now()->utc(), 'recurrence' => 'weekly', 'expires_at' => null]);
    $job = new PublishDueAnnouncementsJob;
    $job->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    $transition = HrAnnouncement::where('recurrence_source_id', $this->notice->id)->sole();
    expect($transition->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-09-26 03:30');
    $this->travelTo($transition->published_at);
    $job->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    $next = HrAnnouncement::where('recurrence_source_id', $transition->id)->sole();
    expect($next->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-10-03 02:30');
});
