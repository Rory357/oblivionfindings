<?php

use App\Domain\Hr\Jobs\PublishDueAnnouncementsJob;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAcknowledgement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\AnnouncementPublishedNotification;
use App\Domain\Hr\Notifications\AnnouncementReminderNotification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'organization_id' => 1, 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);

    $this->worker = User::factory()->create(['role' => 'support_worker', 'organization_id' => 1, 'approved_at' => now()]);
    $this->coordinator = User::factory()->create(['role' => 'coordinator', 'organization_id' => 1, 'approved_at' => now()]);

    foreach ([[$this->worker, 'support_worker', 'ANN-1'], [$this->coordinator, 'coordinator', 'ANN-2']] as [$user, $roleKey, $num]) {
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => $num,
            'position_role' => $roleKey,
            'is_active' => true,
            'created_by' => $this->hr->id,
            'updated_by' => $this->hr->id,
        ]);
    }
});

test('multi-segment store persists targets and notifies only the matched audience', function () {
    Notification::fake();

    $this->actingAs($this->hr)->post('/hr/announcements', [
        'title' => 'Nurses + caregivers notice',
        'content' => 'Please read.',
        'priority' => 'high',
        'intent' => 'publish',
        'targets' => [['type' => 'role', 'value' => 'support_worker']],
        'requires_acknowledgement' => true,
    ])->assertRedirect(route('hr.announcements.index'));

    $announcement = HrAnnouncement::firstWhere('title', 'Nurses + caregivers notice');
    expect($announcement)->not->toBeNull();
    expect($announcement->status)->toBe('published');
    expect($announcement->targets()->where('type', 'role')->where('value', 'support_worker')->exists())->toBeTrue();

    Notification::assertSentTo($this->worker, AnnouncementPublishedNotification::class);
    Notification::assertNotSentTo($this->coordinator, AnnouncementPublishedNotification::class);
});

test('scheduling in the future stores a scheduled status and does not notify yet', function () {
    Notification::fake();

    $this->actingAs($this->hr)->post('/hr/announcements', [
        'title' => 'Future notice',
        'content' => 'Later.',
        'priority' => 'normal',
        'intent' => 'schedule',
        'published_at' => now()->addDays(2)->toDateTimeString(),
        'targets' => [['type' => 'all', 'value' => null]],
    ]);

    $a = HrAnnouncement::firstWhere('title', 'Future notice');
    expect($a->status)->toBe('scheduled');
    Notification::assertNothingSent();
});

test('the scheduled-publish job fires due notices and notifies the audience', function () {
    Notification::fake();

    $a = HrAnnouncement::factory()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'status' => 'scheduled',
        'published_at' => now()->subMinute(),
        'requires_acknowledgement' => true,
    ]);
    $a->targets()->create(['type' => 'all', 'value' => null]);

    (new PublishDueAnnouncementsJob)->handle(
        app(\App\Domain\Hr\Services\AnnouncementAudienceResolver::class),
        app(\App\Domain\Hr\Services\AnnouncementInboxBridge::class),
    );

    expect($a->fresh()->status)->toBe('published');
    Notification::assertSentTo($this->worker, AnnouncementPublishedNotification::class);
});

test('publish bridges to the header-bell inbox announcement', function () {
    Notification::fake();

    $this->actingAs($this->hr)->post('/hr/announcements', [
        'title' => 'Bridged notice',
        'content' => 'Goes to the bell.',
        'priority' => 'urgent',
        'intent' => 'publish',
        'targets' => [['type' => 'all', 'value' => null]],
        'push_to_bell' => true,
    ]);

    $a = HrAnnouncement::firstWhere('title', 'Bridged notice');
    expect($a->inbox_announcement_id)->not->toBeNull();
    $this->assertDatabaseHas('announcements', ['id' => $a->inbox_announcement_id, 'title' => 'Bridged notice', 'is_active' => true]);
});

test('the preview endpoint returns a recipient count for in-progress targets', function () {
    $this->actingAs($this->hr)
        ->getJson('/hr/announcements/preview?targets='.urlencode(json_encode([['type' => 'role', 'value' => 'support_worker']])))
        ->assertOk()
        ->assertJson(['count' => 1]);
});

test('reminders go only to outstanding recipients and respect the cooldown', function () {
    Notification::fake();

    $a = HrAnnouncement::factory()->requiresAck()->create([
        'tenant_id' => 1, 'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()->subDay(),
    ]);
    $a->targets()->create(['type' => 'all', 'value' => null]);
    // Worker already acknowledged → should NOT be reminded.
    HrAnnouncementAcknowledgement::create(['announcement_id' => $a->id, 'user_id' => $this->worker->id, 'acknowledged_at' => now()]);

    $this->actingAs($this->hr)->post("/hr/announcements/{$a->id}/remind")->assertRedirect();

    Notification::assertSentTo($this->coordinator, AnnouncementReminderNotification::class);
    Notification::assertNotSentTo($this->worker, AnnouncementReminderNotification::class);
    expect($a->reminders()->count())->toBe(1);

    // Second call within cooldown sends nothing more.
    $this->actingAs($this->hr)->post("/hr/announcements/{$a->id}/remind");
    expect($a->reminders()->count())->toBe(1);
});

