<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\TimelineCommentLike;
use App\Models\TimelineEvent;
use App\Models\TimelineEventComment;
use App\Models\TimelineEventReaction;
use App\Services\Clients\ClientProfileSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TimelineInteractionController extends Controller
{
    private const ALLOWED_REACTIONS = ['❤️', '👍', '😊', '🎉', '🙏', '💛'];

    public function __construct(
        protected ClientProfileSectionAccess $sectionAccess,
    ) {}

    public function storeComment(Request $request, Client $client, TimelineEvent $timelineEvent)
    {
        $this->authorize('view', $client);
        abort_unless($timelineEvent->client_id === $client->id, 404);
        $this->authorizeInteractionCapabilities($request, $client);

        $validated = $request->validate([
            'body' => 'required|string|max:1000',
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('timeline_event_comments', 'id')->where(
                    fn ($query) => $query->where('timeline_event_id', $timelineEvent->id),
                ),
            ],
        ]);

        TimelineEventComment::create([
            'timeline_event_id' => $timelineEvent->id,
            'user_id' => $request->user()->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'body' => $validated['body'],
        ]);

        return redirect()->back();
    }

    public function destroyComment(Request $request, Client $client, TimelineEventComment $timelineEventComment)
    {
        $this->authorize('view', $client);
        $this->assertCommentBelongsToClient($timelineEventComment, $client);
        $this->authorizeInteractionCapabilities($request, $client);
        abort_unless($timelineEventComment->user_id === $request->user()->id, 403);

        $timelineEventComment->delete();

        return redirect()->back();
    }

    public function toggleCommentLike(Request $request, Client $client, TimelineEventComment $timelineEventComment)
    {
        $this->authorize('view', $client);
        $this->assertCommentBelongsToClient($timelineEventComment, $client);
        $this->authorizeInteractionCapabilities($request, $client);

        $existing = TimelineCommentLike::where('comment_id', $timelineEventComment->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            TimelineCommentLike::create([
                'comment_id' => $timelineEventComment->id,
                'user_id' => $request->user()->id,
            ]);
        }

        return redirect()->back();
    }

    public function toggleReaction(Request $request, Client $client, TimelineEvent $timelineEvent)
    {
        $this->authorize('view', $client);
        abort_unless($timelineEvent->client_id === $client->id, 404);
        $this->authorizeInteractionCapabilities($request, $client);

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10', 'in:'.implode(',', self::ALLOWED_REACTIONS)],
        ]);

        $existing = TimelineEventReaction::where('timeline_event_id', $timelineEvent->id)
            ->where('user_id', $request->user()->id)
            ->where('emoji', $validated['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            TimelineEventReaction::create([
                'timeline_event_id' => $timelineEvent->id,
                'user_id' => $request->user()->id,
                'emoji' => $validated['emoji'],
            ]);
        }

        return redirect()->back();
    }

    private function authorizeInteractionCapabilities(Request $request, Client $client): void
    {
        $user = $request->user();
        abort_unless(
            $user
                && $this->sectionAccess->canViewTimeline($user, $client)
                && $user->canDo('timeline.create'),
            403,
        );
    }

    private function assertCommentBelongsToClient(
        TimelineEventComment $comment,
        Client $client,
    ): void {
        abort_unless(
            $comment->timelineEvent()
                ->where('client_id', $client->id)
                ->exists(),
            404,
        );
    }
}
