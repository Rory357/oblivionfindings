<?php

use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Models\HrRecognitionImage;
use App\Domain\Hr\Notifications\RecognitionMentionNotification;
use App\Domain\Hr\Services\FeedService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Social recognition team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $staff = function (string $name, string $role = 'support_worker', ?Site $site = null): User {
        $person = User::factory()->create(['name' => $name, 'role' => $role, 'approved_at' => now()]);
        $person->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($person, $site ?? $this->site, ['department_id' => $this->team->id]);

        return $person;
    };
    $this->sender = $staff('Shoutout sender');
    $this->recipient = $staff('Shoutout recipient');
    $this->reader = $staff('Audience colleague');
    $this->hidden = $staff('Other site colleague', site: $this->outside);
    $this->moderator = $staff('Recognition moderator', 'hr');
    $this->feed = app(FeedService::class);
    $this->kudos = $this->feed->sendKudos($this->sender, $this->recipient->id, 'teamwork', 'A shared recognition for thoughtful work.');
    $this->base = '/company-hub/recognition/'.$this->kudos->id;
});

test('current authorised audience readers participate while private and withdrawn authority remain denied', function () {
    $this->actingAs($this->reader)->postJson($this->base.'/replies', ['actor_id' => $this->reader->id, 'body' => 'I appreciate this too.'])
        ->assertOk()->assertJsonPath('replies.0.user_id', $this->reader->id)->assertJsonPath('replies.0.can_edit', true)
        ->assertJsonPath('can_reply', true)->assertHeader('Cache-Control', 'no-store, private');
    $this->kudos->update(['is_public' => false]);
    expect($this->feed->canViewKudos($this->kudos->fresh(), $this->reader))->toBeFalse()
        ->and($this->feed->canReplyKudos($this->kudos->fresh(), $this->reader))->toBeFalse()
        ->and($this->feed->canViewKudos($this->kudos->fresh(), $this->recipient))->toBeTrue();
    $this->postJson($this->base.'/replies', ['actor_id' => $this->reader->id, 'body' => 'Private guessing attempt'])->assertNotFound();
    $this->get('/hr/feed')->assertOk()->assertDontSee($this->kudos->message);
    $this->kudos->update(['is_public' => true]);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'hr.recognition.give')->value('id'), ['allowed' => false]);
    $this->actingAs($this->reader->fresh())->postJson($this->base.'/replies', ['actor_id' => $this->reader->id, 'body' => 'Withdrawn permission'])->assertForbidden();
    $this->reader->permissionOverrides()->detach();
    $this->kudos->feedPost()->update(['target_audience' => 'site', 'target_value' => (string) $this->outside->id]);
    $this->actingAs($this->reader->fresh())->getJson($this->base.'/conversation')->assertNotFound();
    $this->assertDatabaseCount('hr_kudos_replies', 1);
});

test('threads bind their actual parent and author edits enforce account ownership and optimistic versions', function () {
    $root = $this->feed->addReply($this->kudos, $this->recipient->id, 'A first comment.');
    $this->actingAs($this->reader)->postJson($this->base.'/replies', ['actor_id' => $this->reader->id, 'body' => 'A threaded reply.', 'parent_id' => $root->id])
        ->assertOk()->assertJsonPath('replies.1.parent_id', $root->id);
    $reply = HrKudosReply::where('user_id', $this->reader->id)->sole();
    $url = $this->base.'/replies/'.$reply->id;
    $this->patchJson($url, ['actor_id' => $this->reader->id, 'version' => 1, 'body' => 'Edited author words.'])
        ->assertOk()->assertJsonPath('replies.1.version', 2)->assertJsonPath('replies.1.body', 'Edited author words.');
    $this->patchJson($url, ['actor_id' => $this->reader->id, 'version' => 1, 'body' => 'Stale words'])->assertConflict();
    $this->patchJson($url, ['actor_id' => $this->sender->id, 'version' => 2, 'body' => 'Wrong account'])->assertConflict();
    $this->patchJson($url, ['actor_id' => $this->reader->id, 'version' => 2, 'body' => '   '])->assertUnprocessable();
    $this->actingAs($this->sender)->patchJson($url, ['actor_id' => $this->sender->id, 'version' => 2, 'body' => 'Not the author'])->assertForbidden();
    $other = $this->feed->sendKudos($this->sender, $this->recipient->id, null, 'A different shared recognition.');
    $foreign = $this->feed->addReply($other, $this->sender->id, 'A foreign parent.');
    $this->postJson($this->base.'/replies', ['actor_id' => $this->sender->id, 'body' => 'Foreign parent injection', 'parent_id' => $foreign->id])->assertNotFound();
    expect($reply->fresh()->body)->toBe('Edited author words.')->and($reply->fresh()->parent_id)->toBe($root->id);
});

