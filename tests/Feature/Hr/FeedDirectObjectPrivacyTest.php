<?php

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedAttachment;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Notifications\AnnouncementReplyNotification;
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

    $hrRoleId = Role::query()->where('name', 'hr')->firstOrFail()->id;
    $this->viewer = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->viewer->roles()->syncWithoutDetaching([$hrRoleId]);
    $this->author = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->author->roles()->syncWithoutDetaching([$hrRoleId]);
    $this->viewerSite = Site::factory()->create();
    $this->hiddenSite = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->viewer->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'primary_site_id' => $this->viewerSite->id,
        'secondary_site_ids' => [],
    ]);
});

test('a site-targeted post cannot be reacted to from outside its audience', function () {
    $post = HrFeedPost::query()->create([
        'user_id' => $this->author->id,
        'post_type' => 'update',
        'target_audience' => 'site',
        'target_value' => (string) $this->hiddenSite->id,
        'content' => 'Hidden Site update',
    ]);

    $this->actingAs($this->viewer)
        ->post('/hr/feed/react', ['subject_type' => 'post', 'subject_id' => $post->id, 'emoji' => 'heart'])
        ->assertNotFound();
});

test('a kudos card inherits the audience of its feed post', function () {
    $post = HrFeedPost::query()->create([
        'user_id' => $this->author->id,
        'post_type' => 'kudos',
        'target_audience' => 'site',
        'target_value' => (string) $this->hiddenSite->id,
        'content' => 'Private recognition',
    ]);
    $recipient = User::factory()->create(['approved_at' => now()]);
    $kudos = HrKudos::query()->create([
        'from_user_id' => $this->author->id,
        'to_user_id' => $recipient->id,
        'category' => 'teamwork',
        'message' => 'Private recognition',
        'feed_post_id' => $post->id,
    ]);

    $this->actingAs($this->viewer)
        ->post("/hr/feed/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertNotFound();
});

test('a feed attachment inherits the audience of its parent post', function () {
    Storage::fake('private');
    Storage::disk('private')->put('hr/feed/hidden/image.jpg', 'image');
    $post = HrFeedPost::query()->create([
        'user_id' => $this->author->id,
        'post_type' => 'update',
        'target_audience' => 'site',
        'target_value' => (string) $this->hiddenSite->id,
        'content' => 'Hidden attachment',
    ]);
    $attachment = HrFeedAttachment::query()->create([
        'feed_post_id' => $post->id,
        'uploaded_by' => $this->author->id,
        'disk' => 'private',
        'original_name' => 'image.jpg',
        'path' => 'hr/feed/hidden/image.jpg',
        'mime' => 'image/jpeg',
        'size' => 5,
    ]);

    $this->actingAs($this->viewer)
        ->get("/hr/feed/attachments/{$attachment->id}")
        ->assertNotFound();
});

test('a site-targeted announcement rejects replies from outside its audience', function () {
    $announcement = HrAnnouncement::query()->create([
        'title' => 'Hidden notice',
        'content' => 'For another Site',
        'priority' => 'normal',
        'status' => 'published',
        'target_audience' => 'site',
        'target_value' => (string) $this->hiddenSite->id,
        'published_at' => now(),
        'created_by' => $this->author->id,
    ]);

    $this->actingAs($this->viewer)
        ->post('/hr/feed/reply', ['subject_type' => 'announcement', 'subject_id' => $announcement->id, 'body' => 'I should not see this'])
        ->assertNotFound();
});

test('the feed returns only announcements whose audience includes the current viewer', function () {
    $hidden = HrAnnouncement::query()->create([
        'title' => 'Hidden notice body',
        'content' => 'For another Site only',
        'priority' => 'normal',
        'status' => 'published',
        'target_audience' => 'site',
        'target_value' => (string) $this->hiddenSite->id,
        'published_at' => now(),
        'created_by' => $this->author->id,
    ]);
    $visible = HrAnnouncement::query()->create([
        'title' => 'Visible notice body',
        'content' => 'For the viewer Site',
        'priority' => 'normal',
        'status' => 'published',
        'target_audience' => 'site',
        'target_value' => (string) $this->viewerSite->id,
        'published_at' => now()->subSecond(),
        'created_by' => $this->author->id,
    ]);

    $this->actingAs($this->viewer)
        ->get('/hr/feed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('announcements', function ($announcements) use ($hidden, $visible): bool {
                $ids = collect($announcements)->pluck('id');

                return ! $ids->contains($hidden->id) && $ids->contains($visible->id);
            }));
});

test('an announcement reply notifies its canonical creator without an organisation marker', function () {
    Notification::fake();
    $announcement = HrAnnouncement::query()->create([
        'title' => 'Visible notice',
        'content' => 'For this Site',
        'priority' => 'normal',
        'status' => 'published',
        'target_audience' => 'site',
        'target_value' => (string) $this->viewerSite->id,
        'published_at' => now(),
        'created_by' => $this->author->id,
    ]);

    $this->actingAs($this->viewer)
        ->post('/hr/feed/reply', ['subject_type' => 'announcement', 'subject_id' => $announcement->id, 'body' => 'Thanks for the update'])
        ->assertRedirect();

    Notification::assertSentTo($this->author, AnnouncementReplyNotification::class);
});
