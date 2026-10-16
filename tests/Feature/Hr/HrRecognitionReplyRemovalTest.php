<?php

use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Notifications\FeedReplyNotification;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrRecognitionReplies;
use App\Http\Controllers\Hr\MyHrController;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

function replyRemovalShoutouts(User $viewer): array
{
    return (new ReflectionMethod(MyHrController::class, 'myHrShoutouts'))->invoke(app(MyHrController::class), $viewer);
}

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Canonical reply team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $staff = function (string $name, string $role = 'support_worker', ?int $department = null): User {
        $person = User::factory()->create(['name' => $name, 'role' => $role, 'approved_at' => now()]);
        $person->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($person, $this->site, ['department_id' => $department]);

        return $person;
    };
    $this->sender = $staff('Canonical giver', 'hr');
    $this->recipient = $staff('Reply author', department: $this->team->id);
    $this->member = $staff('Other team member', department: $this->team->id);
    $this->reader = $staff('Public reader');
    $this->moderator = $staff('HR moderator', 'hr');
    $this->feed = app(FeedService::class);
    $this->replies = app(HrRecognitionReplies::class);
    $this->kudos = $this->feed->sendKudos($this->sender, $this->recipient->id, null, 'A thoughtful recognition message.');
    $this->reply = $this->feed->addReply($this->kudos, $this->recipient->id, 'Author words to withdraw safely.');
    $this->url = '/hr/feed/kudos/'.$this->kudos->id.'/replies/'.$this->reply->id;
    $this->context = ['actor_id' => $this->recipient->id, 'version' => 1];
});

test('author withdrawal erases one canonical reply across Hub feed and MyHR while preserving the parent and card appreciation', function () {
    $this->feed->toggleReaction($this->kudos, $this->reader->id, 'heart');
    $this->kudos->load('replies.user');
    $hub = app(HubRecognition::class);
    expect($hub->describe($this->recipient, $this->kudos)['replies'][0])->toMatchArray(['id' => $this->reply->id, 'version' => 1, 'can_remove' => true])
        ->and($hub->describe($this->sender, $this->kudos)['replies'][0]['can_remove'])->toBeFalse();
    $this->actingAs($this->recipient)->get('/hr/feed')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('posts.data.0.kudos.replies.0.body', $this->reply->body)->where('posts.data.0.kudos.replies.0.can_remove', true));
    expect(collect(replyRemovalShoutouts($this->recipient))->sole()['replies'][0]['can_remove'])->toBeTrue();
    $this->deleteJson($this->url, $this->context)->assertOk()->assertExactJson(['saved' => true, 'kudos_id' => $this->kudos->id, 'reply_id' => $this->reply->id])
        ->assertHeader('Cache-Control', 'no-store, private');
    $withdrawn = $this->reply->fresh();
    expect($withdrawn->body)->toBeNull()->and($withdrawn->author_removed_at)->not->toBeNull()->and($withdrawn->version)->toBe(2)
        ->and($this->kudos->fresh()->replies)->toHaveCount(0)->and(HrKudosReply::count())->toBe(1)
        ->and($hub->describe($this->recipient, $this->kudos)['replies'])->toBe([])
        ->and(collect(replyRemovalShoutouts($this->recipient))->sole()['replies'])->toBe([]);
    $this->get('/hr/feed')->assertOk()->assertInertia(fn (Assert $page) => $page->has('posts.data.0.kudos.replies', 0));
    $this->assertDatabaseHas('hr_kudos', ['id' => $this->kudos->id, 'message' => 'A thoughtful recognition message.']);
    $this->assertDatabaseCount('hr_kudos_reactions', 1);
    $audit = AuditLog::where('action', 'hr.feed.kudos_reply.removed')->sole();
    $metadata = $audit->meta;
    $expectedMetadata = [
        'kudos_id' => $this->kudos->id, 'reply_id' => $this->reply->id, 'reason' => 'author_removed', 'previous_version' => 1, 'version' => 2,
    ];
    ksort($metadata);
    ksort($expectedMetadata);
    expect($audit->user_id)->toBe($this->recipient->id)->and($metadata)->toBe($expectedMetadata)
        ->and(json_encode($audit->meta))->not->toContain('Author words', 'recognition message');
    $this->deleteJson($this->url, $this->context)->assertConflict();
    $this->deleteJson('/hr/my/kudos/'.$this->kudos->id.'/replies/'.$this->reply->id, [...$this->context, 'version' => 2])->assertOk();
    expect(AuditLog::where('action', 'hr.feed.kudos_reply.removed')->count())->toBe(1);
    $migration = require database_path('migrations/2026_10_14_240000_add_recognition_reply_removal_and_comment_appreciation.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(Schema::hasColumn('hr_kudos_replies', 'author_removed_at'))->toBeTrue()->and(Schema::hasTable('hub_comment_appreciations'))->toBeTrue();
});

