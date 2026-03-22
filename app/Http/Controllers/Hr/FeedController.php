<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Services\FeedService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feedService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — community feed with milestones sidebar & kudos leaderboard */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $tenantId = $user->tenant_id;
        $type = $request->query('type');

        $posts = $this->feedService->getFeed($tenantId, $type);
        $milestones = $this->feedService->getMilestones($tenantId);
        $leaderboard = $this->feedService->getKudosLeaderboard($tenantId);

        $posts->through(fn ($post) => [
            'id' => $post->id,
            'post_type' => $post->post_type,
            'content' => $post->content,
            'is_pinned' => $post->is_pinned,
            'user' => $post->user ? [
                'id' => $post->user->id,
                'name' => $post->user->name,
            ] : null,
            'kudos' => $post->kudos ? [
                'id' => $post->kudos->id,
                'category' => $post->kudos->category,
                'from_user' => $post->kudos->fromUser ? [
                    'id' => $post->kudos->fromUser->id,
                    'name' => $post->kudos->fromUser->name,
                ] : null,
                'to_user' => $post->kudos->toUser ? [
                    'id' => $post->kudos->toUser->id,
                    'name' => $post->kudos->toUser->name,
                ] : null,
            ] : null,
            'created_at' => $post->created_at?->diffForHumans(),
            'created_at_date' => $post->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('hr/feed/index', [
            'posts' => $posts,
            'milestones' => $milestones,
            'leaderboard' => $leaderboard,
            'filters' => [
                'type' => $type,
            ],
            'kudosCategories' => FeedService::KUDOS_CATEGORIES,
            'postTypes' => FeedService::POST_TYPES,
            'employees' => \App\Models\User::where('tenant_id', $tenantId)
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — create a feed post                                         */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'post_type' => ['required', 'string', Rule::in(['update', 'announcement'])],
        ]);

        try {
            $this->feedService->createPost($user, $validated);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Post published.');
    }

    /* ------------------------------------------------------------------ */
    /*  Send Kudos — send recognition to another employee                  */
    /* ------------------------------------------------------------------ */

    public function sendKudos(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'category' => ['required', 'string', Rule::in(array_keys(FeedService::KUDOS_CATEGORIES))],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->feedService->sendKudos(
                $user,
                $validated['to_user_id'],
                $validated['category'],
                $validated['message'],
            );
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Kudos sent!');
    }
}
