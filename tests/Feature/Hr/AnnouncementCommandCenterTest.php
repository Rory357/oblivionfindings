<?php

use App\Domain\Hr\Jobs\PublishDueAnnouncementsJob;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAcknowledgement;
use App\Domain\Hr\Models\HrAnnouncementAttachment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\AnnouncementPublishedNotification;
use App\Domain\Hr\Notifications\AnnouncementReminderNotification;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\AnnouncementInboxBridge;
use App\Models\Announcement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);

    $this->workerSite = Site::factory()->create();
    $this->otherSite = Site::factory()->create();
    ensureCanonicalHrStaffProfile($this->hr, $this->workerSite);
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);

    $viewPermission = Permission::query()->where('key', 'hr.announcements.view')->firstOrFail();
    $this->worker->permissionOverrides()->attach($viewPermission->id, ['allowed' => true]);

    foreach ([[$this->worker, 'support_worker', 'ANN-1'], [$this->coordinator, 'coordinator', 'ANN-2']] as [$user, $roleKey, $num]) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'employee_number' => $num,
            'position_role' => $roleKey,
            'primary_site_id' => $user->is($this->worker) ? $this->workerSite->id : $this->otherSite->id,
            'start_date' => today()->subYear(),
            'end_date' => null,
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
        'created_by' => $this->hr->id,
        'status' => 'scheduled',
        'published_at' => now()->subMinute(),
        'requires_acknowledgement' => true,
    ]);
    $a->targets()->create(['type' => 'all', 'value' => null]);

    (new PublishDueAnnouncementsJob)->handle(
        app(AnnouncementAudienceResolver::class),
        app(AnnouncementInboxBridge::class),
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

test('the header bell never widens Site person or mixed audiences', function () {
    Notification::fake();

    $targetSets = [
        'Site only' => [['type' => 'site', 'value' => (string) $this->workerSite->id]],
        'Person only' => [['type' => 'user', 'value' => (string) $this->worker->id]],
        'Mixed role and Site' => [
            ['type' => 'role', 'value' => 'support_worker'],
            ['type' => 'site', 'value' => (string) $this->workerSite->id],
        ],
    ];

    foreach ($targetSets as $label => $targets) {
        $this->actingAs($this->hr)->post('/hr/announcements', [
            'title' => "Private {$label} notice",
            'content' => 'This must remain in the privacy-aware HR inbox.',
            'priority' => 'urgent',
            'intent' => 'publish',
            'targets' => $targets,
            'push_to_bell' => true,
        ])->assertRedirect();

        $announcement = HrAnnouncement::query()->firstWhere('title', "Private {$label} notice");
        expect($announcement->inbox_announcement_id)->toBeNull();
        $this->assertDatabaseMissing('announcements', ['title' => "Private {$label} notice"]);
    }
});

test('changing a bell announcement to a Site audience withdraws the widened bridge', function () {
    Notification::fake();

    $this->actingAs($this->hr)->post('/hr/announcements', [
        'title' => 'Audience changes safely',
        'content' => 'Initially visible to everyone.',
        'priority' => 'urgent',
        'intent' => 'publish',
        'targets' => [['type' => 'all', 'value' => null]],
        'push_to_bell' => true,
    ])->assertRedirect();

    $announcement = HrAnnouncement::query()->firstWhere('title', 'Audience changes safely');
    $inboxId = $announcement->inbox_announcement_id;
    expect($inboxId)->not->toBeNull();

    $this->actingAs($this->hr)->put("/hr/announcements/{$announcement->id}", [
        'title' => 'Audience changes safely',
        'content' => 'Now restricted to one Site.',
        'priority' => 'urgent',
        'intent' => 'publish',
        'targets' => [['type' => 'site', 'value' => (string) $this->workerSite->id]],
        'push_to_bell' => true,
    ])->assertRedirect();

    expect($announcement->fresh()->inbox_announcement_id)->toBe($inboxId);
    $this->assertDatabaseHas('announcements', ['id' => $inboxId, 'is_active' => false]);
});

test('role-only bell announcements retain their exact role audience', function () {
    Notification::fake();

    $this->actingAs($this->hr)->post('/hr/announcements', [
        'title' => 'Role bridge',
        'content' => 'Role-scoped bell notice.',
        'priority' => 'normal',
        'intent' => 'publish',
        'targets' => [['type' => 'role', 'value' => 'support_worker']],
        'push_to_bell' => true,
    ])->assertRedirect();

    $announcement = HrAnnouncement::query()->firstWhere('title', 'Role bridge');
    $inbox = Announcement::query()->findOrFail($announcement->inbox_announcement_id);
    expect($inbox->audience_roles)->toBe(['support_worker'])
        ->and($inbox->is_active)->toBeTrue();
});

test('the preview endpoint returns a recipient count for in-progress targets', function () {
    $this->actingAs($this->hr)
        ->getJson('/hr/announcements/preview?targets='.urlencode(json_encode([['type' => 'role', 'value' => 'support_worker']])))
        ->assertOk()
        ->assertJson(['count' => 1]);
});

test('invalid or ambiguous targets never become an all-staff announcement', function () {
    $invalidTargetSets = [
        [],
        [['type' => 'all', 'value' => null], ['type' => 'role', 'value' => 'support_worker']],
        [['type' => 'site', 'value' => '999999999']],
        [['type' => 'user', 'value' => '999999999']],
        [['type' => 'role', 'value' => '']],
    ];

    foreach ($invalidTargetSets as $index => $targets) {
        $this->actingAs($this->hr)->from('/hr/announcements')->post('/hr/announcements', [
            'title' => "Invalid target {$index}",
            'content' => 'Must not be widened.',
            'priority' => 'normal',
            'intent' => 'publish',
            'targets' => $targets,
        ])->assertRedirect('/hr/announcements')->assertSessionHasErrors('targets');

        $this->assertDatabaseMissing('hr_announcements', ['title' => "Invalid target {$index}"]);
    }

    $this->actingAs($this->hr)
        ->getJson('/hr/announcements/preview?targets='.urlencode(json_encode($invalidTargetSets[1])))
        ->assertOk()
        ->assertJson(['count' => 0]);
});

test('an ended HR profile cannot retain announcement management or acknowledgement access', function () {
    $announcement = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id,
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    $announcement->targets()->create(['type' => 'all', 'value' => null]);

    HrEmployeeProfile::query()
        ->where('user_id', $this->hr->id)
        ->update(['end_date' => today()->subDay()]);

    $this->actingAs($this->hr)->get('/hr/announcements')->assertForbidden();
    $this->actingAs($this->hr)->get("/hr/announcements/{$announcement->id}")->assertForbidden();
    $this->actingAs($this->hr)->post("/hr/announcements/{$announcement->id}/acknowledge")->assertForbidden();
    $this->actingAs($this->hr)->post('/hr/announcements', [
        'title' => 'Former manager notice',
        'content' => 'Must not be stored.',
        'priority' => 'normal',
        'intent' => 'publish',
        'targets' => [['type' => 'all', 'value' => null]],
    ])->assertForbidden();

    $this->assertDatabaseMissing('hr_announcements', ['title' => 'Former manager notice']);
    $this->assertDatabaseMissing('hr_announcement_acknowledgements', [
        'announcement_id' => $announcement->id,
        'user_id' => $this->hr->id,
    ]);
});

test('reminders go only to outstanding recipients and respect the cooldown', function () {
    Notification::fake();

    $a = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()->subDay(),
    ]);
    $a->targets()->create(['type' => 'all', 'value' => null]);
    // Worker already acknowledged → should NOT be reminded.
    HrAnnouncementAcknowledgement::create(['announcement_id' => $a->id, 'user_id' => $this->worker->id, 'acknowledged_at' => now()]);

    $this->actingAs($this->hr)->post("/hr/announcements/{$a->id}/remind")->assertRedirect();

    Notification::assertSentTo($this->coordinator, AnnouncementReminderNotification::class);
    Notification::assertSentTo($this->hr, AnnouncementReminderNotification::class);
    Notification::assertNotSentTo($this->worker, AnnouncementReminderNotification::class);
    expect($a->reminders()->count())->toBe(2);

    // Second call within cooldown sends nothing more.
    $this->actingAs($this->hr)->post("/hr/announcements/{$a->id}/remind");
    expect($a->reminders()->count())->toBe(2);
});