test('reply removal binds real author parent account version current permission staff and approved Site without a moderator override', function () {
    $this->actingAs($this->recipient);
    $this->deleteJson($this->url, [...$this->context, 'actor_id' => $this->sender->id])->assertConflict();
    $this->deleteJson($this->url, [...$this->context, 'version' => 2])->assertConflict();
    foreach (['user_id', 'author_id', 'kudos_id', 'parent_id', 'body'] as $field) {
        $this->deleteJson($this->url, [...$this->context, $field => $field === 'body' ? 'Spoofed words' : $this->sender->id])->assertUnprocessable();
    }
    foreach ([$this->sender, $this->reader, $this->moderator] as $other) {
        $this->actingAs($other)->deleteJson($this->url, ['actor_id' => $other->id, 'version' => 1])->assertForbidden();
    }
    $this->actingAs($this->sender)->deleteJson($this->url, $this->context)->assertConflict();
    $another = $this->feed->sendKudos($this->sender, $this->recipient->id, 'teamwork', 'Another canonical recognition.');
    $this->actingAs($this->recipient)->deleteJson('/hr/feed/kudos/'.$another->id.'/replies/'.$this->reply->id, $this->context)->assertNotFound();
    $this->recipient->hrEmployeeProfile()->update(['is_active' => false]);
    $this->deleteJson($this->url, $this->context)->assertNotFound();
    $this->recipient->hrEmployeeProfile()->update(['is_active' => true]);
    $permission = Permission::where('key', 'hr.recognition.give')->sole();
    $this->recipient->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    $this->actingAs($this->recipient->fresh())->deleteJson($this->url, $this->context)->assertForbidden();
    $this->recipient->permissionOverrides()->detach($permission->id);
    $this->site->update(['archived' => true]);
    $this->actingAs($this->recipient->fresh())->deleteJson($this->url, $this->context)->assertForbidden();
    $this->site->update(['archived' => false]);
    expect($this->reply->fresh()->body)->toBe('Author words to withdraw safely.')->and($this->reply->fresh()->version)->toBe(1);
    expect(AuditLog::where('action', 'hr.feed.kudos_reply.removed')->count())->toBe(0);
    $this->kudos->delete();
    $this->deleteJson($this->url, $this->context)->assertNotFound();
    $this->assertDatabaseMissing('hr_kudos_replies', ['id' => $this->reply->id]);
});

test('a queued individual reply rechecks its source at actual database delivery and author withdrawal clears already delivered source notices', function () {
    $notice = Notification::sent($this->sender, FeedReplyNotification::class)->sole();
    expect($notice->afterCommit)->toBeTrue()->and($notice->via($this->sender))->toBe(['database']);
    $job = unserialize(serialize(new SendQueuedNotifications($this->sender, $notice, ['database'])));
    $manager = new ChannelManager(app());
    $job->handle($manager);
    expect(DB::table('notifications')->where('type', FeedReplyNotification::class)->count())->toBe(1);
    expect(json_decode(DB::table('notifications')->sole()->data, true))->toMatchArray(['kudos_reply_id' => $this->reply->id, 'message' => $this->reply->body]);
    $this->replies->remove($this->recipient, $this->kudos, $this->reply->id, $this->context);
    expect($notice->shouldSend($this->sender, 'database'))->toBeFalse()->and($notice->toArray($this->sender)['message'])->toBe('');
    $job->handle($manager);
    $this->assertDatabaseCount('notifications', 0);
    $lateReply = $this->feed->addReply($this->kudos, $this->recipient->id, 'Withdrawal between the guard and channel write.');
    $lateNotice = Notification::sent($this->sender, FeedReplyNotification::class)->last();
    $withdrawnDuringDelivery = false;
    Event::listen(NotificationSending::class, function ($event) use ($lateReply, &$withdrawnDuringDelivery): void {
        if (! $withdrawnDuringDelivery && $event->notification instanceof FeedReplyNotification
            && ($event->notification->toArray($this->sender)['kudos_reply_id'] ?? null) === (int) $lateReply->id) {
            $withdrawnDuringDelivery = true;
            $this->replies->remove($this->recipient, $this->kudos, $lateReply->id, $this->context);
        }
    });
    unserialize(serialize(new SendQueuedNotifications($this->sender, $lateNotice, ['database'])))->handle($manager);
    expect($withdrawnDuringDelivery)->toBeTrue()->and($lateReply->fresh()->body)->toBeNull();
    $this->assertDatabaseCount('notifications', 0);
    $this->feed->addReply($this->kudos, $this->recipient->id, 'A subsequent current reply.');
    $next = Notification::sent($this->sender, FeedReplyNotification::class)->last();
    $nextJob = unserialize(serialize(new SendQueuedNotifications($this->sender, $next, ['database'])));
    $this->kudos->delete();
    $nextJob->handle($manager);
    $this->assertDatabaseCount('notifications', 0);
    expect($next->shouldSend($this->sender, 'database'))->toBeFalse()
        ->and((new FeedReplyNotification('A colleague', 'your post', 'Unrelated legacy feed notification'))->shouldSend($this->sender, 'database'))->toBeTrue();
});

