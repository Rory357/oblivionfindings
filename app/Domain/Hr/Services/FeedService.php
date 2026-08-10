<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedAttachment;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrFeedReaction;
use App\Domain\Hr\Models\HrFeedReply;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReaction;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Notifications\FeedReplyNotification;
use App\Domain\Hr\Notifications\KudosReceivedNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FeedService
{
    public function __construct(
        private readonly AnnouncementAudienceResolver $announcementAudiences,
        private readonly HrAudienceAccessService $audiences,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    public function canViewKudos(HrKudos $kudos, User $viewer): bool
    {
        if (! $this->currentStaff->isCurrent($viewer)) {
            return false;
        }

        if (in_array((int) $viewer->id, [(int) $kudos->from_user_id, (int) $kudos->to_user_id], true)) {
            return true;
        }

        $post = $kudos->feedPost()->first();
        if (! $post instanceof HrFeedPost) {
            return false;
        }

        $targets = [[
            'type' => $post->target_audience ?: 'all',
            'value' => $post->target_value,
        ]];

        return $this->audiences->resolveUsers($targets)
            ->contains(fn (User $recipient) => (int) $recipient->id === (int) $viewer->id);
    }

    /**
     * Kudos *values* — what the colleague demonstrated (shown as the value badge).
     */
    public const KUDOS_CATEGORIES = [
        'teamwork' => 'Teamwork',
        'innovation' => 'Innovation',
        'leadership' => 'Leadership',
        'customer_focus' => 'Customer Focus',
        'going_above' => 'Going Above & Beyond',
        'other' => 'Other',
    ];

    /**
     * Kudos *impact* — the strength of the shout-out (shown as the impact badge).
     */
    public const KUDOS_IMPACTS = [
        'thank_you' => 'Thank You',
        'good_job' => 'Good Job',
        'impressive' => 'Impressive',
        'exceptional' => 'Exceptional',
    ];

    public const DEFAULT_IMPACT = 'good_job';

    /**
     * Emoji reactions supported on a kudos card.
     */
    public const REACTION_EMOJIS = ['heart', 'party', 'hands'];

    /**
     * Polymorphic wall subjects that carry feed reactions/replies (everything
     * except kudos, which keep their own kudos-keyed reactions).
     */
    public const FEED_SUBJECTS = ['post', 'announcement'];

    /**
     * Composer image attachment allowlist + size cap (defence-in-depth against
     * stored-XSS; images are served inline from the private disk with a locked-down
     * CSP). Images only for now.
     */
    public const ATTACHMENT_MIMES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const ATTACHMENT_MAX_KB = 10240;

    /**
     * Post types supported.
     */
    public const POST_TYPES = ['update', 'milestone', 'kudos', 'announcement'];

    /**
     * Composer "kinds" — the three Post-update flavours. All persist with
     * post_type=update; `kind` only drives the wall badge + composer prompt.
     */
    public const POST_KINDS = ['update', 'question', 'win'];

    /**
     * Create a new feed post.
     */
    public function createPost(User $user, array $data): HrFeedPost
    {
        if (! $this->currentStaff->isCurrent($user)) {
            throw ValidationException::withMessages([
                'content' => 'Only current approved staff can publish to the community feed.',
            ]);
        }

        return DB::transaction(function () use ($user, $data) {
            $targetsSite = ($data['target_audience'] ?? 'all') === 'site' && ! empty($data['target_value']);

            return HrFeedPost::create([
                'user_id' => $user->id,
                'post_type' => $data['post_type'] ?? 'update',
                'kind' => in_array($data['kind'] ?? null, self::POST_KINDS, true) ? $data['kind'] : null,
                'target_audience' => $targetsSite ? 'site' : 'all',
                'target_value' => $targetsSite ? (string) $data['target_value'] : null,
                'content' => $data['content'],
                'is_pinned' => $data['is_pinned'] ?? false,
            ]);
        });
    }

    /**
     * Store an image attachment for a post on the private disk. Mime/size are the
     * caller's validation responsibility; this only persists.
     */
    public function attachToPost(HrFeedPost $post, UploadedFile $file, int $userId): HrFeedAttachment
    {
        $path = $file->store('hr/feed/'.$post->getKey(), 'private');

        return HrFeedAttachment::create([
            'feed_post_id' => $post->id,
            'uploaded_by' => $userId,
            'disk' => 'private',
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    /**
     * Send kudos to another user. Also creates a corresponding feed post.
     */
    public function sendKudos(User $from, int $toUserId, string $category, string $message, ?string $impact = null): HrKudos
    {
        if (! $this->currentStaff->isCurrent($from) || ! $this->currentStaff->isCurrent($toUserId)) {
            throw ValidationException::withMessages([
                'to_user_id' => 'Recognition can only be sent between current approved staff.',
            ]);
        }

        return DB::transaction(function () use ($from, $toUserId, $category, $message, $impact) {
            $recipient = User::query()->findOrFail($toUserId);

            // Create a feed post for the kudos
            $feedPost = HrFeedPost::create([
                'user_id' => $from->id,
                'post_type' => 'kudos',
                'content' => $message,
                'is_pinned' => false,
            ]);

            $kudos = HrKudos::create([
                'from_user_id' => $from->id,
                'to_user_id' => $toUserId,
                'category' => $category,
                'impact' => $this->normaliseImpact($impact),
                'message' => $message,
                'is_public' => true,
                'feed_post_id' => $feedPost->id,
            ]);

            // Tell the recipient they've been recognised (never the sender —
            // a self-kudos stays silent). Best-effort: the notification is
            // afterCommit-queued, and a failure never rolls back the kudos.
            if ($recipient->id !== $from->id) {
                try {
                    $recipient->notify(new KudosReceivedNotification($kudos, $from->name));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to send kudos-received notification', [
                        'kudos_id' => $kudos->id,
                        'recipient_id' => $recipient->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            return $kudos;
        });
    }

    /**
     * Send the same kudos to several recipients at once — one kudos (and feed
     * post) per recipient, all in a single transaction. Single-recipient callers
     * stay on {@see sendKudos}; this is the multi-select path from the wizard.
     *
     * @param  array<int|string>  $toUserIds
     * @return Collection<int, HrKudos>
     */
    public function sendKudosToMany(User $from, array $toUserIds, string $category, string $message, ?string $impact = null): Collection
    {
        $ids = collect($toUserIds)
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values();

        return DB::transaction(fn () => $ids->map(
            fn (int $id) => $this->sendKudos($from, $id, $category, $message, $impact),
        ));
    }

    /**
     * Toggle an emoji reaction on a kudos for one user (one of each emoji per
     * person — calling again removes it). Returns true when the reaction is now
     * active, false when it was removed. Authorisation is the caller's concern.
     */
    public function toggleReaction(HrKudos $kudos, int $userId, string $emoji): bool
    {
        $existing = HrKudosReaction::where('kudos_id', $kudos->id)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        try {
            HrKudosReaction::create([
                'kudos_id' => $kudos->id,
                'user_id' => $userId,
                'emoji' => $emoji,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent identical reaction won the race (rapid double-click) —
            // the unique(kudos_id,user_id,emoji) index already holds it. No-op.
        }

        return true;
    }

    /**
     * Post a reply on a kudos thread. Authorisation (giver/receiver only) is the
     * caller's concern.
     */
    public function addReply(HrKudos $kudos, int $userId, string $body): HrKudosReply
    {
        $reply = HrKudosReply::create([
            'kudos_id' => $kudos->id,
            'user_id' => $userId,
            'body' => $body,
        ]);

        // A kudos thread is between exactly two people — tell the other one.
        $otherId = $userId === $kudos->from_user_id ? $kudos->to_user_id : $kudos->from_user_id;
        if ($otherId) {
            $this->notifyReply((int) $otherId, $userId, 'your kudos', $body);
        }

        return $reply;
    }

    /**
     * Best-effort "someone replied" notification (database/bell only). Skips the
     * self-reply case and any missing users; never breaks the reply write.
     */
    private function notifyReply(int $recipientId, int $replierId, string $context, string $body): void
    {
        if ($recipientId === $replierId) {
            return;
        }

        $recipient = User::find($recipientId);
        $replier = User::find($replierId);
        if (! $recipient || ! $replier) {
            return;
        }

        try {
            $recipient->notify(new FeedReplyNotification($replier->name, $context, $body));
        } catch (\Throwable $e) {
            Log::warning('Failed to send feed reply notification', [
                'recipient_id' => $recipientId,
                'replier_id' => $replierId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Toggle a polymorphic feed reaction (announcements + non-kudos posts).
     * Returns true when now active, false when removed. Authorisation is the
     * caller's concern.
     */
    public function toggleFeedReaction(string $subjectType, int $subjectId, int $userId, string $emoji): bool
    {
        $existing = HrFeedReaction::where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        try {
            HrFeedReaction::create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'user_id' => $userId,
                'emoji' => $emoji,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent identical reaction won the race — already on. No-op.
        }

        return true;
    }

    public function addFeedReply(string $subjectType, int $subjectId, int $userId, string $body): HrFeedReply
    {
        $reply = HrFeedReply::create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'user_id' => $userId,
            'body' => $body,
        ]);

        // Tell a post's author that someone replied. (Announcements are org-wide;
        // reply-to-announcement notifications are a separate follow-up.)
        if ($subjectType === 'post') {
            $authorId = HrFeedPost::query()->whereKey($subjectId)->value('user_id');
            if ($authorId) {
                $this->notifyReply((int) $authorId, $userId, 'your post', $body);
            }
        }

        return $reply;
    }

    /**
     * Reaction summaries (per-emoji counts + the viewer's own) for a set of
     * wall subjects, keyed by subject id.
     *
     * @param  array<int>  $subjectIds
     * @return array<int, array{counts: array<string,int>, mine: array<int,string>}>
     */
    public function feedReactionSummaries(string $subjectType, array $subjectIds, int $viewerId): array
    {
        $out = [];
        foreach ($subjectIds as $id) {
            $out[$id] = ['counts' => array_fill_keys(self::REACTION_EMOJIS, 0), 'mine' => []];
        }
        if (empty($subjectIds)) {
            return $out;
        }

        $rows = HrFeedReaction::where('subject_type', $subjectType)
            ->whereIn('subject_id', $subjectIds)
            ->get(['subject_id', 'user_id', 'emoji']);

        foreach ($rows as $row) {
            if (! isset($out[$row->subject_id])) {
                continue;
            }
            if (array_key_exists($row->emoji, $out[$row->subject_id]['counts'])) {
                $out[$row->subject_id]['counts'][$row->emoji]++;
            }
            if ($row->user_id === $viewerId) {
                $out[$row->subject_id]['mine'][] = $row->emoji;
            }
        }

        foreach ($out as &$entry) {
            $entry['mine'] = array_values(array_unique($entry['mine']));
        }

        return $out;
    }

    /**
     * Reply threads (oldest-first) for a set of wall subjects, keyed by subject id.
     *
     * @param  array<int>  $subjectIds
     * @return array<int, array<int, array<string,mixed>>>
     */
    public function feedReplyThreads(string $subjectType, array $subjectIds): array
    {
        if (empty($subjectIds)) {
            return [];
        }

        return HrFeedReply::where('subject_type', $subjectType)
            ->whereIn('subject_id', $subjectIds)
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->groupBy('subject_id')
            ->map(fn ($group) => $group->map(fn ($reply) => [
                'id' => $reply->id,
                'user_name' => $reply->user?->name ?? 'Unknown',
                'body' => $reply->body,
                'created_at' => $reply->created_at?->diffForHumans(),
            ])->values()->all())
            ->all();
    }

    /**
     * Get paginated community feed, optionally filtered by type. Eager-loads the
     * kudos parties plus their reactions and reply threads so the wall can render
     * the social row without N+1 queries.
     */
    /**
     * @param  array<int>  $viewerSiteIds  Site ids the viewer belongs to (for audience scoping).
     */
    public function getFeed(?string $type, ?string $search, int $viewerId, array $viewerSiteIds = [], int $perPage = 20): LengthAwarePaginator
    {
        $siteValues = array_values(array_unique(array_map('strval', $viewerSiteIds)));

        return HrFeedPost::query()
            ->when($type, fn ($q) => $q->where('post_type', $type))
            // Audience scoping: org-wide posts + the viewer's own posts + posts
            // targeting a site the viewer belongs to.
            ->where(function ($q) use ($viewerId, $siteValues) {
                $q->where('target_audience', 'all')->orWhere('user_id', $viewerId);
                if (! empty($siteValues)) {
                    $q->orWhere(fn ($sub) => $sub->where('target_audience', 'site')->whereIn('target_value', $siteValues));
                }
            })
            ->when($search, function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('content', 'like', $term)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhereHas('kudos.toUser', fn ($u) => $u->where('name', 'like', $term));
                });
            })
            ->with([
                'user:id,name',
                'attachment',
                'kudos.toUser:id,name',
                'kudos.fromUser:id,name',
                'kudos.reactions:id,kudos_id,user_id,emoji',
                'kudos.replies' => fn ($q) => $q->with('user:id,name')->orderBy('created_at'),
            ])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Headline metrics for the hero band: kudos this month, participation rate,
     * upcoming celebrations and posts this week.
     *
     * @return array{kudos_this_month:int, participation:int, celebrations:int, posts_this_week:int}
     */
    public function getMetrics(): array
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $weekAgo = $now->copy()->subDays(7);

        $kudosThisMonth = HrKudos::query()
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        $postsThisWeek = HrFeedPost::query()
            ->where('created_at', '>=', $weekAgo)
            ->count();

        $currentUserIds = $this->currentStaff->currentUserIds();
        $activeEmployees = HrEmployeeProfile::query()
            ->whereIn('user_id', $currentUserIds)
            ->whereNotNull('user_id')
            ->count();

        $participants = HrKudos::query()
            ->where('created_at', '>=', $startOfMonth)
            ->get(['from_user_id', 'to_user_id'])
            ->flatMap(fn ($k) => [$k->from_user_id, $k->to_user_id])
            ->filter()
            ->unique()
            ->count();

        $participation = $activeEmployees > 0
            ? (int) round(min($participants, $activeEmployees) / $activeEmployees * 100)
            : 0;

        $milestones = $this->getMilestones();
        $celebrations = count($milestones['birthdays'])
            + count($milestones['anniversaries'])
            + count($milestones['new_hires']);

        return [
            'kudos_this_month' => $kudosThisMonth,
            'participation' => $participation,
            'celebrations' => $celebrations,
            'posts_this_week' => $postsThisWeek,
        ];
    }

    /**
     * Active announcements for the feed wall (the "Notices"), newest-pinned-first,
     * each with acknowledgement progress and whether the viewer has acknowledged.
     * Sourced from the Announcements module — not a fork.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFeedAnnouncements(int $viewerId, ?string $search = null): array
    {
        $viewer = User::query()->find($viewerId);
        if (! $viewer) {
            return [];
        }

        $announcements = HrAnnouncement::query()
            ->active()
            ->when($search, function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(fn ($sub) => $sub->where('title', 'like', $term)->orWhere('content', 'like', $term));
            })
            ->withCount('acknowledgements')
            ->with(['creator:id,name', 'targets'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->lazy(50)
            ->filter(fn (HrAnnouncement $announcement) => $this->announcementAudiences
                ->includesCurrentUser($announcement, $viewer))
            ->take(10)
            ->collect();

        $ids = $announcements->pluck('id')->all();
        $reactions = $this->feedReactionSummaries('announcement', $ids, $viewerId);
        $replies = $this->feedReplyThreads('announcement', $ids);

        return $announcements
            ->map(function (HrAnnouncement $a) use ($viewerId, $reactions, $replies) {
                $acknowledged = $a->acknowledgements()->where('user_id', $viewerId)->exists();

                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'content' => $a->content,
                    'priority' => $a->priority,
                    'is_pinned' => (bool) $a->is_pinned,
                    'requires_acknowledgement' => (bool) $a->requires_acknowledgement,
                    'target_audience' => $a->target_audience,
                    'target_value' => $a->target_value,
                    'creator' => $a->creator ? [
                        'id' => $a->creator->id,
                        'name' => $a->creator->name,
                    ] : null,
                    'acknowledged_count' => $a->acknowledgements_count,
                    'audience_count' => $this->announcementAudienceCount($a),
                    'viewer_acknowledged' => $acknowledged,
                    'reactions' => $reactions[$a->id] ?? ['counts' => array_fill_keys(self::REACTION_EMOJIS, 0), 'mine' => []],
                    'replies' => $replies[$a->id] ?? [],
                    'created_at' => $a->published_at?->diffForHumans() ?? $a->created_at?->diffForHumans(),
                ];
            })
            ->all();
    }

    /**
     * Active employees expected to acknowledge an announcement — the denominator
     * for the "X of Y acknowledged" progress. Delegates to the shared
     * {@see AnnouncementAudienceResolver} so the feed denominator, the publish
     * recipient list and the Tracking roster can never drift apart. Floored at 1
     * so the progress bar never divides by zero.
     */
    private function announcementAudienceCount(HrAnnouncement $announcement): int
    {
        return $this->announcementAudiences->countForAnnouncement($announcement);
    }

    /**
     * Get upcoming milestones: birthdays, work anniversaries, new hires. All date
     * maths is whole-day and direction-agnostic so it is correct under Carbon 3
     * (where `diffIn*` returns a signed float).
     */
    public function getMilestones(): array
    {
        $today = now()->startOfDay();
        $currentUserIds = $this->currentStaff->currentUserIds();

        // Work anniversaries — ≥ 1 year of service, next anniversary within 30 days.
        $anniversaries = HrEmployeeProfile::query()
            ->whereIn('user_id', $currentUserIds)
            ->whereNotNull('start_date')
            ->where('start_date', '<', $today->copy()->subYear())
            ->with('user:id,name')
            ->get()
            ->map(function ($profile) use ($today) {
                $start = $profile->start_date->copy()->startOfDay();
                $next = $this->nextRecurrence($start, $today);

                return [
                    'type' => 'anniversary',
                    'user_name' => $profile->user?->name ?? 'Unknown',
                    'user_id' => $profile->user_id,
                    'date' => $start->format('M d'),
                    'days_away' => $this->wholeDaysBetween($today, $next),
                    'years' => $next->year - $start->year,
                ];
            })
            ->filter(fn ($m) => $m['days_away'] <= 30)
            ->values()
            ->all();

        // Birthdays — date_of_birth is encrypted; decrypt, find next birthday ≤ 30 days.
        $birthdays = HrEmployeeProfile::query()
            ->whereIn('user_id', $currentUserIds)
            ->whereNotNull('date_of_birth')
            ->with('user:id,name')
            ->get()
            ->map(function ($profile) use ($today) {
                try {
                    $dob = Carbon::parse($profile->date_of_birth)->startOfDay();
                } catch (\Exception) {
                    return null;
                }
                $next = $this->nextRecurrence($dob, $today);

                return [
                    'type' => 'birthday',
                    'user_name' => $profile->user?->name ?? 'Unknown',
                    'user_id' => $profile->user_id,
                    'date' => $dob->format('M d'),
                    'days_away' => $this->wholeDaysBetween($today, $next),
                ];
            })
            ->filter(fn ($m) => $m !== null && $m['days_away'] <= 30)
            ->values()
            ->all();

        // New hires — started in the last 30 days (days_away negative = days since start).
        $newHires = HrEmployeeProfile::query()
            ->whereIn('user_id', $currentUserIds)
            ->whereNotNull('start_date')
            ->where('start_date', '>=', $today->copy()->subDays(30))
            ->with('user:id,name')
            ->get()
            ->map(function ($profile) use ($today) {
                $start = $profile->start_date->copy()->startOfDay();

                return [
                    'type' => 'new_hire',
                    'user_name' => $profile->user?->name ?? 'Unknown',
                    'user_id' => $profile->user_id,
                    'date' => $start->format('M d'),
                    'days_away' => -1 * $this->wholeDaysBetween($start, $today),
                    'position' => $profile->position_title,
                ];
            })
            ->values()
            ->all();

        return [
            'birthdays' => $birthdays,
            'anniversaries' => $anniversaries,
            'new_hires' => $newHires,
        ];
    }

    /** The next occurrence of a month/day on or after $today (this year or next). */
    private function nextRecurrence(Carbon $date, Carbon $today): Carbon
    {
        $next = $date->copy()->year($today->year)->startOfDay();
        if ($next->lt($today)) {
            $next->addYear();
        }

        return $next;
    }

    /** Whole calendar days between two dates, direction-agnostic. */
    private function wholeDaysBetween(Carbon $a, Carbon $b): int
    {
        return (int) abs(round(
            $a->copy()->startOfDay()->diffInDays($b->copy()->startOfDay()),
        ));
    }

    /**
     * Get kudos leaderboard — top recipients by count this month.
     */
    public function getKudosLeaderboard(?Carbon $since = null): Collection
    {
        $since ??= now()->startOfMonth();

        return HrKudos::query()
            ->where('created_at', '>=', $since)
            ->select('to_user_id', DB::raw('COUNT(*) as kudos_count'))
            ->with('toUser:id,name')
            ->groupBy('to_user_id')
            ->orderByDesc('kudos_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->to_user_id,
                'user_name' => $row->toUser?->name ?? 'Unknown',
                'kudos_count' => $row->kudos_count,
            ]);
    }

    /**
     * Most-recognised values this month — every category with its kudos count,
     * highest first. Powers the "Recognition insights" modal.
     *
     * @return array<int, array{key:string, label:string, count:int}>
     */
    public function getValueBreakdown(): array
    {
        $counts = HrKudos::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->select('category', DB::raw('COUNT(*) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        return collect(self::KUDOS_CATEGORIES)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'count' => (int) ($counts[$key] ?? 0),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Kudos volume per week for the last N weeks (oldest-first) — the insights
     * modal's trend sparkline.
     *
     * @return array<int, array{label:string, count:int}>
     */
    public function getKudosTrend(int $weeks = 8): array
    {
        $firstWeek = now()->startOfWeek()->subWeeks($weeks - 1);

        $byWeek = HrKudos::query()
            ->where('created_at', '>=', $firstWeek)
            ->get(['created_at'])
            ->groupBy(fn ($kudos) => $kudos->created_at->copy()->startOfWeek()->format('Y-m-d'));

        $trend = [];
        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $firstWeek->copy()->addWeeks($i);
            $key = $weekStart->format('Y-m-d');
            $trend[] = [
                'label' => $weekStart->format('j M'),
                'count' => isset($byWeek[$key]) ? $byWeek[$key]->count() : 0,
            ];
        }

        return $trend;
    }

    private function normaliseImpact(?string $impact): string
    {
        return array_key_exists($impact, self::KUDOS_IMPACTS) ? $impact : self::DEFAULT_IMPACT;
    }
}
