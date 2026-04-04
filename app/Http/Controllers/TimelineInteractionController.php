<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\TimelineCommentLike;
use App\Models\TimelineEvent;
use App\Models\TimelineEventComment;
use App\Models\TimelineEventReaction;
use Illuminate\Http\Request;

class TimelineInteractionController extends Controller
{
    private const ALLOWED_REACTIONS = ['❤️', '👍', '😊', '🎉', '🙏', '💛'];

    public function storeComment(Request $request, Client $client, TimelineEvent $timelineEvent)
    {
        $this->authorize('view', $client);
        abort_unless($timelineEvent->client_id === $client->id, 404);

        $validated = $request->validate([
            'body' => 'required|string|max:1000',
            'parent_id' => 'nullable|integer|exists:timeline_event_comments,id',
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
        abort_unless($timelineEventComment->user_id === $request->user()->id, 403);

        $timelineEventComment->delete();

        return redirect()->back();
    }

    public function toggleCommentLike(Request $request, Client $client, TimelineEventComment $timelineEventComment)
    {
        $this->authorize('view', $client);

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

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10', 'in:' . implode(',', self::ALLOWED_REACTIONS)],
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
}
