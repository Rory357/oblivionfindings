<?php

use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Notifications\FeedReplyNotification;
use App\Domain\Hr\Notifications\KudosReceivedNotification;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrAudienceAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrTeamRecognition;
use App\Http\Controllers\Hr\MyHrController;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Canonical message-only team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $staff = function (string $name, string $role = 'support_worker', ?Site $site = null, ?int $department = null): User {
        $person = User::factory()->create(['name' => $name, 'role' => $role, 'approved_at' => now()]);
        $person->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($person, $site ?? $this->site, ['department_id' => $department]);

        return $person;
    };
    $this->sender = $staff('Recognition giver', 'hr');
    $this->one = $staff('Recognised colleague one', department: $this->team->id);
    $this->two = $staff('Recognised colleague two', department: $this->team->id);
    $this->reader = $staff('Public recognition reader');
    $this->hidden = $staff('Other site reader', 'hr', $this->outside);
    $this->feed = app(FeedService::class);
    $this->hub = app(HubRecognition::class);
});

test('both canonical HR send routes persist message-only recognition with the approved untouched heading', function () {
    $this->actingAs($this->sender)->post('/hr/feed/kudos', [
        'to_user_ids' => [$this->one->id, $this->two->id], 'message' => '  0123456789  ', 'actor_id' => $this->sender->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/hr/my/kudos', ['to_user_id' => $this->one->id, 'category' => null, 'impact' => null, 'message' => 'A thoughtful personal thank-you.'])->assertRedirect()->assertSessionHasNoErrors();
    expect(HrKudos::count())->toBe(3)->and(HrKudos::whereNotNull('category')->count())->toBe(0)
        ->and(HrKudos::where('impact', 'thank_you')->count())->toBe(3)->and(HrKudos::where('message', '0123456789')->count())->toBe(2);
    $this->assertDatabaseCount('hr_feed_posts', 3);
    $received = (new ReflectionMethod(MyHrController::class, 'myHrShoutouts'))->invoke(app(MyHrController::class), $this->one, 'received');
    expect($received)->toHaveCount(2)->and($received[0])->toMatchArray(['category' => null, 'category_label' => null, 'impact' => 'thank_you', 'impact_label' => 'Thank you']);
    $this->get('/hr/feed')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('posts.data.0.kudos.category', null)->where('posts.data.0.kudos.category_label', null)
        ->where('posts.data.0.kudos.impact', 'thank_you')->where('posts.data.0.kudos.impact_label', 'Thank you'));
    Notification::assertSentTo($this->one, KudosReceivedNotification::class, function ($notification): bool {
        $data = $notification->toArray($this->one);
        $mail = implode(' ', $notification->toMail($this->one)->introLines);

        return $data['category'] === null && $data['category_label'] === null && $data['impact'] === 'thank_you'
            && $data['impact_label'] === 'Thank you' && str_contains($mail, '**Thank you**')
            && ! str_contains($mail, 'for ****') && ! str_contains($mail, '**Other**');
    });
});

test('message-only teams keep one canonical record snapshot authorised projection and participant reply rules', function () {
    $this->actingAs($this->sender)->post('/hr/my/kudos', [
        'to_department_id' => $this->team->id, 'to_user_ids' => [], 'category' => null, 'impact' => 'exceptional',
        'message' => 'Thank you to every member of the team.', 'actor_id' => $this->sender->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $kudos = HrKudos::sole();
    expect($kudos->category)->toBeNull()->and($kudos->impact)->toBe('exceptional')->and($kudos->isTeamRecognition())->toBeTrue()
        ->and($kudos->to_user_id)->toBeNull()->and($kudos->recipients()->orderBy('users.id')->pluck('users.id')->all())->toBe([$this->one->id, $this->two->id]);
    $this->assertDatabaseCount('hr_feed_posts', 1);
    $data = $this->hub->describe($this->reader, $kudos);
    expect($data)->toMatchArray(['recipient_type' => 'team', 'category' => null, 'category_key' => null, 'impact' => 'exceptional', 'impact_label' => 'Above and beyond', 'can_reply' => false]);
    expect($this->hub->describe($this->hidden, $kudos))->toBeNull();
    $this->actingAs($this->reader)->postJson('/hr/feed/kudos/'.$kudos->id.'/reply', ['body' => 'An ordinary reader cannot reply.'])->assertForbidden();
    $this->actingAs($this->one)->post('/hr/feed/kudos/'.$kudos->id.'/reply', ['body' => 'Thank you for recognising our whole team.'])->assertRedirect()->assertSessionHasNoErrors();
    expect($kudos->replies()->count())->toBe(1);
    Notification::assertNotSentTo($this->reader, KudosReceivedNotification::class);
});

test('recognition validates trimmed message boundaries and optional enums consistently before request or direct persistence', function () {
    $this->actingAs($this->sender);
    foreach (['', 'short', '123456789', '   123456789   ', str_repeat('a', 2001)] as $message) {
        foreach (['/hr/feed/kudos', '/hr/my/kudos'] as $url) {
            $this->postJson($url, ['to_user_id' => $this->one->id, 'category' => null, 'message' => $message])->assertUnprocessable()->assertJsonValidationErrors('message');
        }
        expect(fn () => $this->feed->sendKudos($this->sender, $this->one->id, null, $message))->toThrow(ValidationException::class);
        expect(fn () => $this->feed->sendTeamKudos($this->sender, $this->team->id, null, $message))->toThrow(ValidationException::class);
    }
    foreach ([['category' => 'invented_value'], ['impact' => 'invented_heading']] as $invalid) {
        $this->postJson('/hr/feed/kudos', ['to_user_id' => $this->one->id, 'message' => 'A sufficiently thoughtful thank-you.', ...$invalid])->assertUnprocessable()->assertJsonValidationErrors(array_key_first($invalid));
        expect(fn () => $this->feed->sendKudos($this->sender, $this->one->id, $invalid['category'] ?? null, 'A sufficiently thoughtful thank-you.', $invalid['impact'] ?? null))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('hr_kudos', 0);
    $this->assertDatabaseCount('hr_feed_posts', 0);
    Notification::assertNothingSent();
    $atMaximum = $this->feed->sendKudos($this->sender, $this->one->id, null, str_repeat('é', 2000));
    expect(mb_strlen($atMaximum->message))->toBe(2000);
});

test('saved legacy values and every explicit heading remain valid while message-only recognition does not invent a value in analytics or spotlight', function () {
    $legacy = $this->feed->sendKudos($this->sender, $this->one->id, 'teamwork', 'Existing valued appreciation remains valid.', 'good_job');
    $original = $legacy->fresh()->getAttributes();
    $new = $this->feed->sendKudos($this->sender, $this->one->id, null, 'A new message without a company value.');
    foreach (FeedService::KUDOS_IMPACTS as $key => $label) {
        $saved = $this->feed->sendTeamKudos($this->sender, $this->team->id, null, 'The whole team made this moment possible.', $key);
        expect($this->hub->describe($this->reader, $saved))->toMatchArray(['category' => null, 'category_key' => null, 'impact' => $key, 'impact_label' => $label]);
    }
    expect($legacy->fresh()->getAttributes())->toBe($original);
    expect($this->hub->describe($this->reader, $legacy))->toMatchArray(['category' => 'Teamwork', 'category_key' => 'teamwork', 'impact' => 'good_job', 'impact_label' => 'You made a difference']);
    $selected = $this->hub->select($this->sender, ['actor_id' => $this->sender->id, 'version' => 1, 'kudos_id' => $new->id]);
    expect($selected['spotlight'])->toMatchArray(['id' => $new->id, 'category' => null, 'category_key' => null, 'impact' => 'thank_you', 'impact_label' => 'Thank you']);
    $values = collect($this->feed->getValueBreakdown());
    expect($values->sum('count'))->toBe(1)->and($values->firstWhere('key', 'teamwork')['count'])->toBe(1)->and($values->firstWhere('key', 'other')['count'])->toBe(0)
        ->and(collect($this->feed->getKudosTrend())->sum('count'))->toBe(6)->and($this->feed->getKudosLeaderboard()->sole()['kudos_count'])->toBe(2);
    $legacy->impact = null;
    expect($legacy->impactKey())->toBe('good_job')->and($legacy->impactLabel())->toBe('You made a difference');
    $legacy->impact = 'unknown_legacy_heading';
    expect($legacy->impactKey())->toBe('good_job')->and($legacy->fresh()->impact)->toBe('good_job');
});

test('optional value storage is additive and migration-pending writes fail with recoverable validation while valued legacy sends remain usable', function () {
    $columns = collect(Schema::getColumns('hr_kudos'));
    expect($columns->firstWhere('name', 'category')['nullable'])->toBeTrue()->and($columns->firstWhere('name', 'impact')['default'])->toBe('thank_you');
    $pending = Mockery::mock(FeedService::class, [app(AnnouncementAudienceResolver::class), app(HrAudienceAccessService::class), app(HrCurrentStaffService::class), app(HrTeamRecognition::class)])->makePartial();
    $pending->shouldReceive('optionalValueReady')->andReturn(false);
    app()->instance(FeedService::class, $pending);
    $this->actingAs($this->sender)->postJson('/hr/feed/kudos', ['to_user_id' => $this->one->id, 'message' => 'Keep this message available for retry.'])->assertUnprocessable()->assertJsonValidationErrors('category');
    expect(fn () => $pending->sendTeamKudos($this->sender, $this->team->id, null, 'A complete whole-team thank-you.'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('hr_kudos', 0);
    Notification::assertNothingSent();
    $valued = $pending->sendKudos($this->sender, $this->one->id, 'innovation', 'A valued existing path remains available.', 'impressive');
    expect($valued->category)->toBe('innovation')->and($valued->impact)->toBe('impressive');
    $new = $this->feed->sendKudos($this->sender, $this->two->id, null, 'Message-only storage stays meaningful.');
    $migration = require base_path('database/migrations/2026_10_14_210000_allow_message_only_hr_recognition.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back optional value storage');
    expect($new->fresh()->category)->toBeNull()->and($valued->fresh()->category)->toBe('innovation');
});

test('message-only queued team notifications retain actual delivery withdrawal and deleted-record checks', function () {
    $kudos = $this->feed->sendTeamKudos($this->sender, $this->team->id, null, 'Thank you for the care shown by our team.');
    $notifications = [new KudosReceivedNotification($kudos, $this->sender->name), new FeedReplyNotification($this->sender->name, 'your team recognition', 'A thoughtful reply.', $kudos->id)];
    foreach ($notifications as $notification) {
        expect($notification->via($this->one))->not->toBe([])->and($notification->shouldSend($this->one, 'database'))->toBeTrue();
    }
    $this->one->hrEmployeeProfile()->update(['is_active' => false]);
    foreach ($notifications as $notification) {
        expect($notification->shouldSend($this->one, 'database'))->toBeFalse();
    }
    $this->one->hrEmployeeProfile()->update(['is_active' => true]);
    $this->team->update(['is_active' => false]);
    foreach ($notifications as $notification) {
        expect($notification->shouldSend($this->one, 'database'))->toBeFalse();
    }
    $this->team->update(['is_active' => true]);
    foreach ($notifications as $notification) {
        expect($notification->shouldSend($this->one, 'database'))->toBeTrue();
    }
    $kudos->delete();
    foreach ($notifications as $notification) {
        expect($notification->shouldSend($this->one, 'database'))->toBeFalse();
    }
});
