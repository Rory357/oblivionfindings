<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubParticipation;
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
        ]);
        $participation->write($request->user(), $page, $data);

        return back()->with('success', match ($data['kind']) {
            'comment' => 'Comment added.', 'vote' => 'Your vote was saved.', default => 'Reaction updated.',
        });
    }

    public function destroy(Request $request, HubPage $page, int $interaction, HubParticipation $participation)
    {
        $participation->remove($request->user(), $page, $interaction);

        return back()->with('success', 'Comment removed.');
    }
}
