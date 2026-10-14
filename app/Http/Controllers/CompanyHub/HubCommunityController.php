<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubCommunity;
use App\Domain\CompanyHub\Services\HubDepartment;
use App\Domain\CompanyHub\Services\HubDiscovery;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HubCommunityController extends Controller
{
    public function idea(Request $request, HubCommunity $community)
    {
        $data = $request->validate(['title' => 'required|string|max:160', 'body' => 'required|string|max:4000', 'category' => ['required', Rule::in(HubCommunity::CATEGORIES)]]);
        $community->createIdea($request->user(), $data);

        return back()->with('success', 'Your idea is on the board.');
    }

    public function update(Request $request, int $idea, HubCommunity $community)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['support', 'comment', 'remove_comment', 'status', 'remove'])],
            'active' => 'required_if:action,support|boolean', 'body' => 'required_if:action,comment|string|max:2000',
            'comment_id' => 'required_if:action,remove_comment|integer',
            'status' => ['required_if:action,status', Rule::in(HubCommunity::STATUSES)],
            'status_note' => 'required_if:action,status|string|max:2000', 'version' => 'required_if:action,status,remove|integer|min:1',
        ]);
        $community->updateIdea($request->user(), $idea, $data);

        return back()->with('success', 'Idea updated.');
    }

    public function follow(Request $request, HubPage $page, HubCommunity $community)
    {
        $data = $request->validate(['following' => 'required|boolean']);
        $community->follow($request->user(), $page, $data['following']);

        return back()->with('success', $data['following'] ? 'You are following this page.' : 'Page unfollowed.');
    }

    public function followTeams(Request $request, HubPage $page, HubDepartment $department)
    {
        $data = $request->validate(['actor_id' => 'required|integer|min:1', 'revision_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1', 'following' => 'required|boolean', 'user_id' => 'prohibited']);

        return response()->json($department->follow($request->user(), $page, $data))->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, HubPage $page, HubCommunity $community)
    {
        $data = $request->validate(['revision_id' => 'nullable|integer|min:1']);
        $community->trackRead($request->user(), $page, $data['revision_id'] ?? null);

        return response()->noContent();
    }

    public function interests(Request $request, HubDiscovery $discovery)
    {
        if ($request->isMethod('get')) {
            return response()->json($discovery->preferences($request->user()));
        }
        $data = $request->validate([
            'version' => 'required|integer|min:1', 'topics' => 'present|array|max:5',
            'topics.*' => ['string', 'distinct', Rule::in(HubDiscovery::TOPICS)],
            'teams' => 'present|array|max:100', 'teams.*' => 'integer|min:1|distinct',
        ]);

        return response()->json($discovery->saveInterests($request->user(), $data));
    }

    public function seen(Request $request, HubDiscovery $discovery)
    {
        $data = $request->validate(['keys' => 'required|array|min:1|max:200', 'keys.*' => 'required|string|max:100|distinct', 'undo_batch' => 'nullable|uuid']);

        return response()->json($discovery->seen($request->user(), $data['keys'], $data['undo_batch'] ?? null));
    }
}