test('the tracking endpoint returns a roster split by ack status', function () {
    $a = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()->subDay(),
    ]);
    $a->targets()->create(['type' => 'all', 'value' => null]);
    HrAnnouncementAcknowledgement::create(['announcement_id' => $a->id, 'user_id' => $this->worker->id, 'acknowledged_at' => now()]);

    $data = $this->actingAs($this->hr)->getJson("/hr/announcements/{$a->id}/tracking")->assertOk()->json();
    expect($data['acknowledged'])->toBe(1);
    expect($data['outstanding'])->toBe(2);
    expect(collect($data['roster'])->firstWhere('id', $this->worker->id)['status'])->toBe('acknowledged');
});

test('manager mark-acknowledged override records the actor', function () {
    $a = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()->subDay(),
    ]);
    $a->targets()->create(['type' => 'all', 'value' => null]);

    $this->actingAs($this->hr)->post("/hr/announcements/{$a->id}/acknowledge-for", ['user_id' => $this->worker->id])->assertRedirect();

    $this->assertDatabaseHas('hr_announcement_acknowledgements', [
        'announcement_id' => $a->id, 'user_id' => $this->worker->id, 'acknowledged_by' => $this->hr->id,
    ]);
});

test('manager acknowledgement override rejects non-audience and non-current people', function () {
    $announcement = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id,
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    $announcement->targets()->create(['type' => 'site', 'value' => (string) $this->workerSite->id]);

    $unapproved = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => null,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $unapproved->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $this->workerSite->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    foreach ([$this->coordinator, $unapproved] as $invalidRecipient) {
        $this->actingAs($this->hr)
            ->post("/hr/announcements/{$announcement->id}/acknowledge-for", ['user_id' => $invalidRecipient->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('hr_announcement_acknowledgements', [
            'announcement_id' => $announcement->id,
            'user_id' => $invalidRecipient->id,
        ]);
    }
});

test('bulk pin and archive update the targeted announcements', function () {
    $a = HrAnnouncement::factory()->create(['created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->hr)->post('/hr/announcements/bulk', ['action' => 'pin', 'ids' => [$a->id]])->assertRedirect();
    expect($a->fresh()->is_pinned)->toBeTrue();

    $this->actingAs($this->hr)->post('/hr/announcements/bulk', ['action' => 'archive', 'ids' => [$a->id]])->assertRedirect();
    expect($a->fresh()->status)->toBe('archived');
});

test('archive then restore round-trips the lifecycle', function () {
    $a = HrAnnouncement::factory()->create(['created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);
    $a->targets()->create(['type' => 'all', 'value' => null]);

    $this->actingAs($this->hr)->delete("/hr/announcements/{$a->id}")->assertRedirect();
    expect($a->fresh()->status)->toBe('archived');

    $this->actingAs($this->hr)->post("/hr/announcements/{$a->id}/restore")->assertRedirect();
    expect($a->fresh()->status)->toBe('published');
});

test('the filtered list export streams a CSV', function () {
    HrAnnouncement::factory()->create(['created_by' => $this->hr->id, 'title' => 'Exported notice', 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->hr)->get('/hr/announcements/export')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('the detail page renders with the reaction/reply thread payload', function () {
    $a = HrAnnouncement::factory()->create(['created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);
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

test('an ordinary announcement viewer does not receive the acknowledgement roster', function () {
    $announcement = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id,
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    $announcement->targets()->create(['type' => 'all', 'value' => null]);
    HrAnnouncementAcknowledgement::query()->create([
        'announcement_id' => $announcement->id,
        'user_id' => $this->coordinator->id,
        'acknowledged_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->get("/hr/announcements/{$announcement->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.manage', false)
            ->where('tracking', null)
            ->where('announcement.acknowledgements', []));

    $this->actingAs($this->hr)
        ->get("/hr/announcements/{$announcement->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('announcement.acknowledgements.0.user.id', $this->coordinator->id));
});

test('an ordinary announcement viewer sees only their live audience and cannot open manager tabs', function () {
    $visible = HrAnnouncement::factory()->create([
        'created_by' => $this->hr->id,
        'title' => 'Visible Site notice',
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    $visible->targets()->create(['type' => 'site', 'value' => (string) $this->workerSite->id]);

    $hidden = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id,
        'title' => 'Other Site private notice',
        'content' => 'Confidential acknowledgement details.',
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    $hidden->targets()->create(['type' => 'site', 'value' => (string) $this->otherSite->id]);
    HrAnnouncementAcknowledgement::query()->create([
        'announcement_id' => $hidden->id,
        'user_id' => $this->coordinator->id,
        'acknowledged_at' => now(),
    ]);

    $draft = HrAnnouncement::factory()->create([
        'created_by' => $this->hr->id,
        'title' => 'Unpublished management draft',
        'status' => 'draft',
        'published_at' => null,
    ]);
    $draft->targets()->create(['type' => 'all', 'value' => null]);

    $this->actingAs($this->worker)
        ->get('/hr/announcements')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.manage', false)
            ->has('announcements.data', 1)
            ->where('announcements.data.0.id', $visible->id)
            ->where('segments.all_count', 0)
            ->where('segments.sites', [])
            ->where('summary.live', 1)
            ->where('tabCounts.all', 1)
            ->where('tabCounts.tracking', 0));

    foreach (['tracking', 'scheduled', 'insights'] as $managerTab) {
        $this->actingAs($this->worker)
            ->get("/hr/announcements?tab={$managerTab}&announcement={$hidden->id}")
            ->assertForbidden();
    }

    $this->actingAs($this->hr)
        ->get("/hr/announcements?tab=tracking&announcement={$hidden->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tracking.id', $hidden->id)
            ->where('tracking.roster.0.id', $this->coordinator->id));
});

test('a staff member with recognition rights can react to and reply on an announcement', function () {
    $a = HrAnnouncement::factory()->create(['created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);
    $a->targets()->create(['type' => 'all', 'value' => null]);

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
    $a = HrAnnouncement::factory()->create(['created_by' => $this->hr->id, 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->worker)->post('/hr/announcements/bulk', ['action' => 'archive', 'ids' => [$a->id]])->assertForbidden();
    $this->actingAs($this->worker)->get('/hr/announcements/export')->assertForbidden();
    $this->actingAs($this->worker)->getJson("/hr/announcements/{$a->id}/tracking")->assertForbidden();
});

test('a current staff member outside an announcement audience cannot show acknowledge or download it', function () {
    Storage::fake('private');
    Storage::disk('private')->put('hr/announcements/hidden/briefing.pdf', 'confidential briefing');

    $announcement = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id,
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    $announcement->targets()->create(['type' => 'site', 'value' => (string) $this->otherSite->id]);
    $attachment = HrAnnouncementAttachment::query()->create([
        'announcement_id' => $announcement->id,
        'disk' => 'private',
        'path' => 'hr/announcements/hidden/briefing.pdf',
        'original_name' => 'briefing.pdf',
        'mime' => 'application/pdf',
        'size' => 21,
        'uploaded_by' => $this->hr->id,
    ]);

    $this->actingAs($this->worker)->get("/hr/announcements/{$announcement->id}")->assertNotFound();
    $this->actingAs($this->worker)->post("/hr/announcements/{$announcement->id}/acknowledge")->assertNotFound();
    $this->actingAs($this->worker)->get("/hr/announcements/attachments/{$attachment->id}")->assertNotFound();

    $this->assertDatabaseMissing('hr_announcement_acknowledgements', [
        'announcement_id' => $announcement->id,
        'user_id' => $this->worker->id,
    ]);
});

test('a current staff member in an announcement audience can show acknowledge and download it', function () {
    Storage::fake('private');
    Storage::disk('private')->put('hr/announcements/visible/briefing.pdf', 'visible briefing');

    $announcement = HrAnnouncement::factory()->requiresAck()->create([
        'created_by' => $this->hr->id,
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    $announcement->targets()->create(['type' => 'site', 'value' => (string) $this->workerSite->id]);
    $attachment = HrAnnouncementAttachment::query()->create([
        'announcement_id' => $announcement->id,
        'disk' => 'private',
        'path' => 'hr/announcements/visible/briefing.pdf',
        'original_name' => 'briefing.pdf',
        'mime' => 'application/pdf',
        'size' => 16,
        'uploaded_by' => $this->hr->id,
    ]);

    $this->actingAs($this->worker)->get("/hr/announcements/{$announcement->id}")->assertOk();
    $this->actingAs($this->worker)->post("/hr/announcements/{$announcement->id}/acknowledge")->assertRedirect();
    $this->actingAs($this->worker)->get("/hr/announcements/attachments/{$attachment->id}")->assertOk();

    $this->assertDatabaseHas('hr_announcement_acknowledgements', [
        'announcement_id' => $announcement->id,
        'user_id' => $this->worker->id,
    ]);
});
