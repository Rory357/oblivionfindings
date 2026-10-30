<?php

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Notifications\FeedReplyNotification;
use App\Domain\Hr\Notifications\KudosReceivedNotification;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrTeamRecognition;
use App\Http\Controllers\Hr\MyHrController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function teamRecognitionShoutouts(User $viewer, string $box = 'received'): array
{
    return (new ReflectionMethod(MyHrController::class, 'myHrShoutouts'))->invoke(app(MyHrController::class), $viewer, $box);
}

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Canonical care team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $this->staff = function (string $name, string $role = 'support_worker', ?Site $site = null, ?int $department = null): User {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($user, $site ?? $this->site, ['department_id' => $department]);

        return $user;
    };
    $this->sender = ($this->staff)('Team giver', 'hr', department: $this->team->id);
    $this->one = ($this->staff)('Recognised colleague one', department: $this->team->id);
    $this->two = ($this->staff)('Recognised colleague two', department: $this->team->id);
    $this->reader = ($this->staff)('Ordinary public reader');
    $this->hidden = ($this->staff)('Other site HR viewer', 'hr', $this->outside);
    $this->feed = app(FeedService::class);
    $this->teams = app(HrTeamRecognition::class);
    $this->payload = ['recipient_type' => 'team', 'to_department_id' => $this->team->id, 'to_user_ids' => [], 'category' => 'teamwork', 'impact' => 'impressive', 'message' => 'Thank you to the whole care team.', 'actor_id' => $this->sender->id];
    $this->send = fn () => $this->feed->sendTeamKudos($this->sender, $this->team->id, 'teamwork', $this->payload['message'], 'impressive');
});

test('a whole team is one canonical kudos and post with a complete recipient snapshot shared by feed and my HR', function () {
    $this->actingAs($this->sender)->post('/hr/feed/kudos', $this->payload)->assertRedirect()->assertSessionHasNoErrors();
    $kudos = HrKudos::sole();
    expect($kudos->isTeamRecognition())->toBeTrue()->and($kudos->to_user_id)->toBeNull()->and($kudos->to_department_id)->toBe($this->team->id)
        ->and($kudos->recipients()->orderBy('users.id')->pluck('users.id')->all())->toBe([$this->sender->id, $this->one->id, $this->two->id]);
    $this->assertDatabaseCount('hr_kudos', 1);
    $this->assertDatabaseCount('hr_feed_posts', 1);
    $this->assertDatabaseCount('hr_kudos_recipients', 3);
    foreach ([$this->one, $this->two] as $recipient) {
        $received = collect(teamRecognitionShoutouts($recipient))->sole();
        expect($received['id'])->toBe($kudos->id)->and($received['recipient'])->toMatchArray(['id' => null, 'type' => 'team', 'department_id' => $this->team->id, 'name' => $this->team->name, 'role' => 'Team', 'you' => true])
            ->and($received['can_reply'])->toBeTrue();
        Notification::assertSentTo($recipient, KudosReceivedNotification::class, function ($notification) use ($recipient, $kudos): bool {
            $data = $notification->toArray($recipient);

            return $notification->afterCommit === true && $data['recipient_type'] === 'team' && $data['kudos_id'] === $kudos->id && $data['team_name'] === $this->team->name;
        });
    }
    Notification::assertNotSentTo($this->sender, KudosReceivedNotification::class);
    Notification::assertNotSentTo($this->reader, KudosReceivedNotification::class);
    expect(collect(teamRecognitionShoutouts($this->sender, 'given'))->sole()['id'])->toBe($kudos->id);
    $this->actingAs($this->reader)->get('/hr/feed')->assertOk()->assertInertia(fn (Assert $page) => $page->has('posts.data', 1)
        ->where('posts.data.0.kudos.recipient_type', 'team')->where('posts.data.0.kudos.to_user', null)
        ->where('posts.data.0.kudos.to_department.name', $this->team->name)->where('posts.data.0.kudos.can_reply', true)
        ->missing('posts.data.0.kudos.recipients')->missing('posts.data.0.kudos.members')->missing('posts.data.0.kudos.salary'));
    expect($this->feed->getFeed('kudos', 'Canonical care team', $this->reader->id, [$this->site->id])->total())->toBe(1);
    expect($this->feed->getKudosLeaderboard())->toHaveCount(0);
});

