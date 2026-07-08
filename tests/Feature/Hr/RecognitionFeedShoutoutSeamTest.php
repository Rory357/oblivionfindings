<?php

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReaction;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Services\FeedService;
use App\Http\Controllers\Hr\MyHrController;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Seam S11 — Community Feed (/hr/feed) ↔ My-HR Shout-outs (/hr/my/shoutouts).
 *
 * Both surfaces are two lenses on ONE dataset, never a fork:
 *   - FeedService::sendKudos writes a single HrKudos (+ a linked HrFeedPost).
 *   - The FEED reads it via the post's hasOne kudos relation (FeedService::getFeed,
 *     org-wide wall).
 *   - The SHOUT-OUTS tab reads the SAME HrKudos directly by to_user_id / from_user_id
 *     (MyHrController::myHrShoutouts, the viewer's received / given boxes).
 *   - Reactions and replies added through the shared FeedService::toggleReaction /
 *     addReply are single rows keyed by kudos_id — both react/reply controllers
 *     (FeedController + MyHrController) are identical delegations to those methods,
 *     so a reaction/reply on one surface is the SAME row seen on the other.
 *
 * These are pure service/model calls; the protected shout-outs builder is invoked
 * on the real container-resolved MyHrController via reflection so the actual
 * surface read code runs (not a re-implementation). No HTTP → no permission
 * seeding; Notification::fake isolates the kudos-received side effect.
 */
function hrSeamMyShoutouts(User $user, int $tenantId, string $box): array
{
    $controller = app(MyHrController::class);
    $method = new ReflectionMethod($controller, 'myHrShoutouts');
    $method->setAccessible(true);

    return $method->invoke($controller, $user, $tenantId, $box);
}

/** Pull the kudos-typed feed post carrying a given kudos id from the real feed query. */
function hrSeamFeedKudosPost(int $tenantId, int $viewerId, int $kudosId)
{
    return app(FeedService::class)
        ->getFeed($tenantId, null, null, $viewerId)
        ->getCollection()
        ->first(fn ($post) => $post->post_type === 'kudos' && $post->kudos?->id === $kudosId);
}

beforeEach(function () {
    Notification::fake();

    $this->giver = User::factory()->create(['name' => 'Ada Giver']);
    $this->recipient = User::factory()->create(['name' => 'Ben Recipient']);

    $this->kudos = app(FeedService::class)->sendKudos(
        $this->giver,
        $this->recipient->id,
        'teamwork',
        'Outstanding cover on the weekend audit.',
        1,
        'impressive',
    );
});

test('S11 seam: one kudos record is read by BOTH the feed and the my-HR shout-outs surfaces (no fork)', function () {
    // Exactly one kudos row exists — the two surfaces share it, they do not duplicate it.
    expect(HrKudos::count())->toBe(1);

    // FEED read path — surfaced through the linked feed post.
    $feedPost = hrSeamFeedKudosPost(1, $this->giver->id, $this->kudos->id);
    expect($feedPost)->not->toBeNull();
    expect($feedPost->kudos->id)->toBe($this->kudos->id);

    // SHOUT-OUTS read path — the SAME record in the recipient's "received" box…
    $received = collect(hrSeamMyShoutouts($this->recipient, 1, 'received'));
    expect($received->firstWhere('id', $this->kudos->id))->not->toBeNull();

    // …and in the giver's "given" box.
    $given = collect(hrSeamMyShoutouts($this->giver, 1, 'given'));
    expect($given->firstWhere('id', $this->kudos->id))->not->toBeNull();

    // The id the feed shows == the id the shout-outs show == the one HrKudos row.
    expect($feedPost->kudos->id)
        ->toBe($received->firstWhere('id', $this->kudos->id)['id'])
        ->toBe($given->firstWhere('id', $this->kudos->id)['id']);
});

test('S11 seam: a reaction added through the shared path is a single row reflected on both surfaces', function () {
    $reactor = User::factory()->create();

    // The shared mutation both react controllers delegate to.
    app(FeedService::class)->toggleReaction($this->kudos, $reactor->id, 'heart');

    // One row, keyed by kudos_id — no double-count, no per-surface fork.
    expect(HrKudosReaction::where('kudos_id', $this->kudos->id)->count())->toBe(1);

    // FEED read reflects the single reaction…
    $feedPost = hrSeamFeedKudosPost(1, $this->giver->id, $this->kudos->id);
    expect($feedPost->kudos->reactions->where('emoji', 'heart')->count())->toBe(1);

    // …and so does the SHOUT-OUTS read (grouped emoji → reactor list).
    $received = collect(hrSeamMyShoutouts($this->recipient, 1, 'received'));
    $entry = $received->firstWhere('id', $this->kudos->id);
    expect($entry['reactions']['heart'])->toHaveCount(1);
    expect($entry['reactions']['heart'][0]['id'])->toBe($reactor->id);
});

test('S11 seam: a reply added through the shared path is a single row reflected on both surfaces', function () {
    // The shared mutation both reply controllers delegate to (posted by the receiver).
    app(FeedService::class)->addReply($this->kudos, $this->recipient->id, 'Thank you — means a lot!');

    expect(HrKudosReply::where('kudos_id', $this->kudos->id)->count())->toBe(1);

    // FEED read reflects the reply…
    $feedPost = hrSeamFeedKudosPost(1, $this->giver->id, $this->kudos->id);
    expect($feedPost->kudos->replies)->toHaveCount(1);
    expect($feedPost->kudos->replies->first()->body)->toBe('Thank you — means a lot!');

    // …and the giver sees the very same reply on their "given" shout-out.
    $given = collect(hrSeamMyShoutouts($this->giver, 1, 'given'));
    $entry = $given->firstWhere('id', $this->kudos->id);
    expect($entry['replies'])->toHaveCount(1);
    expect($entry['replies'][0]['body'])->toBe('Thank you — means a lot!');
});