test('the tracking endpoint returns a roster split by ack status', function () {
    $a = HrAnnouncement::factory()->requiresAck()->create([
        'tenant_id' => 1, 'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()->subDay(),
    ]);
    $a->targets()->create(['type' => 'all', 'value' => null]);
    HrAnnouncementAcknowledgement::create(['announcement_id' => $a->id, 'user_id' => $this->worker->id, 'acknowledged_at' => now()]);

    $data = $this->actingAs($this->hr)->getJson("/hr/announcements/{$a->id}/tracking")->assertOk()->json();
    expect($data['acknowledged'])->toBe(1);
    expect($data['outstanding'])->toBe(1);
    expect(collect($data['roster'])->firstWhere('id', $this->worker->id)['status'])->toBe('acknowledged');
});

test('manager mark-acknowledged override records the actor', function () {
    $a = HrAnnouncement::factory()->requiresAck()->create([
        'tenant_id' => 1, 'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()->subDay(),
    ]);
    $a->targets()->create(['type' => 'all', 'value' => null]);

    $this->actingAs($this->hr)->post("/hr/announcements/{$a->id}/acknowledge-for", ['user_id' => $this->worker->id])->assertRedirect();

    $this->assertDatabaseHas('hr_announcement_acknowledgements', [
        'announcement_id' => $a->id, 'user_id' => $this->worker->id, 'acknowledged_by' => $this->hr->id,
    ]);
});

test('bulk pin and archive update the targeted announcements', function () {
    $a = HrAnnouncement::factory()->create(['tenant_id' => 1, 'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->hr)->post('/hr/announcements/bulk', ['action' => 'pin', 'ids' => [$a->id]])->assertRedirect();
    expect($a->fresh()->is_pinned)->toBeTrue();

    $this->actingAs($this->hr)->post('/hr/announcements/bulk', ['action' => 'archive', 'ids' => [$a->id]])->assertRedirect();
    expect($a->fresh()->status)->toBe('archived');
});

test('archive then restore round-trips the lifecycle', function () {
    $a = HrAnnouncement::factory()->create(['tenant_id' => 1, 'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);
    $a->targets()->create(['type' => 'all', 'value' => null]);

    $this->actingAs($this->hr)->delete("/hr/announcements/{$a->id}")->assertRedirect();
    expect($a->fresh()->status)->toBe('archived');

    $this->actingAs($this->hr)->post("/hr/announcements/{$a->id}/restore")->assertRedirect();
    expect($a->fresh()->status)->toBe('published');
});

test('the filtered list export streams a CSV', function () {
    HrAnnouncement::factory()->create(['tenant_id' => 1, 'created_by' => $this->hr->id, 'title' => 'Exported notice', 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->hr)->get('/hr/announcements/export')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('the detail page renders with the reaction/reply thread payload', function () {
    $a = HrAnnouncement::factory()->create(['tenant_id' => 1, 'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);
    $a->targets()->create(['type' => 'all', 'value' => null]);

    $this->actingAs($this->hr)->get("/hr/announcements/{$a->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/announcements/show')
            ->has('reactions')
            ->has('replies')
            ->has('reactionEmojis')
            ->where('can.manage', true));
});

test('a staff member with recognition rights can react to and reply on an announcement', function () {
    $a = HrAnnouncement::factory()->create(['tenant_id' => 1, 'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);

    // Attach the worker to the support_worker role so it carries the seeded
    // hr.recognition.give permission (the react/reply routes gate on it).
    $this->worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);

    $this->actingAs($this->worker)->post('/hr/feed/react', [
        'subject_type' => 'announcement', 'subject_id' => $a->id, 'emoji' => 'heart',
    ])->assertRedirect();
    $this->assertDatabaseHas('hr_feed_reactions', ['subject_type' => 'announcement', 'subject_id' => $a->id, 'user_id' => $this->worker->id, 'emoji' => 'heart']);

    $this->actingAs($this->worker)->post('/hr/feed/reply', [
        'subject_type' => 'announcement', 'subject_id' => $a->id, 'body' => 'Thanks for the update!',
    ])->assertRedirect();
    $this->assertDatabaseHas('hr_feed_replies', ['subject_type' => 'announcement', 'subject_id' => $a->id, 'body' => 'Thanks for the update!']);
});

test('a non-manager cannot reach command-center mutations', function () {
    $a = HrAnnouncement::factory()->create(['tenant_id' => 1, 'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->worker)->post('/hr/announcements/bulk', ['action' => 'archive', 'ids' => [$a->id]])->assertForbidden();
    $this->actingAs($this->worker)->get('/hr/announcements/export')->assertForbidden();
    $this->actingAs($this->worker)->getJson("/hr/announcements/{$a->id}/tracking")->assertForbidden();
});