test('moderation hides and restores the canonical comment with reasons versions and private media denial', function () {
    $this->actingAs($this->reader)->postJson($this->base.'/replies', ['actor_id' => $this->reader->id,
        'body' => 'A comment to moderate.', 'images' => [UploadedFile::fake()->image('comment.png')]])->assertOk();
    $reply = HrKudosReply::sole();
    $image = HrRecognitionImage::sole();
    $url = $this->base.'/replies/'.$reply->id.'/moderation';
    $this->patchJson($url, ['actor_id' => $this->reader->id, 'version' => 1, 'hidden' => true, 'reason' => 'Review'])->assertForbidden();
    $this->moderator->permissionOverrides()->attach(Permission::where('key', 'hr.recognition.give')->value('id'), ['allowed' => false]);
    $this->actingAs($this->moderator->fresh())->patchJson($url, ['actor_id' => $this->moderator->id, 'version' => 1, 'hidden' => true, 'reason' => 'Needs review'])
        ->assertOk()->assertJsonPath('replies.0.hidden', true)->assertJsonPath('replies.0.version', 2);
    $this->actingAs($this->recipient)->getJson($this->base.'/conversation')->assertOk()->assertJsonPath('replies.0.body', null)
        ->assertJsonPath('replies.0.images', [])->assertJsonPath('replies.0.moderation_reason', null);
    $this->get('/company-hub/recognition-images/'.$image->id)->assertNotFound();
    $this->actingAs($this->moderator)->patchJson($url, ['actor_id' => $this->moderator->id, 'version' => 1, 'hidden' => false, 'reason' => 'Stale restore'])->assertConflict();
    $this->patchJson($url, ['actor_id' => $this->moderator->id, 'version' => 2, 'hidden' => false, 'reason' => 'Reviewed and restored'])->assertOk();
    $this->actingAs($this->recipient)->getJson($this->base.'/conversation')->assertOk()->assertJsonPath('replies.0.body', 'A comment to moderate.');
    $this->get('/company-hub/recognition-images/'.$image->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

test('mentions use current audience and site membership and queued delivery rechecks the actual source', function () {
    $options = $this->actingAs($this->sender)->getJson('/company-hub/recognition/mention-options')->assertOk()->json('people');
    expect(array_column($options, 'id'))->toContain($this->reader->id)->not->toContain($this->hidden->id);
    $this->postJson($this->base.'/replies', ['actor_id' => $this->sender->id, 'body' => 'Thanks to the wider group.', 'mention_ids' => [$this->reader->id]])->assertOk();
    $notice = Notification::sent($this->reader, RecognitionMentionNotification::class)->sole();
    expect($notice->afterCommit)->toBeTrue()->and($notice->shouldSend($this->reader, 'database'))->toBeTrue()
        ->and($notice->toArray($this->reader)['message'])->toBe('');
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    $this->reader->hrEmployeeProfile()->update(['is_active' => true]);
    $this->postJson($this->base.'/replies', ['actor_id' => $this->sender->id, 'body' => 'An unsafe mention.', 'mention_ids' => [$this->hidden->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('mention_ids');
    $this->kudos->feedPost()->update(['target_audience' => 'site', 'target_value' => (string) $this->outside->id]);
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    $this->assertDatabaseCount('hr_kudos_replies', 1);
    $this->assertDatabaseCount('hr_recognition_mentions', 1);
});

test('a direct picture ID cannot reveal a comment whose author left the readers approved sites', function () {
    $this->actingAs($this->reader)->postJson($this->base.'/replies', ['actor_id' => $this->reader->id,
        'body' => 'A picture with current author visibility.', 'images' => [UploadedFile::fake()->image('site-scoped.png')]])->assertOk();
    $image = HrRecognitionImage::sole();
    $this->actingAs($this->sender)->get('/company-hub/recognition-images/'.$image->id)->assertOk();
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->outside->id, 'secondary_site_ids' => []]);
    $this->getJson($this->base.'/conversation')->assertOk()->assertJsonPath('replies', []);
    $this->get('/company-hub/recognition-images/'.$image->id)->assertNotFound();
});

test('a mention cannot target someone who is visible to the actor but cannot read this Hub card', function () {
    $this->sender->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->outside->id]]);
    $this->actingAs($this->sender->fresh());
    $standalone = $this->getJson('/company-hub/recognition/mention-options')->assertOk()->json('people');
    expect(array_column($standalone, 'id'))->toContain($this->hidden->id);
    $context = $this->getJson($this->base.'/mention-options')->assertOk()->json('people');
    expect(array_column($context, 'id'))->not->toContain($this->hidden->id);
    $this->postJson($this->base.'/replies', ['actor_id' => $this->sender->id, 'body' => 'A visible person with an unavailable card.', 'mention_ids' => [$this->hidden->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('mention_ids');
    $this->assertDatabaseCount('hr_kudos_replies', 0);
});

test('multipart recognition receipt privately stores pictures and retries return the same canonical send', function () {
    $requestId = (string) Str::uuid();
    $payload = ['hub_receipt' => true, 'actor_id' => $this->sender->id, 'client_request_id' => $requestId,
        'to_user_id' => $this->recipient->id, 'message' => 'Recognition with an actual private picture.', 'mention_ids' => [$this->reader->id],
        'images' => [UploadedFile::fake()->image('recognition.png')]];
    $first = $this->actingAs($this->sender)->postJson('/hr/feed/kudos', $payload)->assertOk()->json();
    $this->postJson('/hr/feed/kudos', $payload)->assertOk()->assertExactJson($first);
    $this->postJson('/hr/feed/kudos', [...$payload, 'message' => 'A different message reusing one send key.'])->assertConflict();
    $this->assertDatabaseCount('hr_kudos', 2);
    $this->assertDatabaseCount('hr_recognition_images', 1);
    expect(Storage::disk('private')->allFiles())->toHaveCount(1);
    $id = $first['records'][0]['kudos_id'];
    $record = $this->getJson('/company-hub/recognition/'.$id.'/conversation')->assertOk()->json();
    expect($record['images'])->toHaveCount(1)->and($record['mentions'][0]['id'])->toBe($this->reader->id)
        ->and(json_encode($record))->not->toContain('hr/recognition/');
    $kudos = HrKudos::findOrFail($id);
    $kudos->update(['is_public' => false]);
    $this->actingAs($this->reader)->get($record['images'][0]['url'])->assertNotFound();
});

test('invalid pictures and a late receipt failure leave no new canonical records or private files', function () {
    $payload = ['hub_receipt' => true, 'actor_id' => $this->sender->id, 'to_user_id' => $this->recipient->id, 'message' => 'A validated picture send.'];
    $this->actingAs($this->sender)->postJson('/hr/feed/kudos', [...$payload, 'images' => [UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]])->assertUnprocessable();
    $this->postJson('/hr/feed/kudos', [...$payload, 'images' => array_map(fn () => UploadedFile::fake()->image('picture.png'), range(1, 5))])->assertUnprocessable();
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains(strtolower($query->sql), 'insert into `hr_recognition_send_receipts`')) {
            throw new RuntimeException('Synthetic late receipt failure.');
        }
    });
    $this->postJson('/hr/feed/kudos', [...$payload, 'client_request_id' => (string) Str::uuid(), 'images' => [UploadedFile::fake()->image('saved-before-failure.png')]])->assertServerError();
    $this->assertDatabaseCount('hr_kudos', 1);
    $this->assertDatabaseCount('hr_recognition_images', 0);
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('author withdrawal clears pictures and mentions while retained children use a content-free parent', function () {
    $this->actingAs($this->reader)->postJson($this->base.'/replies', ['actor_id' => $this->reader->id, 'body' => 'Words to withdraw.',
        'mention_ids' => [$this->recipient->id], 'images' => [UploadedFile::fake()->image('withdraw.png')]])->assertOk();
    $parent = HrKudosReply::sole();
    $imageId = HrRecognitionImage::sole()->id;
    $this->actingAs($this->sender)->postJson($this->base.'/replies', ['actor_id' => $this->sender->id, 'body' => 'A retained child.', 'parent_id' => $parent->id])->assertOk();
    $this->actingAs($this->reader)->deleteJson($this->base.'/replies/'.$parent->id, ['actor_id' => $this->reader->id, 'version' => 1])
        ->assertOk()->assertJsonPath('replies.0.body', null)->assertJsonPath('replies.0.removed', true)->assertJsonPath('replies.0.images', [])
        ->assertJsonPath('replies.1.parent_id', $parent->id)->assertJsonPath('replies.1.body', 'A retained child.');
    $this->get('/company-hub/recognition-images/'.$imageId)->assertNotFound();
    $this->assertDatabaseCount('hr_recognition_images', 0);
    $this->assertDatabaseCount('hr_recognition_mentions', 0);
});

test('recognition browsing filters the full authorised source before deterministic pagination', function () {
    $older = $this->kudos;
    $older->update(['created_at' => now()->subDays(2)]);
    foreach (range(1, 22) as $index) {
        $this->feed->sendKudos($this->sender, $this->recipient->id, 'innovation', 'Newer canonical recognition '.$index);
    }
    $service = app(HubRecognition::class);
    $first = $service->browse($this->reader, ['per_page' => 5]);
    $second = $service->browse($this->reader, ['per_page' => 5, 'page' => 2]);
    expect($first['pagination']['has_more'])->toBeTrue()->and($first['pagination']['next_page'])->toBe(2)
        ->and(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')))->toBe([]);
    $filtered = $service->browse($this->reader, ['search' => 'thoughtful', 'category' => 'teamwork', 'team_id' => $this->team->id]);
    expect(array_column($filtered['data'], 'id'))->toBe([$older->id]);
    $this->feed->toggleReaction($older, $this->reader->id, 'heart');
    expect($service->browse($this->reader, ['sort' => 'most_appreciated', 'per_page' => 1])['data'][0]['id'])->toBe($older->id);
    $older->update(['is_public' => false]);
    expect($service->browse($this->reader, ['search' => 'thoughtful'])['data'])->toBe([]);
});
