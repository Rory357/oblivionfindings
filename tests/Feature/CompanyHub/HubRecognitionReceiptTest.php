<?php

use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Notifications\KudosReceivedNotification;
use App\Domain\Hr\Services\FeedService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Recognised canonical team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $staff = function (string $name, ?Site $site = null, ?int $department = null): User {
        $user = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'support_worker')->sole());
        ensureCanonicalHrStaffProfile($user, $site ?? $this->site, ['department_id' => $department]);

        return $user;
    };
    $this->sender = $staff('Receipt sender');
    $this->other = $staff('Replacement signed-in account');
    $this->one = $staff('Recognised colleague one', department: $this->team->id);
    $this->two = $staff('Recognised colleague two', department: $this->team->id);
    $this->hidden = $staff('Other-site colleague', $this->outside);
    $this->payload = ['hub_receipt' => true, 'actor_id' => $this->sender->id,
        'to_user_ids' => [$this->two->id, $this->one->id, $this->two->id], 'category' => null,
        'impact' => 'thank_you', 'message' => '  Thank you for keeping the exact shared work moving.  '];
    $this->url = '/hr/feed/kudos';
});

test('Hub recognition receipt identifies actual individual fanout and current authorised reader destinations', function () {
    $this->actingAs($this->sender);
    $saved = $this->postJson($this->url, $this->payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('saved', true)->assertJsonPath('actor_id', $this->sender->id)->assertJsonCount(2, 'records')
        ->assertJsonPath('records.0.recipient_type', 'person')->assertJsonPath('records.0.to_user_id', $this->two->id)
        ->assertJsonPath('records.1.to_user_id', $this->one->id)->assertJsonPath('records.0.to_department_id', null);
    foreach ($saved->json('records') as $record) {
        $kudos = HrKudos::findOrFail($record['kudos_id']);
        expect($kudos->from_user_id)->toBe($this->sender->id)->and($kudos->to_user_id)->toBe($record['to_user_id'])
            ->and($kudos->feed_post_id)->toBe($record['feed_post_id'])->and($kudos->message)->toBe(trim($this->payload['message']))
            ->and($kudos->category)->toBeNull()->and($record['reader_url'])->toBe(HubRecognition::readerUrl($kudos->id));
        expect(HrFeedPost::findOrFail($record['feed_post_id'])->user_id)->toBe($this->sender->id);
        $this->get($record['reader_url'])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedRecognition.id', $kudos->id)->where('recognitionId', $kudos->id)->where('recognitionUnavailable', false));
    }
    $this->assertDatabaseCount('hr_kudos', 2);
    $this->assertDatabaseCount('hr_feed_posts', 2);
    Notification::assertSentTo([$this->one, $this->two], KudosReceivedNotification::class);
    Notification::assertNotSentTo($this->sender, KudosReceivedNotification::class);
});

test('Hub recognition team receipt retains one canonical team record and its exact membership snapshot', function () {
    $saved = $this->actingAs($this->sender)->postJson($this->url, [...$this->payload,
        'to_user_ids' => [], 'recipient_type' => 'team', 'to_department_id' => $this->team->id])
        ->assertOk()->assertJsonPath('actor_id', $this->sender->id)->assertJsonPath('saved', true)->assertJsonCount(1, 'records')
        ->assertJsonPath('records.0.recipient_type', 'team')->assertJsonPath('records.0.to_user_id', null)
        ->assertJsonPath('records.0.to_department_id', $this->team->id);
    $kudos = HrKudos::findOrFail($saved->json('records.0.kudos_id'));
    expect($kudos->feed_post_id)->toBe($saved->json('records.0.feed_post_id'))
        ->and($kudos->recipients()->pluck('users.id')->sort()->values()->all())->toBe(collect([$this->one->id, $this->two->id])->sort()->values()->all());
    $this->get($saved->json('records.0.reader_url'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedRecognition.id', $kudos->id)->where('selectedRecognition.recipient_type', 'team')
        ->where('selectedRecognition.to_department_id', $this->team->id)->where('recognitionUnavailable', false));
    $this->assertDatabaseCount('hr_kudos', 1);
    $this->assertDatabaseCount('hr_feed_posts', 1);
    Notification::assertSentTo([$this->one, $this->two], KudosReceivedNotification::class);
});

test('Hub receipt negotiation preserves legacy HR HTML Inertia and JSON redirects', function () {
    $legacy = ['to_user_id' => $this->one->id, 'message' => 'Existing HR recognition remains a canonical redirect.'];
    $this->actingAs($this->sender)->post($this->url, $legacy)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson($this->url, $legacy)->assertRedirect();
    $this->withHeaders(['X-Inertia' => 'true'])->post($this->url, [...$legacy, 'actor_id' => $this->sender->id])->assertRedirect();
    $this->assertDatabaseCount('hr_kudos', 3);
    $this->assertDatabaseCount('hr_feed_posts', 3);
    expect(HrKudos::pluck('to_user_id')->unique()->all())->toBe([$this->one->id]);
});

test('Hub recognition receipt rejects stale actors withdrawn give authority and unsafe current recipient IDs', function () {
    $this->actingAs($this->other)->postJson($this->url, $this->payload)->assertConflict();
    $this->actingAs($this->sender)->postJson($this->url, array_diff_key($this->payload, ['actor_id' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    $this->postJson($this->url, [...$this->payload, 'to_user_ids' => [$this->one->id, $this->hidden->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('to_user_ids');
    $this->hidden->hrEmployeeProfile()->update(['department_id' => $this->team->id]);
    $this->postJson($this->url, [...$this->payload, 'to_user_ids' => [], 'to_department_id' => $this->team->id])
        ->assertUnprocessable()->assertJsonValidationErrors('to_department_id');
    $this->sender->load(['permissionOverrides', 'roles.permissions']);
    $this->sender->permissionOverrides()->attach(Permission::where('key', 'hr.recognition.give')->value('id'), ['allowed' => false]);
    expect($this->sender->canDo('hr.recognition.give'))->toBeTrue();
    $this->actingAs($this->sender)->postJson($this->url, $this->payload)->assertForbidden();
    $this->sender->permissionOverrides()->detach();
    $this->sender->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    $this->postJson($this->url, $this->payload)->assertForbidden();
    $this->sender->permissionOverrides()->detach();
    $this->sender->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->postJson($this->url, $this->payload)->assertForbidden();
    $this->sender->hrEmployeeProfile()->update(['end_date' => null]);
    $this->assertDatabaseCount('hr_kudos', 0);
    $this->assertDatabaseCount('hr_feed_posts', 0);
    Notification::assertNothingSent();
    $this->sender->permissionOverrides()->attach(Permission::where('key', 'hr.recognition.view')->value('id'), ['allowed' => false]);
    $this->postJson($this->url, $this->payload)->assertOk()->assertJsonPath('saved', true)
        ->assertJsonPath('records.0.reader_url', null)->assertJsonPath('records.1.reader_url', null);
});

test('Hub exact recognition selection survives bounded feed search and retains canonical social identity', function () {
    $feed = app(FeedService::class);
    $kudos = $feed->sendKudos($this->sender, $this->one->id, null, 'An older exact recognition with a retained conversation.');
    $kudos->feedPost()->update(['created_at' => now()->subDay()]);
    $kudos->update(['created_at' => now()->subDay()]);
    $feed->toggleReaction($kudos, $this->one->id, 'heart');
    $reply = $feed->addReply($kudos, $this->one->id, 'A reply attached to this actual recognition.');
    foreach (range(1, 21) as $index) {
        $feed->sendKudos($this->sender, $this->one->id, null, 'A newer shared recognition '.$index);
    }
    $this->actingAs($this->sender)->get('/company-hub?view=shoutouts&recognition='.$kudos->id.'&search=not-in-current-search')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('kudos', [])->where('recognitionId', $kudos->id)
        ->where('selectedRecognition.id', $kudos->id)->where('recognitionUnavailable', false)
        ->where('selectedRecognition.reactions.heart.count', 1)->where('selectedRecognition.replies.0.id', $reply->id));
    expect(app(HubRecognition::class)->listing($this->sender))->toHaveCount(20)
        ->and(collect(app(HubRecognition::class)->listing($this->sender))->pluck('id')->all())->not->toContain($kudos->id);
    $this->assertDatabaseCount('hr_kudos_reactions', 1);
    $this->assertDatabaseCount('hr_kudos_replies', 1);
});

test('Hub recognition selectors fail closed for private audience staff site permission and missing records', function () {
    $kudos = app(FeedService::class)->sendKudos($this->sender, $this->one->id, null, 'Sensitive recognition must not be guessed by its selector.');
    $url = '/company-hub?view=shoutouts&recognition='.$kudos->id;
    $assertUnavailable = fn () => $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedRecognition', null)->where('recognitionId', null)->where('recognitionUnavailable', true));
    $this->actingAs($this->sender);
    $kudos->update(['is_public' => false]);
    $assertUnavailable();
    $kudos->update(['is_public' => true]);
    $kudos->feedPost()->update(['target_audience' => 'site', 'target_value' => (string) $this->outside->id]);
    $assertUnavailable();
    $kudos->feedPost()->update(['target_audience' => 'all', 'target_value' => null]);
    $this->one->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $assertUnavailable();
    $this->one->hrEmployeeProfile()->update(['end_date' => null, 'primary_site_id' => $this->outside->id]);
    $assertUnavailable();
    $this->one->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $this->sender->permissionOverrides()->attach(Permission::where('key', 'hr.recognition.view')->value('id'), ['allowed' => false]);
    $this->actingAs($this->sender->fresh());
    $assertUnavailable();
    $this->sender->permissionOverrides()->detach();
    $this->actingAs($this->sender->fresh());
    $kudos->delete();
    $assertUnavailable();
    $this->get('/company-hub?view=home&recognition=1')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedRecognition', null)->where('recognitionId', null)->where('recognitionUnavailable', false));
    $this->getJson('/company-hub?view=shoutouts&recognition=2147483648')->assertUnprocessable()->assertJsonValidationErrors('recognition');
});

test('Hub recognition receipt never reports saved when a late canonical fanout write rolls back', function () {
    $insertions = 0;
    DB::listen(function (QueryExecuted $query) use (&$insertions): void {
        if (str_starts_with(strtolower($query->sql), 'insert into `hr_kudos`') && ++$insertions === 2) {
            throw new RuntimeException('Synthetic second-recipient persistence failure.');
        }
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($this->sender)->postJson($this->url, $this->payload))
        ->toThrow(RuntimeException::class, 'Synthetic second-recipient persistence failure.');
    $this->assertDatabaseCount('hr_kudos', 0);
    $this->assertDatabaseCount('hr_feed_posts', 0);
});
