<?php

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedAttachment;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrKudos;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $hrRoleId = Role::query()->where('name', 'hr')->first()->id;

    // Two HR users (both hold hr.recognition.view + give).
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([$hrRoleId]);

    $this->hr2 = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr2->roles()->syncWithoutDetaching([$hrRoleId]);

    $this->r1 = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->r2 = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
});

test('multi-recipient kudos creates one kudos and feed post per recipient', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_ids' => [$this->r1->id, $this->r2->id],
            'category' => 'teamwork',
            'impact' => 'impressive',
            'message' => 'Amazing teamwork on the audit.',
        ])
        ->assertRedirect();

    expect(HrKudos::where('from_user_id', $this->hr->id)->count())->toBe(2);
    expect(HrFeedPost::where('post_type', 'kudos')->count())->toBe(2);

    foreach ([$this->r1, $this->r2] as $recipient) {
        $kudos = HrKudos::where('to_user_id', $recipient->id)->first();
        expect($kudos)->not->toBeNull();
        expect($kudos->impact)->toBe('impressive');
        expect($kudos->category)->toBe('teamwork');
        expect($kudos->feed_post_id)->not->toBeNull();
    }
});

test('a single-recipient kudos stays back-compatible and defaults the impact', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $this->r1->id,
            'category' => 'teamwork',
            'message' => 'Solid shift.',
        ])
        ->assertRedirect();

    expect(HrKudos::where('to_user_id', $this->r1->id)->value('impact'))->toBe('good_job');
});

test('an invalid impact is rejected', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $this->r1->id,
            'category' => 'teamwork',
            'impact' => 'legendary',
            'message' => 'x',
        ])
        ->assertSessionHasErrors('impact');

    expect(HrKudos::count())->toBe(0);
});

test('reacting to a kudos toggles the reaction on and off', function () {
    $this->actingAs($this->hr)->post('/hr/feed/kudos', [
        'to_user_id' => $this->r1->id,
        'category' => 'teamwork',
        'message' => 'Nice work.',
    ]);
    $kudos = HrKudos::firstOrFail();

    $this->actingAs($this->hr)
        ->post("/hr/feed/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertRedirect();
    $this->assertDatabaseHas('hr_kudos_reactions', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->hr->id,
        'emoji' => 'heart',
    ]);

    // Toggling the same emoji removes it.
    $this->actingAs($this->hr)
        ->post("/hr/feed/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertRedirect();
    $this->assertDatabaseMissing('hr_kudos_reactions', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->hr->id,
        'emoji' => 'heart',
    ]);
});

test('an unknown reaction emoji is rejected', function () {
    $this->actingAs($this->hr)->post('/hr/feed/kudos', [
        'to_user_id' => $this->r1->id,
        'category' => 'teamwork',
        'message' => 'Nice work.',
    ]);
    $kudos = HrKudos::firstOrFail();

    $this->actingAs($this->hr)
        ->post("/hr/feed/kudos/{$kudos->id}/react", ['emoji' => 'rocket'])
        ->assertSessionHasErrors('emoji');
});

test('only the giver or receiver can reply to a kudos thread', function () {
    $this->actingAs($this->hr)->post('/hr/feed/kudos', [
        'to_user_id' => $this->r1->id,
        'category' => 'teamwork',
        'message' => 'Nice work.',
    ]);
    $kudos = HrKudos::firstOrFail();

    // The giver (hr) can reply.
    $this->actingAs($this->hr)
        ->post("/hr/feed/kudos/{$kudos->id}/reply", ['body' => 'You earned it!'])
        ->assertRedirect();
    $this->assertDatabaseHas('hr_kudos_replies', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->hr->id,
    ]);

    // A non-participant who still holds the give permission is forbidden.
    $this->actingAs($this->hr2)
        ->post("/hr/feed/kudos/{$kudos->id}/reply", ['body' => 'Me too'])
        ->assertForbidden();
});

test('the employee picker is scoped to the tenant (no cross-tenant leak)', function () {
    // hr + r1 belong to tenant 1; a third employee belongs to tenant 2.
    HrEmployeeProfile::factory()->create(['user_id' => $this->hr->id, 'tenant_id' => 1, 'is_active' => true]);
    HrEmployeeProfile::factory()->create(['user_id' => $this->r1->id, 'tenant_id' => 1, 'is_active' => true]);

    $otherTenantUser = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $otherTenantUser->id, 'tenant_id' => 2, 'is_active' => true]);

    $this->actingAs($this->hr)
        ->get('/hr/feed')
        ->assertInertia(fn ($page) => $page
            ->component('hr/feed/index')
            ->has('employees', 2));
});

