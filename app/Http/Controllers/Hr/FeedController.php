<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedAttachment;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrFeedReaction;
use App\Domain\Hr\Models\HrFeedReply;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Notifications\AnnouncementReplyNotification;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FeedController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(
        private readonly FeedService $feedService,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — community & recognition feed */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->currentStaff->isCurrent($user), 403);

        $type = $request->query('type');
        $search = trim((string) $request->query('search', ''));
        $search = $search !== '' ? $search : null;

        $posts = $this->feedService->getFeed($type, $search, $user->id, $this->viewerSiteIds($user->id));
        // Polymorphic reactions/replies for the non-kudos posts on this page
        // (kudos carry their own kudos-keyed reactions). Loaded in two queries.
        $nonKudosIds = $posts->getCollection()
            ->filter(fn ($post) => $post->post_type !== 'kudos')
            ->pluck('id')
            ->all();
        $postReactions = $this->feedService->feedReactionSummaries('post', $nonKudosIds, $user->id);
        $postReplies = $this->feedService->feedReplyThreads('post', $nonKudosIds);
        $posts->through(fn ($post) => $this->transformPost($post, $user->id, $postReactions, $postReplies));

        return Inertia::render('hr/feed/index', [
            'posts' => $posts,
            'announcements' => $this->feedService->getFeedAnnouncements($user->id, $search),
            'metrics' => $this->feedService->getMetrics(),
            'valueBreakdown' => $this->feedService->getValueBreakdown(),
            'kudosTrend' => $this->feedService->getKudosTrend(),
            'milestones' => $this->feedService->getMilestones(),
            'leaderboard' => $this->feedService->getKudosLeaderboard(),
            'filters' => [
                'type' => $type,
                'search' => $search,
            ],
            'kudosCategories' => FeedService::KUDOS_CATEGORIES,
            'kudosImpacts' => FeedService::KUDOS_IMPACTS,
            'postTypes' => FeedService::POST_TYPES,
            'reactionEmojis' => FeedService::REACTION_EMOJIS,
            'employees' => $this->applicationEmployees(),
            'sites' => $this->operationalSites(),
            'currentUserId' => $user->id,
            'can' => [
                'manageAnnouncements' => (bool) $user->canDo('hr.announcements.manage'),
                // Moderation (remove post/kudos). No hr.recognition.manage key
                // exists (recognition ships only view/give), so the strongest
                // people-management permission stands in.
                'moderate' => (bool) $user->canDo('hr.employees.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — create a feed post (update) */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->currentStaff->isCurrent($user), 403);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'post_type' => ['required', 'string', Rule::in(['update', 'announcement'])],
            'kind' => ['nullable', 'string', Rule::in(FeedService::POST_KINDS)],
            'target_audience' => ['nullable', 'string', Rule::in(['all', 'site'])],
            'target_value' => ['nullable', 'string', 'required_if:target_audience,site', $this->operationalSiteRule()],
            'attachment' => ['nullable', 'file', 'image', 'mimes:'.implode(',', FeedService::ATTACHMENT_MIMES), 'max:'.FeedService::ATTACHMENT_MAX_KB],
        ]);

        try {
            $post = $this->feedService->createPost($user, $validated);
            if ($request->hasFile('attachment')) {
                $this->feedService->attachToPost($post, $request->file('attachment'), $user->id);
            }
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Post published.');
    }

    /* ------------------------------------------------------------------ */
    /*  Send Kudos — recognition to one or more colleagues */
    /* ------------------------------------------------------------------ */

    public function sendKudos(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->currentStaff->isCurrent($user), 403);

        $currentStaffRecipientRule = $this->currentStaff->recipientRule();

        $validated = $request->validate([
            'to_user_id' => ['required_without:to_user_ids', 'integer', 'exists:users,id', $currentStaffRecipientRule],
            'to_user_ids' => ['required_without:to_user_id', 'array', 'min:1'],
            'to_user_ids.*' => ['integer', 'exists:users,id', $currentStaffRecipientRule],
            'category' => ['required', 'string', Rule::in(array_keys(FeedService::KUDOS_CATEGORIES))],
            'impact' => ['nullable', 'string', Rule::in(array_keys(FeedService::KUDOS_IMPACTS))],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $recipientIds = $validated['to_user_ids'] ?? [$validated['to_user_id']];

        try {
            $this->feedService->sendKudosToMany(
                $user,
                $recipientIds,
                $validated['category'],
                $validated['message'],
                $validated['impact'] ?? null,
            );
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $count = count($recipientIds);

        return redirect()->back()->with('success', $count > 1 ? "Kudos sent to {$count} colleagues! 🎉" : 'Kudos sent! 🎉');
    }

    /* ------------------------------------------------------------------ */
    /*  React / Reply — feed-scoped aliases onto the shared kudos path */
    /* ------------------------------------------------------------------ */

    public function react(Request $request, HrKudos $kudos)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->currentStaff->isCurrent($user), 403);

        $this->assertKudosVisibleTo($kudos, $user);

        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in(FeedService::REACTION_EMOJIS)],
        ]);

        $this->feedService->toggleReaction($kudos, $user->id, $validated['emoji']);

        return redirect()->back()->with('success', 'Reaction updated.');
    }

    public function reply(Request $request, HrKudos $kudos)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->currentStaff->isCurrent($user), 403);

        $this->assertKudosVisibleTo($kudos, $user);
        abort_unless(in_array($user->id, [$kudos->from_user_id, $kudos->to_user_id], true), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $this->feedService->addReply($kudos, $user->id, $validated['body']);

        return redirect()->back()->with('success', 'Reply posted.');
    }

    /* ------------------------------------------------------------------ */
    /*  React / Reply — polymorphic wall items (announcements + posts) */
    /* ------------------------------------------------------------------ */

    public function reactFeed(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->currentStaff->isCurrent($user), 403);

        $validated = $request->validate([
            'subject_type' => ['required', 'string', Rule::in(FeedService::FEED_SUBJECTS)],
            'subject_id' => ['required', 'integer'],
            'emoji' => ['required', 'string', Rule::in(FeedService::REACTION_EMOJIS)],
        ]);

        $this->assertFeedSubjectVisibleTo($validated['subject_type'], (int) $validated['subject_id'], $user);
        $this->feedService->toggleFeedReaction(
            $validated['subject_type'],
            (int) $validated['subject_id'],
            $user->id,
            $validated['emoji'],
        );

        return redirect()->back()->with('success', 'Reaction updated.');
    }

    public function replyFeed(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->currentStaff->isCurrent($user), 403);

        $validated = $request->validate([
            'subject_type' => ['required', 'string', Rule::in(FeedService::FEED_SUBJECTS)],
            'subject_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $subject = $this->assertFeedSubjectVisibleTo(
            $validated['subject_type'],
            (int) $validated['subject_id'],
            $user,
        );
        $this->feedService->addFeedReply(
            $validated['subject_type'],
            (int) $validated['subject_id'],
            $user->id,
            $validated['body'],
        );

        if ($subject instanceof HrAnnouncement) {
            $announcement = $subject->loadMissing('creator:id,name');
            $author = $announcement->creator;

            if ($author && $author->id !== $user->id) {
                $author->notify(new AnnouncementReplyNotification($announcement, $user, $validated['body']));
            }
        }

        return redirect()->back()->with('success', 'Reply posted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Moderation — remove an inappropriate post or kudos */
    /* ------------------------------------------------------------------ */

    /**
     * Remove a feed post (and, for a kudos post, its linked kudos + social
     * thread). Neither model has SoftDeletes, so this is a hard delete with
     * an audit-log entry for accountability. Route-gated + re-checked here on
     * hr.employees.manage (no dedicated feed/recognition manage key exists).
     */
    public function destroyPost(Request $request, HrFeedPost $post)
    {
        $user = $request->user();
        abort_unless($user && $this->currentStaff->isCurrent($user) && $user->canDo('hr.employees.manage'), 403);

        $this->removeFeedPost($request, $post);

        return redirect()->back()->with('success', 'Post removed.');
    }

    /**
     * Remove a kudos (and its feed post + social thread) — the kudos-keyed
     * twin of {@see destroyPost} so both wall card types can be moderated.
     */
    public function destroyKudos(Request $request, HrKudos $kudos)
    {
        $user = $request->user();
        abort_unless($user && $this->currentStaff->isCurrent($user) && $user->canDo('hr.employees.manage'), 403);

        $post = $kudos->feedPost;
        if ($post) {
            $this->removeFeedPost($request, $post->setRelation('kudos', $kudos));
        } else {
            $this->removeKudosRow($request, $kudos);
        }

        return redirect()->back()->with('success', 'Kudos removed.');
    }

    /**
     * Hard-delete a wall post with its dependents (kudos + reactions/replies,
     * polymorphic post reactions/replies, image attachment) and write the
     * audit-log entry. Everything commits together.
     */
    private function removeFeedPost(Request $request, HrFeedPost $post): void
    {
        DB::transaction(function () use ($request, $post) {
            $kudos = $post->kudos ?? $post->kudos()->first();
            if ($kudos) {
                $this->removeKudosRow($request, $kudos, auditSeparately: false);
            }

            // Polymorphic reactions/replies on the post itself.
            HrFeedReaction::where('subject_type', 'post')->where('subject_id', $post->id)->delete();
            HrFeedReply::where('subject_type', 'post')->where('subject_id', $post->id)->delete();

            $attachment = $post->attachment;
            if ($attachment) {
                try {
                    Storage::disk($attachment->disk)->delete($attachment->path);
                } catch (\Throwable) {
                    // Best-effort file cleanup — the DB row still goes.
                }
                $attachment->delete();
            }

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'hr.feed.post.removed',
                'auditable_type' => HrFeedPost::class,
                'auditable_id' => $post->id,
                'meta' => [
                    'post_type' => $post->post_type,
                    'author_user_id' => $post->user_id,
                    'content_excerpt' => Str::limit((string) $post->content, 200),
                    'kudos_id' => $kudos?->id,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            $post->delete();
        });
    }

    /** Delete a kudos row with its reactions/replies (+ audit entry when standalone). */
    private function removeKudosRow(Request $request, HrKudos $kudos, bool $auditSeparately = true): void
    {
        DB::transaction(function () use ($request, $kudos, $auditSeparately) {
            $kudos->reactions()->delete();
            $kudos->replies()->delete();

            if ($auditSeparately) {
                AuditLog::create([
                    'user_id' => $request->user()?->id,
                    'action' => 'hr.feed.kudos.removed',
                    'auditable_type' => HrKudos::class,
                    'auditable_id' => $kudos->id,
                    'meta' => [
                        'from_user_id' => $kudos->from_user_id,
                        'to_user_id' => $kudos->to_user_id,
                        'category' => $kudos->category,
                        'content_excerpt' => Str::limit((string) $kudos->message, 200),
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);
            }

            $kudos->delete();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Attachment download — hardened private-disk stream */
    /* ------------------------------------------------------------------ */

    public function downloadAttachment(Request $request, HrFeedAttachment $attachment)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->currentStaff->isCurrent($user), 403);

        $post = $attachment->post()->first();
        abort_unless($post instanceof HrFeedPost, 404);
        $this->assertPostVisibleTo($post, $user);

        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
            $attachment->isImage() ? 'inline' : 'attachment',
        );
    }

    private function assertFeedSubjectVisibleTo(string $type, int $id, User $viewer): HrAnnouncement|HrFeedPost
    {
        if ($type === 'announcement') {
            $announcement = HrAnnouncement::query()->active()->with('targets')->find($id);
            abort_unless($announcement instanceof HrAnnouncement, 404);
            $this->assertAnnouncementVisibleTo($announcement, $viewer);

            return $announcement;
        }

        $post = HrFeedPost::query()->find($id);
        abort_unless($post instanceof HrFeedPost, 404);
        $this->assertPostVisibleTo($post, $viewer);

        return $post;
    }

    private function assertKudosVisibleTo(HrKudos $kudos, User $viewer): void
    {
        abort_unless($this->feedService->canViewKudos($kudos, $viewer), 404);
    }

    private function assertPostVisibleTo(HrFeedPost $post, User $viewer): void
    {
        abort_unless($this->currentStaff->isCurrent($viewer), 404);

        $audience = $post->target_audience ?: 'all';
        $visible = (int) $post->user_id === (int) $viewer->id
            || $audience === 'all'
            || ($audience === 'site'
                && is_numeric($post->target_value)
                && in_array((int) $post->target_value, $this->viewerSiteIds((int) $viewer->id), true));

        abort_unless($visible, 404);
    }

    private function assertAnnouncementVisibleTo(HrAnnouncement $announcement, User $viewer): void
    {
        abort_unless($this->currentStaff->isCurrent($viewer), 404);

        if ((int) $announcement->created_by === (int) $viewer->id) {
            return;
        }

        $profile = $this->currentStaffProfile((int) $viewer->id);
        $targets = $announcement->targets->isNotEmpty()
            ? $announcement->targets->map(fn ($target): array => [
                'type' => $target->type,
                'value' => $target->value,
            ])
            : collect([[
                'type' => $announcement->target_audience ?: 'all',
                'value' => $announcement->target_value,
            ]]);

        $roleNames = collect([$viewer->role, ...$viewer->roles()->pluck('name')->all()])
            ->filter()
            ->map(fn ($role): string => (string) $role)
            ->all();
        $siteIds = $this->viewerSiteIds((int) $viewer->id);
        $visible = $targets->contains(function (array $target) use ($viewer, $profile, $roleNames, $siteIds): bool {
            $type = $target['type'] ?? null;
            $value = trim((string) ($target['value'] ?? ''));

            return match ($type) {
                'all' => true,
                'user' => is_numeric($value) && (int) $value === (int) $viewer->id,
                'site' => is_numeric($value) && in_array((int) $value, $siteIds, true),
                'department' => $profile !== null && (
                    $value === (string) $profile->department
                    || (is_numeric($value) && (int) $value === (int) $profile->department_id)
                ),
                'role' => ($profile !== null && $value === (string) $profile->position_role)
                    || in_array($value, $roleNames, true),
                default => false,
            };
        });

        abort_unless($visible, 404);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function transformPost($post, int $viewerId, array $postReactions = [], array $postReplies = []): array
    {
        $kudos = $post->post_type === 'kudos' ? $post->kudos : null;

        return [
            'id' => $post->id,
            'post_type' => $post->post_type,
            'kind' => $post->kind,
            'content' => $post->content,
            'is_pinned' => $post->is_pinned,
            'user' => $post->user ? [
                'id' => $post->user->id,
                'name' => $post->user->name,
            ] : null,
            'kudos' => $kudos ? [
                'id' => $kudos->id,
                'category' => $kudos->category,
                'impact' => $kudos->impact ?? FeedService::DEFAULT_IMPACT,
                'from_user' => $kudos->fromUser ? [
                    'id' => $kudos->fromUser->id,
                    'name' => $kudos->fromUser->name,
                ] : null,
                'to_user' => $kudos->toUser ? [
                    'id' => $kudos->toUser->id,
                    'name' => $kudos->toUser->name,
                ] : null,
                'reactions' => $this->summariseReactions($kudos, $viewerId),
                'replies' => $kudos->replies->map(fn ($reply) => [
                    'id' => $reply->id,
                    'user_name' => $reply->user?->name ?? 'Unknown',
                    'body' => $reply->body,
                    'created_at' => $reply->created_at?->diffForHumans(),
                ])->values()->all(),
                'can_reply' => in_array($viewerId, [$kudos->from_user_id, $kudos->to_user_id], true),
            ] : null,
            // Non-kudos posts (update/question/win/milestone) carry polymorphic
            // feed reactions + an open reply thread.
            'reactions' => $kudos ? null : ($postReactions[$post->id] ?? $this->emptyReactionSummary()),
            'replies' => $kudos ? null : ($postReplies[$post->id] ?? []),
            'attachment' => $post->attachment ? [
                'id' => $post->attachment->id,
                'name' => $post->attachment->original_name,
                'is_image' => $post->attachment->isImage(),
                'url' => '/hr/feed/attachments/'.$post->attachment->id,
            ] : null,
            'audience' => $post->target_audience === 'site' && $post->target_value ? [
                'scope' => 'site',
                'site_id' => (int) $post->target_value,
            ] : null,
            'created_at' => $post->created_at?->diffForHumans(),
            'created_at_date' => $post->created_at?->toDateTimeString(),
        ];
    }

    /** @return array{counts: array<string,int>, mine: array<int,string>} */
    private function emptyReactionSummary(): array
    {
        return ['counts' => array_fill_keys(FeedService::REACTION_EMOJIS, 0), 'mine' => []];
    }

    /**
     * Per-emoji reaction counts plus the emojis the viewer has reacted with.
     *
     * @return array{counts: array<string,int>, mine: array<int,string>}
     */
    private function summariseReactions(HrKudos $kudos, int $viewerId): array
    {
        $counts = array_fill_keys(FeedService::REACTION_EMOJIS, 0);
        $mine = [];

        foreach ($kudos->reactions as $reaction) {
            if (array_key_exists($reaction->emoji, $counts)) {
                $counts[$reaction->emoji]++;
            }
            if ($reaction->user_id === $viewerId) {
                $mine[] = $reaction->emoji;
            }
        }

        return [
            'counts' => $counts,
            'mine' => array_values(array_unique($mine)),
        ];
    }

    /**
     * Current staff picker for this application.
     *
     * @return array<int, array<string, mixed>>
     */
    private function applicationEmployees(): array
    {
        return $this->currentStaff->currentUsersQuery()
            ->with(['hrEmployeeProfile.primarySite:id,name'])
            ->get()
            ->map(fn (User $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'role' => $employee->hrEmployeeProfile?->position_title,
                'site' => $employee->hrEmployeeProfile?->primarySite?->name,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int, name:string}>
     */
    private function operationalSites(): array
    {
        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($site) => [
                'id' => $site->id,
                'name' => $site->name,
            ])
            ->all();
    }

    /**
     * The site ids the viewer belongs to (primary + secondary) — used to scope
     * which site-targeted posts they see. Empty when they have no profile.
     *
     * @return array<int, int>
     */
    private function viewerSiteIds(int $userId): array
    {
        $profile = $this->currentStaffProfile($userId);

        if (! $profile) {
            return [];
        }

        $ids = [];
        if ($profile->primary_site_id) {
            $ids[] = (int) $profile->primary_site_id;
        }
        foreach ((array) $profile->secondary_site_ids as $siteId) {
            if (is_numeric($siteId)) {
                $ids[] = (int) $siteId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function currentStaffProfile(int $userId): ?HrEmployeeProfile
    {
        return HrEmployeeProfile::query()
            ->where('user_id', $userId)
            ->active()
            ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->first();
    }

    private function operationalSiteRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_numeric($value) || ! Site::query()
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->whereKey((int) $value)
                ->exists()) {
                $fail('The selected Site must be active and available.');
            }
        };
    }
}