test('both existing HR send paths support canonical teams and preserve individual multi select', function () {
    $this->actingAs($this->sender)->post('/hr/my/kudos', $this->payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/hr/feed/kudos', ['to_department_id' => null, 'to_user_ids' => [$this->one->id, $this->two->id], 'category' => 'innovation', 'message' => 'Individual recognition remains individual.'])->assertRedirect()->assertSessionHasNoErrors();
    expect(HrKudos::where('recipient_type', 'team')->count())->toBe(1)->and(HrKudos::where('recipient_type', 'person')->count())->toBe(2);
    expect(HrKudos::where('recipient_type', 'person')->pluck('to_user_id')->sort()->values()->all())->toBe([$this->one->id, $this->two->id]);
    expect(HrKudos::where('recipient_type', 'person')->whereNotNull('to_department_id')->exists())->toBeFalse();
    $this->assertDatabaseCount('hr_kudos_recipients', 3);
    $this->assertDatabaseCount('hr_feed_posts', 3);
});

test('team options and direct sends reject empty inactive invisible and partially authorised membership', function () {
    $empty = HrDepartment::create(['name' => 'Empty team', 'is_active' => true]);
    $inactive = HrDepartment::create(['name' => 'Retired team', 'is_active' => false]);
    $private = HrDepartment::create(['name' => 'Other site private team', 'is_active' => true]);
    $private->sites()->attach($this->outside->id);
    ($this->staff)('Hidden team colleague', site: $this->outside, department: $private->id);
    expect($this->teams->options($this->sender))->toBe([['id' => $this->team->id, 'name' => $this->team->name, 'member_count' => 3]]);
    $this->actingAs($this->sender);
    foreach ([$empty->id, $inactive->id, $private->id, 999999] as $id) {
        $this->postJson('/hr/feed/kudos', [...$this->payload, 'to_department_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('to_department_id');
    }
    ($this->staff)('Newly added outside site member', site: $this->outside, department: $this->team->id);
    expect($this->teams->options($this->sender))->toBe([]);
    $this->postJson('/hr/feed/kudos', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('to_department_id');
    $this->assertDatabaseCount('hr_kudos', 0);
    $this->assertDatabaseCount('hr_feed_posts', 0);
    $this->assertDatabaseCount('hr_kudos_recipients', 0);
    Notification::assertNothingSent();
});

test('team selection cannot spoof people membership sender account or mixed recipient type', function () {
    $this->actingAs($this->sender);
    foreach ([['to_user_ids' => [$this->one->id]], ['to_user_id' => $this->one->id], ['recipient_type' => 'person'], ['member_ids' => [$this->hidden->id]], ['recipient_ids' => [$this->reader->id]], ['from_user_id' => $this->reader->id], ['user_id' => $this->reader->id], ['to_department_id' => null]] as $forged) {
        $this->postJson('/hr/feed/kudos', [...$this->payload, ...$forged])->assertUnprocessable();
    }
    $this->postJson('/hr/feed/kudos', [...$this->payload, 'actor_id' => $this->one->id])->assertConflict();
    $this->assertDatabaseCount('hr_kudos', 0);
    $this->assertDatabaseCount('hr_feed_posts', 0);
    Notification::assertNothingSent();
});

test('snapshot membership stays historical while replies notifications and reads recheck current staff and visibility', function () {
    $kudos = ($this->send)();
    $newMember = ($this->staff)('Joined after the recognition', department: $this->team->id);
    expect($kudos->recipients()->whereKey($newMember->id)->exists())->toBeFalse()->and(teamRecognitionShoutouts($newMember))->toBe([])
        ->and($this->feed->canViewKudos($kudos, $newMember))->toBeTrue()->and($this->feed->canReplyKudos($kudos, $newMember))->toBeTrue();
    $this->actingAs($newMember)->post('/hr/my/kudos/'.$kudos->id.'/reply', ['body' => 'An authorised audience reader'])->assertRedirect();
    $this->post('/hr/my/kudos/'.$kudos->id.'/react', ['emoji' => 'heart'])->assertRedirect();
    $notice = new KudosReceivedNotification($kudos, $this->sender->name);
    expect($notice->via($newMember))->toBe([]);
    $this->one->hrEmployeeProfile()->update(['is_active' => false]);
    $this->one = $this->one->fresh();
    expect($this->feed->canViewKudos($kudos, $this->one))->toBeFalse()->and($this->feed->canReplyKudos($kudos, $this->one))->toBeFalse()
        ->and(teamRecognitionShoutouts($this->one))->toBe([])->and($notice->via($this->one))->toBe([]);
    $this->actingAs($this->one)->post('/hr/my/kudos/'.$kudos->id.'/reply', ['body' => 'Former employee'])->assertNotFound();
    $this->two->hrEmployeeProfile()->update(['primary_site_id' => $this->outside->id, 'secondary_site_ids' => []]);
    $this->two = $this->two->fresh();
    expect($this->feed->canViewKudos($kudos, $this->two))->toBeFalse()->and($notice->via($this->two))->toBe([]);
    $this->actingAs($this->two)->post('/hr/my/kudos/'.$kudos->id.'/react', ['emoji' => 'party'])->assertNotFound();
});

test('team records obey canonical department and feed audience including direct object denial and deletion', function () {
    $kudos = ($this->send)();
    $this->actingAs($this->hidden)->get('/hr/feed?search=Canonical')->assertOk()->assertInertia(fn (Assert $page) => $page->has('posts.data', 0))->assertDontSee($this->payload['message'])->assertDontSee($this->team->name);
    foreach (['react' => ['emoji' => 'heart'], 'reply' => ['body' => 'Outside site']] as $action => $data) {
        $this->post('/hr/feed/kudos/'.$kudos->id.'/'.$action, $data)->assertNotFound();
    }
    $this->delete('/hr/feed/kudos/'.$kudos->id)->assertNotFound();
    $this->delete('/hr/feed/posts/'.$kudos->feed_post_id)->assertNotFound();
    $kudos->feedPost()->update(['target_audience' => 'site', 'target_value' => (string) $this->outside->id]);
    expect($this->feed->canViewKudos($kudos, $this->sender))->toBeFalse()->and($this->feed->canViewKudos($kudos, $this->one))->toBeFalse();
    expect(teamRecognitionShoutouts($this->sender, 'given'))->toBe([]);
    $this->actingAs($this->sender)->post('/hr/feed/kudos/'.$kudos->id.'/reply', ['body' => 'Wrong current audience'])->assertNotFound();
    $kudos->feedPost()->update(['target_audience' => 'all', 'target_value' => null]);
    $this->team->update(['is_active' => false]);
    expect($this->feed->canViewKudos($kudos, $this->sender))->toBeFalse()->and(teamRecognitionShoutouts($this->one))->toBe([]);
    $this->team->delete();
    $kudos = $kudos->fresh();
    expect($kudos->isTeamRecognition())->toBeTrue()->and($kudos->to_department_id)->toBeNull()->and($kudos->to_user_id)->toBeNull()
        ->and($this->feed->canViewKudos($kudos, $this->sender))->toBeFalse();
    $this->get('/hr/feed')->assertOk()->assertInertia(fn (Assert $page) => $page->has('posts.data', 0));
});

test('one shared team social thread uses existing reactions replies notifications and audited removal routes', function () {
    $kudos = ($this->send)();
    $this->actingAs($this->one)->post('/hr/feed/kudos/'.$kudos->id.'/react', ['emoji' => 'party'])->assertRedirect();
    $this->post('/hr/my/kudos/'.$kudos->id.'/react', ['emoji' => 'party'])->assertRedirect();
    $this->assertDatabaseCount('hr_kudos_reactions', 0);
    $this->post('/hr/my/kudos/'.$kudos->id.'/reply', ['body' => 'Our shared thank you'])->assertRedirect();
    Notification::assertSentTo($this->sender, FeedReplyNotification::class);
    Notification::assertNotSentTo($this->reader, FeedReplyNotification::class);
    $this->actingAs($this->reader)->post('/hr/feed/kudos/'.$kudos->id.'/reply', ['body' => 'Public audience participant'])->assertRedirect();
    $this->actingAs($this->sender)->post('/hr/feed/kudos/'.$kudos->id.'/reply', ['body' => 'You all earned it'])->assertRedirect();
    Notification::assertSentTo($this->one, FeedReplyNotification::class, fn ($notice) => $notice->afterCommit === true);
    Notification::assertSentTo($this->two, FeedReplyNotification::class);
    expect(collect(teamRecognitionShoutouts($this->two))->sole()['replies'])->toHaveCount(3);
    $this->post('/hr/feed/kudos/'.$kudos->id.'/react', ['emoji' => 'hands'])->assertRedirect();
    $this->delete('/hr/feed/kudos/'.$kudos->id)->assertRedirect();
    foreach (['hr_kudos', 'hr_feed_posts', 'hr_kudos_recipients', 'hr_kudos_reactions', 'hr_kudos_replies'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseHas('audit_logs', ['action' => 'hr.feed.post.removed', 'auditable_id' => $kudos->feed_post_id]);
    $another = ($this->send)();
    $this->delete('/hr/feed/posts/'.$another->feed_post_id)->assertRedirect();
    $this->assertDatabaseCount('hr_kudos_recipients', 0);
});

test('team send permission and current employment are independently required before persistence', function () {
    $permission = Permission::where('key', 'hr.recognition.give')->sole();
    $this->sender->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    $this->sender = $this->sender->fresh();
    expect($this->teams->options($this->sender))->toBe([]);
    $this->actingAs($this->sender)->post('/hr/feed/kudos', $this->payload)->assertForbidden();
    $this->sender->permissionOverrides()->detach($permission->id);
    $this->sender->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->sender = $this->sender->fresh();
    $this->actingAs($this->sender)->post('/hr/feed/kudos', $this->payload)->assertForbidden();
    $this->assertDatabaseCount('hr_kudos', 0);
    $this->assertDatabaseCount('hr_feed_posts', 0);
    Notification::assertNothingSent();
});

test('queued team channels recheck withdrawn membership staff site department audience and deletion at actual delivery', function () {
    $kudos = ($this->send)();
    $received = new KudosReceivedNotification($kudos, $this->sender->name);
    $reply = new FeedReplyNotification($this->sender->name, 'your team recognition', 'Deferred reply text', $kudos->id);
    // Freeze eligible channels as Laravel does while queueing; only shouldSend guards later delivery.
    expect($received->via($this->one))->toBe(['database', 'mail'])->and($reply->via($this->one))->toBe(['database']);
    $deliver = fn () => [$received->shouldSend($this->one, 'database'), $received->shouldSend($this->one, 'mail'), $reply->shouldSend($this->one, 'database')];
    expect($deliver())->toBe([true, true, true]);
    $kudos->recipients()->detach($this->one->id);
    expect($deliver())->toBe([false, false, false]);
    $kudos->recipients()->attach($this->one->id);
    $this->one->hrEmployeeProfile()->update(['is_active' => false]);
    expect($deliver())->toBe([false, false, false]);
    $this->one->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => $this->outside->id]);
    expect($deliver())->toBe([false, false, false]);
    $this->one->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $this->team->update(['is_active' => false]);
    expect($deliver())->toBe([false, false, false]);
    $this->team->update(['is_active' => true]);
    $kudos->feedPost()->update(['target_audience' => 'site', 'target_value' => (string) $this->outside->id]);
    expect($deliver())->toBe([false, false, false]);
    $kudos->feedPost()->update(['target_audience' => 'all', 'target_value' => null]);
    expect($deliver())->toBe([true, true, true]);
    $kudos->delete();
    expect($deliver())->toBe([false, false, false]);
    $person = $this->feed->sendKudos($this->sender, $this->two->id, 'teamwork', 'Existing person behavior');
    expect((new KudosReceivedNotification($person, $this->sender->name))->shouldSend($this->two, 'mail'))->toBeTrue()
        ->and((new FeedReplyNotification('A colleague', 'your kudos', 'Existing reply'))->shouldSend($this->two, 'database'))->toBeTrue();
    Notification::assertNotSentTo($this->reader, KudosReceivedNotification::class);
});