test('the feed index exposes the recognition payload shape', function () {
    $this->actingAs($this->hr)
        ->get('/hr/feed')
        ->assertInertia(fn ($page) => $page
            ->component('hr/feed/index')
            ->has('metrics')
            ->has('announcements')
            ->has('leaderboard')
            ->has('valueBreakdown')
            ->has('kudosTrend', 8)
            ->has('kudosImpacts')
            ->has('sites'));
});

test('the insights trend buckets kudos into the current week', function () {
    $this->actingAs($this->hr)->post('/hr/feed/kudos', [
        'to_user_id' => $this->r1->id,
        'category' => 'teamwork',
        'message' => 'Great work this week.',
    ]);

    $this->actingAs($this->hr)
        ->get('/hr/feed')
        ->assertInertia(fn ($page) => $page
            ->component('hr/feed/index')
            ->has('kudosTrend', 8)
            ->where('kudosTrend.7.count', 1));
});

test('a post can carry an image attachment served from the private disk', function () {
    Storage::fake('private');

    $this->actingAs($this->hr)
        ->post('/hr/feed', [
            'content' => 'Team photo from the offsite',
            'post_type' => 'update',
            'attachment' => UploadedFile::fake()->image('offsite.jpg', 400, 300),
        ])
        ->assertRedirect();

    $attachment = HrFeedAttachment::firstOrFail();
    expect($attachment->original_name)->toBe('offsite.jpg');
    expect($attachment->disk)->toBe('private');
    Storage::disk('private')->assertExists($attachment->path);

    $this->actingAs($this->hr)
        ->get("/hr/feed/attachments/{$attachment->id}")
        ->assertOk();
});

test('a non-image post attachment is rejected', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed', [
            'content' => 'Notes',
            'post_type' => 'update',
            'attachment' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])
        ->assertSessionHasErrors('attachment');
});

test('kudos cannot be sent to a colleague in a different tenant', function () {
    HrEmployeeProfile::factory()->create(['user_id' => $this->hr->id, 'tenant_id' => 1, 'is_active' => true]);
    $otherTenantUser = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $otherTenantUser->id, 'tenant_id' => 2, 'is_active' => true]);

    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $otherTenantUser->id,
            'category' => 'teamwork',
            'message' => 'Cross-tenant attempt',
        ])
        ->assertSessionHasErrors('to_user_id');

    expect(HrKudos::count())->toBe(0);
});

test('a cross-tenant feed attachment is not served', function () {
    $post = HrFeedPost::create([
        'tenant_id' => 2,
        'user_id' => $this->hr->id,
        'post_type' => 'update',
        'content' => 'Another org post',
    ]);
    $attachment = HrFeedAttachment::create([
        'tenant_id' => 2,
        'feed_post_id' => $post->id,
        'uploaded_by' => $this->hr->id,
        'disk' => 'private',
        'original_name' => 'secret.jpg',
        'path' => 'hr/feed/2/secret.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
    ]);

    $this->actingAs($this->hr)
        ->get("/hr/feed/attachments/{$attachment->id}")
        ->assertNotFound();
});

test('self-service /hr/my/kudos accepts multiple recipients and an impact', function () {
    $this->actingAs($this->hr)
        ->post('/hr/my/kudos', [
            'to_user_ids' => [$this->r1->id, $this->r2->id],
            'category' => 'going_above',
            'impact' => 'exceptional',
            'message' => 'You both went above and beyond.',
        ])
        ->assertRedirect();

    expect(HrKudos::where('impact', 'exceptional')->count())->toBe(2);
});

test('a feed post records the composer kind (update / question / win)', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed', [
            'content' => 'We shipped the new roster system! 🎉',
            'post_type' => 'update',
            'kind' => 'win',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_feed_posts', [
        'post_type' => 'update',
        'kind' => 'win',
        'content' => 'We shipped the new roster system! 🎉',
    ]);
});

test('an unknown composer kind is rejected', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed', [
            'content' => 'Hi team',
            'post_type' => 'update',
            'kind' => 'rant',
        ])
        ->assertSessionHasErrors('kind');
});

