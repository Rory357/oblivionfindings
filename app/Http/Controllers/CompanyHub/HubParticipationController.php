<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubCommentModeration;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPolls;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HubParticipationController extends Controller
{
    public function store(Request $request, HubPage $page, HubParticipation $participation)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['comment', 'reaction', 'vote', 'helpful', 'campaign'])],
            'body' => 'required_if:kind,comment|nullable|string|max:2000',
            'reaction' => ['required_if:kind,reaction', Rule::in(HubParticipation::REACTIONS)],
            'active' => 'required_if:kind,reaction,helpful,campaign|boolean',
            'parent_id' => 'nullable|integer|min:1',
            'step_id' => 'required_if:kind,campaign|string|max:60',
            'step_key' => 'required_if:kind,campaign|string|size:64',
            'block_id' => 'required_if:kind,vote|string|max:60',
            'poll_key' => 'required_if:kind,vote|string|size:64',
            'choice' => 'required_if:kind,vote|integer|min:0|max:7',
            'actor_id' => 'sometimes|required|integer|min:1', 'revision_id' => 'sometimes|required|integer|min:1',
            'poll_version' => 'sometimes|required|integer|min:0',
            'user_id' => 'prohibited', 'author_id' => 'prohibited', 'hub_page_id' => 'prohibited', 'page_id' => 'prohibited',
        ]);
        $participation->write($request->user(), $page, $data);

        if ($data['kind'] === 'vote' && $request->expectsJson() && ! $request->header('X-Inertia')) {
            $poll = $participation->read($request->user(), $page)['polls'][$data['block_id']];

            return response()->json(['saved' => true, 'page_id' => (int) $page->id, 'block_id' => $data['block_id'], 'key' => $poll['key'], 'version' => $poll['version']])
                ->header('Cache-Control', 'private, no-store');
        }

        return back()->with('success', match ($data['kind']) {
            'comment' => 'Comment added.', 'vote' => 'Your vote was saved.', default => 'Reaction updated.',
        });
    }

    public function pollState(Request $request, HubPage $page, string $block, HubPolls $polls)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1', 'revision_id' => 'required|integer|min:1',
            'poll_key' => 'required|string|size:64', 'version' => 'required|integer|min:0',
            'action' => ['required', Rule::in(['close', 'reopen'])], 'closes_at' => 'sometimes|nullable|string|max:40',
            'user_id' => 'prohibited', 'author_id' => 'prohibited', 'page_id' => 'prohibited', 'hub_page_id' => 'prohibited',
            'parent_id' => 'prohibited', 'body' => 'prohibited', 'choice' => 'prohibited',
        ]);

        return response()->json($polls->transition($request->user(), $page, $block, $data))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, HubPage $page, int $interaction, HubParticipation $participation)
    {
        $data = $request->validate([
            'actor_id' => 'sometimes|required|integer|min:1', 'revision_id' => 'sometimes|required|integer|min:1', 'version' => 'sometimes|required|integer|min:1',
            'user_id' => 'prohibited', 'author_id' => 'prohibited', 'hub_page_id' => 'prohibited', 'page_id' => 'prohibited', 'parent_id' => 'prohibited', 'body' => 'prohibited',
        ]);
        $participation->remove($request->user(), $page, $interaction, $data);

        return back()->with('success', 'Comment removed.');
    }

    public function moderation(Request $request, HubPage $page, HubCommentModeration $moderation)
    {
        $data = $request->validate(['actor_id' => 'required|integer|min:1', 'revision_id' => 'required|integer|min:1']);

        return response()->json($moderation->review($request->user(), $page, $data))->header('Cache-Control', 'private, no-store');
    }

    public function appreciate(Request $request, HubPage $page, int $comment, HubParticipation $participation)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1', 'revision_id' => 'required|integer|min:1',
            'version' => 'required|integer|min:1', 'active' => 'required|boolean',
            'user_id' => 'prohibited', 'author_id' => 'prohibited', 'hub_page_id' => 'prohibited', 'page_id' => 'prohibited',
            'parent_id' => 'prohibited', 'body' => 'prohibited', 'reaction' => 'prohibited',
        ]);

        return response()->json($participation->appreciate($request->user(), $page, $comment, $data))->header('Cache-Control', 'private, no-store');
    }

    public function moderate(Request $request, HubPage $page, int $interaction, HubCommentModeration $moderation)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1', 'revision_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1', 'hidden' => 'required|boolean',
            'user_id' => 'prohibited', 'author_id' => 'prohibited', 'hub_page_id' => 'prohibited', 'page_id' => 'prohibited', 'parent_id' => 'prohibited', 'body' => 'prohibited',
        ]);

        return response()->json($moderation->update($request->user(), $page, $interaction, $data))->header('Cache-Control', 'private, no-store');
    }
}