test('queued team replies and author removal retain snapshot department audience staff and Site boundaries', function () {
    $teamKudos = $this->feed->sendTeamKudos($this->sender, $this->team->id, null, 'Recognition for the complete team.');
    $reply = $this->feed->addReply($teamKudos, $this->sender->id, 'You both made a useful difference.');
    $notice = Notification::sent($this->recipient, FeedReplyNotification::class)->sole();
    $job = unserialize(serialize(new SendQueuedNotifications($this->recipient, $notice, ['database'])));
    $deliver = function () use ($job): bool {
        $before = DB::table('notifications')->count();
        $job->handle(new ChannelManager(app()));

        return DB::table('notifications')->count() > $before;
    };
    expect($notice->shouldSend($this->recipient, 'database'))->toBeTrue();
    $teamKudos->recipients()->detach($this->recipient->id);
    expect($deliver())->toBeFalse();
    $teamKudos->recipients()->attach($this->recipient->id);
    $this->team->update(['is_active' => false]);
    expect($deliver())->toBeFalse();
    $this->team->update(['is_active' => true]);
    $this->recipient->hrEmployeeProfile()->update(['is_active' => false]);
    expect($deliver())->toBeFalse();
    $this->recipient->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => $this->outside->id]);
    expect($deliver())->toBeFalse();
    $this->recipient->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $teamKudos->feedPost()->update(['target_audience' => 'site', 'target_value' => (string) $this->outside->id]);
    expect($deliver())->toBeFalse();
    $teamKudos->feedPost()->update(['target_audience' => 'all', 'target_value' => null]);
    $permission = Permission::where('key', 'hr.recognition.give')->sole();
    $this->sender->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    expect($deliver())->toBeFalse();
    $this->sender->permissionOverrides()->detach($permission->id);
    expect($deliver())->toBeTrue();
    $this->actingAs($this->recipient)->deleteJson('/hr/feed/kudos/'.$teamKudos->id.'/replies/'.$reply->id, ['actor_id' => $this->recipient->id, 'version' => 1])->assertForbidden();
    $this->actingAs($this->sender)->withHeader('X-Inertia', 'true')->deleteJson('/hr/my/kudos/'.$teamKudos->id.'/replies/'.$reply->id, ['actor_id' => $this->sender->id, 'version' => 1])->assertRedirect();
    expect($deliver())->toBeFalse();
    $this->assertDatabaseCount('notifications', 0);
    expect($teamKudos->fresh()->recipients()->count())->toBe(2)->and($teamKudos->fresh()->replies)->toHaveCount(0);
});

test('migration pending reply removal is unavailable while legacy replies remain readable without author actions', function () {
    $pending = Mockery::mock(HrRecognitionReplies::class, [$this->feed])->makePartial();
    $pending->shouldReceive('ready')->andReturn(false);
    $this->app->instance(HrRecognitionReplies::class, $pending);
    expect($pending->project($this->recipient, $this->kudos)->sole()['can_remove'])->toBeFalse();
    $this->actingAs($this->recipient)->deleteJson($this->url, $this->context)->assertUnprocessable()->assertJsonValidationErrors('reply');
    expect($this->reply->fresh()->body)->toBe('Author words to withdraw safely.')->and($this->reply->fresh()->version)->toBe(1);
    expect(AuditLog::where('action', 'hr.feed.kudos_reply.removed')->exists())->toBeFalse();
});