test('reacting to an announcement toggles the polymorphic feed reaction', function () {
    $announcement = HrAnnouncement::create([
        'tenant_id' => 1,
        'title' => 'All-hands Friday',
        'content' => 'See you there.',
        'priority' => 'normal',
        'target_audience' => 'all',
        'published_at' => now(),
        'created_by' => $this->hr->id,
    ]);

    $payload = ['subject_type' => 'announcement', 'subject_id' => $announcement->id, 'emoji' => 'party'];

    $this->actingAs($this->hr)->post('/hr/feed/react', $payload)->assertRedirect();
    $this->assertDatabaseHas('hr_feed_reactions', [
        'subject_type' => 'announcement',
        'subject_id' => $announcement->id,
        'user_id' => $this->hr->id,
        'emoji' => 'party',
    ]);

    // Toggling the same emoji removes it.
    $this->actingAs($this->hr)->post('/hr/feed/react', $payload)->assertRedirect();
    $this->assertDatabaseMissing('hr_feed_reactions', [
        'subject_type' => 'announcement',
        'subject_id' => $announcement->id,
        'user_id' => $this->hr->id,
        'emoji' => 'party',
    ]);
});

test('replying to a non-kudos post stores a polymorphic feed reply', function () {
    $post = HrFeedPost::create([
        'tenant_id' => 1,
        'user_id' => $this->hr->id,
        'post_type' => 'update',
        'content' => 'Team update of the week.',
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/feed/reply', ['subject_type' => 'post', 'subject_id' => $post->id, 'body' => 'Love this!'])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_feed_replies', [
        'subject_type' => 'post',
        'subject_id' => $post->id,
        'user_id' => $this->hr->id,
        'body' => 'Love this!',
    ]);
});

test('a cross-tenant feed reaction subject is rejected', function () {
    $otherTenantPost = HrFeedPost::create([
        'tenant_id' => 2,
        'user_id' => $this->hr->id,
        'post_type' => 'update',
        'content' => 'Another org.',
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/feed/react', ['subject_type' => 'post', 'subject_id' => $otherTenantPost->id, 'emoji' => 'heart'])
        ->assertNotFound();
});

test('an unknown feed subject type is rejected', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/react', ['subject_type' => 'kudos', 'subject_id' => 1, 'emoji' => 'heart'])
        ->assertSessionHasErrors('subject_type');
});

test('the wall search filters posts server-side', function () {
    HrFeedPost::create([
        'tenant_id' => 1,
        'user_id' => $this->hr->id,
        'post_type' => 'update',
        'content' => 'Quarterly roster zebra update',
    ]);
    HrFeedPost::create([
        'tenant_id' => 1,
        'user_id' => $this->hr->id,
        'post_type' => 'update',
        'content' => 'An unrelated kai note',
    ]);

    $this->actingAs($this->hr)
        ->get('/hr/feed?search=zebra')
        ->assertInertia(fn ($page) => $page
            ->component('hr/feed/index')
            ->where('filters.search', 'zebra')
            ->has('posts.data', 1));
});

test('a site-scoped post is hidden from viewers outside that site', function () {
    $siteA = \App\Models\Site::factory()->create(['tenant_id' => 1]);
    $siteB = \App\Models\Site::factory()->create(['tenant_id' => 1]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'tenant_id' => 1,
        'is_active' => true,
        'primary_site_id' => $siteA->id,
    ]);

    // r1 (not the viewer) posts one update to site B, then one to site A.
    HrFeedPost::create([
        'tenant_id' => 1, 'user_id' => $this->r1->id, 'post_type' => 'update',
        'target_audience' => 'site', 'target_value' => (string) $siteB->id, 'content' => 'Site B only update',
    ]);
    HrFeedPost::create([
        'tenant_id' => 1, 'user_id' => $this->r1->id, 'post_type' => 'update',
        'target_audience' => 'site', 'target_value' => (string) $siteA->id, 'content' => 'Site A only update',
    ]);

    // The viewer is in site A, so only the site-A post is visible.
    $this->actingAs($this->hr)
        ->get('/hr/feed')
        ->assertInertia(fn ($page) => $page
            ->component('hr/feed/index')
            ->has('posts.data', 1)
            ->where('posts.data.0.content', 'Site A only update'));
});
