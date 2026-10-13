<?php

use App\Domain\Hr\Jobs\PublishDueAnnouncementsJob;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Notifications\AnnouncementPublishedNotification;
use App\Domain\Hr\Notifications\AnnouncementReminderNotification;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\AnnouncementInboxBridge;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-01-18 00:00:00', 'UTC'));
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    $site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->reader, $site);
    $this->payload = ['title' => 'A carefully targeted notice', 'content' => str_repeat('Complete announcement text. ', 18), 'priority' => 'normal', 'intent' => 'draft', 'targets' => [['type' => 'user', 'value' => (string) $this->reader->id]], 'push_to_bell' => false];
});

test('announcement editing loads complete text individual targets and dates only for managers', function () {
    $this->actingAs($this->editor)->post('/hr/announcements', [...$this->payload, 'published_at' => '2027-01-20T10:00:00+13:00', 'ack_deadline' => '2027-01-20T23:59:59+13:00'])->assertRedirect()->assertSessionHasNoErrors();
    $notice = HrAnnouncement::where('title', $this->payload['title'])->firstOrFail();
    $response = $this->getJson('/hr/announcements/'.$notice->id.'/edit-data')->assertOk()
        ->assertJsonPath('content', trim($this->payload['content']))
        ->assertJsonPath('targets.0.type', 'user')->assertJsonPath('targets.0.value', (string) $this->reader->id)
        ->assertJsonPath('published_at', '2027-01-19T21:00:00+00:00')->assertJsonPath('ack_deadline', '2027-01-20T10:59:59+00:00');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs($this->reader)->getJson('/hr/announcements/'.$notice->id.'/edit-data')->assertForbidden();
    Notification::assertNothingSent();
});

test('an offset schedule publishes at the exact instant and Hub staff can read and confirm it', function () {
    $this->actingAs($this->editor)->post('/hr/announcements', [...$this->payload, 'intent' => 'schedule', 'published_at' => '2027-01-20T10:00:00+13:00', 'expires_at' => '2027-01-21T23:59:59+13:00', 'requires_acknowledgement' => true])->assertRedirect()->assertSessionHasNoErrors();
    $notice = HrAnnouncement::where('title', $this->payload['title'])->firstOrFail();
    expect($notice->status)->toBe('scheduled')->and($notice->published_at->toDateTimeString())->toBe('2027-01-19 21:00:00');
    Notification::assertNothingSent();
    $this->actingAs($this->reader)->getJson('/company-hub/announcements/'.$notice->id)->assertNotFound();
    $this->travelTo(Carbon::parse('2027-01-19 20:59:59', 'UTC'));
    (new PublishDueAnnouncementsJob)->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    expect($notice->fresh()->status)->toBe('scheduled');
    $this->travelTo(Carbon::parse('2027-01-19 21:00:00', 'UTC'));
    (new PublishDueAnnouncementsJob)->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
    expect($notice->fresh()->status)->toBe('published');
    Notification::assertSentTo($this->reader, AnnouncementPublishedNotification::class);
    Notification::assertNotSentTo($this->editor, AnnouncementPublishedNotification::class);
    $read = $this->getJson('/company-hub/announcements/'.$notice->id)->assertOk();
    expect($notice->acknowledgements()->count())->toBe(0);
    $this->postJson('/company-hub/announcements/'.$notice->id.'/acknowledge', ['version' => $read->json('version')])->assertOk();
    $this->actingAs($this->editor)->delete('/hr/announcements/'.$notice->id)->assertRedirect();
    $this->actingAs($this->reader)->getJson('/company-hub/announcements/'.$notice->id)->assertNotFound();
});

test('missing past and contradictory schedule dates cannot silently publish', function () {
    $this->actingAs($this->editor);
    foreach ([null, '2027-01-17T23:00:00Z', '2027-01-18T00:00:00Z'] as $time) {
        $this->postJson('/hr/announcements', [...$this->payload, 'intent' => 'schedule', 'published_at' => $time])->assertUnprocessable()->assertJsonValidationErrors('published_at');
    }
    foreach (['expires_at', 'ack_deadline', 'recurrence_ends_at'] as $field) {
        $this->postJson('/hr/announcements', [...$this->payload, 'intent' => 'schedule', 'published_at' => '2027-01-20T10:00:00+13:00', $field => '2027-01-19T23:59:59+13:00'])->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    expect(HrAnnouncement::count())->toBe(0);
    Notification::assertNothingSent();
});

test('editing preserves publication identity and individual audience when target fields are omitted', function () {
    $this->actingAs($this->editor)->post('/hr/announcements', [...$this->payload, 'intent' => 'publish'])->assertRedirect();
    $notice = HrAnnouncement::where('title', $this->payload['title'])->firstOrFail();
    $published = $notice->published_at->toISOString();
    $this->travelTo(now()->addDay());
    $payload = $this->payload;
    unset($payload['targets']);
    $this->put('/hr/announcements/'.$notice->id, [...$payload, 'intent' => 'publish', 'content' => 'Updated complete notice'])->assertRedirect()->assertSessionHasNoErrors();
    expect($notice->fresh()->published_at->toISOString())->toBe($published)
        ->and($notice->targets()->get(['type', 'value'])->toArray())->toBe([['type' => 'user', 'value' => (string) $this->reader->id]]);
});

test('recipient options and notification destinations respect current staff and existing permissions', function () {
    $departed = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($departed);
    $departed->hrEmployeeProfile()->update(['is_active' => false]);
    $response = $this->actingAs($this->editor)->get('/hr/announcements')->assertOk();
    $people = collect($response->inertiaProps('segments.people'));
    expect($people->pluck('key')->all())->toContain((string) $this->reader->id)->not->toContain((string) $departed->id);
    expect(array_keys($people->first()))->toBe(['key', 'label', 'count']);
    $notice = HrAnnouncement::create(['title' => 'Readable', 'content' => 'Notice', 'priority' => 'normal', 'status' => 'published', 'published_at' => now(), 'created_by' => $this->editor->id]);
    foreach ([new AnnouncementPublishedNotification($notice), new AnnouncementReminderNotification($notice)] as $notification) {
        expect($notification->toArray($this->reader)['action_url'])->toBe('/company-hub?view=updates&announcement='.$notice->id)
            ->and($notification->toArray($this->editor)['action_url'])->toBe('/hr/announcements/'.$notice->id);
    }
});
