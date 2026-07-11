<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\TimelineCommentLike;
use App\Models\TimelineEvent;
use App\Models\TimelineEventComment;
use App\Models\TimelineEventReaction;
use App\Models\User;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortalTimelineInteractionController extends Controller
{
    private const ALLOWED_REACTIONS = ['❤️', '👍', '😊', '🎉', '🙏', '💛'];

    public function __construct(
        private readonly PortalClientSectionAccess $sectionAccess,
    ) {}

    public function storeComment(Request $request, Client $client, TimelineEvent $timelineEvent)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $this->authorizeVisibleEvent($user, $client, $timelineEvent);

        $validated = $request->validate([
            'body' => 'required|string|max:1000',
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('timeline_event_comments', 'id')
                    ->where(fn ($query) => $query->where('timeline_event_id', $timelineEvent->id)),
            ],
        ]);

        TimelineEventComment::create([
            'timeline_event_id' => $timelineEvent->id,
            'user_id' => $user->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'body' => $validated['body'],
        ]);

        return redirect()->back();
    }

    public function toggleCommentLike(Request $request, Client $client, TimelineEventComment $timelineEventComment)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $this->authorizeVisibleComment($user, $client, $timelineEventComment);

        $existing = TimelineCommentLike::where('comment_id', $timelineEventComment->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            TimelineCommentLike::create([
                'comment_id' => $timelineEventComment->id,
                'user_id' => $user->id,
            ]);
        }

        return redirect()->back();
    }

    public function destroyComment(Request $request, Client $client, TimelineEventComment $timelineEventComment)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $this->authorizeVisibleComment($user, $client, $timelineEventComment);
        abort_unless($timelineEventComment->user_id === $user->id, 403);

        $timelineEventComment->delete();

        return redirect()->back();
    }

    public function toggleReaction(Request $request, Client $client, TimelineEvent $timelineEvent)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $this->authorizeVisibleEvent($user, $client, $timelineEvent);

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10', 'in:'.implode(',', self::ALLOWED_REACTIONS)],
        ]);

        $existing = TimelineEventReaction::where('timeline_event_id', $timelineEvent->id)
            ->where('user_id', $user->id)
            ->where('emoji', $validated['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            TimelineEventReaction::create([
                'timeline_event_id' => $timelineEvent->id,
                'user_id' => $user->id,
                'emoji' => $validated['emoji'],
            ]);
        }

        return redirect()->back();
    }

    private function authorizeVisibleComment(
        User $user,
        Client $client,
        TimelineEventComment $comment,
    ): void {
        $event = $comment->timelineEvent;
        abort_unless($event, 404);
        $this->authorizeVisibleEvent($user, $client, $event);
    }

    private function authorizeVisibleEvent(
        User $user,
        Client $client,
        TimelineEvent $event,
    ): void {
        abort_unless(
            (int) $event->client_id === (int) $client->id
                && $event->visibility === 'portal',
            404,
        );

        $query = TimelineEvent::query()
            ->whereKey($event->id)
            ->where('client_id', $client->id)
            ->where('visibility', 'portal');
        $this->sectionAccess->constrainTimeline(
            $query,
            $this->sectionAccess->for($user, $client),
        );

        abort_unless($query->exists(), 403);
    }
}
